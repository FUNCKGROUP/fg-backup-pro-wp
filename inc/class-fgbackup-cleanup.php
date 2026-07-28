<?php

defined('ABSPATH') || exit;

class FgBackup_Cleanup {

    public static function rotate_backups() {
        $maximum = max(1, min(100, (int) get_option('fg_backup_rotation', 5)));
        $backups = FgBackup_Backup::list_backups();

        if (count($backups) <= $maximum) {
            return;
        }

        foreach (array_slice($backups, $maximum) as $backup) {
            FgBackup_Backup::delete_backup($backup['name']);
        }
    }

    public static function clean_old_jobs() {
        global $wpdb;

        $names = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'fg_backup_job_%'"
        );

        foreach ((array) $names as $name) {
            $job = get_option($name, []);
            $finished = isset($job['finished_at']) ? (int) $job['finished_at'] : 0;
            $started = isset($job['started_at']) ? (int) $job['started_at'] : 0;
            $reference = $finished ?: $started;

            if ($reference && $reference < time() - (7 * DAY_IN_SECONDS)) {
                delete_option($name);
            }
        }
    }
}
