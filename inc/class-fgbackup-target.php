<?php

interface FgBackup_Target_Interface {
    public function set_credentials(array $credentials);
    public function upload($file_path);
}

class FgBackup_Target {

    public static function upload_backups($targets, $file_path) {
        foreach ($targets as $target) {
            switch ($target) {
                case 'local':
                    // Lokal gespeichert – nichts tun
                    break;
                case 'dropbox':
                    self::run_plugin_upload(FgBackup_Dropbox::class, $file_path);
                    break;
                case 'google_drive':
                    self::run_plugin_upload(FgBackup_GoogleDrive::class, $file_path);
                    break;
                case 'ftp':
                    self::run_plugin_upload(FgBackup_SFTP::class, $file_path);
                    break;
                case 's3':
                    self::run_plugin_upload(FgBackup_S3::class, $file_path);
                    break;
                case 's3_compatible':
                    self::run_plugin_upload(FgBackup_S3_Compatible::class, $file_path);
                    break;
                case 'webdav':
                    self::run_plugin_upload(FgBackup_WebDAV::class, $file_path);
                    break;
                case 'onedrive':
                    self::run_plugin_upload(FgBackup_OneDrive::class, $file_path);
                    break;
            }
        }
    }

    private static function run_plugin_upload($class, $file_path) {
        if (!class_exists($class)) return;

        try {
            $instance = new $class();
            $creds = self::get_credentials_for_target($class);
            $instance->set_credentials($creds);
            $instance->upload($file_path);
        } catch (Exception $e) {
            error_log(get_class($instance) . ' Upload fehlgeschlagen: ' . $e->getMessage());
        }
    }

    private static function get_credentials_for_target($class) {
        switch ($class) {
            case 'FgBackup_S3':
                return [
                    'access_key' => get_option('fg_backup_s3_key'),
                    'secret_key' => get_option('fg_backup_s3_secret'),
                    'region' => get_option('fg_backup_s3_region'),
                    'bucket' => get_option('fg_backup_s3_bucket'),
                    'prefix' => get_option('fg_backup_s3_prefix')
                ];
            case 'FgBackup_S3_Compatible':
                return [
                    'access_key' => get_option('fg_backup_s3c_key'),
                    'secret_key' => get_option('fg_backup_s3c_secret'),
                    'endpoint' => get_option('fg_backup_s3c_endpoint'),
                    'bucket' => get_option('fg_backup_s3c_bucket'),
                    'prefix' => get_option('fg_backup_s3c_prefix')
                ];
            case 'FgBackup_WebDAV':
                return [
                    'webdav_url' => get_option('fg_backup_webdav_url'),
                    'user' => get_option('fg_backup_webdav_user'),
                    'password' => get_option('fg_backup_webdav_pass')
                ];
            case 'FgBackup_OneDrive':
                return [
                    'access_token' => get_option('fg_backup_onedrive_token')
                ];
            default:
                return [];
        }
    }
}