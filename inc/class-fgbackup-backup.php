<?php

defined('ABSPATH') || exit;

class FgBackup_Backup {

    const DB_ROWS_PER_CHUNK = 500;
    const FILES_PER_CHUNK = 150;

    public static function supports_zip() {
        return class_exists('ZipArchive');
    }

    public static function supports_gzip() {
        return function_exists('gzopen') && function_exists('gzwrite') && function_exists('gzread');
    }

    public static function supports_tgz() {
        return class_exists('PharData') && class_exists('Phar') && self::supports_gzip();
    }

    public static function default_filename_pattern() {
        return 'fg-%type-%host-%Y%m%d-%H%M%S';
    }

    public static function sanitize_filename_pattern($pattern) {
        $pattern = wp_strip_all_tags((string) $pattern);
        $pattern = str_replace(['\\', '/'], '-', $pattern);
        $pattern = preg_replace('/[\x00-\x1F\x7F]+/u', '', $pattern);
        $pattern = preg_replace('/[^A-Za-z0-9%._-]+/u', '-', $pattern);
        $pattern = preg_replace('/(?:\.tar\.gz|\.sql\.gz|\.sql\.zip|\.zip|\.tgz|\.sql)$/i', '', $pattern);
        $pattern = trim((string) $pattern, " .-_\t\n\r\0\x0B");

        $allowed = ['%Y', '%y', '%m', '%d', '%H', '%M', '%S', '%host', '%site', '%type', '%format', '%id'];
        $pattern = preg_replace_callback('/%[A-Za-z]+/', static function ($matches) use ($allowed) {
            return in_array($matches[0], $allowed, true) ? $matches[0] : '';
        }, $pattern);
        $pattern = preg_replace('/-+/', '-', (string) $pattern);
        $pattern = trim((string) $pattern, " .-_\t\n\r\0\x0B");

        return $pattern !== '' ? $pattern : self::default_filename_pattern();
    }

    public static function normalize_format($type, $format) {
        $type = $type === 'db' ? 'db' : 'full';
        $allowed = $type === 'full' ? ['zip', 'tgz'] : ['sql', 'gz', 'zip'];
        return in_array($format, $allowed, true) ? $format : ($type === 'full' ? 'zip' : 'gz');
    }

    public static function build_filename($pattern, $type, $format, $job_id, $timestamp = null) {
        $type = $type === 'db' ? 'db' : 'full';
        $format = self::normalize_format($type, $format);
        $timestamp = $timestamp === null ? time() : (int) $timestamp;
        $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        $host = preg_replace('/^www\./i', '', $host);
        $site = (string) get_bloginfo('name');
        $short_id = preg_replace('/^backup_/', '', sanitize_key($job_id));
        $short_id = substr((string) $short_id, 0, 8);

        $replacements = [
            '%format' => self::format_token($type, $format),
            '%type' => $type === 'full' ? 'full' : 'db',
            '%host' => sanitize_title($host !== '' ? $host : 'wordpress'),
            '%site' => sanitize_title($site !== '' ? $site : 'wordpress'),
            '%id' => $short_id !== '' ? $short_id : substr(md5((string) $timestamp), 0, 8),
            '%Y' => wp_date('Y', $timestamp),
            '%y' => wp_date('y', $timestamp),
            '%m' => wp_date('m', $timestamp),
            '%d' => wp_date('d', $timestamp),
            '%H' => wp_date('H', $timestamp),
            '%M' => wp_date('i', $timestamp),
            '%S' => wp_date('s', $timestamp),
        ];

        $base = strtr(self::sanitize_filename_pattern($pattern), $replacements);
        $base = str_replace('%', '', $base);
        $base = sanitize_file_name($base);
        $base = trim($base, " .-_\t\n\r\0\x0B");
        if ($base === '') {
            $base = 'fg-' . $type . '-' . wp_date('Ymd-His', $timestamp);
        }

        return $base . self::file_extension($type, $format);
    }

    private static function format_token($type, $format) {
        if ($type === 'full') {
            return $format === 'tgz' ? 'tgz' : 'zip';
        }

        if ($format === 'zip') {
            return 'sql-zip';
        }
        if ($format === 'gz') {
            return 'sql-gz';
        }
        return 'sql';
    }

    private static function file_extension($type, $format) {
        if ($type === 'full') {
            return $format === 'tgz' ? '.tgz' : '.zip';
        }

        if ($format === 'zip') {
            return '.sql.zip';
        }
        if ($format === 'gz') {
            return '.sql.gz';
        }
        return '.sql';
    }

