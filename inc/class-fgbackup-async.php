<?php

class FgBackup_Async {

    const CRON_HOOK     = 'fg_run_backup_async';
    const MAX_CHUNK_TIME = 10; // Sekunden pro Chunk

    public static function init() {
        add_action(self::CRON_HOOK, [__CLASS__, 'process_chunk'], 10, 1);
    }

    public static function queue_backup($type = 'full', $targets = []) {
        $job_id = 'backup_' . uniqid();
        update_option("fg_backup_job_{$job_id}", [
            'status'              => 'queued',
            'type'                => $type,
            'targets'             => $targets,
            'progress'            => 0,
            'start_time'          => time(),
            'step'                => 'init',
            'db_tables'           => [],
            'current_table_index' => 0,
            'file_index'          => 0,
            'zip_path'            => '',
            'db_file'             => ''
        ]);
        wp_schedule_single_event(time(), self::CRON_HOOK, [$job_id]);
        return $job_id;
    }

    public static function process_chunk($job_id) {
        $job = get_option("fg_backup_job_{$job_id}", false);
        if (!$job) {
            return;
        }

        try {
            // Sicherstellen, dass das Verzeichnis existiert
            FgBackup_Backup::get_backup_dir();

            set_time_limit(self::MAX_CHUNK_TIME);
            $job['status'] = 'running';

            switch ($job['step']) {
                case 'init':
                    $job['step'] = 'db_init';
                    break;

                case 'db_init':
                    // Verzeichnis und Datei anlegen
                    $backup_dir           = FgBackup_Backup::get_backup_dir();
                    $job['db_file']       = $backup_dir . "fg-db-" . date('Y-m-d-H-i-s') . ".sql";
                    $job['db_tables']     = FgBackup_Backup::get_all_tables();
                    $job['step']          = 'db_export';
                    break;

                case 'db_export':
                    $table = $job['db_tables'][$job['current_table_index']];
                    FgBackup_Backup::export_table_to_sql($table, $job['db_file']);
                    $job['current_table_index']++;
                    if ($job['current_table_index'] >= count($job['db_tables'])) {
                        $job['step'] = ($job['type'] === 'db') ? 'upload' : 'files_zip';
                    }
                    break;

                case 'files_zip':
                    if (!$job['zip_path']) {
                        $job['zip_path'] = FgBackup_Backup::get_backup_dir() . "fg-full-" . date('Y-m-d-H-i-s') . ".zip";
                    }
                    $files = array_slice(FgBackup_Backup::get_all_files(), $job['file_index'], 50);
                    foreach ($files as $file) {
                        FgBackup_Backup::add_file_to_zip($file, $job['zip_path']);
                    }
                    $job['file_index'] += count($files);
                    if ($job['file_index'] >= count(FgBackup_Backup::get_all_files())) {
                        FgBackup_Backup::finalize_zip($job['zip_path']);
                        if ($job['type'] === 'full') {
                            FgBackup_Backup::add_db_file_to_zip($job['db_file'], $job['zip_path']);
                        }
                        $job['step'] = 'upload';
                    }
                    break;

                case 'upload':
                    FgBackup_Target::upload_backups($job['targets'], $job['type'] === 'full' ? $job['zip_path'] : $job['db_file']);
                    $job['step'] = 'cleanup';
                    break;

                case 'cleanup':
                    FgBackup_Cleanup::rotate_backups();
                    $job['status'] = 'completed';
                    FgBackup_Notifications::notify_admin_of_backup();
                    break;
            }

            update_option("fg_backup_job_{$job_id}", $job, false);

            if ($job['status'] !== 'completed') {
                wp_schedule_single_event(time() + 1, self::CRON_HOOK, [$job_id]);
            }

        } catch (Exception $e) {
            $job['status'] = 'failed';
            $job['error']  = $e->getMessage();
            update_option("fg_backup_job_{$job_id}", $job);
            FgBackup_Notifications::notify_admin_on_failure($e->getMessage());
        }
    }

    public static function get_status($job_id) {
        return get_option("fg_backup_job_{$job_id}", false);
    }
}
