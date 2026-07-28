<?php

defined('ABSPATH') || exit;

class FgBackup_Notifications {

    public static function success(array $job) {
        if (!get_option('fg_backup_notifications', 0)) {
            return;
        }

        $subject = __('FG Backup erfolgreich', 'fg-backup-pro');
        $body = sprintf(
            "Das Backup wurde erfolgreich erstellt.\n\nDatei: %s\nGröße: %s\nDatum: %s",
            isset($job['file']) ? $job['file'] : '-',
            isset($job['size']) ? size_format((int) $job['size'], 2) : '-',
            wp_date('d.m.Y H:i', isset($job['finished_at']) ? (int) $job['finished_at'] : time())
        );

        wp_mail(get_option('admin_email'), $subject, $body);
    }

    public static function failure(array $job) {
        if (!get_option('fg_backup_notifications', 0)) {
            return;
        }

        $subject = __('FG Backup fehlgeschlagen', 'fg-backup-pro');
        $body = sprintf(
            "Das Backup ist fehlgeschlagen.\n\nFehler: %s\nDatum: %s",
            isset($job['error']) ? $job['error'] : __('Unbekannter Fehler', 'fg-backup-pro'),
            wp_date('d.m.Y H:i')
        );

        wp_mail(get_option('admin_email'), $subject, $body);
    }
}
