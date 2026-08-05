<?php

defined('ABSPATH') || exit;

class FgBackup_Worker {

    const START_GRACE_SECONDS = 30;

    public static function capability_report() {
        $binary = self::find_php_cli();
        $shell = is_executable('/bin/sh') ? '/bin/sh' : '';
        $nohup = self::find_executable(['/usr/bin/nohup', '/bin/nohup']);
        $methods = [];

        foreach (['proc_open', 'exec', 'shell_exec'] as $function) {
            if (self::function_available($function)) {
                $methods[] = $function;
            }
        }

        $available = DIRECTORY_SEPARATOR === '/' && $binary !== '' && $shell !== '' && $nohup !== '' && !empty($methods) && function_exists('escapeshellarg');
        $reason = '';

        if (DIRECTORY_SEPARATOR !== '/') {
            $reason = __('Der Hintergrund-Worker wird derzeit nur auf Unix-/Linux-Hosting gestartet.', 'fg-backup-pro');
        } elseif ($binary === '') {
            $reason = __('Es wurde keine ausführbare PHP-CLI-Binärdatei gefunden.', 'fg-backup-pro');
        } elseif ($shell === '') {
            $reason = __('Die Unix-Shell /bin/sh ist auf diesem Hosting nicht verfügbar.', 'fg-backup-pro');
        } elseif ($nohup === '') {
            $reason = __('Das Programm nohup ist auf diesem Hosting nicht verfügbar.', 'fg-backup-pro');
        } elseif (!$methods) {
            $reason = __('proc_open, exec und shell_exec sind auf diesem Hosting deaktiviert.', 'fg-backup-pro');
        } elseif (!function_exists('escapeshellarg')) {
            $reason = __('escapeshellarg ist auf diesem Hosting nicht verfügbar.', 'fg-backup-pro');
        }

        return [
            'available' => $available,
            'php_binary' => $binary,
            'shell_binary' => $shell,
            'nohup_binary' => $nohup,
            'methods' => $methods,
            'reason' => $reason,
            'disabled_functions' => self::disabled_functions(),
        ];
    }

    public static function launch($job_id, $token) {
        $report = self::capability_report();
        if (empty($report['available'])) {
            return new WP_Error('fg_backup_worker_unavailable', $report['reason']);
        }

        $script = FG_BACKUP_DIR . 'tools/fg-backup-worker.php';
        if (!is_file($script) || !is_readable($script)) {
            return new WP_Error('fg_backup_worker_missing', __('Die Worker-Datei fehlt oder ist nicht lesbar.', 'fg-backup-pro'));
        }

        $parts = [
            escapeshellarg($report['php_binary']),
            escapeshellarg($script),
            '--root=' . escapeshellarg(untrailingslashit(wp_normalize_path(ABSPATH))),
            '--job=' . escapeshellarg(sanitize_key($job_id)),
            '--token=' . escapeshellarg((string) $token),
        ];
        $log_path = FgBackup_Storage::get_job_log_path($job_id);
        $command = escapeshellarg($report['nohup_binary']) . ' ' . implode(' ', $parts)
            . ' >> ' . escapeshellarg($log_path) . ' 2>&1 & echo $!';
        $errors = [];

        foreach ($report['methods'] as $method) {
            $pid = self::launch_with_method($method, $report['shell_binary'], $command, $errors);
            if ($pid > 0) {
                return [
                    'method' => $method,
                    'pid' => $pid,
                    'php_binary' => $report['php_binary'],
                ];
            }
        }

        $message = __('Der PHP-CLI-Worker konnte nicht gestartet werden.', 'fg-backup-pro');
        if ($errors) {
            $message .= ' ' . implode(' ', array_unique($errors));
        }

        return new WP_Error('fg_backup_worker_start_failed', $message);
    }

    public static function is_process_running($pid) {
        $pid = (int) $pid;
        if ($pid <= 0) {
            return false;
        }

        if (self::function_available('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        if (is_dir('/proc/' . $pid)) {
            return true;
        }

        return null;
    }

    private static function launch_with_method($method, $shell, $command, array &$errors) {
        if ($method === 'proc_open') {
            $descriptors = [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $pipes = [];
            $process = @proc_open([$shell, '-c', $command], $descriptors, $pipes);
            if (!is_resource($process)) {
                $errors[] = __('proc_open konnte den Worker nicht starten.', 'fg-backup-pro');
                return 0;
            }

            $stdout = isset($pipes[1]) && is_resource($pipes[1]) ? stream_get_contents($pipes[1]) : '';
            $stderr = isset($pipes[2]) && is_resource($pipes[2]) ? stream_get_contents($pipes[2]) : '';
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            @proc_close($process);

            $pid = self::parse_pid($stdout);
            if ($pid <= 0 && trim((string) $stderr) !== '') {
                $errors[] = sanitize_text_field(trim((string) $stderr));
            }
            return $pid;
        }

        if ($method === 'exec') {
            $output = [];
            $status = 1;
            @exec($command, $output, $status);
            $pid = self::parse_pid(implode("\n", $output));
            if ($pid <= 0) {
                $errors[] = sprintf(__('exec lieferte keinen Worker-Prozess (Status %d).', 'fg-backup-pro'), (int) $status);
            }
            return $pid;
        }

        if ($method === 'shell_exec') {
            $output = @shell_exec($command);
            $pid = self::parse_pid($output);
            if ($pid <= 0) {
                $errors[] = __('shell_exec lieferte keinen Worker-Prozess.', 'fg-backup-pro');
            }
            return $pid;
        }

        return 0;
    }

    private static function parse_pid($value) {
        if (preg_match('/(?:^|\s)(\d+)(?:\s|$)/', (string) $value, $matches)) {
            return max(0, (int) $matches[1]);
        }
        return 0;
    }

    private static function find_php_cli() {
        $candidates = [];

        if (defined('FG_BACKUP_PHP_CLI')) {
            $candidates[] = (string) FG_BACKUP_PHP_CLI;
        }
        if (defined('PHP_BINARY')) {
            $candidates[] = (string) PHP_BINARY;
        }
        if (defined('PHP_BINDIR')) {
            $candidates[] = trailingslashit((string) PHP_BINDIR) . 'php';
        }

        $candidates = array_merge($candidates, [
            '/usr/bin/php',
            '/usr/local/bin/php',
            '/opt/php/bin/php',
        ]);

        $plesk = glob('/opt/plesk/php/*/bin/php');
        if (is_array($plesk)) {
            rsort($plesk, SORT_NATURAL);
            $candidates = array_merge($candidates, $plesk);
        }

        foreach (array_unique($candidates) as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '' || !is_file($candidate) || !is_executable($candidate)) {
                continue;
            }
            $basename = strtolower(basename($candidate));
            if (strpos($basename, 'cgi') !== false || strpos($basename, 'fpm') !== false) {
                continue;
            }
            $real = realpath($candidate);
            return $real !== false ? $real : $candidate;
        }

        return '';
    }

    private static function find_executable(array $candidates) {
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                $real = realpath($candidate);
                return $real !== false ? $real : $candidate;
            }
        }
        return '';
    }

    private static function function_available($function) {
        return function_exists($function) && !in_array(strtolower($function), self::disabled_functions(), true);
    }

    private static function disabled_functions() {
        $disabled = (string) ini_get('disable_functions');
        if ($disabled === '') {
            return [];
        }

        $functions = array_filter(array_map('trim', explode(',', strtolower($disabled))));
        return array_values(array_unique($functions));
    }
}
