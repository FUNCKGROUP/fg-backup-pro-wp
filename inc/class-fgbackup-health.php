<?php

defined('ABSPATH') || exit;

class FgBackup_Health {

    const CRON_HOOK = 'fg_backup_health_check';
    const REPORT_OPTION = 'fg_backup_health_report';
    const LAST_MAIL_OPTION = 'fg_backup_health_last_mail';
    const REPORT_VERSION = 3;

    public static function init() {
        add_action(self::CRON_HOOK, [__CLASS__, 'run_scheduled']);
        add_action('admin_notices', [__CLASS__, 'admin_notice']);
    }

    public static function schedule() {
        if (wp_next_scheduled(self::CRON_HOOK)) {
            return;
        }

        $now = current_datetime();
        $next = $now->setTime(8, 0, 0);
        if ($next <= $now) {
            $next = $next->modify('+1 day');
        }

        wp_schedule_event($next->getTimestamp(), 'daily', self::CRON_HOOK);
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function run_scheduled() {
        self::run(true, true);
    }

    public static function refresh_after_job() {
        try {
            self::run(false, false);
        } catch (Throwable $exception) {
            self::log_exception('refresh_after_job', $exception);
        }
    }

    public static function get_report($ensure = true) {
        $report = get_option(self::REPORT_OPTION, []);
        if (!is_array($report) || empty($report['generated_at']) || (int) (isset($report['report_version']) ? $report['report_version'] : 0) !== self::REPORT_VERSION) {
            return $ensure ? self::run(false, false) : [];
        }

        return $report;
    }

    public static function run($check_remotes = true, $send_notification = false) {
        try {
            return self::build_report($check_remotes, $send_notification);
        } catch (Throwable $exception) {
            self::log_exception('run', $exception);

            $report = [
                'report_version' => self::REPORT_VERSION,
                'status' => 'critical',
                'generated_at' => time(),
                'remote_checked' => false,
                'summary' => __('Der Backup-Status konnte nicht vollständig geprüft werden.', 'fg-backup-pro'),
                'checks' => [
                    'health_check' => self::check(
                        'critical',
                        __('Gesundheitsprüfung', 'fg-backup-pro'),
                        sanitize_text_field($exception->getMessage())
                    ),
                ],
            ];

            update_option(self::REPORT_OPTION, $report, false);
            return $report;
        }
    }

    private static function build_report($check_remotes = true, $send_notification = false) {
        $history = get_option('fg_backup_history', []);
        if (!is_array($history)) {
            $history = [];
        }

        $checks = [];
        $now = time();
        $latest_result = self::first_entry($history, static function ($entry) {
            return !empty($entry['finished_at']);
        });
        $latest_usable = self::first_entry($history, static function ($entry) {
            return isset($entry['status'])
                && in_array($entry['status'], ['completed', 'completed_with_errors'], true)
                && !empty($entry['validation_status'])
                && $entry['validation_status'] === 'valid';
        });
        $latest_clean = self::first_entry($history, static function ($entry) {
            return isset($entry['status'])
                && $entry['status'] === 'completed'
                && !empty($entry['validation_status'])
                && $entry['validation_status'] === 'valid';
        });

        $checks['backup'] = self::backup_check($latest_result, $latest_usable, $latest_clean, $now);
        $checks['schedule'] = self::schedule_check($history, $latest_usable, $now);
        $checks['local'] = self::local_check($latest_usable);
        $checks['active_job'] = self::active_job_check($now);

        foreach (FgBackup_Remotes::enabled_ids() as $target) {
            $checks['remote_' . $target] = self::remote_check($target, $history, $check_remotes);
        }

        $status = self::overall_status($checks);
        $report = [
            'report_version' => self::REPORT_VERSION,
            'status' => $status,
            'generated_at' => $now,
            'remote_checked' => (bool) $check_remotes,
            'summary' => self::summary($status, $checks),
            'checks' => $checks,
        ];

        update_option(self::REPORT_OPTION, $report, false);

        if ($send_notification) {
            FgBackup_Notifications::health($report);
        }

        return $report;
    }

    public static function admin_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $is_plugin_page = !empty($_GET['page']) && sanitize_key(wp_unslash($_GET['page'])) === 'fg-backup-pro';
        if ($is_plugin_page) {
            return;
        }

        $report = self::get_report(true);
        $status = isset($report['status']) ? $report['status'] : 'unknown';
        if (!in_array($status, ['warning', 'critical'], true)) {
            return;
        }

        $class = $status === 'critical' ? 'notice notice-error' : 'notice notice-warning';
        $url = admin_url('admin.php?page=fg-backup-pro&tab=backups#fg-backup-health');
        $message = !empty($report['summary'])
            ? $report['summary']
            : __('Der Backup-Status sollte geprüft werden.', 'fg-backup-pro');

        echo '<div class="' . esc_attr($class) . '"><p><strong>'
            . esc_html__('FG Backup Pro:', 'fg-backup-pro')
            . '</strong> ' . esc_html($message)
            . ' <a href="' . esc_url($url) . '">' . esc_html__('Status öffnen', 'fg-backup-pro') . '</a>'
            . '</p></div>';
    }

