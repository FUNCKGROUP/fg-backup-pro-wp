<?php

defined('ABSPATH') || exit;

class FgBackup_Validator {

    const SCHEMA_VERSION = 1;
    const SQL_COMPLETION_MARKER = '-- FG Backup Pro export completed';
    const MAX_METADATA_BYTES = 2097152;

    public static function manifest_filename($backup_file_name) {
        $backup_file_name = FgBackup_Backup::normalize_backup_filename($backup_file_name);
        return $backup_file_name !== '' ? $backup_file_name . '.json' : '';
    }

    public static function sidecar_path($backup_path) {
        return (string) $backup_path . '.json';
    }

    public static function read_manifest_for_backup($backup_path) {
        $path = self::sidecar_path($backup_path);
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public static function write_manifest($backup_path, array $manifest) {
        $path = self::sidecar_path($backup_path);
        $json = wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new RuntimeException(__('Die JSON-Metadaten konnten nicht erstellt werden.', 'fg-backup-pro'));
        }

        $temporary = $path . '.part';
        @unlink($temporary);
        if (@file_put_contents($temporary, $json . "\n", LOCK_EX) === false) {
            @unlink($temporary);
            throw new RuntimeException(__('Die JSON-Metadaten konnten nicht geschrieben werden.', 'fg-backup-pro'));
        }

        if (!@rename($temporary, $path)) {
            if (!@copy($temporary, $path) || !@unlink($temporary)) {
                @unlink($temporary);
                throw new RuntimeException(__('Die JSON-Metadaten konnten nicht finalisiert werden.', 'fg-backup-pro'));
            }
        }

        return $path;
    }

    public static function delete_manifest($backup_path) {
        $path = self::sidecar_path($backup_path);
        return !is_file($path) || @unlink($path);
    }

