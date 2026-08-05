<?php

defined('ABSPATH') || exit;

class FgBackup_Worker {

    const START_GRACE_SECONDS = 30;
    const MIN_PHP_VERSION_ID = 70400;

    private static $capability_cache = null;

    public static function capability_report() {
        if (is_array(self::$capability_cache)) {
            return self::$capability_cache;
        }

        $shell = @is_executable('/bin/sh') ? '/bin/sh' : '';
        $nohup = self::find_executable(['/usr/bin/nohup', '/bin/nohup']);
        $methods = [];

        foreach (['proc_open', 'exec', 'shell_exec'] as $function) {
            if (self::function_available($function)) {
                $methods[] = $function;
            }
        }

        $binary = '';
        $probe_errors = [];
        $reason = '';

        if (defined('FG_BACKUP_DISABLE_CLI_WORKER') && FG_BACKUP_DISABLE_CLI_WORKER) {
            $reason = __('Der PHP-CLI-Worker wurde über FG_BACKUP_DISABLE_CLI_WORKER deaktiviert.', 'fg-backup-pro');
        } elseif (DIRECTORY_SEPARATOR !== '/') {
            $reason = __('Der Hintergrund-Worker wird derzeit nur auf Unix-/Linux-Hosting gestartet.', 'fg-backup-pro');
        } elseif ($shell === '') {
            $reason = __('Die Unix-Shell /bin/sh ist auf diesem Hosting nicht verfügbar.', 'fg-backup-pro');
        } elseif ($nohup === '') {
            $reason = __('Das Programm nohup ist auf diesem Hosting nicht verfügbar.', 'fg-backup-pro');
        } elseif (!$methods) {
            $reason = __('proc_open, exec und shell_exec sind auf diesem Hosting deaktiviert.', 'fg-backup-pro');
        } elseif (!function_exists('escapeshellarg')) {
            $reason = __('escapeshellarg ist auf diesem Hosting nicht verfügbar.', 'fg-backup-pro');
        } else {
            $binary = self::find_php_cli($methods, $shell, $probe_errors);
            if ($binary === '') {
                $reason = __('Es wurde keine startfähige PHP-CLI-Binärdatei mit PHP 7.4 oder neuer und mysqli-Unterstützung gefunden.', 'fg-backup-pro');
                if ($probe_errors) {
                    $reason .= ' ' . implode(' ', array_slice(array_unique($probe_errors), 0, 3));
                }
            }
        }

        $probe_errors = array_values(array_unique($probe_errors));
        $available = $reason === '' && $binary !== '';

        self::$capability_cache = [
            'available' => $available,
            'php_binary' => $binary,
            'shell_binary' => $shell,
            'nohup_binary' => $nohup,
            'methods' => $methods,
            'reason' => $reason,
            'disabled_functions' => self::disabled_functions(),
            'probe_errors' => $probe_errors,
        ];

        return self::$capability_cache;
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
            if (@posix_kill($pid, 0)) {
                return true;
            }
            if (@is_dir('/proc/' . $pid)) {
                return true;
            }
            return false;
        }

        if (@is_dir('/proc/' . $pid)) {
            return true;
        }
        if (@is_dir('/proc')) {
            return false;
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

    private static function find_php_cli(array $methods, $shell, array &$errors) {
        foreach (self::php_cli_candidates() as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '' || !@is_file($candidate) || !@is_executable($candidate)) {
                continue;
            }

            $basename = strtolower(basename($candidate));
            if (!self::looks_like_php_cli_name($basename)) {
                continue;
            }

            $real = @realpath($candidate);
            $candidate = $real !== false ? $real : $candidate;
            $probe = self::probe_php_cli($candidate, $methods, $shell);
            if (!empty($probe['valid'])) {
                return $candidate;
            }

            if (!empty($probe['error'])) {
                $errors[] = sprintf('%s: %s', $candidate, $probe['error']);
            }
        }

        return '';
    }