    public static function status_label($status) {
        $labels = [
            'healthy' => __('In Ordnung', 'fg-backup-pro'),
            'info' => __('Hinweis', 'fg-backup-pro'),
            'unknown' => __('Noch nicht geprüft', 'fg-backup-pro'),
            'warning' => __('Prüfen', 'fg-backup-pro'),
            'critical' => __('Fehler', 'fg-backup-pro'),
        ];

        return isset($labels[$status]) ? $labels[$status] : $labels['unknown'];
    }

    private static function backup_check($latest_result, $latest_usable, $latest_clean, $now) {
        if (!$latest_usable) {
            if ($latest_result && isset($latest_result['status']) && $latest_result['status'] === 'failed') {
                return self::check(
                    'critical',
                    __('Letztes Backup', 'fg-backup-pro'),
                    __('Es gibt noch kein erfolgreiches Backup. Der letzte Lauf ist fehlgeschlagen.', 'fg-backup-pro')
                );
            }

            $scheduled = get_option('fg_backup_schedule', 'disabled') !== 'disabled';
            return self::check(
                $scheduled ? 'warning' : 'unknown',
                __('Letztes Backup', 'fg-backup-pro'),
                $scheduled
                    ? __('Es wurde noch kein erfolgreiches Backup abgeschlossen.', 'fg-backup-pro')
                    : __('Es wurde noch kein Backup abgeschlossen.', 'fg-backup-pro')
            );
        }

        $finished = isset($latest_usable['finished_at']) ? (int) $latest_usable['finished_at'] : 0;
        $age = $finished > 0 ? human_time_diff($finished, $now) : __('unbekannt', 'fg-backup-pro');
        $status = isset($latest_usable['status']) ? $latest_usable['status'] : '';
        $detail = self::safe_format(
            __('Letztes verwendbares Backup vor %1$s: %2$s', 'fg-backup-pro'),
            $age,
            !empty($latest_usable['file']) ? $latest_usable['file'] : __('nur remote gespeichert', 'fg-backup-pro')
        );

        if ($latest_result && !empty($latest_result['finished_at']) && (int) $latest_result['finished_at'] > $finished) {
            if (isset($latest_result['status']) && $latest_result['status'] === 'failed') {
                return self::check('critical', __('Letztes Backup', 'fg-backup-pro'), __('Der neueste Backup-Lauf ist fehlgeschlagen.', 'fg-backup-pro'));
            }
            if (isset($latest_result['status']) && $latest_result['status'] === 'canceled') {
                return self::check('warning', __('Letztes Backup', 'fg-backup-pro'), __('Der neueste Backup-Lauf wurde abgebrochen.', 'fg-backup-pro'));
            }
        }

        if ($status === 'completed_with_errors') {
            return self::check('warning', __('Letztes Backup', 'fg-backup-pro'), $detail . ' · ' . __('Mindestens ein Remote-Ziel ist fehlgeschlagen.', 'fg-backup-pro'));
        }

        if (!$latest_clean) {
            return self::check('warning', __('Letztes Backup', 'fg-backup-pro'), $detail);
        }

        return self::check('healthy', __('Letztes Backup', 'fg-backup-pro'), $detail);
    }

