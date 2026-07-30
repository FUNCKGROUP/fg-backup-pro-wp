<?php

defined('WP_UNINSTALL_PLUGIN') || exit;

wp_clear_scheduled_hook('fg_backup_scheduled_backup');
wp_clear_scheduled_hook('fg_backup_process_job');
wp_clear_scheduled_hook('fg_backup_health_check');

delete_option('fg_backup_active_job');
delete_option('fg_backup_version');

// Einstellungen, Historie und vorhandene Backup-Dateien bleiben absichtlich erhalten.