    public static function unique_backup_path($file_name) {
        $directory = FgBackup_Storage::get_backup_dir();
        $file_name = sanitize_file_name($file_name);
        if ($file_name === '') {
            throw new RuntimeException(__('Der Backup-Dateiname ist ungültig.', 'fg-backup-pro'));
        }

        if (file_exists($directory . $file_name)) {
            $file_name = wp_unique_filename($directory, $file_name);
        }

        return $directory . $file_name;
    }

    public static function get_source_root() {
        if (defined('FG_BACKUP_SOURCE_ROOT') && is_dir(FG_BACKUP_SOURCE_ROOT)) {
            return trailingslashit(realpath(FG_BACKUP_SOURCE_ROOT));
        }

        $wordpress_root = realpath(ABSPATH);
        $content_root = realpath(WP_CONTENT_DIR);

        if ($wordpress_root === false) {
            throw new RuntimeException(__('Das WordPress-Verzeichnis wurde nicht gefunden.', 'fg-backup-pro'));
        }

        if ($content_root !== false && strpos(wp_normalize_path($content_root), trailingslashit(wp_normalize_path($wordpress_root))) !== 0) {
            $common = self::common_parent($wordpress_root, $content_root);
            if ($common && $common !== DIRECTORY_SEPARATOR && self::looks_like_project_root($common)) {
                return trailingslashit($common);
            }
        }

        return trailingslashit($wordpress_root);
    }

    private static function common_parent($left, $right) {
        $left_parts = explode('/', trim(wp_normalize_path($left), '/'));
        $right_parts = explode('/', trim(wp_normalize_path($right), '/'));
        $common = [];
        $max = min(count($left_parts), count($right_parts));

        for ($index = 0; $index < $max; $index++) {
            if ($left_parts[$index] !== $right_parts[$index]) {
                break;
            }
            $common[] = $left_parts[$index];
        }

        if (!$common) {
            return '';
        }

        return '/' . implode('/', $common);
    }

    private static function looks_like_project_root($directory) {
        $markers = [
            'composer.json',
            'wp-config.php',
            'config/application.php',
            'web/wp-config.php',
        ];

        foreach ($markers as $marker) {
            if (is_file(trailingslashit($directory) . $marker)) {
                return true;
            }
        }

        return false;
    }

