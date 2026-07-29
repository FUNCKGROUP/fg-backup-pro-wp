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
            'estimated_required_space' => 0,
            'available_space' => 0,
            'local_verified' => false,
            'local_deleted' => false,
            'remote_status' => FgBackup_Sftp::enabled() ? 'queued' : 'disabled',
            'remote_path' => '',
            'remote_temp' => '',
            'remote_offset' => 0,
            'remote_total' => 0,
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
            if (!is_array($job) || in_array($job['status'], ['completed', 'failed', 'canceled'], true)) {
                return;
            }

            if (self::is_cancel_requested($job_id)) {
                self::cancel_job($job);
                return;
            }

            if (function_exists('set_time_limit')) {
                if (!empty($job['format']) && $job['format'] === 'tgz' && $job['step'] === 'files_archive') {
                    $time_limit = 300;
                } elseif (in_array($job['step'], ['sftp_prepare', 'sftp_upload', 'sftp_finalize'], true)) {
                    $time_limit = 60;
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
                        self::prepare_sftp_upload($job);
                        break;

                    case 'sftp_upload':
                        self::process_sftp_upload($job);
                        break;

                    case 'sftp_finalize':
                        self::finalize_sftp_upload($job);
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

                if (!in_array($job['status'], ['completed', 'failed', 'canceled'], true)) {
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

        if (!is_array($job) || in_array($job['status'], ['completed', 'failed', 'canceled'], true)) {
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
        if (FgBackup_Sftp::enabled()) {
            $sftp_settings = FgBackup_Sftp::settings();
            if (!FgBackup_Sftp::available()) {
                throw new RuntimeException(__('SFTP ist aktiviert, aber phpseclib fehlt.', 'fg-backup-pro'));
            }
            if ($sftp_settings['host'] === '' || $sftp_settings['username'] === '' || $sftp_settings['host_key'] === '') {
                throw new RuntimeException(__('SFTP ist nicht vollständig eingerichtet. Bitte Verbindung speichern und testen.', 'fg-backup-pro'));
            }
        }
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
            FgBackup_Backup::finalize_archive($job['format'], $job['archive_temp'], $job['db_file'], [
                'plugin' => 'FG Backup Pro',
                'plugin_version' => FG_BACKUP_VERSION,
                'created_at' => gmdate('c'),
                'home_url' => home_url('/'),
                'site_url' => site_url('/'),
                'wordpress_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
                'backup_type' => $job['type'],
                'archive_format' => $job['format'],
                'file_count' => (int) $job['archived_files'],
                'note' => isset($job['note']) ? (string) $job['note'] : '',
            ]);

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
        $job['stage'] = __('Prüfung', 'fg-backup-pro');
        $job['detail'] = __('Inhalt und Integrität werden geprüft.', 'fg-backup-pro');

        if ($job['type'] === 'full') {
            FgBackup_Backup::verify_full_archive($job['artifact_temp'], $job['format']);
        } else {
            FgBackup_Backup::verify_database_artifact($job['artifact_temp'], $job['format']);
        }

        FgBackup_Backup::move_to_final($job['artifact_temp'], $job['final_path']);
        $job['file'] = basename($job['final_path']);
        $job['size'] = (int) filesize($job['final_path']);
        $job['checksum'] = '';
        $job['local_verified'] = true;

        if (FgBackup_Sftp::enabled()) {
            $job['progress'] = 95;
            $job['stage'] = __('SFTP wird vorbereitet', 'fg-backup-pro');
            $job['detail'] = __('Lokales Backup abgeschlossen. Remote-Ziel wird verbunden.', 'fg-backup-pro');
            $job['remote_status'] = 'preparing';
            $job['step'] = 'sftp_prepare';
        } else {
            $job['progress'] = 98;
            $job['stage'] = __('Abschluss', 'fg-backup-pro');
            $job['detail'] = __('Backup wird abgeschlossen.', 'fg-backup-pro');
            $job['step'] = 'cleanup';
        }
    }

    private static function prepare_sftp_upload(array &$job) {
        $upload = FgBackup_Sftp::prepare_upload($job['final_path'], $job['file']);
        $job = array_merge($job, $upload);
        $job['remote_status'] = 'uploading';
        $job['step'] = 'sftp_upload';
        $job['progress'] = 95;
        $job['stage'] = __('SFTP-Upload', 'fg-backup-pro');
        $job['detail'] = sprintf(__('Ziel: %s', 'fg-backup-pro'), $job['remote_path']);
    }

    private static function process_sftp_upload(array &$job) {
        $total = max(1, (int) $job['remote_total']);
        $offset = max(0, (int) $job['remote_offset']);
        if ($offset >= $total) {
            $job['step'] = 'sftp_finalize';
            return;
        }

        $written = FgBackup_Sftp::upload_batch(
            $job['final_path'],
            $job['remote_temp'],
            $offset,
            4,
            static function () use ($job) {
                return self::is_cancel_requested($job['id'], true);
            }
        );
        $written = max(0, (int) $written);
        if ($written <= 0 && $offset < $total) {
            throw new RuntimeException(__('Der SFTP-Upload hat keinen Fortschritt gemacht.', 'fg-backup-pro'));
        }

        $job['remote_offset'] = min($total, $offset + $written);
        $ratio = min(1, $job['remote_offset'] / $total);
        $job['progress'] = 95 + (int) floor($ratio * 4);
        $job['stage'] = __('SFTP-Upload', 'fg-backup-pro');
        $job['detail'] = sprintf(
            __('%1$s von %2$s hochgeladen', 'fg-backup-pro'),
            size_format((int) $job['remote_offset'], 1),
            size_format($total, 1)
        );
        if ($job['remote_offset'] >= $total) {
            $job['step'] = 'sftp_finalize';
            $job['stage'] = __('SFTP wird geprüft', 'fg-backup-pro');
            $job['detail'] = __('Remote-Datei wird geprüft und finalisiert.', 'fg-backup-pro');
        }
    }

    private static function finalize_sftp_upload(array &$job) {
        FgBackup_Sftp::finalize_upload($job['remote_temp'], $job['remote_path'], $job['remote_total']);
        $job['remote_status'] = 'completed';
        $job['remote_temp'] = '';
        $job['progress'] = 99;
        $job['stage'] = __('SFTP abgeschlossen', 'fg-backup-pro');
        $job['detail'] = $job['remote_path'];

        if (!FgBackup_Sftp::settings()['keep_local'] && !empty($job['final_path']) && is_file($job['final_path'])) {
            if (!@unlink($job['final_path'])) {
                throw new RuntimeException(__('Der SFTP-Upload war erfolgreich, die lokale Backup-Datei konnte aber nicht gelöscht werden.', 'fg-backup-pro'));
            }
            $job['local_deleted'] = true;
            $job['file'] = '';
        }
        $job['step'] = 'cleanup';
    }

    private static function complete_job(array &$job) {
        if (!empty($job['temp_dir'])) {
            FgBackup_Storage::remove_tree($job['temp_dir']);
        }

        FgBackup_Cleanup::rotate_backups();
        $job['status'] = 'completed';
        $job['progress'] = 100;
        $job['stage'] = __('Abgeschlossen', 'fg-backup-pro');
        if (!empty($job['remote_path'])) {
            $job['detail'] = !empty($job['local_deleted'])
                ? sprintf(__('SFTP: %s · lokal gelöscht', 'fg-backup-pro'), $job['remote_path'])
                : sprintf(__('SFTP: %s', 'fg-backup-pro'), $job['remote_path']);
        } else {
            $job['detail'] = isset($job['file']) ? $job['file'] : '';
        }
        $job['finished_at'] = time();
        self::add_history($job);
        self::release_lock($job['id']);
        delete_option(self::cancel_option_name($job['id']));
        FgBackup_Notifications::success($job);
    }

    private static function fail_job(array $job, $message) {
        $job['status'] = 'failed';
        $job['stage'] = __('Fehlgeschlagen', 'fg-backup-pro');
        $job['error'] = sanitize_text_field($message);
        $job['detail'] = $job['error'];
        $job['finished_at'] = time();
        $job['updated_at'] = time();

        if (!empty($job['remote_temp'])) {
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
        FgBackup_Notifications::failure($job);
    }

    public static function request_cancel($job_id) {
        $job_id = sanitize_key($job_id);
        $job = self::get_status_raw($job_id);

        if (!is_array($job)) {
            return new WP_Error('fg_backup_not_found', __('Backup-Job nicht gefunden.', 'fg-backup-pro'));
        }

        if (in_array($job['status'], ['completed', 'failed', 'canceled'], true)) {
            return new WP_Error('fg_backup_finished', __('Das Backup ist bereits beendet.', 'fg-backup-pro'));
        }

        update_option(self::cancel_option_name($job_id), time(), false);
        $job['status'] = 'cancel_requested';
        $job['stage'] = __('Abbruch angefordert', 'fg-backup-pro');
        $job['detail'] = !empty($job['local_verified'])
            ? __('Der laufende SFTP-Schritt wird beendet. Das fertige lokale Backup bleibt erhalten.', 'fg-backup-pro')
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

        if (!empty($job['remote_temp'])) {
            FgBackup_Sftp::remove_partial($job['remote_temp']);
            $job['remote_status'] = 'canceled';
        }

        if (!empty($job['temp_dir'])) {
            FgBackup_Storage::remove_tree($job['temp_dir']);
        }

        if (empty($job['local_verified']) && !empty($job['final_path']) && is_file($job['final_path'])) {
            @unlink($job['final_path']);
        }

        $job['status'] = 'canceled';
        $job['stage'] = __('Abgebrochen', 'fg-backup-pro');
        $job['detail'] = !empty($job['local_verified'])
            ? __('SFTP-Upload abgebrochen. Das fertige lokale Backup bleibt erhalten.', 'fg-backup-pro')
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
            'format' => isset($job['format']) ? $job['format'] : '',
            'origin' => $job['origin'],
            'started_at' => (int) $job['started_at'],
            'finished_at' => (int) $job['finished_at'],
            'file' => isset($job['file']) ? $job['file'] : '',
            'size' => isset($job['size']) ? (int) $job['size'] : 0,
            'checksum' => isset($job['checksum']) ? $job['checksum'] : '',
            'error' => isset($job['error']) ? $job['error'] : '',
            'note' => isset($job['note']) ? $job['note'] : '',
            'remote_status' => isset($job['remote_status']) ? $job['remote_status'] : 'disabled',
            'remote_path' => isset($job['remote_path']) ? $job['remote_path'] : '',
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
        $finished = is_array($job) && in_array($job['status'], ['completed', 'failed', 'canceled'], true);

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
