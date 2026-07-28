<?php

defined('ABSPATH') || exit;

class FgBackup_Async {

    const CRON_HOOK = 'fg_backup_process_job';
    const LOCK_OPTION = 'fg_backup_active_job';

    public static function init() {
        add_action(self::CRON_HOOK, [__CLASS__, 'process'], 10, 1);
    }

    public static function queue_backup($type = 'full', $origin = 'manual') {
        $type = in_array($type, ['full', 'db'], true) ? $type : 'full';
        $origin = $origin === 'scheduled' ? 'scheduled' : 'manual';

        FgBackup_Storage::ensure();
        self::release_stale_lock();

        $active = get_option(self::LOCK_OPTION, []);
        if (!empty($active['job_id'])) {
            return new WP_Error('fg_backup_running', __('Es läuft bereits ein Backup.', 'fg-backup-pro'));
        }

        $job_id = 'backup_' . str_replace('-', '', wp_generate_uuid4());
        $job = [
            'id' => $job_id,
            'status' => 'queued',
            'type' => $type,
            'origin' => $origin,
            'progress' => 0,
            'step' => 'init',
            'started_at' => time(),
            'finished_at' => 0,
            'error' => '',
            'file' => '',
            'size' => 0,
            'checksum' => '',
        ];

        update_option(self::LOCK_OPTION, [
            'job_id' => $job_id,
            'created_at' => time(),
        ], false);
        update_option(self::option_name($job_id), $job, false);

        if (wp_schedule_single_event(time() + 1, self::CRON_HOOK, [$job_id]) === false) {
            delete_option(self::LOCK_OPTION);
            delete_option(self::option_name($job_id));
            return new WP_Error('fg_backup_schedule_failed', __('Der Backup-Job konnte nicht geplant werden.', 'fg-backup-pro'));
        }

        return $job_id;
    }

    public static function process($job_id) {
        $job_id = sanitize_key($job_id);
        $job = get_option(self::option_name($job_id), false);
        if (!is_array($job) || in_array($job['status'], ['completed', 'failed'], true)) {
            return;
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(20);
        }

        $job['status'] = 'running';

        try {
            switch ($job['step']) {
                case 'init':
                    self::initialize_job($job);
                    break;

                case 'db_export':
                    self::process_database($job);
                    break;

                case 'files_scan':
                    self::process_file_scan($job);
                    break;

                case 'zip_init':
                    FgBackup_Backup::initialize_zip($job['archive_temp']);
                    $job['step'] = 'files_zip';
                    $job['progress'] = 62;
                    break;

                case 'files_zip':
                    self::process_zip($job);
                    break;

                case 'verify':
                    self::verify_and_publish($job);
                    break;

                case 'cleanup':
                    self::complete_job($job);
                    break;

                default:
                    throw new RuntimeException(__('Unbekannter Backup-Schritt.', 'fg-backup-pro'));
            }

            update_option(self::option_name($job_id), $job, false);

            if (!in_array($job['status'], ['completed', 'failed'], true)) {
                if (wp_schedule_single_event(time() + 1, self::CRON_HOOK, [$job_id]) === false) {
                    throw new RuntimeException(__('Der nächste Backup-Schritt konnte nicht geplant werden.', 'fg-backup-pro'));
                }
            }
        } catch (Throwable $exception) {
            self::fail_job($job, $exception->getMessage());
        }
    }

    private static function initialize_job(array &$job) {
        $timestamp = wp_date('Y-m-d-H-i-s');
        $temp_dir = FgBackup_Storage::create_job_temp_dir($job['id']);
        $source_root = FgBackup_Backup::get_source_root();

        $job['temp_dir'] = $temp_dir;
        $job['source_root'] = $source_root;
        $job['db_file'] = $temp_dir . 'database.sql.part';
        $job['manifest_file'] = $temp_dir . 'files.jsonl';
        $job['archive_temp'] = $temp_dir . 'backup.zip.part';
        $job['final_path'] = FgBackup_Storage::get_backup_dir()
            . ($job['type'] === 'full' ? 'fg-full-' : 'fg-db-')
            . $timestamp
            . ($job['type'] === 'full' ? '.zip' : '.sql');
        $job['tables'] = FgBackup_Backup::get_tables();
        $job['table_index'] = 0;
        $job['row_offset'] = 0;
        $job['table_started'] = false;
        if ($job['type'] === 'full' && !class_exists('ZipArchive')) {
            throw new RuntimeException(__('Die PHP-Erweiterung ZipArchive ist für vollständige Backups erforderlich.', 'fg-backup-pro'));
        }

        $job['scan_queue'] = [[
            'path' => $source_root,
            'offset' => 0,
        ]];
        $job['file_count'] = 0;
        $job['manifest_offset'] = 0;
        $job['zipped_files'] = 0;
        $job['progress'] = 2;
        $job['step'] = 'db_export';

        FgBackup_Backup::write_database_header($job['db_file']);
    }

    private static function process_database(array &$job) {
        $tables = isset($job['tables']) && is_array($job['tables']) ? $job['tables'] : [];
        $table_count = count($tables);

        if ($job['table_index'] >= $table_count) {
            FgBackup_Backup::write_database_footer($job['db_file']);
            if ($job['type'] === 'db') {
                $job['step'] = 'verify';
                $job['progress'] = 90;
            } else {
                $job['step'] = 'files_scan';
                $job['progress'] = 45;
            }
            return;
        }

        $table = $tables[$job['table_index']];
        $result = FgBackup_Backup::export_table_chunk(
            $table,
            $job['db_file'],
            (int) $job['row_offset'],
            !$job['table_started']
        );

        $job['table_started'] = true;
        $job['row_offset'] += (int) $result['rows'];

        if (!empty($result['done'])) {
            $job['table_index']++;
            $job['row_offset'] = 0;
            $job['table_started'] = false;
        }

        $range = $job['type'] === 'db' ? 78 : 38;
        $base = 5;
        $job['progress'] = $table_count > 0
            ? min($base + $range, $base + (int) floor(($job['table_index'] / $table_count) * $range))
            : $base + $range;
    }