    private static function schedule_check(array $history, $latest_usable, $now) {
        $schedule = get_option('fg_backup_schedule', 'disabled');
        if ($schedule === 'disabled') {
            return self::check('info', __('Zeitplan', 'fg-backup-pro'), __('Automatische Backups sind deaktiviert.', 'fg-backup-pro'));
        }

        if (!wp_next_scheduled(FgBackup_Cron::HOOK)) {
            FgBackup_Cron::schedule();
        }
        $next = wp_next_scheduled(FgBackup_Cron::HOOK);
        if (!$next) {
            return self::check('critical', __('Zeitplan', 'fg-backup-pro'), __('Der Zeitplan ist aktiviert, aber WordPress hat keinen nächsten Lauf geplant.', 'fg-backup-pro'));
        }

        $thresholds = [
            'daily' => 36 * HOUR_IN_SECONDS,
            'weekly' => 9 * DAY_IN_SECONDS,
            'monthly' => 40 * DAY_IN_SECONDS,
        ];
        $threshold = isset($thresholds[$schedule]) ? $thresholds[$schedule] : 9 * DAY_IN_SECONDS;
        $last_time = $latest_usable && !empty($latest_usable['finished_at']) ? (int) $latest_usable['finished_at'] : 0;
        $next_text = wp_date('d.m.Y H:i', $next);

        if ($last_time && $last_time < $now - $threshold) {
            return self::check(
                'critical',
                __('Zeitplan', 'fg-backup-pro'),
                self::safe_format(__('Das letzte verwendbare Backup ist überfällig. Nächster geplanter Lauf: %s.', 'fg-backup-pro'), $next_text)
            );
        }

        $scheduled_failure = self::first_entry($history, static function ($entry) {
            return isset($entry['origin'], $entry['status']) && $entry['origin'] === 'scheduled' && $entry['status'] === 'failed';
        });
        $scheduled_success = self::first_entry($history, static function ($entry) {
            return isset($entry['origin'], $entry['status'])
                && $entry['origin'] === 'scheduled'
                && in_array($entry['status'], ['completed', 'completed_with_errors'], true);
        });

        if ($scheduled_failure && (!$scheduled_success || (int) $scheduled_failure['finished_at'] > (int) $scheduled_success['finished_at'])) {
            return self::check(
                'critical',
                __('Zeitplan', 'fg-backup-pro'),
                self::safe_format(__('Der letzte automatische Lauf ist fehlgeschlagen. Nächster Versuch: %s.', 'fg-backup-pro'), $next_text)
            );
        }

        return self::check(
            'healthy',
            __('Zeitplan', 'fg-backup-pro'),
            self::safe_format(__('Nächster automatischer Lauf: %s.', 'fg-backup-pro'), $next_text)
        );
    }

