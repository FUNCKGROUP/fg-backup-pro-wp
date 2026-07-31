<?php

defined('ABSPATH') || exit;

class FgBackup_Admin {

    public static function init() {
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_front_admin_bar_assets']);
        add_action('admin_bar_menu', [__CLASS__, 'admin_bar_status'], 100);
        add_action('wp_ajax_fg_backup_start', [__CLASS__, 'ajax_start']);
        add_action('wp_ajax_fg_backup_status', [__CLASS__, 'ajax_status']);
        add_action('wp_ajax_fg_backup_cancel', [__CLASS__, 'ajax_cancel']);
        add_action('wp_ajax_fg_backup_health_check', [__CLASS__, 'ajax_health_check']);
        add_action('wp_ajax_fg_backup_storage_test', [__CLASS__, 'ajax_storage_test']);
        add_action('wp_ajax_fg_backup_validate', [__CLASS__, 'ajax_validate']);
        add_action('wp_ajax_fg_backup_validation_report', [__CLASS__, 'ajax_validation_report']);
        add_action('wp_ajax_fg_backup_sftp_test', [__CLASS__, 'ajax_sftp_test']);
        add_action('wp_ajax_fg_backup_sftp_reset_key', [__CLASS__, 'ajax_sftp_reset_key']);
        add_action('wp_ajax_fg_backup_sftp_list', [__CLASS__, 'ajax_sftp_list']);
        add_action('wp_ajax_fg_backup_sftp_delete', [__CLASS__, 'ajax_sftp_delete']);
        add_action('wp_ajax_fg_backup_webdav_test', [__CLASS__, 'ajax_webdav_test']);
        add_action('wp_ajax_fg_backup_webdav_list', [__CLASS__, 'ajax_webdav_list']);
        add_action('wp_ajax_fg_backup_webdav_delete', [__CLASS__, 'ajax_webdav_delete']);
        add_action('wp_ajax_fg_backup_dropbox_begin_relay', [__CLASS__, 'ajax_dropbox_begin_relay']);
        add_action('wp_ajax_fg_backup_dropbox_begin_manual', [__CLASS__, 'ajax_dropbox_begin_manual']);
        add_action('wp_ajax_fg_backup_dropbox_complete_manual', [__CLASS__, 'ajax_dropbox_complete_manual']);
        add_action('wp_ajax_fg_backup_dropbox_oauth_status', [__CLASS__, 'ajax_dropbox_oauth_status']);
        add_action('wp_ajax_fg_backup_dropbox_disconnect', [__CLASS__, 'ajax_dropbox_disconnect']);
        add_action('wp_ajax_fg_backup_dropbox_test', [__CLASS__, 'ajax_dropbox_test']);
        add_action('wp_ajax_fg_backup_dropbox_list', [__CLASS__, 'ajax_dropbox_list']);
        add_action('wp_ajax_fg_backup_dropbox_delete', [__CLASS__, 'ajax_dropbox_delete']);
        add_action('wp_ajax_fg_backup_s3_test', [__CLASS__, 'ajax_s3_test']);
        add_action('wp_ajax_fg_backup_s3_list', [__CLASS__, 'ajax_s3_list']);
        add_action('wp_ajax_fg_backup_s3_delete', [__CLASS__, 'ajax_s3_delete']);
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
        add_action('admin_post_fg_backup_download', [__CLASS__, 'download']);
        add_action('admin_post_fg_backup_manifest', [__CLASS__, 'download_manifest']);
        add_action('admin_post_fg_backup_delete', [__CLASS__, 'delete']);
        add_action('admin_post_fg_backup_bulk_delete', [__CLASS__, 'bulk_delete']);
    }

    public static function activate() {
        self::run_upgrade();
    }

    public static function deactivate() {
        FgBackup_Cron::deactivate();
        FgBackup_Health::deactivate();
    }

    public static function maybe_upgrade() {
        if (get_option('fg_backup_version') !== FG_BACKUP_VERSION) {
            self::run_upgrade();
        } else {
            self::migrate_remote_preferences();
            self::install_defaults();
            FgBackup_Health::schedule();
        }
    }

    private static function run_upgrade() {
        self::migrate_notification_settings();
        self::migrate_remote_preferences();
        self::install_defaults();
        FgBackup_Storage::ensure();
        FgBackup_Storage::migrate_legacy_backups();
        self::remove_legacy_remote_credentials();
        FgBackup_Cleanup::clean_old_jobs();
        update_option('fg_backup_version', FG_BACKUP_VERSION, false);
        FgBackup_Cron::reschedule();
        FgBackup_Health::schedule();
        FgBackup_Health::refresh_after_job();
    }

    private static function migrate_notification_settings() {
        if (get_option('fg_backup_notification_mode', null) !== null) {
            return;
        }

        $legacy = get_option('fg_backup_notifications', 0) ? 'all' : 'off';
        add_option('fg_backup_notification_mode', $legacy, '', false);
    }

    private static function migrate_remote_preferences() {
        if (get_option('fg_backup_keep_local', null) !== null) {
            return;
        }

        $legacy_options = [
            'fg_backup_sftp_keep_local',
            'fg_backup_webdav_keep_local',
            'fg_backup_dropbox_keep_local',
            'fg_backup_s3_keep_local',
        ];
        $found = false;
        $keep_local = 0;

        foreach ($legacy_options as $option) {
            $value = get_option($option, null);
            if ($value === null) {
                continue;
            }
            $found = true;
            if (!empty($value)) {
                $keep_local = 1;
                break;
            }
        }

        if (!$found) {
            $keep_local = 1;
        }

        add_option('fg_backup_keep_local', $keep_local, '', false);

        foreach ($legacy_options as $option) {
            delete_option($option);
        }
    }

    private static function install_defaults() {
        $defaults = [
            'fg_backup_type' => 'full',
            'fg_backup_archive_format' => 'zip',
            'fg_backup_database_format' => 'gz',
            'fg_backup_filename_pattern' => FgBackup_Backup::default_filename_pattern(),
            'fg_backup_schedule' => 'disabled',
            'fg_backup_hour' => 2,
            'fg_backup_rotation' => 5,
            'fg_backup_notifications' => 0,
            'fg_backup_notification_mode' => 'off',
            'fg_backup_notification_email' => get_option('admin_email'),
            'fg_backup_exclusions' => '',
            'fg_backup_storage_mode' => FgBackup_Storage::MODE_CONTENT,
            'fg_backup_storage_path' => '',
            'fg_backup_keep_local' => 1,
            'fg_backup_sftp_enabled' => 0,
            'fg_backup_sftp_host' => '',
            'fg_backup_sftp_port' => 22,
            'fg_backup_sftp_username' => '',
            'fg_backup_sftp_auth' => 'password',
            'fg_backup_sftp_password' => '',
            'fg_backup_sftp_private_key_path' => '',
            'fg_backup_sftp_key_passphrase' => '',
            'fg_backup_sftp_remote_dir' => '/backups/%host',
            'fg_backup_sftp_retention' => 10,
            'fg_backup_sftp_host_key' => '',
            'fg_backup_sftp_host_key_target' => '',
            'fg_backup_webdav_enabled' => 0,
            'fg_backup_webdav_base_url' => '',
            'fg_backup_webdav_username' => '',
            'fg_backup_webdav_password' => '',
            'fg_backup_webdav_remote_dir' => '/backups/%host',
            'fg_backup_webdav_retention' => 10,
            'fg_backup_webdav_allow_private' => 0,
            'fg_backup_dropbox_enabled' => 0,
            'fg_backup_dropbox_app_key' => '',
            'fg_backup_dropbox_relay_url' => 'https://lizenz.funckgroup-server.com/wp-json/fg-dropbox-relay/v1',
            'fg_backup_dropbox_remote_dir' => '/backups/%host',
            'fg_backup_dropbox_retention' => 10,
            'fg_backup_s3_enabled' => 0,
            'fg_backup_s3_provider' => 'custom',
            'fg_backup_s3_endpoint' => '',
            'fg_backup_s3_region_name' => 'us-east-1',
            'fg_backup_s3_bucket_name' => '',
            'fg_backup_s3_access_key' => '',
            'fg_backup_s3_secret_key' => '',
            'fg_backup_s3_session_token' => '',
            'fg_backup_s3_path_style' => 1,
            'fg_backup_s3_remote_dir' => '/backups/%host',
            'fg_backup_s3_retention' => 10,
            'fg_backup_s3_allow_private' => 0,
            'fg_backup_s3_allow_http' => 0,
        ];

        foreach ($defaults as $name => $value) {
            if (get_option($name, null) === null) {
                add_option($name, $value, '', false);
            }
        }
    }