    private static function process_file_scan(array &$job) {
        $result = FgBackup_Backup::scan_files_chunk(
            isset($job['scan_queue']) ? (array) $job['scan_queue'] : [],
            $job['manifest_file'],
            $job['source_root'],
            700,
            7
        );

        $job['scan_queue'] = $result['queue'];
        $job['file_count'] += (int) $result['files_added'];
        $job['progress'] = min(60, 45 + (int) floor(min($job['file_count'], 10000) / 667));

        if (!empty($result['done'])) {
            $job['step'] = 'zip_init';
            $job['progress'] = 60;
        }
    }

    private static function process_zip(array &$job) {
        $result = FgBackup_Backup::add_manifest_files_to_zip(
            $job['manifest_file'],
            (int) $job['manifest_offset'],
            $job['archive_temp'],
            $job['source_root'],
            FgBackup_Backup::FILES_PER_CHUNK,
            7
        );

        $job['manifest_offset'] = (int) $result['offset'];
        $job['zipped_files'] += (int) $result['added'];

        $file_count = max(1, (int) $job['file_count']);
        $job['progress'] = min(92, 62 + (int) floor(($job['zipped_files'] / $file_count) * 30));

        if (!empty($result['done'])) {
            FgBackup_Backup::finalize_zip($job['archive_temp'], $job['db_file'], [
                'plugin' => 'FG Backup Pro',
                'plugin_version' => FG_BACKUP_VERSION,
                'created_at' => gmdate('c'),
                'home_url' => home_url('/'),
                'site_url' => site_url('/'),
                'wordpress_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
                'backup_type' => $job['type'],
                'file_count' => (int) $job['zipped_files'],
            ]);
            $job['step'] = 'verify';
            $job['progress'] = 94;
        }
    }

    private static function verify_and_publish(array &$job) {
        if ($job['type'] === 'full') {
            FgBackup_Backup::verify_zip($job['archive_temp']);
            FgBackup_Backup::move_to_final($job['archive_temp'], $job['final_path']);
        } else {
            FgBackup_Backup::verify_sql($job['db_file']);
            FgBackup_Backup::move_to_final($job['db_file'], $job['final_path']);
        }

        $job['file'] = basename($job['final_path']);
        $job['size'] = (int) filesize($job['final_path']);
        $job['checksum'] = FgBackup_Backup::checksum($job['final_path']);
        $job['progress'] = 98;
        $job['step'] = 'cleanup';
    }

    private static function complete_job(array &$job) {
        if (!empty($job['temp_dir'])) {
            FgBackup_Storage::remove_tree($job['temp_dir']);
        }

        FgBackup_Cleanup::rotate_backups();
        $job['status'] = 'completed';
        $job['progress'] = 100;
        $job['finished_at'] = time();
        self::add_history($job);
        self::release_lock($job['id']);
        FgBackup_Notifications::success($job);
    }

    private static function fail_job(array $job, $message) {
        $job['status'] = 'failed';
        $job['error'] = sanitize_text_field($message);
        $job['finished_at'] = time();

        if (!empty($job['temp_dir'])) {
            FgBackup_Storage::remove_tree($job['temp_dir']);
        }

        update_option(self::option_name($job['id']), $job, false);
        self::add_history($job);
        self::release_lock($job['id']);
        FgBackup_Notifications::failure($job);
    }

    private static function add_history(array $job) {
        $history = get_option('fg_backup_history', []);
        if (!is_array($history)) {
            $history = [];
        }

        array_unshift($history, [
            'id' => $job['id'],
            'status' => $job['status'],
            'type' => $job['type'],
            'origin' => $job['origin'],
            'started_at' => (int) $job['started_at'],
            'finished_at' => (int) $job['finished_at'],
            'file' => isset($job['file']) ? $job['file'] : '',
            'size' => isset($job['size']) ? (int) $job['size'] : 0,
            'checksum' => isset($job['checksum']) ? $job['checksum'] : '',
            'error' => isset($job['error']) ? $job['error'] : '',
        ]);

        update_option('fg_backup_history', array_slice($history, 0, 20), false);
    }

    public static function get_status($job_id) {
        return get_option(self::option_name(sanitize_key($job_id)), false);
    }

    public static function get_active_job() {
        self::release_stale_lock();
        $active = get_option(self::LOCK_OPTION, []);
        if (empty($active['job_id'])) {
            return false;
        }
        return self::get_status($active['job_id']);
    }

    private static function release_stale_lock() {
        $active = get_option(self::LOCK_OPTION, []);
        if (empty($active['job_id'])) {
            return;
        }

        $created = isset($active['created_at']) ? (int) $active['created_at'] : 0;
        $job = self::get_status($active['job_id']);
        $finished = is_array($job) && in_array($job['status'], ['completed', 'failed'], true);

        if ($finished || !$created || $created < time() - (12 * HOUR_IN_SECONDS)) {
            delete_option(self::LOCK_OPTION);
        }
    }

    private static function release_lock($job_id) {
        $active = get_option(self::LOCK_OPTION, []);
        if (!empty($active['job_id']) && $active['job_id'] === $job_id) {
            delete_option(self::LOCK_OPTION);
        }
    }

    private static function option_name($job_id) {
        return 'fg_backup_job_' . sanitize_key($job_id);
    }
}
