<?php

defined('ABSPATH') || exit;

class FgBackup_Async {

    const CRON_HOOK = 'fg_backup_process_job';
    const LOCK_OPTION = 'fg_backup_active_job';
    const PROCESS_LOCK_TTL = 30 * MINUTE_IN_SECONDS;

    private static $process_tokens = [];
    private static $shutdown_dispatch_registered = false;

    public static function init() {
        add_action(self::CRON_HOOK, [__CLASS__, 'process'], 10, 1);
    }

    public static function queue_backup($type = 'full', $origin = 'manual', $format = '', $note = '') {
        $type = in_array($type, ['full', 'db'], true) ? $type : 'full';
        $origin = $origin === 'scheduled' ? 'scheduled' : 'manual';
        if ($format === '') {
            $format = $type === 'full'
                ? get_option('fg_backup_archive_format', 'zip')
                : get_option('fg_backup_database_format', 'gz');
        }
        $format = FgBackup_Backup::normalize_format($type, $format);
        $note = FgBackup_Admin::sanitize_note($note);

        FgBackup_Storage::ensure();
        self::release_stale_lock();

        $active = get_option(self::LOCK_OPTION, []);
        if (!empty($active['job_id'])) {
            return new WP_Error('fg_backup_running', __('Es läuft bereits ein Backup.', 'fg-backup-pro'));
        }

        $remote_queue = FgBackup_Remotes::enabled_ids();
        $job_id = 'backup_' . str_replace('-', '', wp_generate_uuid4());
        $job = [
            'id' => $job_id,
            'status' => 'queued',
            'type' => $type,
            'format' => $format,
            'origin' => $origin,
            'note' => $note,
            'progress' => 0,
            'stage' => __('Wartet auf Start', 'fg-backup-pro'),
            'detail' => '',
            'step' => 'init',
            'started_at' => time(),
            'updated_at' => time(),
            'finished_at' => 0,
            'error' => '',
            'file' => '',
            'size' => 0,
            'checksum' => '',
            'manifest_path' => '',
            'manifest_file_name' => '',
            'validation_status' => 'unverified',
            'validation_report' => [],
            'validated_at' => 0,
            'estimated_required_space' => 0,
            'available_space' => 0,
            'local_verified' => false,
            'local_deleted' => false,
            'remote_status' => $remote_queue ? 'queued' : 'disabled',
            'remote_queue' => $remote_queue,
            'remote_index' => 0,
            'remote_current' => '',
            'remote_state' => [],
            'remote_results' => [],
            'remote_errors' => [],
            'remote_path' => '',
            'remote_temp' => '',
            'remote_offset' => 0,
            'remote_total' => 0,
            'remote_artifact_kind' => 'backup',
            'remote_backup_path' => '',
            'remote_manifest_path' => '',
        ];

        update_option(self::LOCK_OPTION, [
            'job_id' => $job_id,
            'created_at' => time(),
        ], false);
        update_option(self::option_name($job_id), $job, false);

        $scheduled = self::schedule_job($job_id);
        if (is_wp_error($scheduled)) {
            delete_option(self::LOCK_OPTION);
            delete_option(self::option_name($job_id));
            return $scheduled;
        }

        self::dispatch_cron();

        return $job_id;
    }

    public static function process($job_id) {
        $job_id = sanitize_key($job_id);
        if ($job_id === '' || !self::acquire_process_lock($job_id)) {
            return;
        }

        try {
            $job = self::get_status_raw($job_id);
            if (!is_array($job) || in_array($job['status'], ['completed', 'completed_with_errors', 'failed', 'canceled'], true)) {
                return;
            }

            if (self::is_cancel_requested($job_id)) {
                self::cancel_job($job);
                return;
            }

            if (function_exists('set_time_limit')) {
                if (!empty($job['format']) && $job['format'] === 'tgz' && $job['step'] === 'files_archive') {
                    $time_limit = 300;
                } elseif (in_array($job['step'], ['verify', 'sftp_prepare', 'sftp_upload', 'sftp_finalize', 'remote_prepare', 'remote_upload', 'remote_finalize'], true)) {
                    $time_limit = 3600;
                } else {
                    $time_limit = 20;
                }
                @set_time_limit($time_limit);
            }

            $job['status'] = 'running';
            $job['updated_at'] = time();
            $job['processing_started_at'] = time();
            update_option(self::option_name($job_id), $job, false);

            try {
                $process_started = microtime(true);

                switch ($job['step']) {
                    case 'init':
                    case 'db_export':
                    case 'db_package':
                        self::process_database_pipeline($job, $process_started);
                        break;

                    case 'files_scan':
                        self::process_file_scan($job);
                        break;

                    case 'archive_init':
                        FgBackup_Backup::initialize_archive($job['format'], $job['archive_temp']);
                        $job['step'] = 'files_archive';
                        $job['progress'] = 62;
                        $job['stage'] = __('Archivierung', 'fg-backup-pro');
                        $job['detail'] = __('Archiv wurde vorbereitet.', 'fg-backup-pro');
                        break;

                    case 'files_archive':
                        self::process_archive($job);
                        break;

                    case 'verify':
                        self::verify_and_publish($job);
                        if ($job['step'] === 'cleanup' && (microtime(true) - $process_started) < 12) {
                            self::complete_job($job);
                        }
                        break;

                    case 'sftp_prepare':
                    case 'sftp_upload':
                    case 'sftp_finalize':
                    case 'remote_prepare':
                    case 'remote_upload':
                    case 'remote_finalize':
                        self::process_remote_step($job);
                        if ($job['step'] === 'cleanup' && (microtime(true) - $process_started) < 12) {
                            self::complete_job($job);
                        }
                        break;

                    case 'cleanup':
                        self::complete_job($job);
                        break;

                    default:
                        throw new RuntimeException(__('Unbekannter Backup-Schritt.', 'fg-backup-pro'));
                }

                if (self::is_cancel_requested($job_id, true)) {
                    self::cancel_job($job);
                    return;
                }

                $job['processing_started_at'] = 0;
                $job['updated_at'] = time();
                update_option(self::option_name($job_id), $job, false);

                if (!in_array($job['status'], ['completed', 'completed_with_errors', 'failed', 'canceled'], true)) {
                    $scheduled = self::schedule_job($job_id);
                    if (is_wp_error($scheduled)) {
                        throw new RuntimeException($scheduled->get_error_message());
                    }
                    self::dispatch_cron_on_shutdown();
                }
            } catch (Throwable $exception) {
                self::fail_job($job, $exception->getMessage());
            }
        } finally {
            self::release_process_lock($job_id);
        }
    }