    private static function remove_legacy_remote_credentials() {
        $legacy_options = [
            'fg_backup_targets',
            'fg_backup_dropbox_token',
            'fg_backup_gdrive_token',
            'fg_backup_s3_key',
            'fg_backup_s3_secret',
            'fg_backup_s3_region',
            'fg_backup_s3_bucket',
            'fg_backup_s3_prefix',
            'fg_backup_s3c_key',
            'fg_backup_s3c_secret',
            'fg_backup_s3c_endpoint',
            'fg_backup_s3c_bucket',
            'fg_backup_s3c_prefix',
            'fg_backup_ftp_host',
            'fg_backup_ftp_port',
            'fg_backup_ftp_user',
            'fg_backup_ftp_pass',
            'fg_backup_ftp_dir',
            'fg_backup_webdav_url',
            'fg_backup_webdav_user',
            'fg_backup_webdav_pass',
            'fg_backup_onedrive_token',
        ];

        foreach ($legacy_options as $option) {
            delete_option($option);
        }
    }

    public static function register_settings() {
        register_setting('fg_backup_settings', 'fg_backup_type', [
            'type' => 'string',
            'sanitize_callback' => [__CLASS__, 'sanitize_type'],
            'default' => 'full',
        ]);
        register_setting('fg_backup_settings', 'fg_backup_archive_format', [
            'type' => 'string',
            'sanitize_callback' => [__CLASS__, 'sanitize_archive_format'],
            'default' => 'zip',
        ]);
        register_setting('fg_backup_settings', 'fg_backup_database_format', [
            'type' => 'string',
            'sanitize_callback' => [__CLASS__, 'sanitize_database_format'],
            'default' => 'gz',
        ]);
        register_setting('fg_backup_settings', 'fg_backup_filename_pattern', [
            'type' => 'string',
            'sanitize_callback' => [__CLASS__, 'sanitize_filename_pattern'],
            'default' => FgBackup_Backup::default_filename_pattern(),
        ]);
        register_setting('fg_backup_settings', 'fg_backup_schedule', [
            'type' => 'string',
            'sanitize_callback' => [__CLASS__, 'sanitize_schedule'],
            'default' => 'disabled',
        ]);
        register_setting('fg_backup_settings', 'fg_backup_hour', [
            'type' => 'integer',
            'sanitize_callback' => [__CLASS__, 'sanitize_hour'],
            'default' => 2,
        ]);
        register_setting('fg_backup_settings', 'fg_backup_rotation', [
            'type' => 'integer',
            'sanitize_callback' => [__CLASS__, 'sanitize_rotation'],
            'default' => 5,
        ]);
        register_setting('fg_backup_settings', 'fg_backup_notifications', [
            'type' => 'boolean',
            'sanitize_callback' => [__CLASS__, 'sanitize_checkbox'],
            'default' => 0,
        ]);
        register_setting('fg_backup_settings', 'fg_backup_notification_mode', [
            'type' => 'string',
            'sanitize_callback' => [__CLASS__, 'sanitize_notification_mode'],
            'default' => 'off',
        ]);
        register_setting('fg_backup_settings', 'fg_backup_notification_email', [
            'type' => 'string',
            'sanitize_callback' => [__CLASS__, 'sanitize_notification_email'],
            'default' => get_option('admin_email'),
        ]);
        register_setting('fg_backup_settings', 'fg_backup_storage_mode', [
            'type' => 'string',
            'sanitize_callback' => [__CLASS__, 'sanitize_storage_mode'],
            'default' => FgBackup_Storage::MODE_CONTENT,
        ]);
        register_setting('fg_backup_settings', 'fg_backup_storage_path', [
            'type' => 'string',
            'sanitize_callback' => [__CLASS__, 'sanitize_storage_path'],
            'default' => '',
        ]);
        register_setting('fg_backup_settings', 'fg_backup_exclusions', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default' => '',
        ]);
        register_setting('fg_backup_settings', 'fg_backup_sftp_enabled', [
            'type' => 'boolean', 'sanitize_callback' => [__CLASS__, 'sanitize_checkbox'], 'default' => 0,
        ]);
        register_setting('fg_backup_settings', 'fg_backup_webdav_enabled', [
            'type' => 'boolean', 'sanitize_callback' => [__CLASS__, 'sanitize_checkbox'], 'default' => 0,
        ]);
        register_setting('fg_backup_settings', 'fg_backup_dropbox_enabled', [
            'type' => 'boolean', 'sanitize_callback' => [__CLASS__, 'sanitize_checkbox'], 'default' => 0,
        ]);
        register_setting('fg_backup_settings', 'fg_backup_s3_enabled', [
            'type' => 'boolean', 'sanitize_callback' => [__CLASS__, 'sanitize_checkbox'], 'default' => 0,
        ]);
        register_setting('fg_backup_settings', 'fg_backup_keep_local', [
            'type' => 'boolean', 'sanitize_callback' => [__CLASS__, 'sanitize_checkbox'], 'default' => 1,
        ]);
        register_setting('fg_backup_sftp_settings', 'fg_backup_sftp_host', [
            'type' => 'string', 'sanitize_callback' => [__CLASS__, 'sanitize_sftp_host'], 'default' => '',
        ]);
        register_setting('fg_backup_sftp_settings', 'fg_backup_sftp_port', [
            'type' => 'integer', 'sanitize_callback' => [__CLASS__, 'sanitize_sftp_port'], 'default' => 22,
        ]);
        register_setting('fg_backup_sftp_settings', 'fg_backup_sftp_username', [
            'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '',
        ]);
        register_setting('fg_backup_sftp_settings', 'fg_backup_sftp_auth', [
            'type' => 'string', 'sanitize_callback' => [__CLASS__, 'sanitize_sftp_auth'], 'default' => 'password',
        ]);
        register_setting('fg_backup_sftp_settings', 'fg_backup_sftp_password', [
            'type' => 'string', 'sanitize_callback' => [__CLASS__, 'sanitize_sftp_password'], 'default' => '',
        ]);
        register_setting('fg_backup_sftp_settings', 'fg_backup_sftp_private_key_path', [
            'type' => 'string', 'sanitize_callback' => [__CLASS__, 'sanitize_private_key_path'], 'default' => '',
        ]);
        register_setting('fg_backup_sftp_settings', 'fg_backup_sftp_key_passphrase', [
            'type' => 'string', 'sanitize_callback' => [__CLASS__, 'sanitize_sftp_passphrase'], 'default' => '',
        ]);
        register_setting('fg_backup_sftp_settings', 'fg_backup_sftp_remote_dir', [
            'type' => 'string', 'sanitize_callback' => ['FgBackup_Sftp', 'sanitize_remote_dir'], 'default' => '/backups/%host',
        ]);
        register_setting('fg_backup_sftp_settings', 'fg_backup_sftp_retention', [
            'type' => 'integer', 'sanitize_callback' => [__CLASS__, 'sanitize_sftp_retention'], 'default' => 10,
        ]);
        register_setting('fg_backup_webdav_settings', 'fg_backup_webdav_base_url', [
            'type' => 'string', 'sanitize_callback' => [__CLASS__, 'sanitize_webdav_url'], 'default' => '',
        ]);
        register_setting('fg_backup_webdav_settings', 'fg_backup_webdav_username', [
            'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '',
        ]);
        register_setting('fg_backup_webdav_settings', 'fg_backup_webdav_password', [
            'type' => 'string', 'sanitize_callback' => [__CLASS__, 'sanitize_webdav_password'], 'default' => '',
        ]);
        register_setting('fg_backup_webdav_settings', 'fg_backup_webdav_remote_dir', [
            'type' => 'string', 'sanitize_callback' => ['FgBackup_Webdav', 'sanitize_remote_dir'], 'default' => '/backups/%host',
        ]);
        register_setting('fg_backup_webdav_settings', 'fg_backup_webdav_retention', [
            'type' => 'integer', 'sanitize_callback' => [__CLASS__, 'sanitize_remote_retention'], 'default' => 10,
        ]);
        register_setting('fg_backup_webdav_settings', 'fg_backup_webdav_allow_private', [
            'type' => 'boolean', 'sanitize_callback' => [__CLASS__, 'sanitize_checkbox'], 'default' => 0,
        ]);
        register_setting('fg_backup_dropbox_settings', 'fg_backup_dropbox_app_key', [
            'type' => 'string', 'sanitize_callback' => [__CLASS__, 'sanitize_dropbox_app_key'], 'default' => '',
        ]);
        register_setting('fg_backup_dropbox_settings', 'fg_backup_dropbox_relay_url', [
            'type' => 'string', 'sanitize_callback' => [__CLASS__, 'sanitize_dropbox_relay_url'], 'default' => 'https://lizenz.funckgroup-server.com/wp-json/fg-dropbox-relay/v1',
        ]);
        register_setting('fg_backup_dropbox_settings', 'fg_backup_dropbox_remote_dir', [
            'type' => 'string', 'sanitize_callback' => ['FgBackup_Dropbox', 'sanitize_remote_dir'], 'default' => '/backups/%host',
        ]);
        register_setting('fg_backup_dropbox_settings', 'fg_backup_dropbox_retention', [
            'type' => 'integer', 'sanitize_callback' => [__CLASS__, 'sanitize_remote_retention'], 'default' => 10,
        ]);
        register_setting('fg_backup_s3_settings', 'fg_backup_s3_provider', [
            'type' => 'string', 'sanitize_callback' => ['FgBackup_S3', 'sanitize_provider'], 'default' => 'custom',
        ]);
        register_setting('fg_backup_s3_settings', 'fg_backup_s3_endpoint', [
            'type' => 'string', 'sanitize_callback' => ['FgBackup_S3', 'sanitize_endpoint'], 'default' => '',
        ]);
        register_setting('fg_backup_s3_settings', 'fg_backup_s3_region_name', [
            'type' => 'string', 'sanitize_callback' => ['FgBackup_S3', 'sanitize_region'], 'default' => 'us-east-1',
        ]);
        register_setting('fg_backup_s3_settings', 'fg_backup_s3_bucket_name', [
            'type' => 'string', 'sanitize_callback' => ['FgBackup_S3', 'sanitize_bucket'], 'default' => '',
        ]);
        register_setting('fg_backup_s3_settings', 'fg_backup_s3_access_key', [
            'type' => 'string', 'sanitize_callback' => [__CLASS__, 'sanitize_s3_access_key'], 'default' => '',
        ]);
        register_setting('fg_backup_s3_settings', 'fg_backup_s3_secret_key', [
            'type' => 'string', 'sanitize_callback' => [__CLASS__, 'sanitize_s3_secret_key'], 'default' => '',
        ]);
        register_setting('fg_backup_s3_settings', 'fg_backup_s3_session_token', [
            'type' => 'string', 'sanitize_callback' => [__CLASS__, 'sanitize_s3_session_token'], 'default' => '',
        ]);
        register_setting('fg_backup_s3_settings', 'fg_backup_s3_path_style', [
            'type' => 'boolean', 'sanitize_callback' => [__CLASS__, 'sanitize_checkbox'], 'default' => 1,
        ]);
        register_setting('fg_backup_s3_settings', 'fg_backup_s3_remote_dir', [
            'type' => 'string', 'sanitize_callback' => ['FgBackup_S3', 'sanitize_remote_dir'], 'default' => '/backups/%host',
        ]);
        register_setting('fg_backup_s3_settings', 'fg_backup_s3_retention', [
            'type' => 'integer', 'sanitize_callback' => [__CLASS__, 'sanitize_remote_retention'], 'default' => 10,
        ]);
        register_setting('fg_backup_s3_settings', 'fg_backup_s3_allow_private', [
            'type' => 'boolean', 'sanitize_callback' => [__CLASS__, 'sanitize_checkbox'], 'default' => 0,
        ]);
        register_setting('fg_backup_s3_settings', 'fg_backup_s3_allow_http', [
            'type' => 'boolean', 'sanitize_callback' => [__CLASS__, 'sanitize_checkbox'], 'default' => 0,
        ]);
    }

    public static function sanitize_type($value) {
        return in_array($value, ['full', 'db'], true) ? $value : 'full';
    }

    public static function sanitize_archive_format($value) {
        return in_array($value, ['zip', 'tgz'], true) ? $value : 'zip';
    }

    public static function sanitize_database_format($value) {
        return in_array($value, ['sql', 'gz', 'zip'], true) ? $value : 'gz';
    }

    public static function sanitize_filename_pattern($value) {
        return FgBackup_Backup::sanitize_filename_pattern($value);
    }

    public static function sanitize_storage_mode($value) {
        $mode = FgBackup_Storage::sanitize_mode_value((string) $value);

        if ($mode === FgBackup_Storage::MODE_CUSTOM) {
            $raw_path = isset($_POST['fg_backup_storage_path'])
                ? wp_unslash((string) $_POST['fg_backup_storage_path'])
                : (string) get_option('fg_backup_storage_path', '');
            $path = FgBackup_Storage::normalize_base_path($raw_path);

            if ($path === '') {
                add_settings_error(
                    'fg_backup_settings',
                    'fg_backup_storage_path_invalid',
                    __('Bitte für den benutzerdefinierten Speicherort einen gültigen absoluten lokalen Pfad angeben.', 'fg-backup-pro'),
                    'error'
                );
                return FgBackup_Storage::sanitize_mode_value((string) get_option('fg_backup_storage_mode', FgBackup_Storage::MODE_CONTENT));
            }

            try {
                FgBackup_Storage::test_configuration($mode, $path);
            } catch (Throwable $exception) {
                add_settings_error('fg_backup_settings', 'fg_backup_storage_test_failed', $exception->getMessage(), 'error');
                return FgBackup_Storage::sanitize_mode_value((string) get_option('fg_backup_storage_mode', FgBackup_Storage::MODE_CONTENT));
            }
        }

        FgBackup_Storage::reset_cache();
        return $mode;
    }

    public static function sanitize_storage_path($value) {
        $value = trim((string) $value);
        if ($value === '') {
            FgBackup_Storage::reset_cache();
            return '';
        }

        $path = FgBackup_Storage::normalize_base_path($value);
        if ($path === '') {
            add_settings_error(
                'fg_backup_settings',
                'fg_backup_storage_path_invalid',
                __('Der lokale Speicherpfad muss ein absoluter Dateisystempfad ohne URL- oder Stream-Protokoll sein.', 'fg-backup-pro'),
                'error'
            );
            return (string) get_option('fg_backup_storage_path', '');
        }

        FgBackup_Storage::reset_cache();
        return untrailingslashit($path);
    }

    public static function sanitize_schedule($value) {
        return in_array($value, ['disabled', 'daily', 'weekly', 'monthly'], true) ? $value : 'disabled';
    }

    public static function sanitize_hour($value) {
        return max(0, min(23, (int) $value));
    }

    public static function sanitize_rotation($value) {
        $allowed = [3, 5, 10, 20];
        $value = (int) $value;
        return in_array($value, $allowed, true) ? $value : 5;
    }

    public static function sanitize_notification_mode($value) {
        return in_array($value, ['off', 'errors', 'all'], true) ? $value : 'off';
    }

    public static function sanitize_notification_email($value) {
        $email = sanitize_email((string) $value);
        if ($email === '') {
            add_settings_error('fg_backup_settings', 'fg_backup_email_invalid', __('Bitte eine gültige E-Mail-Adresse eingeben.', 'fg-backup-pro'), 'error');
            return sanitize_email((string) get_option('fg_backup_notification_email', get_option('admin_email')));
        }
        return $email;
    }


    public static function sanitize_sftp_host($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $probe = strpos($value, '://') === false ? 'sftp://' . $value : $value;
        $host = wp_parse_url($probe, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            $host = preg_replace('/^.*@/', '', $value);
            $host = preg_replace('#[/:].*$#', '', (string) $host);
        }

        return trim(sanitize_text_field((string) $host), '[]');
    }

    public static function sanitize_sftp_port($value) {
        return max(1, min(65535, (int) $value));
    }

    public static function sanitize_sftp_auth($value) {
        return $value === 'key' ? 'key' : 'password';
    }

    public static function sanitize_private_key_path($value) {
        return trim(wp_normalize_path((string) $value));
    }

    public static function sanitize_sftp_retention($value) {
        return self::sanitize_remote_retention($value);
    }

    public static function sanitize_remote_retention($value) {
        return max(1, min(100, (int) $value));
    }

    public static function sanitize_sftp_password($value) {
        return self::sanitize_secret_option($value, 'fg_backup_sftp_password', 'fg_backup_sftp_settings');
    }

    public static function sanitize_sftp_passphrase($value) {
        return self::sanitize_secret_option($value, 'fg_backup_sftp_key_passphrase', 'fg_backup_sftp_settings');
    }

    public static function sanitize_webdav_url($value) {
        return FgBackup_Webdav::sanitize_base_url($value);
    }

    public static function sanitize_webdav_password($value) {
        return self::sanitize_secret_option($value, 'fg_backup_webdav_password', 'fg_backup_webdav_settings');
    }

    public static function sanitize_dropbox_app_key($value) {
        return preg_replace('/[^A-Za-z0-9_-]+/', '', (string) $value);
    }

    public static function sanitize_dropbox_relay_url($value) {
        $value = rtrim(esc_url_raw(trim((string) $value), ['https']), '/');
        return $value;
    }


    public static function sanitize_s3_access_key($value) {
        return self::sanitize_secret_option($value, 'fg_backup_s3_access_key', 'fg_backup_s3_settings');
    }

    public static function sanitize_s3_secret_key($value) {
        return self::sanitize_secret_option($value, 'fg_backup_s3_secret_key', 'fg_backup_s3_settings');
    }

    public static function sanitize_s3_session_token($value) {
        return self::sanitize_secret_option($value, 'fg_backup_s3_session_token', 'fg_backup_s3_settings');
    }

    private static function sanitize_secret_option($value, $option, $settings_group) {
        $value = (string) $value;
        if ($value === '') {
            return (string) get_option($option, '');
        }
        try {
            return FgBackup_Secrets::encrypt($value);
        } catch (Throwable $exception) {
            add_settings_error($settings_group, 'fg_backup_secret_error', $exception->getMessage(), 'error');
            return (string) get_option($option, '');
        }
    }

    public static function sanitize_note($value) {
        $value = preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $value));
        $value = trim((string) $value);

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, 160);
        }

        return substr($value, 0, 160);
    }

    public static function sanitize_checkbox($value) {
        return empty($value) ? 0 : 1;
    }

    public static function render_settings_notices($settings_group) {
        if (!current_user_can('manage_options')) {
            return;
        }

        $messages = get_settings_errors((string) $settings_group);
        $has_error = false;
        $has_success = false;

        foreach ($messages as $message) {
            $type = isset($message['type']) ? (string) $message['type'] : 'error';
            if ($type === 'error') {
                $has_error = true;
            }
            if (in_array($type, ['success', 'updated'], true)) {
                $has_success = true;
            }
        }

        settings_errors((string) $settings_group);

        $settings_updated = isset($_GET['settings-updated'])
            && sanitize_text_field(wp_unslash((string) $_GET['settings-updated'])) === 'true';

        if ($settings_updated && !$has_error && !$has_success) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__('Einstellungen gespeichert.', 'fg-backup-pro')
                . '</p></div>';
        }
    }

    public static function enqueue_assets() {
        if (!is_admin()) {
            return;
        }

        $is_plugin_page = !empty($_GET['page']) && sanitize_key(wp_unslash($_GET['page'])) === 'fg-backup-pro';
        $active_job = FgBackup_Async::get_active_job();

        if (is_array($active_job) && !$is_plugin_page) {
            self::enqueue_admin_bar_assets($active_job);
        }

        if (!$is_plugin_page) {
            return;
        }

        wp_enqueue_style('fg-backup-pro', FG_BACKUP_URL . 'assets/style.css', [], FG_BACKUP_VERSION);
        wp_enqueue_script('fg-backup-pro', FG_BACKUP_URL . 'assets/script.js', ['jquery'], FG_BACKUP_VERSION, true);

        $preview_timestamp = time();
        $preview_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        $preview_host = preg_replace('/^www\./i', '', $preview_host);
        $preview_site = (string) get_bloginfo('name');

        wp_localize_script('fg-backup-pro', 'fgBackupPro', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('fg_backup_ajax'),
            'failedText' => __('Backup fehlgeschlagen.', 'fg-backup-pro'),
            'cancelConfirmText' => __('Laufendes Backup wirklich abbrechen?', 'fg-backup-pro'),
            'sftpTestText' => __('SFTP-Verbindung wird getestet …', 'fg-backup-pro'),
            'sftpResetConfirmText' => __('Gespeicherten SFTP-Serverschlüssel wirklich zurücksetzen?', 'fg-backup-pro'),
            'sftpListLoadingText' => __('Remote-Dateien werden geladen …', 'fg-backup-pro'),
            'sftpListEmptyText' => __('Keine Remote-Backups gefunden.', 'fg-backup-pro'),
            'sftpDeleteConfirmText' => __('Remote-Backup wirklich löschen?', 'fg-backup-pro'),
            'sftpDeleteText' => __('Löschen', 'fg-backup-pro'),
            'webdavTestText' => __('WebDAV-Verbindung wird getestet …', 'fg-backup-pro'),
            'dropboxTestText' => __('Dropbox-Verbindung wird getestet …', 'fg-backup-pro'),
            's3TestText' => __('S3-Verbindung wird getestet …', 'fg-backup-pro'),
            'storageTestText' => __('Lokaler Speicherort wird geprüft …', 'fg-backup-pro'),
            'storageTestFailedText' => __('Der lokale Speicherort konnte nicht geprüft werden.', 'fg-backup-pro'),
            'remoteListLoadingText' => __('Remote-Dateien werden geladen …', 'fg-backup-pro'),
            'remoteListEmptyText' => __('Keine Remote-Backups gefunden.', 'fg-backup-pro'),
            'remoteDeleteConfirmText' => __('Remote-Backup wirklich löschen?', 'fg-backup-pro'),
            'remoteDeleteText' => __('Löschen', 'fg-backup-pro'),
            'dropboxConnectingText' => __('Dropbox-Verbindung wird vorbereitet …', 'fg-backup-pro'),
            'dropboxWaitingText' => __('Warte auf die Dropbox-Freigabe …', 'fg-backup-pro'),
            'dropboxDisconnectConfirmText' => __('Dropbox-Verbindung wirklich trennen?', 'fg-backup-pro'),
            'spaceFullText' => __('Vollständiges Backup: mindestens %1$s temporärer Speicher; nach dem Dateiscan erfolgt eine genauere Prüfung.', 'fg-backup-pro'),
            'spaceDbText' => __('Datenbank-Backup: voraussichtlich %1$s temporärer Speicher.', 'fg-backup-pro'),
            'spaceAvailableText' => __('Frei: %s', 'fg-backup-pro'),
            'localDeletedText' => __('Lokale Datei nach erfolgreichen Remote-Uploads gelöscht.', 'fg-backup-pro'),
            'healthCheckingText' => __('Backup-Status wird geprüft …', 'fg-backup-pro'),
            'healthCheckedText' => __('Backup-Status wurde aktualisiert.', 'fg-backup-pro'),
            'bulkDeleteConfirmText' => __('Ausgewählte Backups wirklich löschen?', 'fg-backup-pro'),
            'bulkDeleteNoneText' => __('Bitte mindestens ein Backup auswählen.', 'fg-backup-pro'),
            'bulkSelectedText' => __('%d ausgewählt', 'fg-backup-pro'),
            'validateText' => __('Backup wird vollständig validiert …', 'fg-backup-pro'),
            'validateDoneText' => __('Validierung abgeschlossen.', 'fg-backup-pro'),
            'bulkValidateNoneText' => __('Bitte mindestens ein Backup für die Validierung auswählen.', 'fg-backup-pro'),
            'reportLoadingText' => __('Prüfbericht wird geladen …', 'fg-backup-pro'),
            'activeJobId' => is_array($active_job) && !empty($active_job['id']) ? $active_job['id'] : '',
            'pageUrl' => admin_url('admin.php?page=fg-backup-pro'),
            'filenamePreview' => [
                'defaultPattern' => FgBackup_Backup::default_filename_pattern(),
                'host' => sanitize_title($preview_host !== '' ? $preview_host : 'wordpress'),
                'site' => sanitize_title($preview_site !== '' ? $preview_site : 'wordpress'),
                'id' => 'demo1234',
                'date' => [
                    'Y' => wp_date('Y', $preview_timestamp),
                    'y' => wp_date('y', $preview_timestamp),
                    'm' => wp_date('m', $preview_timestamp),
                    'd' => wp_date('d', $preview_timestamp),
                    'H' => wp_date('H', $preview_timestamp),
                    'M' => wp_date('i', $preview_timestamp),
                    'S' => wp_date('s', $preview_timestamp),
                ],
            ],
        ]);
    }

    public static function enqueue_front_admin_bar_assets() {
        if (!is_admin_bar_showing() || !current_user_can('manage_options')) {
            return;
        }

        $active_job = FgBackup_Async::get_active_job();
        if (is_array($active_job)) {
            self::enqueue_admin_bar_assets($active_job);
        }
    }

    private static function enqueue_admin_bar_assets(array $active_job) {
        wp_enqueue_script('fg-backup-admin-bar', FG_BACKUP_URL . 'assets/admin-bar.js', ['jquery'], FG_BACKUP_VERSION, true);
        wp_localize_script('fg-backup-admin-bar', 'fgBackupAdminBar', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('fg_backup_ajax'),
            'jobId' => !empty($active_job['id']) ? sanitize_key($active_job['id']) : '',
            'pageUrl' => admin_url('admin.php?page=fg-backup-pro'),
        ]);
    }

    public static function admin_bar_status($wp_admin_bar) {
        if (!is_admin_bar_showing() || !current_user_can('manage_options')) {
            return;
        }

        $job = FgBackup_Async::get_active_job();
        if (!is_array($job)) {
            $report = FgBackup_Health::get_report(true);
            $health_status = isset($report['status']) ? $report['status'] : 'unknown';
            if (!in_array($health_status, ['warning', 'critical'], true)) {
                return;
            }

            $wp_admin_bar->add_node([
                'id' => 'fg-backup-pro-health',
                'title' => esc_html__('FG Backup Pro: Prüfung nötig', 'fg-backup-pro'),
                'href' => admin_url('admin.php?page=fg-backup-pro&tab=backups#fg-backup-health'),
                'meta' => [
                    'title' => !empty($report['summary']) ? sanitize_text_field($report['summary']) : __('Backup-Status prüfen', 'fg-backup-pro'),
                ],
            ]);
            return;
        }

        $status = isset($job['status']) ? $job['status'] : '';
        $progress = isset($job['progress']) ? max(0, min(100, (int) $job['progress'])) : 0;

        if ($status === 'cancel_requested') {
            $title = __('FG Backup Pro: Abbruch …', 'fg-backup-pro');
        } elseif ($status === 'queued') {
            $title = __('FG Backup Pro: Startet …', 'fg-backup-pro');
        } else {
            $title = sprintf(__('FG Backup Pro: %d %%', 'fg-backup-pro'), $progress);
        }

        $wp_admin_bar->add_node([
            'id' => 'fg-backup-pro-status',
            'title' => esc_html($title),
            'href' => admin_url('admin.php?page=fg-backup-pro'),
            'meta' => [
                'title' => !empty($job['stage']) ? sanitize_text_field($job['stage']) : __('Backup läuft', 'fg-backup-pro'),
            ],
        ]);
    }

    public static function render_main_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $backups = FgBackup_Backup::list_backups();
        $history = get_option('fg_backup_history', []);
        $active_job = FgBackup_Async::get_active_job();
        $full_space = FgBackup_Backup::estimate_initial_space('full', get_option('fg_backup_archive_format', 'zip'));
        $db_space = FgBackup_Backup::estimate_initial_space('db', get_option('fg_backup_database_format', 'gz'));
        $health = FgBackup_Health::get_report(true);
        include FG_BACKUP_DIR . 'views/admin-main.php';
    }

    public static function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        include FG_BACKUP_DIR . 'views/admin-settings.php';
    }

    public static function render_sftp_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $sftp_settings = FgBackup_Sftp::settings();
        include FG_BACKUP_DIR . 'views/admin-sftp.php';
    }

    public static function render_webdav_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $webdav_settings = FgBackup_Webdav::settings();
        include FG_BACKUP_DIR . 'views/admin-webdav.php';
    }

    public static function render_dropbox_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $dropbox_settings = FgBackup_Dropbox::settings();
        $dropbox_account = FgBackup_Dropbox::account();
        include FG_BACKUP_DIR . 'views/admin-dropbox.php';
    }


    public static function render_s3_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $s3_settings = FgBackup_S3::settings();
        include FG_BACKUP_DIR . 'views/admin-s3.php';
    }

    public static function register_rest_routes() {
        register_rest_route('fg-backup-pro/v1', '/dropbox/callback', [
            'methods' => 'POST',
            'callback' => ['FgBackup_Dropbox', 'rest_callback'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function ajax_storage_test() {
        self::assert_ajax_admin();

        $mode = isset($_POST['mode']) ? sanitize_key(wp_unslash((string) $_POST['mode'])) : FgBackup_Storage::MODE_CONTENT;
        $path = isset($_POST['path']) ? wp_unslash((string) $_POST['path']) : '';

        try {
            $result = FgBackup_Storage::test_configuration($mode, $path);
            $free = $result['free_bytes'] !== null ? size_format($result['free_bytes'], 2) : __('unbekannt', 'fg-backup-pro');
            $message = sprintf(
                __('Speicherort ist nutzbar. Frei: %1$s · Außerhalb des Webroots: %2$s', 'fg-backup-pro'),
                $free,
                !empty($result['outside_webroot']) ? __('Ja', 'fg-backup-pro') : __('Nein', 'fg-backup-pro')
            );
            if (!empty($result['fallback']) && !empty($result['fallback_reason'])) {
                $message = $result['fallback_reason'] . ' ' . $message;
            }

            wp_send_json_success([
                'message' => $message,
                'path' => $result['path'],
                'free' => $free,
                'outside_webroot' => !empty($result['outside_webroot']),
                'fallback' => !empty($result['fallback']),
            ]);
        } catch (Throwable $exception) {
            wp_send_json_error(['message' => $exception->getMessage()]);
        }
    }

    public static function ajax_sftp_test() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Keine Berechtigung.', 'fg-backup-pro')], 403);
        }
        check_ajax_referer('fg_backup_ajax', 'security');
        try {
            $result = FgBackup_Sftp::test_and_pin();
            wp_send_json_success([
                'message' => sprintf(__('Verbindung erfolgreich. Ziel: %s', 'fg-backup-pro'), $result['directory']),
                'fingerprint' => $result['fingerprint'],
                'target' => $result['target'],
            ]);
        } catch (Throwable $exception) {
            wp_send_json_error(['message' => $exception->getMessage()]);
        }
    }

    public static function ajax_sftp_reset_key() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Keine Berechtigung.', 'fg-backup-pro')], 403);
        }
        check_ajax_referer('fg_backup_ajax', 'security');
        delete_option('fg_backup_sftp_host_key');
        delete_option('fg_backup_sftp_host_key_target');
        wp_send_json_success(['message' => __('Gespeicherter Serverschlüssel wurde zurückgesetzt.', 'fg-backup-pro')]);
    }


    public static function ajax_sftp_list() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Keine Berechtigung.', 'fg-backup-pro')], 403);
        }
        check_ajax_referer('fg_backup_ajax', 'security');

        try {
            wp_send_json_success([
                'files' => FgBackup_Sftp::list_backups(),
                'directory' => FgBackup_Sftp::resolved_remote_dir(),
            ]);
        } catch (Throwable $exception) {
            wp_send_json_error(['message' => $exception->getMessage()]);
        }
    }

    public static function ajax_sftp_delete() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Keine Berechtigung.', 'fg-backup-pro')], 403);
        }
        check_ajax_referer('fg_backup_ajax', 'security');

        $file = isset($_POST['file']) ? sanitize_text_field(wp_unslash($_POST['file'])) : '';
        try {
            FgBackup_Sftp::delete_backup($file);
            wp_send_json_success(['message' => __('Remote-Backup wurde gelöscht.', 'fg-backup-pro')]);
        } catch (Throwable $exception) {
            wp_send_json_error(['message' => $exception->getMessage()]);
        }
    }

    public static function ajax_webdav_test() {
        self::assert_ajax_admin();
        try {
            $result = FgBackup_Webdav::test_connection();
            wp_send_json_success([
                'message' => sprintf(__('Verbindung erfolgreich. Ziel: %s', 'fg-backup-pro'), $result['directory']),
            ]);
        } catch (Throwable $exception) {
            wp_send_json_error(['message' => $exception->getMessage()]);
        }
    }

    public static function ajax_webdav_list() {
        self::assert_ajax_admin();
        try {
            wp_send_json_success([
                'files' => FgBackup_Webdav::list_backups(),
                'directory' => FgBackup_Webdav::resolved_remote_dir(),
            ]);
        } catch (Throwable $exception) {
            wp_send_json_error(['message' => $exception->getMessage()]);
        }
    }

    public static function ajax_webdav_delete() {
        self::assert_ajax_admin();
        $file = isset($_POST['file']) ? sanitize_text_field(wp_unslash($_POST['file'])) : '';
        try {
            FgBackup_Webdav::delete_backup($file);
            wp_send_json_success(['message' => __('Remote-Backup wurde gelöscht.', 'fg-backup-pro')]);
        } catch (Throwable $exception) {
            wp_send_json_error(['message' => $exception->getMessage()]);
        }
    }

    public static function ajax_dropbox_begin_relay() {
        self::assert_ajax_admin();
        try {
            wp_send_json_success(FgBackup_Dropbox::begin_relay_oauth());
        } catch (Throwable $exception) {
            wp_send_json_error(['message' => $exception->getMessage()]);
        }
    }

    public static function ajax_dropbox_begin_manual() {
        self::assert_ajax_admin();
        try {
            wp_send_json_success(FgBackup_Dropbox::begin_manual_oauth());
        } catch (Throwable $exception) {
            wp_send_json_error(['message' => $exception->getMessage()]);
        }
    }

    public static function ajax_dropbox_complete_manual() {
        self::assert_ajax_admin();
        $code = isset($_POST['code']) ? sanitize_text_field(wp_unslash($_POST['code'])) : '';
        try {
            wp_send_json_success([
                'account' => FgBackup_Dropbox::complete_manual_oauth($code),
                'message' => __('Dropbox wurde verbunden.', 'fg-backup-pro'),
            ]);
        } catch (Throwable $exception) {
            wp_send_json_error(['message' => $exception->getMessage()]);
        }
    }

    public static function ajax_dropbox_oauth_status() {
        self::assert_ajax_admin();
        wp_send_json_success(FgBackup_Dropbox::oauth_status());
    }

    public static function ajax_dropbox_disconnect() {
        self::assert_ajax_admin();
        FgBackup_Dropbox::disconnect();
        wp_send_json_success(['message' => __('Dropbox-Verbindung wurde getrennt.', 'fg-backup-pro')]);
    }

    public static function ajax_dropbox_test() {
        self::assert_ajax_admin();
        try {
            $result = FgBackup_Dropbox::test_connection();
            $account = !empty($result['account']['name']) ? $result['account']['name'] : $result['account']['email'];
            wp_send_json_success([
                'message' => sprintf(__('Verbindung erfolgreich. Konto: %1$s · Ziel: %2$s', 'fg-backup-pro'), $account, $result['directory']),
            ]);
        } catch (Throwable $exception) {
            wp_send_json_error(['message' => $exception->getMessage()]);
        }
    }

    public static function ajax_dropbox_list() {
        self::assert_ajax_admin();
        try {
            wp_send_json_success([
                'files' => FgBackup_Dropbox::list_backups(),
                'directory' => FgBackup_Dropbox::resolved_remote_dir(),
            ]);
        } catch (Throwable $exception) {
            wp_send_json_error(['message' => $exception->getMessage()]);
        }
    }

    public static function ajax_dropbox_delete() {
        self::assert_ajax_admin();
        $file = isset($_POST['file']) ? sanitize_text_field(wp_unslash($_POST['file'])) : '';
        try {
            FgBackup_Dropbox::delete_backup($file);
            wp_send_json_success(['message' => __('Remote-Backup wurde gelöscht.', 'fg-backup-pro')]);
        } catch (Throwable $exception) {
            wp_send_json_error(['message' => $exception->getMessage()]);
        }
    }


    public static function ajax_s3_test() {
        self::assert_ajax_admin();
        try {
            $result = FgBackup_S3::test_connection();
            wp_send_json_success([
                'message' => sprintf(
                    __('Verbindung erfolgreich. Bucket: %1$s · Ziel: %2$s', 'fg-backup-pro'),
                    $result['bucket'],
                    $result['directory']
                ),
            ]);
        } catch (Throwable $exception) {
            wp_send_json_error(['message' => $exception->getMessage()]);
        }
    }

    public static function ajax_s3_list() {
        self::assert_ajax_admin();
        try {
            wp_send_json_success([
                'files' => FgBackup_S3::list_backups(),
                'directory' => 's3://' . FgBackup_S3::settings()['bucket'] . '/' . FgBackup_S3::resolved_remote_dir(),
            ]);
        } catch (Throwable $exception) {
            wp_send_json_error(['message' => $exception->getMessage()]);
        }
    }

    public static function ajax_s3_delete() {
        self::assert_ajax_admin();
        $file = isset($_POST['file']) ? sanitize_text_field(wp_unslash($_POST['file'])) : '';
        try {
            FgBackup_S3::delete_backup($file);
            wp_send_json_success(['message' => __('Remote-Backup wurde gelöscht.', 'fg-backup-pro')]);
        } catch (Throwable $exception) {
            wp_send_json_error(['message' => $exception->getMessage()]);
        }
    }

    private static function assert_ajax_admin() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Keine Berechtigung.', 'fg-backup-pro')], 403);
        }
        check_ajax_referer('fg_backup_ajax', 'security');
    }

    public static function ajax_health_check() {
        self::assert_ajax_admin();
        $report = FgBackup_Health::run(true, false);
        $checks = [];
        foreach ((array) (isset($report['checks']) ? $report['checks'] : []) as $key => $check) {
            $status = isset($check['status']) ? sanitize_key($check['status']) : 'unknown';
            $checks[sanitize_key((string) $key)] = [
                'status' => $status,
                'status_label' => FgBackup_Health::status_label($status),
                'label' => isset($check['label']) ? sanitize_text_field($check['label']) : '',
                'detail' => isset($check['detail']) ? sanitize_text_field($check['detail']) : '',
            ];
        }

        wp_send_json_success([
            'message' => __('Backup-Status wurde aktualisiert.', 'fg-backup-pro'),
            'status' => isset($report['status']) ? sanitize_key($report['status']) : 'unknown',
            'status_label' => FgBackup_Health::status_label(isset($report['status']) ? $report['status'] : 'unknown'),
            'summary' => isset($report['summary']) ? sanitize_text_field($report['summary']) : '',
            'generated' => !empty($report['generated_at']) ? wp_date('d.m.Y H:i', (int) $report['generated_at']) : '',
            'checks' => $checks,
        ]);
    }

    public static function ajax_validate() {
        self::assert_ajax_admin();
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $raw_files = [];
        if (isset($_POST['backups']) && is_array($_POST['backups'])) {
            $raw_files = wp_unslash($_POST['backups']);
        } elseif (isset($_POST['file'])) {
            $raw_files = [wp_unslash($_POST['file'])];
        }

        $files = [];
        foreach (array_slice($raw_files, 0, 20) as $raw_file) {
            $file = FgBackup_Backup::normalize_backup_filename((string) $raw_file);
            if ($file !== '') {
                $files[$file] = $file;
            }
        }
        if (!$files) {
            wp_send_json_error(['message' => __('Bitte mindestens ein gültiges Backup auswählen.', 'fg-backup-pro')]);
        }

        $items = [];
        foreach ($files as $file) {
            $info = FgBackup_Backup::get_backup_info($file);
            if (!$info || empty($info['path'])) {
                $items[] = [
                    'file' => $file,
                    'status' => 'invalid',
                    'status_label' => FgBackup_Validator::status_label('invalid'),
                    'error' => __('Backup nicht gefunden.', 'fg-backup-pro'),
                ];
                continue;
            }

            try {
                $manifest = FgBackup_Validator::validate_and_write(
                    $info['path'],
                    $info['type'],
                    $info['format'],
                    [
                        'started_at' => !empty($info['mtime']) ? (int) $info['mtime'] : time(),
                        'note' => !empty($info['note']) ? (string) $info['note'] : '',
                    ]
                );
                FgBackup_Backup::update_history_validation($file, $manifest);
                $status = !empty($manifest['validation']['status']) ? sanitize_key((string) $manifest['validation']['status']) : 'invalid';
                $items[] = [
                    'file' => $file,
                    'status' => $status,
                    'status_label' => FgBackup_Validator::status_label($status),
                    'validated_at' => !empty($manifest['validation']['validated_at']) ? wp_date('d.m.Y H:i', (int) strtotime((string) $manifest['validation']['validated_at'])) : '',
                    'manifest_exists' => true,
                    'checks' => isset($manifest['validation']['checks']) ? array_values((array) $manifest['validation']['checks']) : [],
                    'error' => '',
                ];
            } catch (Throwable $exception) {
                $items[] = [
                    'file' => $file,
                    'status' => 'invalid',
                    'status_label' => FgBackup_Validator::status_label('invalid'),
                    'error' => sanitize_text_field($exception->getMessage()),
                ];
            }
        }

        FgBackup_Health::refresh_after_job();
        wp_send_json_success([
            'message' => __('Validierung abgeschlossen.', 'fg-backup-pro'),
            'items' => $items,
        ]);
    }

    public static function ajax_validation_report() {
        self::assert_ajax_admin();
        $file = isset($_POST['file']) ? FgBackup_Backup::normalize_backup_filename(wp_unslash($_POST['file'])) : '';
        if ($file === '') {
            wp_send_json_error(['message' => __('Ungültiger Backup-Dateiname.', 'fg-backup-pro')]);
        }
        $path = FgBackup_Backup::get_backup_path($file);
        if (!$path) {
            wp_send_json_error(['message' => __('Backup nicht gefunden.', 'fg-backup-pro')]);
        }
        $manifest = FgBackup_Validator::read_manifest_for_backup($path);
        if (!$manifest) {
            wp_send_json_error(['message' => __('Für dieses Backup existiert noch kein Prüfbericht. Bitte zuerst validieren.', 'fg-backup-pro')]);
        }
        wp_send_json_success([
            'file' => $file,
            'manifest' => $manifest,
            'status_label' => FgBackup_Validator::status_label(!empty($manifest['validation']['status']) ? $manifest['validation']['status'] : 'unverified'),
        ]);
    }

    public static function ajax_start() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Keine Berechtigung.', 'fg-backup-pro')], 403);
        }
        check_ajax_referer('fg_backup_ajax', 'security');

        $type = isset($_POST['backup_type']) ? self::sanitize_type(wp_unslash($_POST['backup_type'])) : 'full';
        $note = isset($_POST['backup_note']) ? self::sanitize_note(wp_unslash($_POST['backup_note'])) : '';
        $job_id = FgBackup_Async::queue_backup($type, 'manual', '', $note);

        if (is_wp_error($job_id)) {
            wp_send_json_error(['message' => $job_id->get_error_message()]);
        }

        wp_send_json_success(['job_id' => $job_id]);
    }

    public static function ajax_status() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Keine Berechtigung.', 'fg-backup-pro')], 403);
        }
        check_ajax_referer('fg_backup_ajax', 'security');

        $job_id = isset($_POST['job_id']) ? sanitize_key(wp_unslash($_POST['job_id'])) : '';
        $job = FgBackup_Async::get_status($job_id);

        if (!is_array($job)) {
            wp_send_json_error(['message' => __('Backup-Job nicht gefunden.', 'fg-backup-pro')]);
        }

        wp_send_json_success([
            'status' => isset($job['status']) ? $job['status'] : '',
            'progress' => isset($job['progress']) ? (int) $job['progress'] : 0,
            'stage' => isset($job['stage']) ? $job['stage'] : '',
            'detail' => isset($job['detail']) ? $job['detail'] : '',
            'error' => isset($job['error']) ? $job['error'] : '',
            'file' => isset($job['file']) ? $job['file'] : '',
            'size' => !empty($job['size']) ? size_format((int) $job['size'], 2) : '',
            'started_at' => isset($job['started_at']) ? (int) $job['started_at'] : 0,
            'finished_at' => isset($job['finished_at']) ? (int) $job['finished_at'] : 0,
            'remote_status' => isset($job['remote_status']) ? $job['remote_status'] : 'disabled',
            'remote_path' => isset($job['remote_path']) ? $job['remote_path'] : '',
            'remote_results' => isset($job['remote_results']) ? (array) $job['remote_results'] : [],
            'remote_summary' => FgBackup_Remotes::summarize(isset($job['remote_results']) ? (array) $job['remote_results'] : []),
            'local_deleted' => !empty($job['local_deleted']),
            'validation_status' => isset($job['validation_status']) ? $job['validation_status'] : 'unverified',
            'manifest_file' => isset($job['manifest_file_name']) ? $job['manifest_file_name'] : '',
        ]);
    }

    public static function ajax_cancel() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Keine Berechtigung.', 'fg-backup-pro')], 403);
        }
        check_ajax_referer('fg_backup_ajax', 'security');

        $job_id = isset($_POST['job_id']) ? sanitize_key(wp_unslash($_POST['job_id'])) : '';
        $job = FgBackup_Async::request_cancel($job_id);

        if (is_wp_error($job)) {
            wp_send_json_error(['message' => $job->get_error_message()]);
        }

        wp_send_json_success([
            'status' => $job['status'],
            'stage' => $job['stage'],
            'detail' => $job['detail'],
            'progress' => isset($job['progress']) ? (int) $job['progress'] : 0,
        ]);
    }

    public static function download() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Keine Berechtigung.', 'fg-backup-pro'), 403);
        }

        $file = isset($_GET['file']) ? FgBackup_Backup::normalize_backup_filename(wp_unslash($_GET['file'])) : '';
        if ($file === '') {
            wp_die(esc_html__('Ungültiger Backup-Dateiname.', 'fg-backup-pro'), 400);
        }
        check_admin_referer('fg_backup_download_' . $file);
        $path = FgBackup_Backup::get_backup_path($file);

        if (!$path) {
            wp_die(esc_html__('Backup nicht gefunden.', 'fg-backup-pro'), 404);
        }

        while (ob_get_level()) {
            ob_end_clean();
        }

        nocache_headers();
        header('Content-Type: application/octet-stream');
        $download_name = str_replace(["\r", "\n", '"'], '', basename($path));
        header('Content-Disposition: attachment; filename="' . $download_name . '"; filename*=UTF-8\'\'' . rawurlencode($download_name));
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');

        $handle = fopen($path, 'rb');
        if ($handle) {
            fpassthru($handle);
            fclose($handle);
        }
        exit;
    }

    public static function download_manifest() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Keine Berechtigung.', 'fg-backup-pro'), 403);
        }

        $file = isset($_GET['file']) ? FgBackup_Backup::normalize_backup_filename(wp_unslash($_GET['file'])) : '';
        if ($file === '') {
            wp_die(esc_html__('Ungültiger Backup-Dateiname.', 'fg-backup-pro'), 400);
        }
        check_admin_referer('fg_backup_manifest_' . $file);
        $backup_path = FgBackup_Backup::get_backup_path($file);
        $path = $backup_path ? FgBackup_Validator::sidecar_path($backup_path) : '';
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            wp_die(esc_html__('JSON-Manifest nicht gefunden.', 'fg-backup-pro'), 404);
        }

        while (ob_get_level()) {
            ob_end_clean();
        }
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . str_replace(["\r", "\n", '"'], '', basename($path)) . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    public static function delete() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Keine Berechtigung.', 'fg-backup-pro'), 403);
        }

        $file = isset($_GET['file']) ? FgBackup_Backup::normalize_backup_filename(wp_unslash($_GET['file'])) : '';
        if ($file === '') {
            wp_die(esc_html__('Ungültiger Backup-Dateiname.', 'fg-backup-pro'), 400);
        }
        check_admin_referer('fg_backup_delete_' . $file);
        $deleted = FgBackup_Backup::delete_backup($file) ? 1 : 0;
        FgBackup_Health::refresh_after_job();

        wp_safe_redirect(add_query_arg([
            'page' => 'fg-backup-pro',
            'tab' => 'backups',
            'fg_backup_deleted' => $deleted,
            'fg_backup_delete_failed' => $deleted ? 0 : 1,
        ], admin_url('admin.php')));
        exit;
    }

    public static function bulk_delete() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Keine Berechtigung.', 'fg-backup-pro'), 403);
        }

        check_admin_referer('fg_backup_bulk_delete');

        $raw_files = isset($_POST['backups']) && is_array($_POST['backups'])
            ? wp_unslash($_POST['backups'])
            : [];
        $files = [];
        foreach (array_slice($raw_files, 0, 100) as $raw_file) {
            $file = FgBackup_Backup::normalize_backup_filename((string) $raw_file);
            if ($file !== '') {
                $files[$file] = $file;
            }
        }

        $deleted = 0;
        $failed = 0;
        foreach ($files as $file) {
            if (FgBackup_Backup::delete_backup($file)) {
                $deleted++;
            } else {
                $failed++;
            }
        }

        FgBackup_Health::refresh_after_job();

        wp_safe_redirect(add_query_arg([
            'page' => 'fg-backup-pro',
            'tab' => 'backups',
            'fg_backup_deleted' => $deleted,
            'fg_backup_delete_failed' => $failed,
        ], admin_url('admin.php')));
        exit;
    }
}