    private static function php_cli_candidates() {
        $candidates = [];

        if (defined('FG_BACKUP_PHP_CLI')) {
            $candidates[] = (string) FG_BACKUP_PHP_CLI;
        }

        $current_version = defined('PHP_MAJOR_VERSION') && defined('PHP_MINOR_VERSION')
            ? PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION
            : '';
        $versions = array_values(array_unique(array_filter([
            $current_version,
            '8.5', '8.4', '8.3', '8.2', '8.1', '8.0', '7.4',
        ])));

        foreach ($versions as $version) {
            $compact = str_replace('.', '', $version);
            foreach (['/usr/bin/', '/usr/local/bin/'] as $prefix) {
                $candidates[] = $prefix . 'php' . $version;
                $candidates[] = $prefix . 'php' . $version . '-cli';
                $candidates[] = $prefix . 'php' . $compact;
                $candidates[] = $prefix . 'php' . $compact . '-cli';
            }
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

        foreach ([
            '/usr/bin/php*',
            '/usr/local/bin/php*',
            '/opt/plesk/php/*/bin/php',
            '/opt/alt/php*/usr/bin/php',
            '/opt/php*/bin/php',
            '/usr/local/php*/bin/php',
        ] as $pattern) {
            $found = @glob($pattern);
            if (is_array($found)) {
                rsort($found, SORT_NATURAL);
                $candidates = array_merge($candidates, $found);
            }
        }

        $unique = [];
        foreach ($candidates as $candidate) {
            $key = wp_normalize_path((string) $candidate);
            if ($key === '' || isset($unique[$key])) {
                continue;
            }
            $unique[$key] = (string) $candidate;
        }

        return array_values($unique);
    }

    private static function looks_like_php_cli_name($basename) {
        if (strpos($basename, 'cgi') !== false || strpos($basename, 'fpm') !== false || strpos($basename, 'phpize') !== false || strpos($basename, 'php-config') !== false) {
            return false;
        }

        return (bool) preg_match('/^php(?:-?cli|\d+(?:\.\d+)?(?:-cli)?)?$/', $basename);
    }

    private static function probe_php_cli($candidate, array $methods, $shell) {
        $code = 'echo PHP_SAPI, "|", PHP_VERSION_ID, "|", (extension_loaded("mysqli") ? "1" : "0");';
        $command = escapeshellarg($candidate) . ' -r ' . escapeshellarg($code);
        $messages = [];

        foreach ($methods as $method) {
            $result = self::run_probe_command($method, $shell, $command);
            $output = trim(isset($result['output']) ? (string) $result['output'] : '');
            if (preg_match('/(?:^|\s)cli\|(\d+)\|([01])(?:\s|$)/', $output, $matches)) {
                $version_id = (int) $matches[1];
                $mysqli = $matches[2] === '1';
                if ($version_id < self::MIN_PHP_VERSION_ID) {
                    return [
                        'valid' => false,
                        'error' => sprintf(__('PHP-CLI ist zu alt (Version-ID %d).', 'fg-backup-pro'), $version_id),
                    ];
                }
                if (!$mysqli) {
                    return [
                        'valid' => false,
                        'error' => __('Die CLI-PHP-Konfiguration lädt mysqli nicht.', 'fg-backup-pro'),
                    ];
                }
                return ['valid' => true, 'error' => ''];
            }

            $error = trim(isset($result['error']) ? (string) $result['error'] : '');
            if ($error !== '') {
                $messages[] = sanitize_text_field($error);
            } elseif ($output !== '') {
                $messages[] = sanitize_text_field($output);
            }
        }

        return [
            'valid' => false,
            'error' => $messages
                ? implode(' ', array_slice(array_unique($messages), 0, 2))
                : __('Die CLI-Prüfung lieferte keine gültige Antwort.', 'fg-backup-pro'),
        ];
    }

    private static function run_probe_command($method, $shell, $command) {
        if ($method === 'proc_open') {
            $descriptors = [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $pipes = [];
            $process = @proc_open([$shell, '-c', $command], $descriptors, $pipes);
            if (!is_resource($process)) {
                return ['output' => '', 'error' => 'proc_open failed', 'status' => 1];
            }

            $stdout = isset($pipes[1]) && is_resource($pipes[1]) ? stream_get_contents($pipes[1]) : '';
            $stderr = isset($pipes[2]) && is_resource($pipes[2]) ? stream_get_contents($pipes[2]) : '';
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            $status = @proc_close($process);
            return ['output' => $stdout, 'error' => $stderr, 'status' => (int) $status];
        }

        if ($method === 'exec') {
            $output = [];
            $status = 1;
            @exec($command . ' 2>&1', $output, $status);
            return ['output' => implode("\n", $output), 'error' => '', 'status' => (int) $status];
        }

        if ($method === 'shell_exec') {
            $output = @shell_exec($command . ' 2>&1');
            return ['output' => (string) $output, 'error' => '', 'status' => null];
        }

        return ['output' => '', 'error' => 'unsupported probe method', 'status' => 1];
    }

    private static function find_executable(array $candidates) {
        foreach ($candidates as $candidate) {
            if (@is_file($candidate) && @is_executable($candidate)) {
                $real = @realpath($candidate);
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
