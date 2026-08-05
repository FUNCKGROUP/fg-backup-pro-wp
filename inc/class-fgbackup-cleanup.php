<?php

defined('ABSPATH') || exit;

class FgBackup_Cleanup {

    public static function rotate_backups() {
        $maximum = max(1, min(100, (int) get_option('fg_backup_rotation', 5)));
        $backups = FgBackup_Backup::list_backups();

        if (count($backups) <= $maximum) {
            return;
        }

        $valid_full = 0;
        $full = 0;
        foreach ($backups as $backup) {
            if (!empty($backup['type']) && $backup['type'] === 'full') {
                $full++;
                if (!empty($backup['validation_status']) && $backup['validation_status'] === 'valid') {
                    $valid_full++;
                }
            }
        }

        foreach (array_slice($backups, $maximum) as $backup) {
            $is_full = !empty($backup['type']) && $backup['type'] === 'full';
            $is_valid = !empty($backup['validation_status']) && $backup['validation_status'] === 'valid';

            if ($is_full) {
                if ($is_valid && $valid_full <= 1) {
                    continue;
                }
                if ($valid_full === 0 && $full <= 1) {
                    continue;
                }
            }

            if (FgBackup_Backup::delete_backup($backup['name']) && $is_full) {
                $full--;
                if ($is_valid) {
                    $valid_full--;
                }
            }
        }
    }

    public static function clean_old_jobs() {
        global $wpdb;

        $now = time();
        $known_jobs = [];
        $active_jobs = [];
        $terminal_states = ['completed', 'completed_with_errors', 'failed', 'canceled'];
        $names = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'fg_backup_job_%'"
        );

        foreach ((array) $names as $name) {
            $job = get_option($name, []);
            if (!is_array($job)) {
                continue;
            }

            $job_id = !empty($job['id']) ? sanitize_key($job['id']) : '';
            $status = isset($job['status']) ? (string) $job['status'] : '';
            $finished = isset($job['finished_at']) ? (int) $job['finished_at'] : 0;
            $updated = isset($job['updated_at']) ? (int) $job['updated_at'] : 0;
            $started = isset($job['started_at']) ? (int) $job['started_at'] : 0;
            $reference = $finished ?: ($updated ?: $started);

            if ($job_id !== '') {
                $known_jobs[$job_id] = $status;
                if (!in_array($status, $terminal_states, true)) {
                    $active_jobs[$job_id] = true;
                }
            }

            if ($reference && $reference < $now - (7 * DAY_IN_SECONDS)) {
                delete_option($name);
                if ($job_id !== '') {
                    delete_option('fg_backup_cancel_' . $job_id);
                    delete_option('fg_backup_process_' . $job_id);
                    unset($active_jobs[$job_id]);
                }
            }
        }

        self::clean_orphaned_temp_directories($known_jobs, $active_jobs, $now);
        self::clean_old_logs($now);
    }

    private static function clean_orphaned_temp_directories(array $known_jobs, array $active_jobs, $now) {
        $root = FgBackup_Storage::get_temp_root();
        if (!is_dir($root)) {
            return;
        }

        $entries = @scandir($root);
        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = trailingslashit($root) . $entry;
            if (!is_dir($path)) {
                continue;
            }

            $job_id = sanitize_key($entry);
            if ($job_id !== '' && isset($active_jobs[$job_id])) {
                continue;
            }

            $mtime = @filemtime($path);
            $known_terminal = $job_id !== '' && isset($known_jobs[$job_id]);
            $orphan_is_old = !$known_terminal && $mtime && $mtime < $now - (6 * HOUR_IN_SECONDS);

            if ($known_terminal || $orphan_is_old) {
                FgBackup_Storage::remove_tree($path);
            }
        }
    }

    private static function clean_old_logs($now) {
        $root = FgBackup_Storage::get_log_root();
        if (!is_dir($root)) {
            return;
        }

        $logs = glob(trailingslashit($root) . '*.log');
        if (!is_array($logs)) {
            return;
        }

        foreach ($logs as $log) {
            $mtime = @filemtime($log);
            if ($mtime && $mtime < $now - (30 * DAY_IN_SECONDS)) {
                @unlink($log);
            }
        }
    }
}