    private static function local_check($latest_usable) {
        $local_backups = FgBackup_Backup::list_backups();

        if ($local_backups) {
            $latest_local = reset($local_backups);
            $latest_valid = false;
            foreach ($local_backups as $candidate) {
                if (!empty($candidate['validation_status']) && $candidate['validation_status'] === 'valid') {
                    $latest_valid = $candidate;
                    break;
                }
            }

            if (!$latest_valid) {
                $latest_name = !empty($latest_local['name']) ? (string) $latest_local['name'] : __('unbekannt', 'fg-backup-pro');
                return self::check(
                    'warning',
                    __('Lokale Sicherung', 'fg-backup-pro'),
                    self::safe_format(__('Lokale Backups sind vorhanden, aber keines wurde erfolgreich tiefenvalidiert. Neueste Datei: %s.', 'fg-backup-pro'), $latest_name)
                );
            }

            $file = !empty($latest_valid['name']) ? (string) $latest_valid['name'] : '';
            $size = isset($latest_valid['size_raw']) ? (int) $latest_valid['size_raw'] : 0;
            if ($file === '' || $size <= 0) {
                return self::check('critical', __('Lokale Sicherung', 'fg-backup-pro'), __('Die neueste gültige lokale Backup-Datei ist leer oder nicht lesbar.', 'fg-backup-pro'));
            }

            $detail = self::safe_format(__('Neueste gültige lokale Sicherung: %1$s (%2$s).', 'fg-backup-pro'), $file, size_format($size, 2));
            if (!empty($latest_local['name']) && $latest_local['name'] !== $file) {
                $detail .= ' ' . __('Ein neueres Backup ist noch nicht gültig validiert.', 'fg-backup-pro');
                return self::check('warning', __('Lokale Sicherung', 'fg-backup-pro'), $detail);
            }

            return self::check('healthy', __('Lokale Sicherung', 'fg-backup-pro'), $detail);
        }

        if (!$latest_usable) {
            return self::check('unknown', __('Lokale Sicherung', 'fg-backup-pro'), __('Noch keine gültige lokale Sicherung vorhanden.', 'fg-backup-pro'));
        }

        if (!empty($latest_usable['local_deleted'])) {
            return self::check('info', __('Lokale Sicherung', 'fg-backup-pro'), __('Nach erfolgreichen Remote-Uploads wie eingestellt gelöscht; die Sicherung war zuvor gültig validiert.', 'fg-backup-pro'));
        }

        return self::check('warning', __('Lokale Sicherung', 'fg-backup-pro'), __('Es wurde keine gültige lokale Backup-Datei gefunden.', 'fg-backup-pro'));
    }

    private static function active_job_check($now) {
        $active = get_option(FgBackup_Async::LOCK_OPTION, []);
        $job_id = is_array($active) && !empty($active['job_id']) ? sanitize_key($active['job_id']) : '';
        $job = $job_id !== '' ? get_option('fg_backup_job_' . $job_id, false) : false;
        if (!is_array($job) || (isset($job['status']) && in_array($job['status'], ['completed', 'completed_with_errors', 'failed', 'canceled'], true))) {
            return self::check('healthy', __('Backup-Prozess', 'fg-backup-pro'), __('Kein Backup-Prozess hängt fest.', 'fg-backup-pro'));
        }

        $updated = !empty($job['updated_at']) ? (int) $job['updated_at'] : (!empty($job['started_at']) ? (int) $job['started_at'] : $now);
        if ($updated < $now - HOUR_IN_SECONDS) {
            return self::check('warning', __('Backup-Prozess', 'fg-backup-pro'), __('Der aktive Backup-Prozess wurde seit mehr als einer Stunde nicht aktualisiert.', 'fg-backup-pro'));
        }

        return self::check(
            'info',
            __('Backup-Prozess', 'fg-backup-pro'),
            self::safe_format(__('Backup läuft: %s', 'fg-backup-pro'), !empty($job['stage']) ? $job['stage'] : __('in Bearbeitung', 'fg-backup-pro'))
        );
    }

