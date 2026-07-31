<?php

defined('ABSPATH') || exit;

class FgBackup_Notifications {

    public static function success(array $job) {
        if (self::mode() !== 'all') {
            return;
        }
        $subject = __('FG Backup erfolgreich', 'fg-backup-pro');
        $body = self::base_body($job, __('Das Backup wurde erfolgreich erstellt.', 'fg-backup-pro'));
        self::send($subject, $body);
    }

    public static function warning(array $job) {
        if (!in_array(self::mode(), ['errors', 'all'], true)) {
            return;
        }
        $subject = __('FG Backup mit Remote-Fehlern', 'fg-backup-pro');
        $body = self::base_body($job, __('Das lokale Backup wurde erstellt, mindestens ein Remote-Ziel ist jedoch fehlgeschlagen.', 'fg-backup-pro'));
        self::send($subject, $body);
    }

    public static function failure(array $job) {
        if (!in_array(self::mode(), ['errors', 'all'], true)) {
            return;
        }
        $subject = __('FG Backup fehlgeschlagen', 'fg-backup-pro');
        $body = self::safe_format(
            "Website: %s\nURL: %s\n\nDas Backup ist fehlgeschlagen.\n\nFehler: %s\nDatum: %s",
            wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
            home_url('/'),
            isset($job['error']) ? $job['error'] : __('Unbekannter Fehler', 'fg-backup-pro'),
            wp_date('d.m.Y H:i')
        );
        if (!empty($job['local_verified']) && !empty($job['file'])) {
            $body .= "\nLokales Backup vorhanden: " . $job['file'];
        }
        $remote = self::remote_lines(isset($job['remote_results']) ? (array) $job['remote_results'] : []);
        if ($remote !== '') {
            $body .= "\n\nRemote-Ziele:\n" . $remote;
        }
        self::send($subject, $body);
    }

    public static function health(array $report) {
        $mode = self::mode();
        $status = isset($report['status']) ? $report['status'] : 'unknown';

        if (!in_array($mode, ['errors', 'all'], true)) {
            return;
        }

        if (!in_array($status, ['warning', 'critical'], true)) {
            delete_option(FgBackup_Health::LAST_MAIL_OPTION);
            return;
        }

        $signature_parts = [$status];
        foreach (isset($report['checks']) ? (array) $report['checks'] : [] as $key => $check) {
            $check_status = isset($check['status']) ? $check['status'] : '';
            if (in_array($check_status, ['warning', 'critical'], true)) {
                $signature_parts[] = sanitize_key((string) $key) . ':' . $check_status;
            }
        }
        $signature = hash('sha256', implode('|', $signature_parts));
        $last = get_option(FgBackup_Health::LAST_MAIL_OPTION, []);
        $last_signature = is_array($last) && isset($last['signature']) ? (string) $last['signature'] : '';
        $last_sent = is_array($last) && isset($last['sent_at']) ? (int) $last['sent_at'] : 0;

        if ($signature === $last_signature && $last_sent > time() - DAY_IN_SECONDS) {
            return;
        }

        $subject = $status === 'critical'
            ? __('FG Backup Pro: Backup-Fehler', 'fg-backup-pro')
            : __('FG Backup Pro: Backup prüfen', 'fg-backup-pro');

        $body = self::safe_format(
            "Website: %s\nURL: %s\nZeitpunkt: %s\n\n%s\n",
            wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
            home_url('/'),
            wp_date('d.m.Y H:i', isset($report['generated_at']) ? (int) $report['generated_at'] : time()),
            isset($report['summary']) ? $report['summary'] : __('Der Backup-Status sollte geprüft werden.', 'fg-backup-pro')
        );

        foreach (isset($report['checks']) ? (array) $report['checks'] : [] as $check) {
            if (empty($check['label']) || empty($check['detail'])) {
                continue;
            }
            $body .= self::safe_format(
                "\n- %s [%s]: %s",
                $check['label'],
                FgBackup_Health::status_label(isset($check['status']) ? $check['status'] : 'unknown'),
                $check['detail']
            );
        }

        if (self::send($subject, $body)) {
            update_option(FgBackup_Health::LAST_MAIL_OPTION, [
                'signature' => $signature,
                'sent_at' => time(),
            ], false);
        }
    }

    public static function mode() {
        $mode = get_option('fg_backup_notification_mode', null);
        if (in_array($mode, ['off', 'errors', 'all'], true)) {
            return $mode;
        }

        return get_option('fg_backup_notifications', 0) ? 'all' : 'off';
    }

    public static function recipient() {
        $email = sanitize_email((string) get_option('fg_backup_notification_email', ''));
        if ($email === '') {
            $email = sanitize_email((string) get_option('admin_email'));
        }
        return $email;
    }

    private static function safe_format($format) {
        $args = func_get_args();
        array_shift($args);

        try {
            return vsprintf((string) $format, $args);
        } catch (Throwable $exception) {
            error_log('[FG Backup Pro] Benachrichtigungsformat fehlgeschlagen: ' . $exception->getMessage());

            $fallback = preg_replace('/%(?:\d+\$)?[-+ 0\'\.\d]*[bcdeEfFgGosuxX]/', '', (string) $format);
            $fallback = preg_replace('/%%/', '%', (string) $fallback);
            $values = [];
            foreach ($args as $arg) {
                if (is_scalar($arg) || $arg === null) {
                    $values[] = (string) $arg;
                }
            }
            return trim((string) $fallback . ($values ? ' ' . implode(' ', $values) : ''));
        }
    }

    private static function remote_lines(array $results) {
        $lines = [];

        foreach ($results as $id => $result) {
            $label = FgBackup_Remotes::label($id);
            $status = isset($result['status']) ? (string) $result['status'] : '';

            if ($status === 'completed') {
                $message = !empty($result['path'])
                    ? (string) $result['path']
                    : __('erfolgreich', 'fg-backup-pro');
            } elseif ($status === 'failed') {
                $message = !empty($result['error'])
                    ? (string) $result['error']
                    : __('fehlgeschlagen', 'fg-backup-pro');
            } elseif ($status === 'canceled') {
                $message = __('abgebrochen', 'fg-backup-pro');
            } else {
                continue;
            }

            $lines[] = '- ' . $label . ': ' . $message;
        }

        return implode("\n", $lines);
    }

    private static function send($subject, $body) {
        $recipient = self::recipient();
        if ($recipient === '') {
            return false;
        }
        return (bool) wp_mail($recipient, $subject, $body);
    }

    private static function base_body(array $job, $intro) {
        $local = !empty($job['file'])
            ? $job['file']
            : (!empty($job['local_deleted']) ? __('Nach erfolgreichen Remote-Uploads gelöscht', 'fg-backup-pro') : '-');
        $body = self::safe_format(
            "Website: %s\nURL: %s\n\n%s\n\nLokale Datei: %s\nGröße: %s\nDatum: %s",
            wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
            home_url('/'),
            $intro,
            $local,
            isset($job['size']) ? size_format((int) $job['size'], 2) : '-',
            wp_date('d.m.Y H:i', isset($job['finished_at']) ? (int) $job['finished_at'] : time())
        );
        $remote = self::remote_lines(isset($job['remote_results']) ? (array) $job['remote_results'] : []);
        if ($remote !== '') {
            $body .= "\n\nRemote-Ziele:\n" . $remote;
        }
        if (!empty($job['note'])) {
            $body .= "\n\nNotiz: " . $job['note'];
        }
        return $body;
    }
}
