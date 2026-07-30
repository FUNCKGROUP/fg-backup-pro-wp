<?php

defined('ABSPATH') || exit;

class FgBackup_Notifications {

    public static function success(array $job) {
        if (!get_option('fg_backup_notifications', 0)) {
            return;
        }
        $subject = __('FG Backup erfolgreich', 'fg-backup-pro');
        $body = self::base_body($job, __('Das Backup wurde erfolgreich erstellt.', 'fg-backup-pro'));
        wp_mail(get_option('admin_email'), $subject, $body);
    }

    public static function warning(array $job) {
        if (!get_option('fg_backup_notifications', 0)) {
            return;
        }
        $subject = __('FG Backup mit Remote-Fehlern', 'fg-backup-pro');
        $body = self::base_body($job, __('Das lokale Backup wurde erstellt, mindestens ein Remote-Ziel ist jedoch fehlgeschlagen.', 'fg-backup-pro'));
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
        $remote = FgBackup_Remotes::summarize(isset($job['remote_results']) ? (array) $job['remote_results'] : []);
        if ($remote !== '') {
            $body .= "\nRemote-Ziele: " . $remote;
        }
        wp_mail(get_option('admin_email'), $subject, $body);
    }

    private static function base_body(array $job, $intro) {
        $local = !empty($job['file'])
            ? $job['file']
            : (!empty($job['local_deleted']) ? __('Nach erfolgreichen Remote-Uploads gelöscht', 'fg-backup-pro') : '-');
        $body = sprintf(
            "%s\n\nLokale Datei: %s\nGröße: %s\nDatum: %s",
            $intro,
            $local,
            isset($job['size']) ? size_format((int) $job['size'], 2) : '-',
            wp_date('d.m.Y H:i', isset($job['finished_at']) ? (int) $job['finished_at'] : time())
        );
        $remote = FgBackup_Remotes::summarize(isset($job['remote_results']) ? (array) $job['remote_results'] : []);
        if ($remote !== '') {
            $body .= "\nRemote-Ziele: " . $remote;
        }
        if (!empty($job['note'])) {
            $body .= "\nNotiz: " . $job['note'];
        }
        return $body;
    }
}