    public static function kick($job_id) {
        $job_id = sanitize_key($job_id);
        $job = self::get_status_raw($job_id);

        if (!is_array($job) || in_array($job['status'], ['completed', 'completed_with_errors', 'failed', 'canceled'], true)) {
            return $job;
        }

        if ($job['status'] === 'cancel_requested' && !self::is_process_active($job_id)) {
            self::cancel_job($job);
            return self::get_status_raw($job_id);
        }

        if (!self::is_process_active($job_id)) {
            $scheduled = self::schedule_job($job_id);
            if (!is_wp_error($scheduled)) {
                self::dispatch_cron();
            }
        }

        return self::get_status_raw($job_id);
    }

    private static function process_database_pipeline(array &$job, $process_started) {
        if ($job['step'] === 'init') {
            self::initialize_job($job);
        }

        if ($job['step'] === 'db_export') {
            self::process_database($job);
        }

        if (self::is_cancel_requested($job['id'], true)) {
            return;
        }

        if ($job['step'] === 'db_package' && (microtime(true) - $process_started) < 10) {
            self::package_database($job);
        }

        if (self::is_cancel_requested($job['id'], true)) {
            return;
        }

        if ($job['type'] === 'db' && $job['step'] === 'verify' && (microtime(true) - $process_started) < 12) {
            self::verify_and_publish($job);
        }

        if ($job['type'] === 'db' && $job['step'] === 'cleanup' && (microtime(true) - $process_started) < 14) {
            self::complete_job($job);
        }
    }

    private static function initialize_job(array &$job) {
        $source_root = FgBackup_Backup::get_source_root();
        FgBackup_Remotes::assert_enabled_configuration();
        $space = FgBackup_Backup::estimate_initial_space($job['type'], $job['format']);
        FgBackup_Backup::assert_free_space(FgBackup_Storage::get_temp_root(), $space['required']);

        $job['estimated_required_space'] = (int) $space['required'];
        $job['available_space'] = (int) $space['available'];

        $temp_dir = FgBackup_Storage::create_job_temp_dir($job['id']);
        $pattern = get_option('fg_backup_filename_pattern', FgBackup_Backup::default_filename_pattern());
        $file_name = FgBackup_Backup::build_filename($pattern, $job['type'], $job['format'], $job['id'], $job['started_at']);

        if ($job['type'] === 'full' && $job['format'] === 'zip' && !FgBackup_Backup::supports_zip()) {
            throw new RuntimeException(__('ZIP ist auf diesem Server nicht verfügbar.', 'fg-backup-pro'));
        }
        if ($job['type'] === 'full' && $job['format'] === 'tgz' && !FgBackup_Backup::supports_tgz()) {
            throw new RuntimeException(__('TGZ ist auf diesem Server nicht verfügbar.', 'fg-backup-pro'));
        }
        if ($job['type'] === 'db' && $job['format'] === 'gz' && !FgBackup_Backup::supports_gzip()) {
            throw new RuntimeException(__('GZIP ist auf diesem Server nicht verfügbar.', 'fg-backup-pro'));
        }
        if ($job['type'] === 'db' && $job['format'] === 'zip' && !FgBackup_Backup::supports_zip()) {
            throw new RuntimeException(__('ZIP ist auf diesem Server nicht verfügbar.', 'fg-backup-pro'));
        }

        $job['temp_dir'] = $temp_dir;
        $job['source_root'] = $source_root;
        $job['db_file'] = $temp_dir . 'database.sql.part';
        $job['manifest_file'] = $temp_dir . 'files.jsonl';
        $job['final_path'] = FgBackup_Backup::unique_backup_path($file_name);
        $job['artifact_temp'] = $job['db_file'];
        if ($job['type'] === 'full') {
            $job['archive_temp'] = $job['format'] === 'tgz' ? $temp_dir . 'backup.tar' : $temp_dir . 'backup.zip.part';
            $job['archive_compressed'] = $job['format'] === 'tgz' ? $temp_dir . 'backup.tar.gz' : '';
        }
        $job['tables'] = FgBackup_Backup::get_tables();
        $job['table_index'] = 0;
        $job['row_offset'] = 0;
        $job['database_rows'] = 0;
        $job['table_started'] = false;
        $job['scan_queue'] = [[
            'path' => $source_root,
            'offset' => 0,
        ]];
        $job['file_count'] = 0;
        $job['file_bytes'] = 0;
        $job['manifest_offset'] = 0;
        $job['archived_files'] = 0;
        $job['progress'] = 2;
        $job['stage'] = __('Datenbankexport', 'fg-backup-pro');
        $job['detail'] = sprintf(
            __('Speicher geprüft: ungefähr %1$s benötigt, %2$s verfügbar.', 'fg-backup-pro'),
            size_format((int) $space['required'], 1),
            $space['available'] > 0 ? size_format((int) $space['available'], 1) : __('nicht ermittelbar', 'fg-backup-pro')
        );
        $job['step'] = 'db_export';

        FgBackup_Backup::write_database_header($job['db_file']);
    }

