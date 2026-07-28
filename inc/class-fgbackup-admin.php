<?php

defined('ABSPATH') || exit;

class FgBackup_Admin {

    public static function init() {
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('wp_ajax_fg_backup_start', [__CLASS__, 'ajax_start']);
        add_action('wp_ajax_fg_backup_status', [__CLASS__, 'ajax_status']);
        add_action('admin_post_fg_backup_download', [__CLASS__, 'download']);
        add_action('admin_post_fg_backup_delete', [__CLASS__, 'delete']);
    }

    public static function activate() {
        self::install_defaults();
        self::run_upgrade();
        FgBackup_Cron::reschedule();
    }

    public static function deactivate() {
        FgBackup_Cron::deactivate();
    }

    public static function maybe_upgrade() {
        if (get_option('fg_backup_version') !== FG_BACKUP_VERSION) {
            self::run_upgrade();
        }
    }

    private static function run_upgrade() {
        self::install_defaults();
        FgBackup_Storage::ensure();
        FgBackup_Storage::migrate_legacy_backups();
        self::remove_legacy_remote_credentials();
        FgBackup_Cleanup::clean_old_jobs();
        update_option('fg_backup_version', FG_BACKUP_VERSION, false);
        FgBackup_Cron::reschedule();
    }

    private static function install_defaults() {
        $defaults = [
            'fg_backup_type' => 'full',
            'fg_backup_schedule' => 'disabled',
            'fg_backup_hour' => 2,
            'fg_backup_rotation' => 5,
            'fg_backup_notifications' => 0,
            'fg_backup_exclusions' => '',
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
        register_setting('fg_backup_settings', 'fg_backup_exclusions', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default' => '',
        ]);
    }

    public static function sanitize_type($value) {
        return in_array($value, ['full', 'db'], true) ? $value : 'full';
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

    public static function sanitize_checkbox($value) {
        return empty($value) ? 0 : 1;
    }

    public static function enqueue_assets() {
        if (!is_admin() || empty($_GET['page']) || sanitize_key(wp_unslash($_GET['page'])) !== 'fg-backup-pro') {
            return;
        }

        wp_enqueue_style('fg-backup-pro', FG_BACKUP_URL . 'assets/style.css', [], FG_BACKUP_VERSION);
        wp_enqueue_script('fg-backup-pro', FG_BACKUP_URL . 'assets/script.js', ['jquery'], FG_BACKUP_VERSION, true);
        $active_job = FgBackup_Async::get_active_job();

        wp_localize_script('fg-backup-pro', 'fgBackupPro', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('fg_backup_ajax'),
            'runningText' => __('Backup läuft …', 'fg-backup-pro'),
            'failedText' => __('Backup fehlgeschlagen.', 'fg-backup-pro'),
            'completedText' => __('Backup abgeschlossen.', 'fg-backup-pro'),
            'activeJobId' => is_array($active_job) && !empty($active_job['id']) ? $active_job['id'] : '',
        ]);
    }

    public static function render_main_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $backups = FgBackup_Backup::list_backups();
        $history = get_option('fg_backup_history', []);
        $active_job = FgBackup_Async::get_active_job();
        include FG_BACKUP_DIR . 'views/admin-main.php';
    }

    public static function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        include FG_BACKUP_DIR . 'views/admin-settings.php';
    }

    public static function ajax_start() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Keine Berechtigung.', 'fg-backup-pro')], 403);
        }
        check_ajax_referer('fg_backup_ajax', 'security');

        $type = isset($_POST['backup_type']) ? self::sanitize_type(wp_unslash($_POST['backup_type'])) : 'full';
        $job_id = FgBackup_Async::queue_backup($type, 'manual');

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
            'status' => $job['status'],
            'progress' => isset($job['progress']) ? (int) $job['progress'] : 0,
            'error' => isset($job['error']) ? $job['error'] : '',
        ]);
    }

    public static function download() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Keine Berechtigung.', 'fg-backup-pro'), 403);
        }

        $file = isset($_GET['file']) ? sanitize_file_name(wp_unslash($_GET['file'])) : '';
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
        $download_name = str_replace(['\r', '\n', '"'], '', basename($path));
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

    public static function delete() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Keine Berechtigung.', 'fg-backup-pro'), 403);
        }

        $file = isset($_GET['file']) ? sanitize_file_name(wp_unslash($_GET['file'])) : '';
        check_admin_referer('fg_backup_delete_' . $file);
        FgBackup_Backup::delete_backup($file);

        wp_safe_redirect(admin_url('admin.php?page=fg-backup-pro&tab=backups'));
        exit;
    }
}
