<?php
/**
 * Plugin Name: FG Backup Pro
 * Plugin URI: https://github.com/FUNCKGROUP/fg-backup-pro-wp
 * Description: Sichere lokale WordPress-Backups mit asynchroner Verarbeitung, Prüfung und Rotation.
 * Version: 1.1.1
 * Author: FUNCKGROUP - Benedict von Funck
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Text Domain: fg-backup-pro
 * GitHub Plugin URI: https://github.com/FUNCKGROUP/fg-backup-pro-wp
 * Primary Branch: main
 */

defined('ABSPATH') || exit;

define('FG_BACKUP_VERSION', '1.1.1');
define('FG_BACKUP_FILE', __FILE__);
define('FG_BACKUP_DIR', plugin_dir_path(__FILE__));
define('FG_BACKUP_URL', plugin_dir_url(__FILE__));

$core_bootstrap = FG_BACKUP_DIR . 'includes/fg-core/bootstrap.php';
if (is_readable($core_bootstrap)) {
    require_once $core_bootstrap;
}

require_once FG_BACKUP_DIR . 'inc/class-fgbackup-storage.php';
require_once FG_BACKUP_DIR . 'inc/class-fgbackup-backup.php';
require_once FG_BACKUP_DIR . 'inc/class-fgbackup-cleanup.php';
require_once FG_BACKUP_DIR . 'inc/class-fgbackup-notifications.php';
require_once FG_BACKUP_DIR . 'inc/class-fgbackup-async.php';
require_once FG_BACKUP_DIR . 'inc/class-fgbackup-cron.php';
require_once FG_BACKUP_DIR . 'inc/class-fgbackup-admin.php';

function fg_backup_pro_register_with_core() {
    if (!is_admin() || !function_exists('fg_core_register_plugin')) {
        return;
    }

    fg_core_register_plugin([
        'slug'        => 'fg-backup-pro',
        'title'       => __('FG Backup Pro', 'fg-backup-pro'),
        'menu_title'  => __('FG Backup Pro', 'fg-backup-pro'),
        'description' => __('Lokale Sicherungen, Zeitplanung und Backup-Prüfung.', 'fg-backup-pro'),
        'version'     => FG_BACKUP_VERSION,
        'plugin_file' => FG_BACKUP_FILE,
        'default_tab' => 'backups',
        'position'    => 30,
        'tabs'        => [
            'backups' => [
                'title'    => __('Backups', 'fg-backup-pro'),
                'callback' => ['FgBackup_Admin', 'render_main_page'],
                'position' => 10,
            ],
            'settings' => [
                'title'    => __('Einstellungen', 'fg-backup-pro'),
                'callback' => ['FgBackup_Admin', 'render_settings_page'],
                'position' => 20,
            ],
        ],
    ]);
}
fg_backup_pro_register_with_core();

FgBackup_Admin::init();
FgBackup_Async::init();
FgBackup_Cron::init();

add_action('plugins_loaded', ['FgBackup_Admin', 'maybe_upgrade'], 5);

register_activation_hook(FG_BACKUP_FILE, ['FgBackup_Admin', 'activate']);
register_deactivation_hook(FG_BACKUP_FILE, ['FgBackup_Admin', 'deactivate']);
