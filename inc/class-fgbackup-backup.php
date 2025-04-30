<?php

class FgBackup_Backup {

    public static function get_backup_dir() {
        return WP_CONTENT_DIR . '/fg-backup-pro/';
    }

    public static function get_all_tables() {
        global $wpdb;
        return $wpdb->get_col("SHOW TABLES");
    }

    public static function export_table_to_sql($table, $file_path) {
        global $wpdb;

        $content = "-- Table: {$table}\n";
        $content .= "DROP TABLE IF EXISTS {$table};\n";
        $create_table = $wpdb->get_row("SHOW CREATE TABLE {$table}", ARRAY_N);
        $content .= $create_table[1] . ";\n\n";

        $rows = $wpdb->get_results("SELECT * FROM {$table}", ARRAY_A);
        foreach ($rows as $row) {
            $values = array_map([$wpdb, 'escape'], $row);
            $content .= "INSERT INTO {$table} (" . implode(", ", array_keys($row)) . ") VALUES ('" . implode("','", $values) . "');\n";
        }
        $content .= "\n";

        file_put_contents($file_path, $content, FILE_APPEND);
    }

    public static function get_all_files() {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(ABSPATH),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
        return $files;
    }

    public static function add_file_to_zip($source, $zip_path) {
        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return;
        }
        $relative_path = substr($source, strlen(ABSPATH));
        $zip->addFile($source, $relative_path);
        $zip->close();
    }

    public static function finalize_zip($zip_path) {
        $zip = new ZipArchive();
        if ($zip->open($zip_path) === true) {
            $zip->close();
        }
    }

    public static function add_db_file_to_zip($db_file, $zip_path) {
        $zip = new ZipArchive();
        if ($zip->open($zip_path) === true) {
            $zip->addFile($db_file, basename($db_file));
            $zip->close();
        }
    }

    public static function list_backups() {
        $dir = self::get_backup_dir();
        $files = is_dir($dir) ? scandir($dir) : [];
        $backups = [];

        foreach ($files as $file) {
            if (in_array($file, ['.', '..'])) continue;
            $path = $dir . '/' . $file;
            if (!is_file($path)) continue;
            $size = filesize($path);
            $time = filemtime($path);
            $backups[] = [
                'name' => $file,
                'size' => size_format($size),
                'date' => date('d.m.Y H:i', $time),
                'path' => $path
            ];
        }

        return $backups;
    }

    public static function delete_backup($file_name) {
        $file_path = self::get_backup_dir() . '/' . $file_name;
        if (file_exists($file_path)) unlink($file_path);
    }

    public static function create_backup($type = 'full', $send_email = false) {
        $backup_dir = self::get_backup_dir();
        if (!is_dir($backup_dir)) wp_mkdir_p($backup_dir);

        $timestamp = date('Y-m-d-H-i-s');
        $db_file = $backup_dir . "/fg-db-{$timestamp}.sql";
        $zip_file = $backup_dir . "/fg-full-{$timestamp}.zip";

        // Datenbank-Backup
        if ($type === 'full' || $type === 'db') {
            self::export_all_tables_to_sql($db_file);
            if ($send_email && $type === 'db') {
                FgBackup_Notifications::send_db_email($db_file);
            }
        }

        // Volles Backup
        if ($type === 'full') {
            self::create_zip_from_files_and_db($db_file, $zip_file);
        }

        return $type === 'full' ? basename($zip_file) : basename($db_file);
    }

    public static function export_all_tables_to_sql($file_path) {
        global $wpdb;

        $tables = $wpdb->get_col("SHOW TABLES");
        $content = '';

        foreach ($tables as $table) {
            $content .= "-- Table: {$table}\n";
            $content .= "DROP TABLE IF EXISTS {$table};\n";
            $create_table = $wpdb->get_var("SHOW CREATE TABLE {$table}", 1);
            $content .= $create_table[1] . ";\n\n";

            $rows = $wpdb->get_results("SELECT * FROM {$table}", ARRAY_A);
            foreach ($rows as $row) {
                $values = array_map([$wpdb, 'escape'], $row);
                $content .= "INSERT INTO {$table} (" . implode(", ", array_map('esc_sql', array_keys($row))) . ") VALUES ('" . implode("', '", $values) . "');\n";
            }
            $content .= "\n";
        }

        file_put_contents($file_path, $content);
    }

    public static function create_zip_from_files_and_db($db_file, $zip_file) {
        $rootPath = ABSPATH;
        $zip = new ZipArchive();
        $zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($rootPath),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $name => $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($rootPath));
                $zip->addFile($filePath, $relativePath);
            }
        }

        // DB-Datei hinzufügen
        $zip->addFile($db_file, basename($db_file));
        $zip->close();
    }
}