    private static function process_database(array &$job) {
        $started = microtime(true);
        $max_seconds = 8.0;
        $operations = 0;

        do {
            $tables = isset($job['tables']) && is_array($job['tables']) ? $job['tables'] : [];
            $table_count = count($tables);

            if ($job['table_index'] >= $table_count) {
                FgBackup_Backup::write_database_footer($job['db_file']);
                if ($job['type'] === 'db') {
                    $job['step'] = 'db_package';
                    $job['progress'] = 84;
                    $job['stage'] = __('Datenbank wird verpackt', 'fg-backup-pro');
                    $job['detail'] = FgBackup_Backup::format_label('db', $job['format']);
                } else {
                    $job['step'] = 'files_scan';
                    $job['progress'] = 45;
                    $job['stage'] = __('Dateien werden erfasst', 'fg-backup-pro');
                    $job['detail'] = __('Dateiliste wird erstellt.', 'fg-backup-pro');
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
            $job['database_rows'] = isset($job['database_rows']) ? (int) $job['database_rows'] + (int) $result['rows'] : (int) $result['rows'];
            $display_index = min($table_count, $job['table_index'] + 1);
            $job['stage'] = __('Datenbankexport', 'fg-backup-pro');
            $job['detail'] = sprintf(
                __('Tabelle %1$d von %2$d: %3$s (%4$d Zeilen)', 'fg-backup-pro'),
                $display_index,
                $table_count,
                $table,
                (int) $job['row_offset']
            );

            if (!empty($result['done'])) {
                $job['table_index']++;
                $job['row_offset'] = 0;
                $job['table_started'] = false;
            }

            $range = $job['type'] === 'db' ? 76 : 38;
            $base = 5;
            $job['progress'] = $table_count > 0
                ? min($base + $range, $base + (int) floor(($job['table_index'] / $table_count) * $range))
                : $base + $range;

            $operations++;

            if (($operations % 5) === 0 && self::is_cancel_requested($job['id'], true)) {
                return;
            }
        } while ($operations < 100 && (microtime(true) - $started) < $max_seconds);
    }

    private static function package_database(array &$job) {
        $destination = $job['db_file'];
        if ($job['format'] === 'gz') {
            $destination = $job['temp_dir'] . 'database.sql.gz.part';
        } elseif ($job['format'] === 'zip') {
            $destination = $job['temp_dir'] . 'database.sql.zip.part';
        }

        $last_cancel_check = 0.0;
        $job['artifact_temp'] = FgBackup_Backup::package_database(
            $job['db_file'],
            $job['format'],
            $destination,
            static function () use ($job, &$last_cancel_check) {
                if ((microtime(true) - $last_cancel_check) < 0.5) {
                    return false;
                }
                $last_cancel_check = microtime(true);
                return self::is_cancel_requested($job['id'], true);
            }
        );
        $job['step'] = 'verify';
        $job['progress'] = 92;
        $job['stage'] = __('Prüfung', 'fg-backup-pro');
        $job['detail'] = __('Datenbanksicherung wird geprüft.', 'fg-backup-pro');
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
        $job['file_bytes'] += isset($result['bytes_added']) ? (int) $result['bytes_added'] : 0;
        $job['progress'] = min(60, 45 + (int) floor(min($job['file_count'], 10000) / 667));
        $job['stage'] = __('Dateien werden erfasst', 'fg-backup-pro');
        $job['detail'] = sprintf(__('%d Dateien gefunden', 'fg-backup-pro'), (int) $job['file_count']);

        if (!empty($result['done'])) {
            $database_bytes = is_file($job['db_file']) ? (int) filesize($job['db_file']) : 0;
            $required = FgBackup_Backup::estimate_archive_space((int) $job['file_bytes'], $database_bytes, $job['format']);
            FgBackup_Backup::assert_free_space(FgBackup_Storage::get_temp_root(), $required);
            $job['estimated_required_space'] = $required;
            $available = @disk_free_space(FgBackup_Storage::get_temp_root());
            $job['available_space'] = $available === false ? 0 : (int) $available;
            $job['step'] = 'archive_init';
            $job['progress'] = 60;
            $job['stage'] = __('Archiv wird vorbereitet', 'fg-backup-pro');
            $job['detail'] = sprintf(
                __('%1$s · ungefähr %2$s temporärer Speicher erforderlich', 'fg-backup-pro'),
                FgBackup_Backup::format_label('full', $job['format']),
                size_format($required, 1)
            );
        }
    }

    private static function process_archive(array &$job) {
        $result = FgBackup_Backup::add_manifest_files_to_archive(
            $job['format'],
            $job['manifest_file'],
            (int) $job['manifest_offset'],
            $job['archive_temp'],
            $job['source_root'],
            FgBackup_Backup::FILES_PER_CHUNK,
            7
        );

        $job['manifest_offset'] = (int) $result['offset'];
        $job['archived_files'] += (int) $result['added'];

        $file_count = max(1, (int) $job['file_count']);
        $job['progress'] = min(92, 62 + (int) floor(($job['archived_files'] / $file_count) * 30));
        $job['stage'] = __('Archivierung', 'fg-backup-pro');
        $job['detail'] = sprintf(
            __('%1$d von %2$d Dateien', 'fg-backup-pro'),
            (int) $job['archived_files'],
            (int) $job['file_count']
        );

        if (!empty($result['done'])) {
            FgBackup_Backup::finalize_archive(
                $job['format'],
                $job['archive_temp'],
                $job['db_file'],
                FgBackup_Validator::embedded_manifest($job)
            );

            if ($job['format'] === 'tgz') {
                $job['progress'] = 93;
                $job['stage'] = __('Komprimierung', 'fg-backup-pro');
                $job['detail'] = __('TAR-Archiv wird mit GZIP komprimiert.', 'fg-backup-pro');
                $job['updated_at'] = time();
                update_option(self::option_name($job['id']), $job, false);

                $last_cancel_check = 0.0;
                $compressed = FgBackup_Backup::compress_tar(
                    $job['archive_temp'],
                    $job['archive_compressed'],
                    static function () use ($job, &$last_cancel_check) {
                        if ((microtime(true) - $last_cancel_check) < 0.5) {
                            return false;
                        }
                        $last_cancel_check = microtime(true);
                        return self::is_cancel_requested($job['id'], true);
                    },
                    static function ($processed, $total) use (&$job) {
                        $ratio = $total > 0 ? min(1, $processed / $total) : 0;
                        $job['progress'] = 93 + (int) floor($ratio * 3);
                        $job['detail'] = sprintf(
                            __('TAR wird komprimiert: %1$s von %2$s', 'fg-backup-pro'),
                            size_format((int) $processed, 1),
                            size_format((int) $total, 1)
                        );
                        $job['updated_at'] = time();
                        update_option(self::option_name($job['id']), $job, false);
                    }
                );

                if (!$compressed || self::is_cancel_requested($job['id'], true)) {
                    self::cancel_job($job);
                    return;
                }

                $job['artifact_temp'] = $job['archive_compressed'];
            } else {
                $job['artifact_temp'] = $job['archive_temp'];
            }

            $job['step'] = 'verify';
            $job['progress'] = 94;
            $job['stage'] = __('Prüfung', 'fg-backup-pro');
            $job['detail'] = __('Archiv wird geprüft.', 'fg-backup-pro');
        }
    }

    private static function verify_and_publish(array &$job) {
        $job['stage'] = __('Tiefenvalidierung', 'fg-backup-pro');
        $job['detail'] = __('Archiv, SQL-Daten und Metadaten werden vollständig geprüft.', 'fg-backup-pro');
        $job['updated_at'] = time();
        update_option(self::option_name($job['id']), $job, false);

        FgBackup_Backup::move_to_final($job['artifact_temp'], $job['final_path']);

        try {
            $manifest = FgBackup_Validator::validate_and_write(
                $job['final_path'],
                $job['type'],
                $job['format'],
                array_merge($job, ['require_completion_marker' => true])
            );
        } catch (Throwable $exception) {
            @unlink($job['final_path']);
            FgBackup_Validator::delete_manifest($job['final_path']);
            throw $exception;
        }

        $validation_status = !empty($manifest['validation']['status'])
            ? sanitize_key((string) $manifest['validation']['status'])
            : 'invalid';
        if ($validation_status === 'invalid') {
            @unlink($job['final_path']);
            FgBackup_Validator::delete_manifest($job['final_path']);
            throw new RuntimeException(__('Die Tiefenvalidierung des Backups ist fehlgeschlagen.', 'fg-backup-pro'));
        }

        $job['file'] = basename($job['final_path']);
        $job['size'] = (int) filesize($job['final_path']);
        $job['checksum'] = !empty($manifest['backup']['sha256']) ? (string) $manifest['backup']['sha256'] : '';
        $job['manifest_path'] = FgBackup_Validator::sidecar_path($job['final_path']);
        $job['manifest_file_name'] = basename($job['manifest_path']);
        $job['validation_status'] = $validation_status;
        $job['validation_report'] = isset($manifest['validation']) ? (array) $manifest['validation'] : [];
        $job['validated_at'] = !empty($manifest['validation']['validated_at']) ? (int) strtotime((string) $manifest['validation']['validated_at']) : time();
        $job['local_verified'] = true;

        $job['remote_queue'] = isset($job['remote_queue']) && is_array($job['remote_queue'])
            ? array_values($job['remote_queue'])
            : FgBackup_Remotes::enabled_ids();
        if ($job['remote_queue']) {
            $job['progress'] = 95;
            $job['stage'] = __('Remote-Uploads werden vorbereitet', 'fg-backup-pro');
            $job['detail'] = __('Validiertes Backup und JSON-Manifest werden an die Remote-Ziele übertragen.', 'fg-backup-pro');
            $job['remote_status'] = 'preparing';
            $job['remote_artifact_kind'] = 'backup';
            $job['step'] = 'remote_prepare';
        } else {
            $job['progress'] = 98;
            $job['stage'] = __('Abschluss', 'fg-backup-pro');
            $job['detail'] = __('Validiertes Backup wird abgeschlossen.', 'fg-backup-pro');
            $job['step'] = 'cleanup';
        }
    }

    private static function process_remote_step(array &$job) {
        // Alte 2.0-Jobs werden beim nächsten Schritt auf die generische Remote-Pipeline umgestellt.
        if (strpos((string) $job['step'], 'sftp_') === 0) {
            $job['remote_queue'] = ['sftp'];
            $job['remote_index'] = 0;
            $job['remote_current'] = 'sftp';
            $job['remote_state'] = [
                'remote_path' => isset($job['remote_path']) ? $job['remote_path'] : '',
                'remote_temp' => isset($job['remote_temp']) ? $job['remote_temp'] : '',
                'offset' => isset($job['remote_offset']) ? (int) $job['remote_offset'] : 0,
                'total' => isset($job['remote_total']) ? (int) $job['remote_total'] : 0,
            ];
            $job['step'] = str_replace('sftp_', 'remote_', $job['step']);
        }

        try {
            if ($job['step'] === 'remote_prepare') {
                self::prepare_remote_upload($job);
            } elseif ($job['step'] === 'remote_upload') {
                self::process_remote_upload($job);
            } elseif ($job['step'] === 'remote_finalize') {
                self::finalize_remote_upload($job);
            }
        } catch (Throwable $exception) {
            if (self::is_cancel_requested($job['id'], true)) {
                return;
            }
            self::record_remote_failure($job, $exception->getMessage());
            self::advance_remote($job);
        }
    }

    private static function prepare_remote_upload(array &$job) {
        $queue = isset($job['remote_queue']) && is_array($job['remote_queue']) ? array_values($job['remote_queue']) : [];
        $index = isset($job['remote_index']) ? (int) $job['remote_index'] : 0;
        if (!isset($queue[$index])) {
            self::finish_remote_sequence($job);
            return;
        }

        $target = sanitize_key($queue[$index]);
        $kind = !empty($job['remote_artifact_kind']) && $job['remote_artifact_kind'] === 'manifest' ? 'manifest' : 'backup';
        $local_path = $kind === 'manifest' ? (string) $job['manifest_path'] : (string) $job['final_path'];
        $file_name = $kind === 'manifest'
            ? (basename(str_replace('\\', '/', (string) $job['remote_backup_path'])) . '.json')
            : (string) $job['file'];
        if ($local_path === '' || !is_file($local_path)) {
            throw new RuntimeException($kind === 'manifest'
                ? __('Das lokale JSON-Manifest fehlt.', 'fg-backup-pro')
                : __('Die lokale Backup-Datei fehlt.', 'fg-backup-pro'));
        }

        $job['remote_current'] = $target;
        $job['remote_state'] = FgBackup_Remotes::prepare($target, $local_path, $file_name);
        $job['remote_state']['artifact_kind'] = $kind;
        self::sync_legacy_remote_fields($job);
        $job['remote_status'] = 'uploading';
        if (!isset($job['remote_results'][$target]) || !is_array($job['remote_results'][$target])) {
            $job['remote_results'][$target] = [
                'label' => FgBackup_Remotes::label($target),
                'status' => 'uploading',
                'path' => '',
                'manifest_path' => '',
                'error' => '',
            ];
        }
        $job['step'] = 'remote_upload';
        $job['progress'] = self::remote_progress($job, 0);
        $job['stage'] = sprintf(
            $kind === 'manifest' ? __('%s-JSON-Upload', 'fg-backup-pro') : __('%s-Upload', 'fg-backup-pro'),
            FgBackup_Remotes::label($target)
        );
        $job['detail'] = !empty($job['remote_state']['remote_path'])
            ? sprintf(__('Ziel: %s', 'fg-backup-pro'), $job['remote_state']['remote_path'])
            : __('Upload wird vorbereitet.', 'fg-backup-pro');
    }

    private static function process_remote_upload(array &$job) {
        $target = isset($job['remote_current']) ? sanitize_key($job['remote_current']) : '';
        if ($target === '') {
            throw new RuntimeException(__('Das aktuelle Remote-Ziel fehlt.', 'fg-backup-pro'));
        }

        $state = isset($job['remote_state']) && is_array($job['remote_state']) ? $job['remote_state'] : [];
        $kind = !empty($job['remote_artifact_kind']) && $job['remote_artifact_kind'] === 'manifest' ? 'manifest' : 'backup';
        $local_path = $kind === 'manifest' ? (string) $job['manifest_path'] : (string) $job['final_path'];
        $job['remote_state'] = FgBackup_Remotes::upload(
            $target,
            $local_path,
            $state,
            static function () use ($job) {
                return self::is_cancel_requested($job['id'], true);
            },
            static function ($uploaded, $total) use (&$job, $target, $kind) {
                $ratio = $total > 0 ? min(1, max(0, $uploaded / $total)) : 0;
                $job['progress'] = self::remote_progress($job, $ratio);
                $job['stage'] = sprintf(
                    $kind === 'manifest' ? __('%s-JSON-Upload', 'fg-backup-pro') : __('%s-Upload', 'fg-backup-pro'),
                    FgBackup_Remotes::label($target)
                );
                $job['detail'] = sprintf(
                    __('%1$s von %2$s hochgeladen', 'fg-backup-pro'),
                    size_format((int) $uploaded, 1),
                    size_format((int) $total, 1)
                );
                $job['updated_at'] = time();
                update_option(self::option_name($job['id']), $job, false);
            }
        );
        self::sync_legacy_remote_fields($job);

        $offset = isset($job['remote_state']['offset']) ? (int) $job['remote_state']['offset'] : 0;
        $total = isset($job['remote_state']['total']) ? max(1, (int) $job['remote_state']['total']) : 1;
        $ratio = min(1, $offset / $total);
        $job['progress'] = self::remote_progress($job, $ratio);
        $job['stage'] = sprintf(
            $kind === 'manifest' ? __('%s-JSON-Upload', 'fg-backup-pro') : __('%s-Upload', 'fg-backup-pro'),
            FgBackup_Remotes::label($target)
        );
        $job['detail'] = sprintf(
            __('%1$s von %2$s hochgeladen', 'fg-backup-pro'),
            size_format($offset, 1),
            size_format($total, 1)
        );

        if (!empty($job['remote_state']['done']) || $offset >= $total) {
            $job['step'] = 'remote_finalize';
            $job['stage'] = sprintf(__('%s wird geprüft', 'fg-backup-pro'), FgBackup_Remotes::label($target));
            $job['detail'] = __('Remote-Datei wird geprüft und finalisiert.', 'fg-backup-pro');
        }
    }

    private static function finalize_remote_upload(array &$job) {
        $target = isset($job['remote_current']) ? sanitize_key($job['remote_current']) : '';
        if ($target === '') {
            throw new RuntimeException(__('Das aktuelle Remote-Ziel fehlt.', 'fg-backup-pro'));
        }
        $kind = !empty($job['remote_artifact_kind']) && $job['remote_artifact_kind'] === 'manifest' ? 'manifest' : 'backup';
        $path = FgBackup_Remotes::finalize($target, isset($job['remote_state']) ? (array) $job['remote_state'] : []);

        if ($kind === 'backup') {
            $job['remote_backup_path'] = (string) $path;
            $job['remote_results'][$target] = [
                'label' => FgBackup_Remotes::label($target),
                'status' => 'uploading',
                'path' => (string) $path,
                'manifest_path' => '',
                'error' => '',
            ];
            $job['remote_artifact_kind'] = 'manifest';
            $job['remote_state'] = [];
            $job['remote_temp'] = '';
            $job['remote_offset'] = 0;
            $job['remote_total'] = 0;
            $job['step'] = 'remote_prepare';
            $job['stage'] = sprintf(__('%s-Manifest wird vorbereitet', 'fg-backup-pro'), FgBackup_Remotes::label($target));
            $job['detail'] = __('Backup hochgeladen. Das JSON-Manifest folgt.', 'fg-backup-pro');
            return;
        }

        $job['remote_manifest_path'] = (string) $path;
        $job['remote_results'][$target] = [
            'label' => FgBackup_Remotes::label($target),
            'status' => 'completed',
            'path' => (string) $job['remote_backup_path'],
            'manifest_path' => (string) $path,
            'error' => '',
        ];
        $job['remote_path'] = (string) $job['remote_backup_path'];
        $job['remote_status'] = 'completed';
        FgBackup_Remotes::rotate($target);
        $job['stage'] = sprintf(__('%s abgeschlossen', 'fg-backup-pro'), FgBackup_Remotes::label($target));
        $job['detail'] = (string) $job['remote_backup_path'];
        self::advance_remote($job);
    }

    private static function record_remote_failure(array &$job, $message) {
        $target = isset($job['remote_current']) ? sanitize_key($job['remote_current']) : '';
        if ($target === '') {
            $queue = isset($job['remote_queue']) ? (array) $job['remote_queue'] : [];
            $index = isset($job['remote_index']) ? (int) $job['remote_index'] : 0;
            $target = isset($queue[$index]) ? sanitize_key($queue[$index]) : 'remote';
        }
        FgBackup_Remotes::remove_partial($target, isset($job['remote_state']) ? (array) $job['remote_state'] : []);
        $clean_message = sanitize_text_field((string) $message);
        $job['remote_errors'][$target] = $clean_message;
        $job['remote_results'][$target] = [
            'label' => FgBackup_Remotes::label($target),
            'status' => 'failed',
            'path' => !empty($job['remote_state']['remote_path']) ? $job['remote_state']['remote_path'] : '',
            'error' => $clean_message,
        ];
        $job['remote_status'] = 'failed';
    }

    private static function advance_remote(array &$job) {
        $job['remote_index'] = isset($job['remote_index']) ? (int) $job['remote_index'] + 1 : 1;
        $job['remote_current'] = '';
        $job['remote_state'] = [];
        $job['remote_temp'] = '';
        $job['remote_offset'] = 0;
        $job['remote_total'] = 0;
        $job['remote_artifact_kind'] = 'backup';
        $job['remote_backup_path'] = '';
        $job['remote_manifest_path'] = '';
        $queue = isset($job['remote_queue']) && is_array($job['remote_queue']) ? array_values($job['remote_queue']) : [];
        if (isset($queue[$job['remote_index']])) {
            $next = sanitize_key($queue[$job['remote_index']]);
            $job['step'] = 'remote_prepare';
            $job['remote_status'] = 'preparing';
            $job['progress'] = self::remote_progress($job, 0);
            $job['stage'] = sprintf(__('%s wird vorbereitet', 'fg-backup-pro'), FgBackup_Remotes::label($next));
            $job['detail'] = __('Nächstes Remote-Ziel wird verbunden.', 'fg-backup-pro');
            return;
        }
        self::finish_remote_sequence($job);
    }

    private static function finish_remote_sequence(array &$job) {
        $queue = isset($job['remote_queue']) && is_array($job['remote_queue']) ? $job['remote_queue'] : [];
        $errors = isset($job['remote_errors']) && is_array($job['remote_errors']) ? $job['remote_errors'] : [];
        $delete_local = !empty($queue)
            && empty($errors)
            && !FgBackup_Remotes::keep_local();

        if ($delete_local && !empty($job['final_path']) && is_file($job['final_path'])) {
            if (!@unlink($job['final_path'])) {
                $job['remote_errors']['local'] = __('Alle Remote-Uploads waren erfolgreich, die lokale Backup-Datei konnte aber nicht gelöscht werden.', 'fg-backup-pro');
            } else {
                if (!empty($job['manifest_path']) && is_file($job['manifest_path'])) {
                    @unlink($job['manifest_path']);
                }
                $job['local_deleted'] = true;
                $job['file'] = '';
            }
        }
        $job['progress'] = 99;
        $job['step'] = 'cleanup';
        $job['stage'] = empty($job['remote_errors'])
            ? __('Remote-Uploads abgeschlossen', 'fg-backup-pro')
            : __('Remote-Uploads mit Fehlern', 'fg-backup-pro');
        $job['detail'] = FgBackup_Remotes::summarize(isset($job['remote_results']) ? (array) $job['remote_results'] : []);
    }

    private static function remote_progress(array $job, $target_ratio) {
        $queue = isset($job['remote_queue']) && is_array($job['remote_queue']) ? array_values($job['remote_queue']) : [];
        $count = max(1, count($queue));
        $index = max(0, min($count - 1, isset($job['remote_index']) ? (int) $job['remote_index'] : 0));
        $overall = ($index + min(1, max(0, (float) $target_ratio))) / $count;
        return min(99, 95 + (int) floor($overall * 4));
    }

    private static function sync_legacy_remote_fields(array &$job) {
        $state = isset($job['remote_state']) && is_array($job['remote_state']) ? $job['remote_state'] : [];
        $job['remote_path'] = isset($state['remote_path']) ? (string) $state['remote_path'] : '';
        $job['remote_temp'] = isset($state['remote_temp']) ? (string) $state['remote_temp'] : '';
        $job['remote_offset'] = isset($state['offset']) ? (int) $state['offset'] : 0;
        $job['remote_total'] = isset($state['total']) ? (int) $state['total'] : 0;
    }

    private static function complete_job(array &$job) {
        if (!empty($job['temp_dir'])) {
            FgBackup_Storage::remove_tree($job['temp_dir']);
        }

        FgBackup_Cleanup::rotate_backups();
        $has_remote_errors = !empty($job['remote_errors']);
        $job['status'] = $has_remote_errors ? 'completed_with_errors' : 'completed';
        $job['progress'] = 100;
        $job['stage'] = $has_remote_errors
            ? __('Abgeschlossen mit Fehlern', 'fg-backup-pro')
            : __('Abgeschlossen', 'fg-backup-pro');
        $remote_summary = FgBackup_Remotes::summarize(isset($job['remote_results']) ? (array) $job['remote_results'] : []);
        if ($remote_summary !== '') {
            $job['detail'] = $remote_summary;
            if (!empty($job['local_deleted'])) {
                $job['detail'] .= ' · ' . __('lokal gelöscht', 'fg-backup-pro');
            } elseif (!empty($job['file'])) {
                $job['detail'] .= ' · ' . sprintf(__('lokal: %s', 'fg-backup-pro'), $job['file']);
            }
        } else {
            $job['detail'] = isset($job['file']) ? $job['file'] : '';
        }
        $job['finished_at'] = time();
        update_option(self::option_name($job['id']), $job, false);
        self::add_history($job);
        self::release_lock($job['id']);
        delete_option(self::cancel_option_name($job['id']));

        self::refresh_health_safely();
        if ($has_remote_errors) {
            self::notify_safely('warning', $job);
        } else {
            self::notify_safely('success', $job);
        }
    }

    private static function fail_job(array $job, $message) {
        $job['status'] = 'failed';
        $job['stage'] = __('Fehlgeschlagen', 'fg-backup-pro');
        $job['error'] = sanitize_text_field($message);
        $job['detail'] = $job['error'];
        $job['finished_at'] = time();
        $job['updated_at'] = time();

        if (!empty($job['remote_current'])) {
            FgBackup_Remotes::remove_partial($job['remote_current'], isset($job['remote_state']) ? (array) $job['remote_state'] : []);
        } elseif (!empty($job['remote_temp'])) {
            FgBackup_Sftp::remove_partial($job['remote_temp']);
        }
        if (!empty($job['local_verified']) && isset($job['remote_status']) && $job['remote_status'] !== 'completed') {
            $job['remote_status'] = 'failed';
        }

        if (!empty($job['temp_dir'])) {
            FgBackup_Storage::remove_tree($job['temp_dir']);
        }

        update_option(self::option_name($job['id']), $job, false);
        self::add_history($job);
        self::release_lock($job['id']);
        delete_option(self::cancel_option_name($job['id']));

        self::refresh_health_safely();
        self::notify_safely('failure', $job);
    }

    public static function request_cancel($job_id) {
        $job_id = sanitize_key($job_id);
        $job = self::get_status_raw($job_id);

        if (!is_array($job)) {
            return new WP_Error('fg_backup_not_found', __('Backup-Job nicht gefunden.', 'fg-backup-pro'));
        }

        if (in_array($job['status'], ['completed', 'completed_with_errors', 'failed', 'canceled'], true)) {
            return new WP_Error('fg_backup_finished', __('Das Backup ist bereits beendet.', 'fg-backup-pro'));
        }

        update_option(self::cancel_option_name($job_id), time(), false);
        $job['status'] = 'cancel_requested';
        $job['stage'] = __('Abbruch angefordert', 'fg-backup-pro');
        $job['detail'] = !empty($job['local_verified'])
            ? __('Der laufende Remote-Schritt wird beendet. Das fertige lokale Backup bleibt erhalten.', 'fg-backup-pro')
            : __('Der aktuelle Arbeitsschritt wird beendet und temporäre Daten werden entfernt.', 'fg-backup-pro');
        $job['updated_at'] = time();
        update_option(self::option_name($job_id), $job, false);

        wp_clear_scheduled_hook(self::CRON_HOOK, [$job_id]);

        if (!self::is_process_active($job_id)) {
            self::cancel_job($job);
            return self::get_status_raw($job_id);
        }

        $scheduled = self::schedule_job($job_id);
        if (!is_wp_error($scheduled)) {
            self::dispatch_cron();
        }

        return $job;
    }

    public static function is_cancel_requested($job_id, $fresh = false) {
        $option = self::cancel_option_name($job_id);
        if ($fresh) {
            wp_cache_delete($option, 'options');
        }
        return (bool) get_option($option, false);
    }

    private static function cancel_job(array $job) {
        $job_id = isset($job['id']) ? sanitize_key($job['id']) : '';
        if ($job_id === '') {
            return;
        }

        wp_clear_scheduled_hook(self::CRON_HOOK, [$job_id]);

        if (!empty($job['remote_current'])) {
            FgBackup_Remotes::remove_partial($job['remote_current'], isset($job['remote_state']) ? (array) $job['remote_state'] : []);
            $job['remote_status'] = 'canceled';
            $job['remote_results'][$job['remote_current']] = [
                'label' => FgBackup_Remotes::label($job['remote_current']),
                'status' => 'canceled',
                'path' => !empty($job['remote_state']['remote_path']) ? $job['remote_state']['remote_path'] : '',
                'error' => '',
            ];
        } elseif (!empty($job['remote_temp'])) {
            FgBackup_Sftp::remove_partial($job['remote_temp']);
            $job['remote_status'] = 'canceled';
        }

        if (!empty($job['temp_dir'])) {
            FgBackup_Storage::remove_tree($job['temp_dir']);
        }

        if (empty($job['local_verified']) && !empty($job['final_path']) && is_file($job['final_path'])) {
            @unlink($job['final_path']);
            FgBackup_Validator::delete_manifest($job['final_path']);
        }

        $job['status'] = 'canceled';
        $job['stage'] = __('Abgebrochen', 'fg-backup-pro');
        $job['detail'] = !empty($job['local_verified'])
            ? __('Remote-Upload abgebrochen. Das fertige lokale Backup bleibt erhalten.', 'fg-backup-pro')
            : __('Temporäre Backup-Daten wurden entfernt.', 'fg-backup-pro');
        $job['error'] = '';
        if (empty($job['local_verified'])) {
            $job['file'] = '';
            $job['size'] = 0;
        }
        $job['finished_at'] = time();
        $job['updated_at'] = time();

        update_option(self::option_name($job_id), $job, false);
        self::add_history($job);
        self::release_lock($job_id);
        delete_option(self::cancel_option_name($job_id));
        self::refresh_health_safely();
    }

    private static function refresh_health_safely() {
        try {
            FgBackup_Health::refresh_after_job();
        } catch (Throwable $exception) {
            error_log('[FG Backup Pro] Gesundheitsprüfung nach Backup fehlgeschlagen: ' . $exception->getMessage());
        }
    }

    private static function notify_safely($method, array $job) {
        try {
            if (is_callable(['FgBackup_Notifications', $method])) {
                call_user_func(['FgBackup_Notifications', $method], $job);
            }
        } catch (Throwable $exception) {
            error_log('[FG Backup Pro] Backup-Benachrichtigung fehlgeschlagen: ' . $exception->getMessage());
        }
    }

    private static function add_history(array $job) {
        $history = get_option('fg_backup_history', []);
        if (!is_array($history)) {
            $history = [];
        }

        $deduplicated = [];
        foreach ($history as $entry) {
            if (!is_array($entry) || empty($entry['id']) || $entry['id'] !== $job['id']) {
                $deduplicated[] = $entry;
            }
        }
        $history = $deduplicated;

        array_unshift($history, [
            'id' => $job['id'],
            'status' => $job['status'],
            'type' => $job['type'],
            'format' => isset($job['format']) ? $job['format'] : '',
            'origin' => $job['origin'],
            'started_at' => (int) $job['started_at'],
            'finished_at' => (int) $job['finished_at'],
            'file' => isset($job['file']) ? $job['file'] : '',
            'size' => isset($job['size']) ? (int) $job['size'] : 0,
            'checksum' => isset($job['checksum']) ? $job['checksum'] : '',
            'manifest_file' => isset($job['manifest_file_name']) ? $job['manifest_file_name'] : '',
            'validation_status' => isset($job['validation_status']) ? $job['validation_status'] : 'unverified',
            'validated_at' => isset($job['validated_at']) ? (int) $job['validated_at'] : 0,
            'error' => isset($job['error']) ? $job['error'] : '',
            'note' => isset($job['note']) ? $job['note'] : '',
            'remote_status' => isset($job['remote_status']) ? $job['remote_status'] : 'disabled',
            'remote_path' => isset($job['remote_path']) ? $job['remote_path'] : '',
            'remote_results' => isset($job['remote_results']) ? (array) $job['remote_results'] : [],
            'remote_errors' => isset($job['remote_errors']) ? (array) $job['remote_errors'] : [],
            'local_deleted' => !empty($job['local_deleted']),
        ]);

        update_option('fg_backup_history', array_slice($history, 0, 20), false);
    }

    public static function get_status($job_id) {
        return self::kick($job_id);
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
        $job = self::get_status_raw($active['job_id']);
        $finished = is_array($job) && in_array($job['status'], ['completed', 'completed_with_errors', 'failed', 'canceled'], true);

        if (is_array($job) && $job['status'] === 'cancel_requested' && !self::is_process_active($active['job_id'])) {
            self::cancel_job($job);
            return;
        }

        if ($finished || !is_array($job) || !$created) {
            delete_option(self::LOCK_OPTION);
            return;
        }

        if ($created < time() - (12 * HOUR_IN_SECONDS) && !self::is_process_active($active['job_id'])) {
            self::fail_job($job, __('Der Backup-Job wurde wegen fehlender Aktivität beendet.', 'fg-backup-pro'));
        }
    }

    private static function release_lock($job_id) {
        $active = get_option(self::LOCK_OPTION, []);
        if (!empty($active['job_id']) && $active['job_id'] === $job_id) {
            delete_option(self::LOCK_OPTION);
        }
    }

    private static function cancel_option_name($job_id) {
        return 'fg_backup_cancel_' . sanitize_key($job_id);
    }

    private static function process_lock_option_name($job_id) {
        return 'fg_backup_process_' . sanitize_key($job_id);
    }

    private static function option_name($job_id) {
        return 'fg_backup_job_' . sanitize_key($job_id);
    }

    private static function get_status_raw($job_id) {
        return get_option(self::option_name(sanitize_key($job_id)), false);
    }

    private static function schedule_job($job_id) {
        $job_id = sanitize_key($job_id);
        if ($job_id === '') {
            return new WP_Error('fg_backup_schedule_failed', __('Der Backup-Job konnte nicht geplant werden.', 'fg-backup-pro'));
        }

        if (wp_next_scheduled(self::CRON_HOOK, [$job_id])) {
            return true;
        }

        $scheduled = wp_schedule_single_event(time(), self::CRON_HOOK, [$job_id], true);
        if (is_wp_error($scheduled)) {
            return new WP_Error('fg_backup_schedule_failed', $scheduled->get_error_message());
        }
        if ($scheduled === false) {
            return new WP_Error('fg_backup_schedule_failed', __('Der Backup-Job konnte nicht geplant werden.', 'fg-backup-pro'));
        }

        return true;
    }

    public static function dispatch_cron() {
        $cron_url = site_url('wp-cron.php?doing_wp_cron=' . rawurlencode(sprintf('%.22F', microtime(true))));
        wp_remote_post($cron_url, [
            'timeout' => 0.01,
            'blocking' => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        ]);
    }

    private static function dispatch_cron_on_shutdown() {
        if (self::$shutdown_dispatch_registered) {
            return;
        }

        self::$shutdown_dispatch_registered = true;
        register_shutdown_function([__CLASS__, 'dispatch_cron']);
    }

    private static function acquire_process_lock($job_id) {
        $job_id = sanitize_key($job_id);
        $option = self::process_lock_option_name($job_id);
        $token = wp_generate_uuid4();
        $value = [
            'token' => $token,
            'started_at' => time(),
        ];

        if (!add_option($option, $value, '', false)) {
            $existing = get_option($option, []);
            $started = is_array($existing) && isset($existing['started_at']) ? (int) $existing['started_at'] : 0;

            if ($started && $started >= time() - self::PROCESS_LOCK_TTL) {
                return false;
            }

            delete_option($option);
            if (!add_option($option, $value, '', false)) {
                return false;
            }
        }

        self::$process_tokens[$job_id] = $token;
        return true;
    }

    private static function is_process_active($job_id) {
        $job_id = sanitize_key($job_id);
        $option = self::process_lock_option_name($job_id);
        $lock = get_option($option, false);

        if (!is_array($lock)) {
            return false;
        }

        $started = isset($lock['started_at']) ? (int) $lock['started_at'] : 0;
        if (!$started || $started < time() - self::PROCESS_LOCK_TTL) {
            delete_option($option);
            return false;
        }

        return true;
    }

    private static function release_process_lock($job_id) {
        $job_id = sanitize_key($job_id);
        if ($job_id === '' || empty(self::$process_tokens[$job_id])) {
            return;
        }

        $option = self::process_lock_option_name($job_id);
        $lock = get_option($option, []);
        if (is_array($lock) && !empty($lock['token']) && hash_equals((string) $lock['token'], (string) self::$process_tokens[$job_id])) {
            delete_option($option);
        }

        unset(self::$process_tokens[$job_id]);
    }
}
