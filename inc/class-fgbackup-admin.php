<?php

class FgBackup_Admin {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_admin_page']);
        add_action('admin_post_fg_run_backup', [__CLASS__, 'handle_backup_request']);
        add_action('admin_post_fg_delete_backup', [__CLASS__, 'handle_delete_request']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('wp_ajax_fg_backup_check_status', [__CLASS__, 'ajax_check_status']);
        add_action('wp_ajax_fg_backup_start_async', [__CLASS__, 'ajax_start_async']);
    }

    public static function register_admin_page() {
        $icon = 'data:image/svg+xml;base64,' . base64_encode('
                    <svg width="20" height="20" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M57.9 18.1C55.3 15.5 52 13 48 13H16C12 13 8 13 8 17V55C8 59 12 59 16 59H56C60 59 60 55 60 51C60 47 56 43 52 43H12L20 35L24.5 39.5L33 31L37.5 35.5L46 18.1H57.9Z" fill="#007cba"/>
                    </svg>
                ');

        add_menu_page(
            __('FG Backup Pro', 'fg-backup-pro'),
            __('FG Backup Pro', 'fg-backup-pro'),
            'manage_options',
            'fg-backup-pro',
            [__CLASS__, 'render_main_page'],
            $icon,
            59
        );

        add_submenu_page(
            'fg-backup-pro',
            __('Einstellungen', 'fg-backup-pro'),
            __('Einstellungen', 'fg-backup-pro'),
            'manage_options',
            'fg-backup-pro-settings',
            [__CLASS__, 'render_settings_page']
        );
    }

    public static function enqueue_assets($hook) {
        if (!in_array($hook, ['toplevel_page_fg-backup-pro', 'fg-backup_page_fg-backup-settings'])) return;

        wp_enqueue_style('fg-backup-pro-style', FG_BACKUP_URL . 'assets/style.css');
        wp_enqueue_script('fg-backup-pro-script', FG_BACKUP_URL . 'assets/script.js', ['jquery'], null, true);
    }

    public static function render_main_page() {
        include_once FG_BACKUP_DIR . 'views/admin-main.php';
    }

    public static function render_settings_page() {
        include_once FG_BACKUP_DIR . 'views/admin-settings.php';
    }

    public static function handle_backup_request() {
        check_admin_referer('fg_backup_nonce');

        $type = isset($_POST['backup_type']) ? sanitize_text_field($_POST['backup_type']) : 'full';
        $send_email = !empty($_POST['send_email']);
        $targets = isset($_POST['targets']) ? array_map('sanitize_text_field', $_POST['targets']) : [];

        FgBackup_Async::queue_backup($type, $targets);

        wp_redirect(admin_url('admin.php?page=fg-backup-pro&backup=success'));
        exit;
    }

    public static function handle_delete_request() {
        check_admin_referer('fg_delete_backup');

        $file = isset($_GET['file']) ? urldecode(sanitize_file_name($_GET['file'])) : '';
        FgBackup_Backup::delete_backup($file);

        wp_redirect(admin_url('admin.php?page=fg-backup-pro'));
        exit;
    }

    public static function ajax_check_status() {
        check_ajax_referer('fg_backup_nonce', 'security');

        $job_id = isset($_POST['job_id']) ? sanitize_key($_POST['job_id']) : '';
        $status = FgBackup_Async::get_status($job_id);

        if (!$status) {
            wp_send_json_error(['message' => 'Job nicht gefunden']);
        }

        wp_send_json_success($status);
    }

    public static function ajax_start_async() {
        error_log("FG Backup Pro: AJAX Start Async Triggered");
        check_ajax_referer('fg_backup_nonce', 'security');

        $job_id = FgBackup_Async::queue_backup('full', ['local']);
        wp_send_json_success(['job_id' => $job_id]);
    }
}