<?php
/*
Plugin Name: FG Backup Pro
Description: Ein leistungsstarkes Backup-Plugin für WordPress mit asynchroner Verarbeitung, Ziel-Uploads und automatischer Rotation.
Version: 1.0.0
Author: FUNCKGROUP - Benedict von Funck
Text Domain: fg-backup
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

define('FG_BACKUP_DIR', plugin_dir_path(__FILE__));
define('FG_BACKUP_URL', plugin_dir_url(__FILE__));

// Includes
require_once FG_BACKUP_DIR . 'inc/class-fgbackup-admin.php';
require_once FG_BACKUP_DIR . 'inc/class-fgbackup-async.php';
require_once FG_BACKUP_DIR . 'inc/class-fgbackup-backup.php';
require_once FG_BACKUP_DIR . 'inc/class-fgbackup-cleanup.php';
require_once FG_BACKUP_DIR . 'inc/class-fgbackup-cron.php';
require_once FG_BACKUP_DIR . 'inc/class-fgbackup-notifications.php';
require_once FG_BACKUP_DIR . 'inc/class-fgbackup-target.php';

// Targets (optional auskommentieren)
require_once FG_BACKUP_DIR . 'inc/targets/class-fgbackup-dropbox.php';
require_once FG_BACKUP_DIR . 'inc/targets/class-fgbackup-google-drive.php';
require_once FG_BACKUP_DIR . 'inc/targets/class-fgbackup-sftp.php';
require_once FG_BACKUP_DIR . 'inc/targets/class-fgbackup-s3.php';
require_once FG_BACKUP_DIR . 'inc/targets/class-fgbackup-s3-compatible.php';
require_once FG_BACKUP_DIR . 'inc/targets/class-fgbackup-webdav.php';
require_once FG_BACKUP_DIR . 'inc/targets/class-fgbackup-onedrive.php';

// Init
FgBackup_Admin::init();
FgBackup_Async::init();
FgBackup_Cron::init();