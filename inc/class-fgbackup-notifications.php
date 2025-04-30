<?php

class FgBackup_Notifications {

    public static function notify_admin_of_backup() {
        $to = get_option('admin_email');
        $subject = '✅ FG Backup abgeschlossen';
        $body = "Ein neues Backup wurde erfolgreich erstellt.\n\nDatum: " . date('d.m.Y H:i');
        wp_mail($to, $subject, $body);
    }

    public static function notify_admin_on_failure($message) {
        $to = get_option('admin_email');
        $subject = '❌ FG Backup fehlgeschlagen';
        $body = "Bei der Backup-Erstellung ist ein Fehler aufgetreten:\n\n" . $message;
        wp_mail($to, $subject, $body);
    }

    public static function send_db_email($file_path) {
        $to = get_option('admin_email');
        $subject = '📎 FG Datenbank-Backup per Email';
        $body = "Im Anhang finden Sie das aktuelle Datenbank-Backup Ihres WordPress-Projekts.\n\nDatum: " . date('d.m.Y H:i');
        $attachments = [$file_path];
        wp_mail($to, $subject, $body, '', $attachments);
    }
}