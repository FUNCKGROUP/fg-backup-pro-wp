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
        } else {
            self::install_defaults();
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
            'fg_backup_archive_format' => 'zip',
            'fg_backup_database_format' => 'gz',
            'fg_backup_filename_pattern' => FgBackup_Backup::default_filename_pattern(),
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
        register_setting('fg_backup_settings', 'fg_backup_exclusions', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default' => '',
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
            'status' => isset($job['status']) ? $job['status'] : '',
            'progress' => isset($job['progress']) ? (int) $job['progress'] : 0,
            'stage' => isset($job['stage']) ? $job['stage'] : '',
            'detail' => isset($job['detail']) ? $job['detail'] : '',
            'error' => isset($job['error']) ? $job['error'] : '',
            'file' => isset($job['file']) ? $job['file'] : '',
            'size' => !empty($job['size']) ? size_format((int) $job['size'], 2) : '',
            'started_at' => isset($job['started_at']) ? (int) $job['started_at'] : 0,
            'finished_at' => isset($job['finished_at']) ? (int) $job['finished_at'] : 0,
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
