<?php

defined('ABSPATH') || exit;

class FgBackup_Notifications {

    public static function success(array $job) {
        if (!get_option('fg_backup_notifications', 0)) {
            return;
        }

        $subject = __('FG Backup erfolgreich', 'fg-backup-pro');
        $local = !empty($job['file'])
            ? $job['file']
            : (!empty($job['local_deleted']) ? __('Nach erfolgreichem SFTP-Upload gelöscht', 'fg-backup-pro') : '-');
        $body = sprintf(
            "Das Backup wurde erfolgreich erstellt.\n\nLokale Datei: %s\nGröße: %s\nDatum: %s",
            $local,
            isset($job['size']) ? size_format((int) $job['size'], 2) : '-',
            wp_date('d.m.Y H:i', isset($job['finished_at']) ? (int) $job['finished_at'] : time())
        );

        if (!empty($job['remote_path'])) {
            $body .= "\nSFTP: " . $job['remote_path'];
        }
        if (!empty($job['note'])) {
            $body .= "\nNotiz: " . $job['note'];
        }

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

        if (!empty($job['local_verified']) && !empty($job['file'])) {
            $body .= "\nLokales Backup vorhanden: " . $job['file'];
        }
        if (!empty($job['remote_path'])) {
            $body .= "\nSFTP-Ziel: " . $job['remote_path'];
        }

        wp_mail(get_option('admin_email'), $subject, $body);
    }
}