    private static function remote_check($target, array $history, $perform_remote) {
        $label = FgBackup_Remotes::label($target);
        $latest_target_entry = self::first_entry($history, static function ($item) use ($target) {
            return !empty($item['remote_results'][$target]['status']);
        });

        if ($latest_target_entry) {
            $latest_result = isset($latest_target_entry['remote_results'][$target]) ? (array) $latest_target_entry['remote_results'][$target] : [];
            $latest_status = isset($latest_result['status']) ? (string) $latest_result['status'] : '';
            if ($latest_status === 'failed') {
                return self::check(
                    'critical',
                    $label,
                    !empty($latest_result['error'])
                        ? self::safe_format(__('Der letzte Upload ist fehlgeschlagen: %s', 'fg-backup-pro'), $latest_result['error'])
                        : __('Der letzte Upload ist fehlgeschlagen.', 'fg-backup-pro')
                );
            }
            if ($latest_status === 'canceled') {
                return self::check('warning', $label, __('Der letzte Upload wurde abgebrochen.', 'fg-backup-pro'));
            }
        }

        $entry = self::first_entry($history, static function ($item) use ($target) {
            return !empty($item['remote_results'][$target]['status'])
                && $item['remote_results'][$target]['status'] === 'completed'
                && !empty($item['validation_status'])
                && $item['validation_status'] === 'valid'
                && !empty($item['remote_results'][$target]['manifest_path']);
        });

        if (!$entry) {
            return self::check('warning', $label, self::safe_format(__('Für %s wurde noch kein vollständig validierter Upload mit JSON-Manifest protokolliert.', 'fg-backup-pro'), $label));
        }

        $result = isset($entry['remote_results'][$target]) ? (array) $entry['remote_results'][$target] : [];
        $remote_path = isset($result['path']) ? (string) $result['path'] : '';
        $file_name = $remote_path !== '' ? basename(str_replace('\\', '/', $remote_path)) : '';
        if ($file_name === '' && !empty($entry['file'])) {
            $file_name = basename((string) $entry['file']);
        }

        if (!$perform_remote) {
            $detail = $file_name !== ''
                ? self::safe_format(__('Letzter erfolgreicher Upload: %s. Remote-Prüfung noch nicht aktuell.', 'fg-backup-pro'), $file_name)
                : __('Ein erfolgreicher Upload ist protokolliert. Remote-Prüfung noch nicht aktuell.', 'fg-backup-pro');
            return self::check('info', $label, $detail);
        }

        try {
            $class = FgBackup_Remotes::class_name($target);
            if (!is_callable([$class, 'list_backups'])) {
                throw new RuntimeException(__('Das Ziel unterstützt keine Dateiprüfung.', 'fg-backup-pro'));
            }
            $files = $class::list_backups();
            $match = null;
            foreach ((array) $files as $file) {
                if (!isset($file['name'])) {
                    continue;
                }
                if ($file_name !== '' && strcasecmp((string) $file['name'], $file_name) === 0) {
                    $match = $file;
                    break;
                }
            }

            if ($file_name !== '' && !$match) {
                return self::check('critical', $label, self::safe_format(__('Das zuletzt hochgeladene Backup %s wurde remote nicht gefunden.', 'fg-backup-pro'), $file_name));
            }

            if ($match) {
                $expected = isset($entry['size']) ? (int) $entry['size'] : 0;
                $actual = isset($match['size_bytes']) ? (int) $match['size_bytes'] : 0;
                if ($expected > 0 && $actual > 0 && $expected !== $actual) {
                    return self::check(
                        'critical',
                        $label,
                        self::safe_format(__('Die Remote-Dateigröße stimmt nicht: erwartet %1$s, gefunden %2$s.', 'fg-backup-pro'), size_format($expected, 2), size_format($actual, 2))
                    );
                }

                $manifest_path = !empty($result['manifest_path']) ? (string) $result['manifest_path'] : '';
                $remote_manifest = $manifest_path !== '' ? FgBackup_Remotes::read_manifest($target, $manifest_path) : [];
                if (!$remote_manifest) {
                    return self::check('critical', $label, __('Das Remote-Backup ist vorhanden, aber das zugehörige JSON-Manifest fehlt oder ist nicht lesbar.', 'fg-backup-pro'));
                }
                if (empty($remote_manifest['validation']['status']) || $remote_manifest['validation']['status'] !== 'valid') {
                    return self::check('critical', $label, __('Das Remote-JSON bestätigt keine gültige Sicherung.', 'fg-backup-pro'));
                }
                if (!empty($remote_manifest['backup']['filename'])) {
                    $manifest_filename = (string) $remote_manifest['backup']['filename'];
                    $local_filename = !empty($entry['file']) ? basename((string) $entry['file']) : '';
                    if ($manifest_filename !== $file_name && ($local_filename === '' || $manifest_filename !== $local_filename)) {
                        return self::check('critical', $label, __('Der Dateiname im Remote-JSON stimmt weder mit der lokalen noch mit der entfernten Backup-Datei überein.', 'fg-backup-pro'));
                    }
                }
                if (!empty($remote_manifest['backup']['size']) && $actual > 0 && (int) $remote_manifest['backup']['size'] !== $actual) {
                    return self::check('critical', $label, __('Die Dateigröße im Remote-JSON stimmt nicht mit der Remote-Datei überein.', 'fg-backup-pro'));
                }
                if (!empty($entry['checksum']) && !empty($remote_manifest['backup']['sha256']) && !hash_equals((string) $entry['checksum'], (string) $remote_manifest['backup']['sha256'])) {
                    return self::check('critical', $label, __('Die Prüfsumme im Remote-JSON stimmt nicht mit dem lokalen Prüfbericht überein.', 'fg-backup-pro'));
                }

                return self::check(
                    'healthy',
                    $label,
                    self::safe_format(__('%1$s und das gültige JSON-Manifest sind remote vorhanden (%2$s).', 'fg-backup-pro'), $file_name, $actual > 0 ? size_format($actual, 2) : __('Größe unbekannt', 'fg-backup-pro'))
                );
            }

            return self::check('healthy', $label, __('Remote-Ziel ist erreichbar.', 'fg-backup-pro'));
        } catch (Throwable $exception) {
            return self::check('critical', $label, self::safe_format(__('Remote-Prüfung fehlgeschlagen: %s', 'fg-backup-pro'), sanitize_text_field($exception->getMessage())));
        }
    }

