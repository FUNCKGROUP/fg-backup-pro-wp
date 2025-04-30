<?php
/**
 * Hauptklasse für Backup-Operationen
 */
class FgBackup_Backup {

    /**
     * Liefert das Backup-Verzeichnis (mit Slash am Ende),
     * legt es bei Bedarf an und prüft die Schreibrechte.
     *
     * @return string
     * @throws Exception
     */
    public static function get_backup_dir() {
        $dir = WP_CONTENT_DIR . '/fg-backup-pro';
        if (!is_dir($dir)) {
            if (!wp_mkdir_p($dir)) {
                throw new Exception("FG Backup Pro: Cannot create backup directory: {$dir}");
            }
        }
        if (!is_writable($dir)) {
            throw new Exception("FG Backup Pro: Backup directory is not writable: {$dir}");
        }
        return trailingslashit($dir);
    }

    /**
     * Gibt alle Datenbank-Tabellen zurück.
     *
     * @return array
     */
    public static function get_all_tables() {
        global $wpdb;
        return $wpdb->get_col("SHOW TABLES");
    }

    /**
     * Exportiert eine einzelne Tabelle in eine SQL-Datei.
     *
     * @param string $table
     * @param string $file_path
     */
    public static function export_table_to_sql( $table, $file_path ) {
        global $wpdb;
        $content  = "-- Table: {$table}\n";
        $content .= "DROP TABLE IF EXISTS {$table};\n";
        $create   = $wpdb->get_row("SHOW CREATE TABLE {$table}", ARRAY_N);
        $content .= $create[1] . ";\n\n";

        $rows = $wpdb->get_results("SELECT * FROM {$table}", ARRAY_A);
        foreach ( $rows as $row ) {
            $values = array_map( [ $wpdb, 'escape' ], $row );
            $content .= "INSERT INTO {$table} (" . implode( ", ", array_keys( $row ) ) . ")
                VALUES ('" . implode( "','", $values ) . "');\n";
        }
        $content .= "\n";

        file_put_contents( $file_path, $content, FILE_APPEND );
    }

    /**
     * Liefert alle Dateien der WP-Installation.
     *
     * @return array
     */
    public static function get_all_files() {
        $files    = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( ABSPATH ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ( $iterator as $file ) {
            if ( $file->isFile() ) {
                $files[] = $file->getPathname();
            }
        }
        return $files;
    }

    /**
     * Fügt eine einzelne Datei zu einem Zip-Archiv hinzu.
     *
     * @param string $source   Pfad der Quelldatei
     * @param string $zip_path Pfad zum Zip-Archiv
     */
    public static function add_file_to_zip( $source, $zip_path ) {
        $zip = new ZipArchive();
        if ( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
            return;
        }
        $relative = substr( $source, strlen( ABSPATH ) );
        $zip->addFile( $source, $relative );
        $zip->close();
    }

    /**
     * Schließt ein Zip, falls es offen ist.
     *
     * @param string $zip_path
     */
    public static function finalize_zip( $zip_path ) {
        $zip = new ZipArchive();
        if ( $zip->open( $zip_path ) === true ) {
            $zip->close();
        }
    }

    /**
     * Fügt die DB-SQL-Datei dem Zip-Archiv hinzu.
     *
     * @param string $db_file
     * @param string $zip_path
     */
    public static function add_db_file_to_zip( $db_file, $zip_path ) {
        $zip = new ZipArchive();
        if ( $zip->open( $zip_path ) === true ) {
            $zip->addFile( $db_file, basename( $db_file ) );
            $zip->close();
        }
    }

    /**
     * Listet alle lokalen Backups auf.
     *
     * @return array
     */
    public static function list_backups() {
        $dir    = self::get_backup_dir();
        $files  = is_dir( $dir ) ? scandir( $dir ) : [];
        $result = [];

        foreach ( $files as $file ) {
            if ( in_array( $file, [ '.', '..' ], true ) ) {
                continue;
            }
            $path = $dir . $file;
            if ( ! is_file( $path ) ) {
                continue;
            }
            $result[] = [
                'name' => $file,
                'size' => size_format( filesize( $path ) ),
                'date' => date( 'd.m.Y H:i', filemtime( $path ) ),
                'path' => $path,
            ];
        }

        return $result;
    }

    /**
     * Löscht ein Backup.
     *
     * @param string $file_name
     */
    public static function delete_backup( $file_name ) {
        $file_path = self::get_backup_dir() . $file_name;
        if ( file_exists( $file_path ) ) {
            unlink( $file_path );
        }
    }

    /**
     * Erstellt ein Backup (DB oder Full) und liefert den Datei-Namen zurück.
     *
     * @param string $type       'full' oder 'db'
     * @param bool   $send_email Falls true, wird bei DB-Backup eine E-Mail verschickt
     * @return string
     * @throws Exception
     */
    public static function create_backup( $type = 'full', $send_email = false ) {
        // Verzeichnis prüfen/erstellen
        $backup_dir = self::get_backup_dir();
        $timestamp  = date( 'Y-m-d-H-i-s' );
        $db_file    = $backup_dir . "fg-db-{$timestamp}.sql";
        $zip_file   = $backup_dir . "fg-full-{$timestamp}.zip";

        // Datenbank-Backup
        if ( in_array( $type, [ 'full', 'db' ], true ) ) {
            self::export_all_tables_to_sql( $db_file );
            if ( $send_email && $type === 'db' ) {
                FgBackup_Notifications::send_db_email( $db_file );
            }
        }

        // Volles Backup (Dateien + DB)
        if ( $type === 'full' ) {
            self::create_zip_from_files_and_db( $db_file, $zip_file );
        }

        return ( $type === 'full' ) ? basename( $zip_file ) : basename( $db_file );
    }

    /**
     * Exportiert alle Tabellen in eine SQL-Datei (überschreibt bestehende).
     *
     * @param string $file_path
     */
    public static function export_all_tables_to_sql( $file_path ) {
        global $wpdb;
        $tables = $wpdb->get_col( "SHOW TABLES" );
        $content = '';

        foreach ( $tables as $table ) {
            $content .= "-- Table: {$table}\n";
            $content .= "DROP TABLE IF EXISTS {$table};\n";
            $create   = $wpdb->get_row( "SHOW CREATE TABLE {$table}", ARRAY_N );
            $content .= $create[1] . ";\n\n";

            $rows = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A );
            foreach ( $rows as $row ) {
                $values  = array_map( [ $wpdb, 'escape' ], $row );
                $content .= "INSERT INTO {$table} (" . implode( ", ", array_keys( $row ) ) . ")
                    VALUES ('" . implode( "','", $values ) . "');\n";
            }
            $content .= "\n";
        }

        file_put_contents( $file_path, $content );
    }

    /**
     * Erstellt ein vollständiges Zip-Archiv (Dateien + DB).
     *
     * @param string $db_file
     * @param string $zip_file
     */
    public static function create_zip_from_files_and_db( $db_file, $zip_file ) {
        $rootPath = ABSPATH;
        $zip      = new ZipArchive();
        $zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE );

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $rootPath ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ( $files as $file ) {
            if ( ! $file->isDir() ) {
                $filePath     = $file->getRealPath();
                $relativePath = substr( $filePath, strlen( $rootPath ) );
                $zip->addFile( $filePath, $relativePath );
            }
        }

        // Datenbank-SQL hinzufügen
        $zip->addFile( $db_file, basename( $db_file ) );
        $zip->close();
    }
}
