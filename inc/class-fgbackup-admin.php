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
        add_action('wp_ajax_fg_backup_sftp_test', [__CLASS__, 'ajax_sftp_test']);
        add_action('wp_ajax_fg_backup_sftp_reset_key', [__CLASS__, 'ajax_sftp_reset_key']);
        add_action('wp_ajax_fg_backup_sftp_list', [__CLASS__, 'ajax_sftp_list']);
        add_action('wp_ajax_fg_backup_sftp_delete', [__CLASS__, 'ajax_sftp_delete']);
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
            'fg_backup_sftp_keep_local' => 1,
            'fg_backup_sftp_host_key' => '',
            'fg_backup_sftp_host_key_target' => '',
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


        register_setting('fg_backup_sftp_settings', 'fg_backup_sftp_enabled', [
            'type' => 'boolean', 'sanitize_callback' => [__CLASS__, 'sanitize_checkbox'], 'default' => 0,
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
        register_setting('fg_backup_sftp_settings', 'fg_backup_sftp_keep_local', [
            'type' => 'boolean', 'sanitize_callback' => [__CLASS__, 'sanitize_checkbox'], 'default' => 1,
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
        return max(1, min(100, (int) $value));
    }

    public static function sanitize_sftp_password($value) {
        return self::sanitize_secret_option($value, 'fg_backup_sftp_password');
    }

    public static function sanitize_sftp_passphrase($value) {
        return self::sanitize_secret_option($value, 'fg_backup_sftp_key_passphrase');
    }

    private static function sanitize_secret_option($value, $option) {
        $value = (string) $value;
        if ($value === '') {
            return (string) get_option($option, '');
        }
        try {
            return FgBackup_Secrets::encrypt($value);
        } catch (Throwable $exception) {
            add_settings_error('fg_backup_sftp_settings', 'fg_backup_secret_error', $exception->getMessage(), 'error');
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
            'spaceFullText' => __('Vollständiges Backup: mindestens %1$s temporärer Speicher; nach dem Dateiscan erfolgt eine genauere Prüfung.', 'fg-backup-pro'),
            'spaceDbText' => __('Datenbank-Backup: voraussichtlich %1$s temporärer Speicher.', 'fg-backup-pro'),
            'spaceAvailableText' => __('Frei: %s', 'fg-backup-pro'),
            'localDeletedText' => __('Lokal nach erfolgreichem SFTP-Upload gelöscht.', 'fg-backup-pro'),
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
        $full_space = FgBackup_Backup::estimate_initial_space('full', get_option('fg_backup_archive_format', 'zip'));
        $db_space = FgBackup_Backup::estimate_initial_space('db', get_option('fg_backup_database_format', 'gz'));
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
            'local_deleted' => !empty($job['local_deleted']),
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