    public static function get_tables() {
        global $wpdb;

        $pattern = $wpdb->esc_like($wpdb->base_prefix) . '%';
        $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $pattern));

        if (!is_array($tables)) {
            throw new RuntimeException(__('Die Datenbanktabellen konnten nicht ermittelt werden.', 'fg-backup-pro'));
        }

        sort($tables, SORT_STRING);
        return array_values($tables);
    }

    public static function write_database_header($file_path) {
        $header = "-- FG Backup Pro " . FG_BACKUP_VERSION . "\n";
        $header .= "-- Created: " . gmdate('c') . "\n\n";
        $header .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
        $header .= "SET FOREIGN_KEY_CHECKS = 0;\n";
        $header .= "SET NAMES utf8mb4;\n\n";

        self::append_to_file($file_path, $header, false);
    }

    public static function write_database_footer($file_path) {
        self::append_to_file($file_path, "\nSET FOREIGN_KEY_CHECKS = 1;\n", true);
    }

    public static function export_table_chunk($table, $file_path, $offset, $write_structure) {
        global $wpdb;

        $quoted_table = self::quote_identifier($table);

        if ($write_structure) {
            $create = $wpdb->get_row("SHOW CREATE TABLE {$quoted_table}", ARRAY_N);
            if (!is_array($create) || empty($create[1])) {
                throw new RuntimeException(sprintf(
                    __('Tabellenstruktur konnte nicht exportiert werden: %s', 'fg-backup-pro'),
                    $table
                ));
            }

            $structure = "\n-- Table: {$table}\n";
            $structure .= "DROP TABLE IF EXISTS {$quoted_table};\n";
            $structure .= $create[1] . ";\n\n";
            self::append_to_file($file_path, $structure, true);
        }

        $limit = self::DB_ROWS_PER_CHUNK;
        $offset = max(0, (int) $offset);
        $rows = $wpdb->get_results("SELECT * FROM {$quoted_table} LIMIT {$limit} OFFSET {$offset}", ARRAY_A);

        if (!is_array($rows)) {
            throw new RuntimeException(sprintf(
                __('Daten konnten nicht exportiert werden: %s', 'fg-backup-pro'),
                $table
            ));
        }

        foreach ($rows as $row) {
            $columns = [];
            $values = [];

            foreach ($row as $column => $value) {
                $columns[] = self::quote_identifier($column);
                $values[] = self::sql_value($value);
            }

            $line = "INSERT INTO {$quoted_table} (" . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n";
            self::append_to_file($file_path, $line, true);
        }

        return [
            'rows' => count($rows),
            'done' => count($rows) < $limit,
        ];
    }

    private static function quote_identifier($identifier) {
        return '`' . str_replace('`', '``', (string) $identifier) . '`';
    }

    private static function sql_value($value) {
        global $wpdb;

        if ($value === null) {
            return 'NULL';
        }

        $value = (string) $value;
        if (method_exists($wpdb, '_real_escape')) {
            $value = $wpdb->_real_escape($value);
        } else {
            $value = addslashes($value);
        }

        return "'{$value}'";
    }

    private static function append_to_file($file_path, $content, $append) {
        $flags = $append ? FILE_APPEND | LOCK_EX : LOCK_EX;
        $written = @file_put_contents($file_path, $content, $flags);

        if ($written === false) {
            throw new RuntimeException(__('Die Datenbankdatei konnte nicht geschrieben werden.', 'fg-backup-pro'));
        }
    }

    public static function scan_files_chunk(array $queue, $manifest_path, $source_root, $max_entries, $max_seconds) {
        $processed = 0;
        $files_added = 0;
        $started = microtime(true);
        $source_root = trailingslashit($source_root);

        while ($queue && $processed < $max_entries && (microtime(true) - $started) < $max_seconds) {
            $queue_item = array_shift($queue);
            $directory = is_array($queue_item) && isset($queue_item['path'])
                ? (string) $queue_item['path']
                : (string) $queue_item;
            $start_index = is_array($queue_item) && isset($queue_item['offset'])
                ? max(0, (int) $queue_item['offset'])
                : 0;

            if (!is_dir($directory) || is_link($directory) || self::is_excluded($directory, true, $source_root)) {
                continue;
            }

            $items = @scandir($directory);
            if (!is_array($items)) {
                continue;
            }

            $item_count = count($items);
            for ($index = $start_index; $index < $item_count; $index++) {
                $item = $items[$index];
                if ($item === '.' || $item === '..') {
                    continue;
                }

                $path = trailingslashit($directory) . $item;
                $processed++;

                if (!is_link($path) && !self::is_excluded($path, is_dir($path), $source_root)) {
                    if (is_dir($path)) {
                        $queue[] = [
                            'path' => $path,
                            'offset' => 0,
                        ];
                    } elseif (is_file($path) && is_readable($path)) {
                        $relative = ltrim(substr(wp_normalize_path($path), strlen(wp_normalize_path($source_root))), '/');
                        if ($relative !== '') {
                            $entry = wp_json_encode([
                                'path' => $path,
                                'name' => $relative,
                                'size' => (int) @filesize($path),
                            ]);

                            if (!is_string($entry) || @file_put_contents($manifest_path, $entry . "\n", FILE_APPEND | LOCK_EX) === false) {
                                throw new RuntimeException(__('Die Dateiliste konnte nicht geschrieben werden.', 'fg-backup-pro'));
                            }

                            $files_added++;
                        }
                    }
                }

                if ($processed >= $max_entries || (microtime(true) - $started) >= $max_seconds) {
                    if ($index + 1 < $item_count) {
                        array_unshift($queue, [
                            'path' => $directory,
                            'offset' => $index + 1,
                        ]);
                    }
                    break;
                }
            }
        }

        return [
            'queue' => array_values($queue),
            'files_added' => $files_added,
            'done' => empty($queue),
        ];
    }

    private static function is_excluded($path, $is_directory, $source_root) {
        $normalized = wp_normalize_path($path);
        $relative = '/' . ltrim(substr($normalized, strlen(wp_normalize_path($source_root))), '/');
        $private_root = trailingslashit(wp_normalize_path(FgBackup_Storage::get_private_root()));

        if (strpos(trailingslashit($normalized), $private_root) === 0) {
            return true;
        }

        $directory_names = [
            '/.git/',
            '/.svn/',
            '/node_modules/',
            '/wp-content/cache/',
            '/wp-content/upgrade/',
            '/wp-content/ai1wm-backups/',
            '/wp-content/updraft/',
            '/wp-content/wpvividbackups/',
            '/wp-content/backups/',
        ];

        $haystack = trailingslashit($relative);
        foreach ($directory_names as $excluded) {
            if (strpos($haystack, $excluded) !== false) {
                return true;
            }
        }

        if (!$is_directory) {
            $basename = basename($normalized);
            if (in_array($basename, ['debug.log', 'error_log', '.DS_Store'], true)) {
                return true;
            }
            if (substr($basename, -4) === '.tmp' || substr($basename, -5) === '.part') {
                return true;
            }
        }

        $custom = (string) get_option('fg_backup_exclusions', '');
        if ($custom !== '') {
            $patterns = preg_split('/\r\n|\r|\n/', $custom);
            foreach ((array) $patterns as $pattern) {
                $pattern = trim(wp_normalize_path($pattern));
                if ($pattern !== '' && strpos($relative, $pattern) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function initialize_archive($format, $archive_path) {
        if ($format === 'tgz') {
            self::initialize_tar($archive_path);
            return;
        }
        self::initialize_zip($archive_path);
    }

    public static function add_manifest_files_to_archive($format, $manifest_path, $manifest_offset, $archive_path, $source_root, $max_files, $max_seconds) {
        if ($format === 'tgz') {
            return self::add_manifest_files_to_tar($manifest_path, $manifest_offset, $archive_path, $source_root, $max_files, $max_seconds);
        }

        return self::add_manifest_files_to_zip($manifest_path, $manifest_offset, $archive_path, $source_root, $max_files, $max_seconds);
    }

    public static function finalize_archive($format, $archive_path, $db_file, array $metadata) {
        if ($format === 'tgz') {
            self::finalize_tar($archive_path, $db_file, $metadata);
            return;
        }
        self::finalize_zip($archive_path, $db_file, $metadata);
    }

    public static function initialize_zip($zip_path) {
        if (!self::supports_zip()) {
            throw new RuntimeException(__('Die PHP-Erweiterung ZipArchive ist nicht verfügbar.', 'fg-backup-pro'));
        }

        $zip = new ZipArchive();
        $result = $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($result !== true) {
            throw new RuntimeException(__('Das ZIP-Archiv konnte nicht erstellt werden.', 'fg-backup-pro'));
        }
        $zip->close();
    }

    public static function add_manifest_files_to_zip($manifest_path, $manifest_offset, $zip_path, $source_root, $max_files, $max_seconds) {
        $handle = self::open_manifest($manifest_path, $manifest_offset);
        $zip = new ZipArchive();
        $result = $zip->open($zip_path, ZipArchive::CREATE);
        if ($result !== true) {
            fclose($handle);
            throw new RuntimeException(__('Das ZIP-Archiv konnte nicht geöffnet werden.', 'fg-backup-pro'));
        }

        $result = self::consume_manifest($handle, $source_root, $max_files, $max_seconds, static function ($real, $archive_name) use ($zip) {
            if (!$zip->addFile($real, $archive_name)) {
                throw new RuntimeException(sprintf(
                    __('Datei konnte nicht zum Archiv hinzugefügt werden: %s', 'fg-backup-pro'),
                    $archive_name
                ));
            }
        });

        $zip->close();
        fclose($handle);
        return $result;
    }

    public static function finalize_zip($zip_path, $db_file, array $metadata) {
        $zip = new ZipArchive();
        $result = $zip->open($zip_path, ZipArchive::CREATE);
        if ($result !== true) {
            throw new RuntimeException(__('Das ZIP-Archiv konnte nicht finalisiert werden.', 'fg-backup-pro'));
        }

        if (!$zip->addFile($db_file, 'database/database.sql')) {
            $zip->close();
            throw new RuntimeException(__('Die Datenbank konnte nicht zum ZIP-Archiv hinzugefügt werden.', 'fg-backup-pro'));
        }

        $metadata_json = wp_json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($metadata_json) || !$zip->addFromString('fg-backup.json', $metadata_json . "\n")) {
            $zip->close();
            throw new RuntimeException(__('Die Backup-Metadaten konnten nicht geschrieben werden.', 'fg-backup-pro'));
        }

        $zip->close();
    }

    private static function initialize_tar($tar_path) {
        if (!self::supports_tgz()) {
            throw new RuntimeException(__('TGZ ist auf diesem Server nicht verfügbar.', 'fg-backup-pro'));
        }

        @unlink($tar_path);
        @unlink($tar_path . '.gz');
        try {
            $tar = new PharData($tar_path);
            unset($tar);
        } catch (Throwable $exception) {
            throw new RuntimeException(__('Das TAR-Archiv konnte nicht erstellt werden.', 'fg-backup-pro'));
        }
    }

    private static function add_manifest_files_to_tar($manifest_path, $manifest_offset, $tar_path, $source_root, $max_files, $max_seconds) {
        $handle = self::open_manifest($manifest_path, $manifest_offset);
        try {
            $tar = new PharData($tar_path);
            $result = self::consume_manifest($handle, $source_root, $max_files, $max_seconds, static function ($real, $archive_name) use ($tar) {
                try {
                    $tar->addFile($real, $archive_name);
                } catch (Throwable $exception) {
                    throw new RuntimeException(sprintf(
                        __('Datei konnte nicht zum Archiv hinzugefügt werden: %s', 'fg-backup-pro'),
                        $archive_name
                    ));
                }
            });
            unset($tar);
            fclose($handle);
            return $result;
        } catch (Throwable $exception) {
            fclose($handle);
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }
            throw new RuntimeException(__('Das TAR-Archiv konnte nicht fortgesetzt werden.', 'fg-backup-pro'));
        }
    }

    private static function finalize_tar($tar_path, $db_file, array $metadata) {
        try {
            $tar = new PharData($tar_path);
            $tar->addFile($db_file, 'database/database.sql');
            $metadata_json = wp_json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (!is_string($metadata_json)) {
                throw new RuntimeException(__('Die Backup-Metadaten konnten nicht geschrieben werden.', 'fg-backup-pro'));
            }
            $tar->addFromString('fg-backup.json', $metadata_json . "\n");
            unset($tar);
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }
            throw new RuntimeException(__('Das TAR-Archiv konnte nicht finalisiert werden.', 'fg-backup-pro'));
        }
    }

    public static function compress_tar($tar_path, $gzip_path, callable $should_cancel = null, callable $progress_callback = null) {
        if (!self::supports_tgz()) {
            throw new RuntimeException(__('TGZ ist auf diesem Server nicht verfügbar.', 'fg-backup-pro'));
        }

        $input = @fopen($tar_path, 'rb');
        @unlink($gzip_path);
        $output = @gzopen($gzip_path, 'wb6');
        if (!$input || !$output) {
            if ($input) {
                fclose($input);
            }
            if ($output) {
                gzclose($output);
            }
            throw new RuntimeException(__('Das TAR-Archiv konnte nicht mit GZIP komprimiert werden.', 'fg-backup-pro'));
        }

        $total = max(0, (int) @filesize($tar_path));
        $processed = 0;
        $last_progress = 0.0;

        while (!feof($input)) {
            if ($should_cancel && call_user_func($should_cancel)) {
                fclose($input);
                gzclose($output);
                @unlink($gzip_path);
                return false;
            }

            $chunk = fread($input, 1024 * 1024);
            if ($chunk === false) {
                fclose($input);
                gzclose($output);
                @unlink($gzip_path);
                throw new RuntimeException(__('Das TAR-Archiv konnte nicht gelesen werden.', 'fg-backup-pro'));
            }

            if ($chunk !== '') {
                $length = strlen($chunk);
                if (gzwrite($output, $chunk) !== $length) {
                    fclose($input);
                    gzclose($output);
                    @unlink($gzip_path);
                    throw new RuntimeException(__('Die TGZ-Datei konnte nicht geschrieben werden.', 'fg-backup-pro'));
                }
                $processed += $length;
            }

            if ($progress_callback && ((microtime(true) - $last_progress) >= 1.0 || feof($input))) {
                call_user_func($progress_callback, $processed, $total);
                $last_progress = microtime(true);
            }
        }

        fclose($input);
        gzclose($output);

        if (!is_file($gzip_path) || filesize($gzip_path) <= 0) {
            @unlink($gzip_path);
            throw new RuntimeException(__('Die TGZ-Datei wurde nicht erstellt.', 'fg-backup-pro'));
        }

        return true;
    }

    private static function open_manifest($manifest_path, $manifest_offset) {
        $handle = @fopen($manifest_path, 'rb');
        if (!$handle) {
            throw new RuntimeException(__('Die Dateiliste konnte nicht gelesen werden.', 'fg-backup-pro'));
        }

        if ($manifest_offset > 0 && fseek($handle, $manifest_offset) !== 0) {
            fclose($handle);
            throw new RuntimeException(__('Die Dateiliste konnte nicht fortgesetzt werden.', 'fg-backup-pro'));
        }

        return $handle;
    }

    private static function consume_manifest($handle, $source_root, $max_files, $max_seconds, callable $consumer) {
        $added = 0;
        $started = microtime(true);
        $source_root_real = trailingslashit(wp_normalize_path(realpath($source_root)));

        while (!feof($handle) && $added < $max_files && (microtime(true) - $started) < $max_seconds) {
            $line = fgets($handle);
            if ($line === false) {
                break;
            }

            $entry = json_decode($line, true);
            if (!is_array($entry) || empty($entry['path']) || empty($entry['name'])) {
                continue;
            }

            $path = (string) $entry['path'];
            $real = realpath($path);
            if ($real === false || is_link($path) || !is_file($real) || !is_readable($real)) {
                continue;
            }

            if (strpos(wp_normalize_path($real), $source_root_real) !== 0) {
                continue;
            }

            $archive_name = ltrim(str_replace('\\', '/', (string) $entry['name']), '/');
            if ($archive_name === '' || strpos($archive_name, '../') !== false) {
                continue;
            }

            $consumer($real, $archive_name);
            $added++;
        }

        $offset = ftell($handle);
        return [
            'offset' => $offset === false ? 0 : $offset,
            'added' => $added,
            'done' => feof($handle),
        ];
    }

    public static function package_database($sql_file, $format, $destination, callable $should_cancel = null) {
        $format = self::normalize_format('db', $format);
        if ($format === 'sql') {
            return $sql_file;
        }

        if ($format === 'gz') {
            if (!self::gzip_file($sql_file, $destination, $should_cancel)) {
                return false;
            }
            return $destination;
        }

        self::zip_database($sql_file, $destination);
        return $destination;
    }

    private static function gzip_file($source, $destination, callable $should_cancel = null) {
        if (!self::supports_gzip()) {
            throw new RuntimeException(__('GZIP ist auf diesem Server nicht verfügbar.', 'fg-backup-pro'));
        }

        $input = @fopen($source, 'rb');
        $output = @gzopen($destination, 'wb9');
        if (!$input || !$output) {
            if ($input) {
                fclose($input);
            }
            if ($output) {
                gzclose($output);
            }
            throw new RuntimeException(__('Die GZIP-Datei konnte nicht erstellt werden.', 'fg-backup-pro'));
        }

        while (!feof($input)) {
            if ($should_cancel && call_user_func($should_cancel)) {
                fclose($input);
                gzclose($output);
                @unlink($destination);
                return false;
            }

            $chunk = fread($input, 1024 * 1024);
            if ($chunk === false) {
                fclose($input);
                gzclose($output);
                throw new RuntimeException(__('Die SQL-Datei konnte nicht gelesen werden.', 'fg-backup-pro'));
            }
            if ($chunk !== '' && gzwrite($output, $chunk) === false) {
                fclose($input);
                gzclose($output);
                throw new RuntimeException(__('Die GZIP-Datei konnte nicht geschrieben werden.', 'fg-backup-pro'));
            }
        }

        fclose($input);
        gzclose($output);
        return true;
    }

    private static function zip_database($source, $destination) {
        if (!self::supports_zip()) {
            throw new RuntimeException(__('Die PHP-Erweiterung ZipArchive ist nicht verfügbar.', 'fg-backup-pro'));
        }

        $zip = new ZipArchive();
        $result = $zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($result !== true || !$zip->addFile($source, 'database.sql')) {
            if ($result === true) {
                $zip->close();
            }
            throw new RuntimeException(__('Das Datenbank-ZIP konnte nicht erstellt werden.', 'fg-backup-pro'));
        }
        $zip->close();
    }

    public static function verify_database_artifact($file_path, $format) {
        $format = self::normalize_format('db', $format);
        if ($format === 'sql') {
            self::verify_sql($file_path);
            return;
        }
        if ($format === 'gz') {
            self::verify_gzip_sql($file_path);
            return;
        }
        self::verify_database_zip($file_path);
    }

    public static function verify_full_archive($file_path, $format) {
        if ($format === 'tgz') {
            self::verify_tgz($file_path);
            return;
        }
        self::verify_zip($file_path);
    }

    public static function verify_sql($file_path) {
        if (!is_file($file_path) || filesize($file_path) < 50) {
            throw new RuntimeException(__('Die SQL-Sicherung ist leer oder unvollständig.', 'fg-backup-pro'));
        }

        $handle = @fopen($file_path, 'rb');
        $header = $handle ? fread($handle, 200) : false;
        if ($handle) {
            fclose($handle);
        }

        if (!is_string($header) || strpos($header, '-- FG Backup Pro') === false) {
            throw new RuntimeException(__('Die SQL-Sicherung konnte nicht verifiziert werden.', 'fg-backup-pro'));
        }
    }

    private static function verify_gzip_sql($file_path) {
        if (!is_file($file_path) || filesize($file_path) < 20 || !self::supports_gzip()) {
            throw new RuntimeException(__('Die GZIP-Sicherung ist leer oder nicht lesbar.', 'fg-backup-pro'));
        }

        $handle = @gzopen($file_path, 'rb');
        $header = $handle ? gzread($handle, 200) : false;
        if ($handle) {
            gzclose($handle);
        }

        if (!is_string($header) || strpos($header, '-- FG Backup Pro') === false) {
            throw new RuntimeException(__('Die GZIP-Sicherung konnte nicht verifiziert werden.', 'fg-backup-pro'));
        }
    }

    private static function verify_database_zip($file_path) {
        if (!self::supports_zip() || !is_file($file_path) || filesize($file_path) < 50) {
            throw new RuntimeException(__('Das Datenbank-ZIP ist leer oder nicht lesbar.', 'fg-backup-pro'));
        }

        $zip = new ZipArchive();
        $result = $zip->open($file_path, defined('ZipArchive::CHECKCONS') ? ZipArchive::CHECKCONS : 0);
        if ($result !== true) {
            throw new RuntimeException(__('Das Datenbank-ZIP ist beschädigt.', 'fg-backup-pro'));
        }
        $header = $zip->getFromName('database.sql', 200);
        $zip->close();

        if (!is_string($header) || strpos($header, '-- FG Backup Pro') === false) {
            throw new RuntimeException(__('Das Datenbank-ZIP enthält keine gültige SQL-Datei.', 'fg-backup-pro'));
        }
    }

    public static function verify_zip($file_path) {
        if (!is_file($file_path) || filesize($file_path) < 100 || !self::supports_zip()) {
            throw new RuntimeException(__('Das ZIP-Backup ist leer oder unvollständig.', 'fg-backup-pro'));
        }

        $zip = new ZipArchive();
        $result = $zip->open($file_path, defined('ZipArchive::CHECKCONS') ? ZipArchive::CHECKCONS : 0);
        if ($result !== true) {
            throw new RuntimeException(__('Das ZIP-Backup ist beschädigt.', 'fg-backup-pro'));
        }

        $has_database = $zip->locateName('database/database.sql') !== false;
        $has_metadata = $zip->locateName('fg-backup.json') !== false;
        $file_count = $zip->numFiles;
        $zip->close();

        if (!$has_database || !$has_metadata || $file_count < 2) {
            throw new RuntimeException(__('Das ZIP-Backup enthält nicht alle Pflichtdateien.', 'fg-backup-pro'));
        }
    }

    private static function verify_tgz($file_path) {
        if (!is_file($file_path) || filesize($file_path) < 100 || !self::supports_tgz()) {
            throw new RuntimeException(__('Das TGZ-Backup ist leer oder unvollständig.', 'fg-backup-pro'));
        }

        try {
            $archive = new PharData($file_path);
            $has_database = isset($archive['database/database.sql']);
            $has_metadata = isset($archive['fg-backup.json']);
            $file_count = count($archive);
            unset($archive);
        } catch (Throwable $exception) {
            throw new RuntimeException(__('Das TGZ-Backup ist beschädigt.', 'fg-backup-pro'));
        }

        if (!$has_database || !$has_metadata || $file_count < 2) {
            throw new RuntimeException(__('Das TGZ-Backup enthält nicht alle Pflichtdateien.', 'fg-backup-pro'));
        }
    }

    public static function move_to_final($temporary, $final) {
        if (file_exists($final)) {
            throw new RuntimeException(__('Die Zieldatei existiert bereits.', 'fg-backup-pro'));
        }

        if (!@rename($temporary, $final)) {
            if (!@copy($temporary, $final) || !@unlink($temporary)) {
                throw new RuntimeException(__('Das Backup konnte nicht in den Zielordner verschoben werden.', 'fg-backup-pro'));
            }
        }
    }

    public static function checksum($file_path) {
        $hash = @hash_file('sha256', $file_path);
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException(__('Die Prüfsumme konnte nicht erstellt werden.', 'fg-backup-pro'));
        }
        return $hash;
    }

    public static function list_backups() {
        $directory = FgBackup_Storage::get_backup_dir();
        $items = scandir($directory);
        $backups = [];

        if (!is_array($items)) {
            return $backups;
        }

        foreach ($items as $item) {
            if (!self::is_backup_filename($item)) {
                continue;
            }

            $path = $directory . $item;
            if (!is_file($path)) {
                continue;
            }

            $history = self::history_entry_for_file($item);
            $type = !empty($history['type']) ? $history['type'] : self::detect_type($path, $item);
            $format = !empty($history['format']) ? $history['format'] : self::detect_format($item, $type);
            $mtime = (int) filemtime($path);
            $backups[] = [
                'name' => $item,
                'path' => $path,
                'type' => $type,
                'format' => $format,
                'format_label' => self::format_label($type, $format),
                'size_raw' => (int) filesize($path),
                'size' => size_format((int) filesize($path), 2),
                'mtime' => $mtime,
                'date' => wp_date('d.m.Y H:i', $mtime),
                'checksum' => !empty($history['checksum']) ? (string) $history['checksum'] : '',
                'verified' => !empty($history['checksum']) && (!isset($history['status']) || $history['status'] === 'completed'),
            ];
        }

        usort($backups, static function ($left, $right) {
            return $right['mtime'] <=> $left['mtime'];
        });

        return $backups;
    }

    private static function is_backup_filename($file_name) {
        return (bool) preg_match('/(?:\.zip|\.sql|\.sql\.gz|\.sql\.zip|\.tgz|\.tar\.gz)$/i', $file_name);
    }

    private static function history_entry_for_file($file_name) {
        $history = get_option('fg_backup_history', []);
        foreach ((array) $history as $entry) {
            if (!empty($entry['file']) && $entry['file'] === $file_name) {
                return is_array($entry) ? $entry : [];
            }
        }
        return [];
    }

    private static function detect_type($path, $file_name) {
        $lower = strtolower($file_name);
        if (preg_match('/\.sql(?:\.gz|\.zip)?$/', $lower)) {
            return 'db';
        }

        if (substr($lower, -4) === '.zip' && self::supports_zip()) {
            $zip = new ZipArchive();
            if ($zip->open($path) === true) {
                $is_db = $zip->locateName('database.sql') !== false && $zip->locateName('fg-backup.json') === false;
                $zip->close();
                if ($is_db) {
                    return 'db';
                }
            }
        }

        return 'full';
    }

    private static function detect_format($file_name, $type) {
        $lower = strtolower($file_name);
        if ($type === 'full') {
            return preg_match('/\.(?:tgz|tar\.gz)$/', $lower) ? 'tgz' : 'zip';
        }
        if (preg_match('/\.sql\.gz$/', $lower)) {
            return 'gz';
        }
        if (preg_match('/\.sql\.zip$/', $lower) || substr($lower, -4) === '.zip') {
            return 'zip';
        }
        return 'sql';
    }

    public static function format_label($type, $format) {
        if ($type === 'full') {
            return $format === 'tgz' ? 'TGZ' : 'ZIP';
        }
        if ($format === 'gz') {
            return 'SQL.GZ';
        }
        if ($format === 'zip') {
            return 'SQL.ZIP';
        }
        return 'SQL';
    }

    public static function get_backup_path($file_name) {
        $file_name = sanitize_file_name($file_name);
        if ($file_name === '' || !self::is_backup_filename($file_name)) {
            return false;
        }

        $path = FgBackup_Storage::get_backup_dir() . $file_name;
        if (!is_file($path) || !FgBackup_Storage::is_path_inside($path, FgBackup_Storage::get_backup_dir())) {
            return false;
        }

        return $path;
    }

    public static function delete_backup($file_name) {
        $path = self::get_backup_path($file_name);
        if (!$path) {
            return false;
        }
        return @unlink($path);
    }
}