    private static function overall_status(array $checks) {
        $has_unknown = false;
        foreach ($checks as $check) {
            $candidate = isset($check['status']) ? $check['status'] : 'unknown';
            if ($candidate === 'critical') {
                return 'critical';
            }
            if ($candidate === 'warning') {
                return 'warning';
            }
            if ($candidate === 'unknown') {
                $has_unknown = true;
            }
        }

        return $has_unknown ? 'unknown' : 'healthy';
    }

    private static function summary($status, array $checks) {
        if ($status === 'healthy') {
            return __('Backup-Status ist in Ordnung.', 'fg-backup-pro');
        }
        if ($status === 'info') {
            return __('Backup-Status enthält Hinweise, aber keinen Fehler.', 'fg-backup-pro');
        }
        if ($status === 'unknown') {
            return __('Der Backup-Status ist noch nicht vollständig aussagekräftig.', 'fg-backup-pro');
        }

        $messages = [];
        foreach ($checks as $check) {
            if (!isset($check['status']) || !in_array($check['status'], ['warning', 'critical'], true)) {
                continue;
            }
            $messages[] = isset($check['label']) ? $check['label'] : __('Backup', 'fg-backup-pro');
        }
        $messages = array_values(array_unique($messages));

        if (!$messages) {
            return __('Der Backup-Status sollte geprüft werden.', 'fg-backup-pro');
        }

        return self::safe_format(
            __('Prüfung erforderlich: %s.', 'fg-backup-pro'),
            implode(', ', $messages)
        );
    }

    private static function check($status, $label, $detail) {
        return [
            'status' => sanitize_key($status),
            'label' => sanitize_text_field($label),
            'detail' => sanitize_text_field($detail),
        ];
    }

    private static function safe_format($format) {
        $args = func_get_args();
        array_shift($args);

        try {
            return vsprintf((string) $format, $args);
        } catch (Throwable $exception) {
            self::log_exception('format', $exception);

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

    private static function log_exception($context, Throwable $exception) {
        error_log('[FG Backup Pro] Health ' . sanitize_key((string) $context) . ': ' . $exception->getMessage());
    }

    private static function first_entry(array $history, callable $callback) {
        foreach ($history as $entry) {
            if (is_array($entry) && $callback($entry)) {
                return $entry;
            }
        }
        return false;
    }
}