    public static function embedded_manifest(array $job) {
        global $wpdb;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'plugin' => [
                'name' => 'FG Backup Pro',
                'version' => FG_BACKUP_VERSION,
            ],
            'backup' => [
                'id' => isset($job['id']) ? (string) $job['id'] : '',
                'type' => isset($job['type']) ? (string) $job['type'] : 'full',
                'format' => isset($job['format']) ? (string) $job['format'] : 'zip',
                'created_at' => gmdate('c', !empty($job['started_at']) ? (int) $job['started_at'] : time()),
                'file_count' => isset($job['archived_files']) ? (int) $job['archived_files'] : 0,
                'uncompressed_size' => isset($job['file_bytes']) ? (int) $job['file_bytes'] : 0,
                'note' => isset($job['note']) ? (string) $job['note'] : '',
            ],
            'website' => [
                'host' => (string) wp_parse_url(home_url('/'), PHP_URL_HOST),
                'home_url' => home_url('/'),
                'site_url' => site_url('/'),
                'wordpress_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
                'database_version' => method_exists($wpdb, 'db_version') ? (string) $wpdb->db_version() : '',
                'table_prefix' => (string) $wpdb->prefix,
                'multisite' => is_multisite(),
            ],
            'database' => [
                'included' => true,
                'tables' => isset($job['tables']) && is_array($job['tables']) ? count($job['tables']) : 0,
                'rows' => isset($job['database_rows']) ? (int) $job['database_rows'] : 0,
                'sql_size' => !empty($job['db_file']) && is_file($job['db_file']) ? (int) filesize($job['db_file']) : 0,
            ],
            'validation' => [
                'status' => 'pending',
                'validated_at' => null,
                'checks' => [],
            ],
        ];
    }

    public static function validate_and_write($backup_path, $type, $format, array $context = []) {
        $existing = self::read_manifest_for_backup($backup_path);
        $report = self::validate($backup_path, $type, $format, $existing, !empty($context['require_completion_marker']));
        $manifest = self::build_manifest($backup_path, $type, $format, $report, $context, $existing);
        self::write_manifest($backup_path, $manifest);
        return $manifest;
    }

    public static function validate($backup_path, $type, $format, array $existing_manifest = [], $require_completion_marker = false) {
        $type = $type === 'db' ? 'db' : 'full';
        $format = FgBackup_Backup::normalize_format($type, $format);
        $checks = [];
        $details = [
            'file_count' => 0,
            'uncompressed_size' => 0,
            'database_tables' => 0,
            'database_rows' => 0,
            'sql_size' => 0,
            'embedded_manifest' => [],
        ];

        if (!is_file($backup_path) || !is_readable($backup_path)) {
            $checks[] = self::check('file', __('Backup-Datei', 'fg-backup-pro'), 'failed', __('Datei fehlt oder ist nicht lesbar.', 'fg-backup-pro'));
            return self::report($checks, $details, '', 0);
        }

        $size = (int) filesize($backup_path);
        $checks[] = self::check(
            'file',
            __('Backup-Datei', 'fg-backup-pro'),
            $size > 0 ? 'passed' : 'failed',
            $size > 0 ? sprintf(__('Datei ist lesbar (%s).', 'fg-backup-pro'), size_format($size, 2)) : __('Datei ist leer.', 'fg-backup-pro')
        );

        $checksum = '';
        if ($size > 0) {
            $checksum = @hash_file('sha256', $backup_path);
            $checks[] = self::check(
                'checksum',
                __('SHA-256-Prüfsumme', 'fg-backup-pro'),
                is_string($checksum) && $checksum !== '' ? 'passed' : 'failed',
                is_string($checksum) && $checksum !== '' ? __('Prüfsumme wurde vollständig berechnet.', 'fg-backup-pro') : __('Prüfsumme konnte nicht berechnet werden.', 'fg-backup-pro')
            );
        }

        try {
            if ($type === 'full') {
                $details = $format === 'tgz'
                    ? self::validate_tgz($backup_path, $checks, (bool) $require_completion_marker)
                    : self::validate_zip($backup_path, $checks, (bool) $require_completion_marker);
            } else {
                $sql = self::validate_database($backup_path, $format, $checks, (bool) $require_completion_marker);
                $details = array_merge($details, $sql);
            }
        } catch (Throwable $exception) {
            $checks[] = self::check('exception', __('Validierung', 'fg-backup-pro'), 'failed', sanitize_text_field($exception->getMessage()));
        }

        if (!empty($existing_manifest['backup']['filename']) && (string) $existing_manifest['backup']['filename'] !== basename($backup_path)) {
            $checks[] = self::check('manifest_filename', __('JSON-Dateiname', 'fg-backup-pro'), 'failed', __('Der Dateiname im JSON stimmt nicht mit der Backup-Datei überein.', 'fg-backup-pro'));
        }
        if (!empty($existing_manifest['backup']['size']) && (int) $existing_manifest['backup']['size'] !== $size) {
            $checks[] = self::check('manifest_size', __('JSON-Dateigröße', 'fg-backup-pro'), 'failed', __('Die im JSON gespeicherte Dateigröße stimmt nicht mehr.', 'fg-backup-pro'));
        }
        if (!empty($existing_manifest['backup']['sha256']) && $checksum !== '' && !hash_equals((string) $existing_manifest['backup']['sha256'], $checksum)) {
            $checks[] = self::check('manifest_checksum', __('JSON-Prüfsumme', 'fg-backup-pro'), 'failed', __('Die Backup-Datei wurde seit der letzten Prüfung verändert.', 'fg-backup-pro'));
        } elseif (!empty($existing_manifest['backup']['sha256']) && $checksum !== '') {
            $checks[] = self::check('manifest_checksum', __('JSON-Prüfsumme', 'fg-backup-pro'), 'passed', __('Die gespeicherte Prüfsumme stimmt überein.', 'fg-backup-pro'));
        }

        return self::report($checks, $details, is_string($checksum) ? $checksum : '', $size);
    }

    private static function validate_database($backup_path, $format, array &$checks, $require_completion_marker) {
        if ($format === 'sql') {
            $handle = @fopen($backup_path, 'rb');
            if (!$handle) {
                throw new RuntimeException(__('Die SQL-Datei konnte nicht geöffnet werden.', 'fg-backup-pro'));
            }
            try {
                return self::scan_sql_stream($handle, false, $checks, $require_completion_marker);
            } finally {
                fclose($handle);
            }
        }

        if ($format === 'gz') {
            if (!FgBackup_Backup::supports_gzip()) {
                throw new RuntimeException(__('GZIP ist auf diesem Server nicht verfügbar.', 'fg-backup-pro'));
            }
            $handle = @gzopen($backup_path, 'rb');
            if (!$handle) {
                throw new RuntimeException(__('Die GZIP-Datei konnte nicht geöffnet werden.', 'fg-backup-pro'));
            }
            try {
                return self::scan_sql_stream($handle, true, $checks, $require_completion_marker);
            } finally {
                gzclose($handle);
            }
        }

        if (!FgBackup_Backup::supports_zip()) {
            throw new RuntimeException(__('ZipArchive ist auf diesem Server nicht verfügbar.', 'fg-backup-pro'));
        }
        $zip = new ZipArchive();
        $opened = $zip->open($backup_path, defined('ZipArchive::CHECKCONS') ? ZipArchive::CHECKCONS : 0);
        if ($opened !== true) {
            throw new RuntimeException(__('Das Datenbank-ZIP ist beschädigt.', 'fg-backup-pro'));
        }
        try {
            $result = null;
            $unsafe = [];
            $file_count = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (!is_array($stat) || !isset($stat['name'])) {
                    $unsafe[] = '#' . $index;
                    continue;
                }
                $name = str_replace('\\', '/', (string) $stat['name']);
                if (!self::safe_archive_name($name)) {
                    $unsafe[] = $name;
                    continue;
                }
                if (substr($name, -1) === '/') {
                    continue;
                }
                $file_count++;
                $stream = $zip->getStream($name);
                if (!$stream) {
                    throw new RuntimeException(sprintf(__('Archivdatei konnte nicht gelesen werden: %s', 'fg-backup-pro'), $name));
                }
                try {
                    if ($name === 'database.sql') {
                        $result = self::scan_sql_stream($stream, false, $checks, $require_completion_marker);
                    } else {
                        self::drain_stream($stream);
                    }
                } finally {
                    fclose($stream);
                }
            }
            if (!is_array($result)) {
                throw new RuntimeException(__('Das Datenbank-ZIP enthält keine database.sql.', 'fg-backup-pro'));
            }
            $checks[] = self::check('archive', __('Archivstruktur', 'fg-backup-pro'), 'passed', sprintf(_n('%d ZIP-Eintrag wurde vollständig gelesen.', '%d ZIP-Einträge wurden vollständig gelesen.', $file_count, 'fg-backup-pro'), $file_count));
            $checks[] = self::check('paths', __('Archivpfade', 'fg-backup-pro'), $unsafe ? 'failed' : 'passed', $unsafe ? __('Das Archiv enthält unsichere oder ungültige Pfade.', 'fg-backup-pro') : __('Alle Archivpfade sind relativ und sicher.', 'fg-backup-pro'));
            return $result;
        } finally {
            $zip->close();
        }
    }

    private static function validate_zip($backup_path, array &$checks, $require_completion_marker) {
        if (!FgBackup_Backup::supports_zip()) {
            throw new RuntimeException(__('ZipArchive ist auf diesem Server nicht verfügbar.', 'fg-backup-pro'));
        }

        $zip = new ZipArchive();
        $opened = $zip->open($backup_path, defined('ZipArchive::CHECKCONS') ? ZipArchive::CHECKCONS : 0);
        if ($opened !== true) {
            throw new RuntimeException(__('Das ZIP-Backup ist beschädigt.', 'fg-backup-pro'));
        }

        $file_count = 0;
        $uncompressed = 0;
        $unsafe = [];
        $database = null;
        $embedded = [];

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (!is_array($stat) || !isset($stat['name'])) {
                    $unsafe[] = '#' . $index;
                    continue;
                }
                $name = str_replace('\\', '/', (string) $stat['name']);
                if (!self::safe_archive_name($name)) {
                    $unsafe[] = $name;
                    continue;
                }
                if (substr($name, -1) === '/') {
                    continue;
                }

                $file_count++;
                $uncompressed += isset($stat['size']) ? (int) $stat['size'] : 0;
                $stream = $zip->getStream($name);
                if (!$stream) {
                    throw new RuntimeException(sprintf(__('Archivdatei konnte nicht gelesen werden: %s', 'fg-backup-pro'), $name));
                }

                if ($name === 'database/database.sql') {
                    $database_checks = [];
                    $database = self::scan_sql_stream($stream, false, $database_checks, $require_completion_marker);
                    foreach ($database_checks as $check) {
                        $checks[] = $check;
                    }
                } elseif ($name === 'fg-backup.json') {
                    $raw = self::read_stream_limited($stream, self::MAX_METADATA_BYTES);
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $embedded = $decoded;
                    }
                } else {
                    self::drain_stream($stream);
                }
                fclose($stream);
            }
        } finally {
            $zip->close();
        }

        $checks[] = self::check('archive', __('Archivstruktur', 'fg-backup-pro'), 'passed', sprintf(__('%d Archiveinträge wurden vollständig gelesen.', 'fg-backup-pro'), $file_count));
        $checks[] = self::check('paths', __('Archivpfade', 'fg-backup-pro'), $unsafe ? 'failed' : 'passed', $unsafe ? __('Das Archiv enthält unsichere oder ungültige Pfade.', 'fg-backup-pro') : __('Alle Archivpfade sind relativ und sicher.', 'fg-backup-pro'));
        $checks[] = self::check('embedded_manifest', __('Manifest im Archiv', 'fg-backup-pro'), $embedded ? 'passed' : 'failed', $embedded ? __('fg-backup.json ist gültiges JSON.', 'fg-backup-pro') : __('fg-backup.json fehlt oder ist ungültig.', 'fg-backup-pro'));
        $checks[] = self::check('database_present', __('Datenbank im Archiv', 'fg-backup-pro'), is_array($database) ? 'passed' : 'failed', is_array($database) ? __('database/database.sql ist enthalten und lesbar.', 'fg-backup-pro') : __('Die Datenbankdatei fehlt.', 'fg-backup-pro'));

        self::compare_embedded_counts($embedded, $file_count, $checks);

        return [
            'file_count' => $file_count,
            'uncompressed_size' => $uncompressed,
            'database_tables' => is_array($database) ? (int) $database['database_tables'] : 0,
            'database_rows' => is_array($database) ? (int) $database['database_rows'] : 0,
            'sql_size' => is_array($database) ? (int) $database['sql_size'] : 0,
            'embedded_manifest' => $embedded,
        ];
    }

    private static function validate_tgz($backup_path, array &$checks, $require_completion_marker) {
        if (!FgBackup_Backup::supports_tgz()) {
            throw new RuntimeException(__('TGZ ist auf diesem Server nicht verfügbar.', 'fg-backup-pro'));
        }

        $archive = new PharData($backup_path);
        $file_count = 0;
        $uncompressed = 0;
        $unsafe = [];
        $database = null;
        $embedded = [];
        $prefix = 'phar://' . str_replace('\\', '/', $backup_path) . '/';

        try {
            $iterator = new RecursiveIteratorIterator($archive, RecursiveIteratorIterator::LEAVES_ONLY);
            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $path_name = str_replace('\\', '/', $file->getPathname());
                $name = strpos($path_name, $prefix) === 0 ? substr($path_name, strlen($prefix)) : basename($path_name);
                if (!self::safe_archive_name($name)) {
                    $unsafe[] = $name;
                    continue;
                }

                $file_count++;
                $uncompressed += (int) $file->getSize();
                $stream = $file->openFile('rb');
                if ($name === 'database/database.sql') {
                    $database_checks = [];
                    $database = self::scan_sql_stream($stream, false, $database_checks, $require_completion_marker);
                    foreach ($database_checks as $check) {
                        $checks[] = $check;
                    }
                } elseif ($name === 'fg-backup.json') {
                    $raw = self::read_spl_stream_limited($stream, self::MAX_METADATA_BYTES);
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $embedded = $decoded;
                    }
                } else {
                    while (!$stream->eof()) {
                        $stream->fread(1048576);
                    }
                }
            }
        } finally {
            unset($archive);
        }

        $checks[] = self::check('archive', __('Archivstruktur', 'fg-backup-pro'), 'passed', sprintf(__('%d Archiveinträge wurden vollständig gelesen.', 'fg-backup-pro'), $file_count));
        $checks[] = self::check('paths', __('Archivpfade', 'fg-backup-pro'), $unsafe ? 'failed' : 'passed', $unsafe ? __('Das Archiv enthält unsichere oder ungültige Pfade.', 'fg-backup-pro') : __('Alle Archivpfade sind relativ und sicher.', 'fg-backup-pro'));
        $checks[] = self::check('embedded_manifest', __('Manifest im Archiv', 'fg-backup-pro'), $embedded ? 'passed' : 'failed', $embedded ? __('fg-backup.json ist gültiges JSON.', 'fg-backup-pro') : __('fg-backup.json fehlt oder ist ungültig.', 'fg-backup-pro'));
        $checks[] = self::check('database_present', __('Datenbank im Archiv', 'fg-backup-pro'), is_array($database) ? 'passed' : 'failed', is_array($database) ? __('database/database.sql ist enthalten und lesbar.', 'fg-backup-pro') : __('Die Datenbankdatei fehlt.', 'fg-backup-pro'));

        self::compare_embedded_counts($embedded, $file_count, $checks);

        return [
            'file_count' => $file_count,
            'uncompressed_size' => $uncompressed,
            'database_tables' => is_array($database) ? (int) $database['database_tables'] : 0,
            'database_rows' => is_array($database) ? (int) $database['database_rows'] : 0,
            'sql_size' => is_array($database) ? (int) $database['sql_size'] : 0,
            'embedded_manifest' => $embedded,
        ];
    }

    private static function scan_sql_stream($handle, $gzip, array &$checks, $require_completion_marker = false) {
        $header = '';
        $tail = '';
        $carry = '';
        $tables = 0;
        $rows = 0;
        $bytes = 0;
        $read_error = false;

        $spl_stream = $handle instanceof SplFileObject;
        while (true) {
            if ($spl_stream) {
                $chunk = $handle->fread(1048576);
            } else {
                $chunk = $gzip ? gzread($handle, 1048576) : fread($handle, 1048576);
            }
            if ($chunk === false) {
                $read_error = true;
                break;
            }
            if ($chunk === '') {
                $ended = $spl_stream ? $handle->eof() : ($gzip ? gzeof($handle) : feof($handle));
                if ($ended) {
                    break;
                }
                continue;
            }

            $bytes += strlen($chunk);
            if (strlen($header) < 512) {
                $header .= substr($chunk, 0, 512 - strlen($header));
            }
            $tail = substr($tail . $chunk, -1024);

            $combined = $carry . $chunk;
            $last_newline = strrpos($combined, "\n");
            if ($last_newline === false) {
                $carry = $combined;
                continue;
            }

            $complete = substr($combined, 0, $last_newline + 1);
            $carry = substr($combined, $last_newline + 1);
            $tables += preg_match_all('/^-- Table:\s+/m', $complete, $matches);
            $rows += preg_match_all('/^INSERT INTO\s+/m', $complete, $matches);
        }

        if ($carry !== '') {
            $tables += preg_match_all('/^-- Table:\s+/m', $carry, $matches);
            $rows += preg_match_all('/^INSERT INTO\s+/m', $carry, $matches);
        }

        $has_header = strpos($header, '-- FG Backup Pro') !== false;
        $has_footer = strpos($tail, self::SQL_COMPLETION_MARKER) !== false;

        $checks[] = self::check('sql_readable', __('SQL vollständig lesbar', 'fg-backup-pro'), $read_error ? 'failed' : 'passed', $read_error ? __('Der SQL-Datenstrom wurde vorzeitig unterbrochen.', 'fg-backup-pro') : sprintf(__('%s SQL-Daten wurden vollständig gelesen.', 'fg-backup-pro'), size_format($bytes, 2)));
        $checks[] = self::check('sql_header', __('SQL-Kopf', 'fg-backup-pro'), $has_header ? 'passed' : 'failed', $has_header ? __('FG-Backup-Pro-Kopf ist vorhanden.', 'fg-backup-pro') : __('Der erwartete SQL-Kopf fehlt.', 'fg-backup-pro'));
        $checks[] = self::check(
            'sql_footer',
            __('SQL-Abschlussmarkierung', 'fg-backup-pro'),
            $has_footer ? 'passed' : ($require_completion_marker ? 'failed' : 'warning'),
            $has_footer
                ? __('Der Export wurde vollständig abgeschlossen.', 'fg-backup-pro')
                : ($require_completion_marker
                    ? __('Die Abschlussmarkierung fehlt; der neue Export ist unvollständig.', 'fg-backup-pro')
                    : __('Legacy-Backup ohne 2.4-Abschlussmarkierung; vollständig lesbar, aber nicht eindeutig abgeschlossen.', 'fg-backup-pro'))
        );
        $checks[] = self::check('sql_tables', __('Datenbanktabellen', 'fg-backup-pro'), $tables > 0 ? 'passed' : 'warning', $tables > 0 ? sprintf(_n('%d Tabelle wurde exportiert.', '%d Tabellen wurden exportiert.', $tables, 'fg-backup-pro'), $tables) : __('Keine Tabellenmarkierung gefunden.', 'fg-backup-pro'));

        return [
            'file_count' => 0,
            'uncompressed_size' => $bytes,
            'database_tables' => $tables,
            'database_rows' => $rows,
            'sql_size' => $bytes,
            'embedded_manifest' => [],
        ];
    }

    private static function build_manifest($backup_path, $type, $format, array $report, array $context, array $existing) {
        global $wpdb;

        $embedded = !empty($report['details']['embedded_manifest']) && is_array($report['details']['embedded_manifest'])
            ? $report['details']['embedded_manifest']
            : [];
        $created = !empty($context['started_at']) ? (int) $context['started_at'] : (int) filemtime($backup_path);
        if (!empty($existing['backup']['created_at'])) {
            $existing_created = strtotime((string) $existing['backup']['created_at']);
            if ($existing_created) {
                $created = (int) $existing_created;
            }
        }
        $job_id = !empty($context['id']) ? (string) $context['id'] : (!empty($existing['backup']['id']) ? (string) $existing['backup']['id'] : '');
        $note = isset($context['note']) ? (string) $context['note'] : (!empty($existing['backup']['note']) ? (string) $existing['backup']['note'] : '');

        $website = !empty($embedded['website']) && is_array($embedded['website']) ? $embedded['website'] : [];
        $database = !empty($embedded['database']) && is_array($embedded['database']) ? $embedded['database'] : [];
        $stored_size = isset($existing['backup']['size']) && (int) $existing['backup']['size'] > 0
            ? (int) $existing['backup']['size']
            : (isset($report['size']) ? (int) $report['size'] : (int) filesize($backup_path));
        $stored_checksum = !empty($existing['backup']['sha256'])
            ? (string) $existing['backup']['sha256']
            : (isset($report['checksum']) ? (string) $report['checksum'] : '');
        $stored_file_count = isset($existing['backup']['file_count'])
            ? (int) $existing['backup']['file_count']
            : (isset($report['details']['file_count']) ? (int) $report['details']['file_count'] : 0);
        $stored_uncompressed_size = isset($existing['backup']['uncompressed_size'])
            ? (int) $existing['backup']['uncompressed_size']
            : (isset($report['details']['uncompressed_size']) ? (int) $report['details']['uncompressed_size'] : 0);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'plugin' => [
                'name' => 'FG Backup Pro',
                'version' => FG_BACKUP_VERSION,
            ],
            'backup' => [
                'id' => $job_id,
                'filename' => basename($backup_path),
                'type' => $type === 'db' ? 'db' : 'full',
                'format' => FgBackup_Backup::normalize_format($type, $format),
                'created_at' => gmdate('c', $created > 0 ? $created : time()),
                'size' => $stored_size,
                'sha256' => $stored_checksum,
                'file_count' => $stored_file_count,
                'uncompressed_size' => $stored_uncompressed_size,
                'note' => $note,
            ],
            'website' => [
                'host' => isset($website['host']) ? (string) $website['host'] : (string) wp_parse_url(home_url('/'), PHP_URL_HOST),
                'home_url' => isset($website['home_url']) ? (string) $website['home_url'] : home_url('/'),
                'site_url' => isset($website['site_url']) ? (string) $website['site_url'] : site_url('/'),
                'wordpress_version' => isset($website['wordpress_version']) ? (string) $website['wordpress_version'] : get_bloginfo('version'),
                'php_version' => isset($website['php_version']) ? (string) $website['php_version'] : PHP_VERSION,
                'database_version' => isset($website['database_version']) ? (string) $website['database_version'] : (method_exists($wpdb, 'db_version') ? (string) $wpdb->db_version() : ''),
                'table_prefix' => isset($website['table_prefix']) ? (string) $website['table_prefix'] : (string) $wpdb->prefix,
                'multisite' => isset($website['multisite']) ? (bool) $website['multisite'] : is_multisite(),
            ],
            'database' => [
                'included' => true,
                'tables' => isset($report['details']['database_tables']) ? (int) $report['details']['database_tables'] : (isset($database['tables']) ? (int) $database['tables'] : 0),
                'rows' => isset($report['details']['database_rows']) ? (int) $report['details']['database_rows'] : (isset($database['rows']) ? (int) $database['rows'] : 0),
                'sql_size' => isset($report['details']['sql_size']) ? (int) $report['details']['sql_size'] : (isset($database['sql_size']) ? (int) $database['sql_size'] : 0),
            ],
            'validation' => [
                'status' => isset($report['status']) ? (string) $report['status'] : 'invalid',
                'validated_at' => gmdate('c'),
                'observed_size' => isset($report['size']) ? (int) $report['size'] : 0,
                'observed_sha256' => isset($report['checksum']) ? (string) $report['checksum'] : '',
                'checks' => isset($report['checks']) ? array_values($report['checks']) : [],
            ],
        ];
    }

    private static function compare_embedded_counts(array $embedded, $actual_file_count, array &$checks) {
        if (!$embedded || empty($embedded['backup']['file_count'])) {
            return;
        }
        $expected_sources = (int) $embedded['backup']['file_count'];
        $actual_sources = max(0, (int) $actual_file_count - 2);
        $checks[] = self::check(
            'file_count',
            __('Dateianzahl', 'fg-backup-pro'),
            $actual_sources === $expected_sources ? 'passed' : 'failed',
            $actual_sources === $expected_sources
                ? sprintf(_n('%d gesicherte Quelldatei stimmt mit dem Manifest überein.', '%d gesicherte Quelldateien stimmen mit dem Manifest überein.', $actual_sources, 'fg-backup-pro'), $actual_sources)
                : sprintf(__('Manifest: %1$d Quelldateien, Archiv: %2$d.', 'fg-backup-pro'), $expected_sources, $actual_sources)
        );
    }

    private static function safe_archive_name($name) {
        if (!is_string($name) || $name === '' || strpos($name, "\0") !== false) {
            return false;
        }
        $name = str_replace('\\', '/', $name);
        if ($name[0] === '/' || preg_match('/^[A-Za-z]:\//', $name)) {
            return false;
        }
        foreach (explode('/', $name) as $part) {
            if ($part === '..') {
                return false;
            }
        }
        return true;
    }

    private static function read_stream_limited($handle, $limit) {
        $data = '';
        while (!feof($handle) && strlen($data) < $limit) {
            $chunk = fread($handle, min(65536, $limit - strlen($data)));
            if ($chunk === false) {
                break;
            }
            $data .= $chunk;
        }
        return $data;
    }

    private static function read_spl_stream_limited(SplFileObject $handle, $limit) {
        $data = '';
        while (!$handle->eof() && strlen($data) < $limit) {
            $data .= $handle->fread(min(65536, $limit - strlen($data)));
        }
        return $data;
    }

    private static function drain_stream($handle) {
        while (!feof($handle)) {
            $chunk = fread($handle, 1048576);
            if ($chunk === false) {
                throw new RuntimeException(__('Ein Archiveintrag konnte nicht vollständig gelesen werden.', 'fg-backup-pro'));
            }
        }
    }

    private static function check($id, $label, $status, $detail) {
        return [
            'id' => sanitize_key($id),
            'label' => sanitize_text_field($label),
            'status' => in_array($status, ['passed', 'warning', 'failed'], true) ? $status : 'failed',
            'detail' => sanitize_text_field($detail),
        ];
    }

    private static function report(array $checks, array $details, $checksum, $size) {
        $status = 'valid';
        foreach ($checks as $check) {
            if (!empty($check['status']) && $check['status'] === 'failed') {
                $status = 'invalid';
                break;
            }
            if (!empty($check['status']) && $check['status'] === 'warning') {
                $status = 'warning';
            }
        }

        return [
            'status' => $status,
            'validated_at' => time(),
            'checksum' => (string) $checksum,
            'size' => (int) $size,
            'checks' => array_values($checks),
            'details' => $details,
        ];
    }

    public static function status_label($status) {
        $labels = [
            'valid' => __('Gültig', 'fg-backup-pro'),
            'warning' => __('Warnung', 'fg-backup-pro'),
            'invalid' => __('Ungültig', 'fg-backup-pro'),
            'unverified' => __('Nicht geprüft', 'fg-backup-pro'),
        ];
        return isset($labels[$status]) ? $labels[$status] : $labels['unverified'];
    }
}
