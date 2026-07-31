<?php

defined('ABSPATH') || exit;

class FgBackup_Webdav {
    const TEST_FILE_PREFIX = '.fg-backup-test-';

    public static function available() {
        return function_exists('curl_init') && function_exists('curl_exec');
    }

    public static function enabled() {
        return (bool) get_option('fg_backup_webdav_enabled', 0);
    }

    public static function settings() {
        return [
            'base_url' => self::sanitize_base_url(get_option('fg_backup_webdav_base_url', '')),
            'username' => trim((string) get_option('fg_backup_webdav_username', '')),
            'remote_dir' => self::sanitize_remote_dir(get_option('fg_backup_webdav_remote_dir', '/backups/%host')),
            'retention' => max(1, min(100, (int) get_option('fg_backup_webdav_retention', 10))),
            'allow_private' => (bool) get_option('fg_backup_webdav_allow_private', 0),
        ];
    }

    public static function password() {
        return defined('FG_BACKUP_WEBDAV_PASSWORD')
            ? (string) FG_BACKUP_WEBDAV_PASSWORD
            : FgBackup_Secrets::decrypt((string) get_option('fg_backup_webdav_password', ''));
    }

    public static function sanitize_base_url($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $value = esc_url_raw($value, ['https']);
        return rtrim((string) $value, '/');
    }

    public static function sanitize_remote_dir($value) {
        $value = trim(str_replace('\\', '/', (string) $value));
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', '', $value);
        $value = preg_replace('#/+#', '/', (string) $value);
        $value = preg_replace('#(?:^|/)\.{1,2}(?=/|$)#', '', (string) $value);
        $value = preg_replace('/[^A-Za-z0-9%._\/-]+/u', '-', (string) $value);
        $value = '/' . ltrim((string) $value, '/');
        return rtrim($value, '/') ?: '/';
    }

    public static function resolved_remote_dir(array $settings = null) {
        $settings = $settings ?: self::settings();
        $host = sanitize_title(preg_replace('/^www\./i', '', (string) wp_parse_url(home_url('/'), PHP_URL_HOST)));
        $site = sanitize_title((string) get_bloginfo('name'));
        return self::sanitize_remote_dir(strtr($settings['remote_dir'], [
            '%host' => $host !== '' ? $host : 'wordpress',
            '%site' => $site !== '' ? $site : 'wordpress',
        ]));
    }

    public static function assert_ready() {
        if (!self::available()) {
            throw new RuntimeException(__('WebDAV ist aktiviert, aber die PHP-cURL-Erweiterung fehlt.', 'fg-backup-pro'));
        }
        $settings = self::settings();
        if ($settings['base_url'] === '' || $settings['username'] === '' || self::password() === '') {
            throw new RuntimeException(__('WebDAV ist nicht vollständig eingerichtet.', 'fg-backup-pro'));
        }
        self::assert_safe_url($settings['base_url'], $settings['allow_private']);
    }

    public static function test_connection() {
        self::assert_ready();
        $settings = self::settings();
        $directory = self::resolved_remote_dir($settings);
        self::ensure_directory($settings, $directory);

        $name = self::TEST_FILE_PREFIX . strtolower(wp_generate_password(12, false, false)) . '.tmp';
        $path = rtrim($directory, '/') . '/' . $name;
        $url = self::url_for_path($settings, $path);
        $payload = 'FG Backup Pro ' . gmdate('c');
        $error = null;

        try {
            $response = self::request($settings, 'PUT', $url, [
                'body' => $payload,
                'headers' => ['Content-Type: application/octet-stream'],
                'timeout' => 60,
            ]);
            self::assert_status($response, [200, 201, 204], __('Die WebDAV-Testdatei konnte nicht geschrieben werden.', 'fg-backup-pro'));

            $read = self::request($settings, 'GET', $url, ['timeout' => 60]);
            self::assert_status($read, [200, 206], __('Die WebDAV-Testdatei konnte nicht gelesen werden.', 'fg-backup-pro'));
            if (!is_string($read['body']) || !hash_equals($payload, $read['body'])) {
                throw new RuntimeException(__('Die WebDAV-Testdatei wurde nicht korrekt zurückgelesen.', 'fg-backup-pro'));
            }
        } catch (Throwable $exception) {
            $error = $exception;
        }

        try {
            $deleted = self::request($settings, 'DELETE', $url, ['timeout' => 60]);
            if (!in_array((int) $deleted['status'], [200, 202, 204, 404], true) && $error === null) {
                $error = new RuntimeException(__('Die WebDAV-Testdatei konnte nicht gelöscht werden.', 'fg-backup-pro'));
            }
        } catch (Throwable $delete_error) {
            if ($error === null) {
                $error = $delete_error;
            }
        }

        if ($error instanceof Throwable) {
            throw $error;
        }

        return [
            'directory' => $directory,
            'url' => $settings['base_url'],
        ];
    }

    public static function prepare_upload($local_path, $file_name) {
        self::assert_ready();
        if (!is_file($local_path) || !is_readable($local_path)) {
            throw new RuntimeException(__('Die lokale Backup-Datei ist für WebDAV nicht lesbar.', 'fg-backup-pro'));
        }

        $settings = self::settings();
        $directory = self::resolved_remote_dir($settings);
        self::ensure_directory($settings, $directory);
        $remote_path = self::unique_remote_path($settings, $directory, $file_name);
        $remote_temp = $remote_path . '.part';

        try {
            $existing = self::request($settings, 'DELETE', self::url_for_path($settings, $remote_temp), ['timeout' => 30]);
            if (!in_array((int) $existing['status'], [200, 202, 204, 404], true)) {
                throw new RuntimeException(__('Eine vorhandene WebDAV-Teildatei konnte nicht entfernt werden.', 'fg-backup-pro'));
            }
        } catch (Throwable $exception) {
            // 404 und nicht vorhandene Teildateien sind unkritisch; andere Fehler werden im Upload sichtbar.
        }

        return [
            'remote_path' => $remote_path,
            'remote_temp' => $remote_temp,
            'remote_dir' => $directory,
            'offset' => 0,
            'total' => (int) filesize($local_path),
            'done' => false,
        ];
    }

    public static function upload_state($local_path, array $state, $cancel_callback = null, $progress_callback = null) {
        $total = isset($state['total']) ? max(0, (int) $state['total']) : (int) filesize($local_path);
        if (!empty($state['done'])) {
            return ['offset' => $total, 'total' => $total, 'done' => true];
        }

        $settings = self::settings();
        $remote_temp = isset($state['remote_temp']) ? (string) $state['remote_temp'] : '';
        if ($remote_temp === '') {
            throw new RuntimeException(__('Der temporäre WebDAV-Pfad fehlt.', 'fg-backup-pro'));
        }

        $response = self::request($settings, 'PUT', self::url_for_path($settings, $remote_temp), [
            'upload_file' => $local_path,
            'headers' => ['Content-Type: application/octet-stream'],
            'timeout' => 0,
            'cancel_callback' => $cancel_callback,
            'progress_callback' => $progress_callback,
        ]);
        self::assert_status($response, [200, 201, 204], __('Der WebDAV-Upload ist fehlgeschlagen.', 'fg-backup-pro'));

        $info = self::remote_info($settings, $remote_temp);
        if (!is_array($info) || (int) $info['size'] !== $total) {
            throw new RuntimeException(__('Die Größe der WebDAV-Datei stimmt nicht mit dem lokalen Backup überein.', 'fg-backup-pro'));
        }

        if (is_callable($progress_callback)) {
            call_user_func($progress_callback, $total, $total);
        }

        return ['offset' => $total, 'total' => $total, 'done' => true];
    }

    public static function finalize_state(array $state) {
        $settings = self::settings();
        $remote_temp = isset($state['remote_temp']) ? (string) $state['remote_temp'] : '';
        $remote_path = isset($state['remote_path']) ? (string) $state['remote_path'] : '';
        $total = isset($state['total']) ? (int) $state['total'] : 0;
        if ($remote_temp === '' || $remote_path === '') {
            throw new RuntimeException(__('Die WebDAV-Zielpfade fehlen.', 'fg-backup-pro'));
        }

        $source_url = self::url_for_path($settings, $remote_temp);
        $destination_url = self::url_for_path($settings, $remote_path);
        $response = self::request($settings, 'MOVE', $source_url, [
            'headers' => [
                'Destination: ' . $destination_url,
                'Overwrite: F',
            ],
            'timeout' => 60,
        ]);
        self::assert_status($response, [200, 201, 204], __('Die WebDAV-Datei konnte nicht finalisiert werden.', 'fg-backup-pro'));

        $info = self::remote_info($settings, $remote_path);
        if (!is_array($info) || (int) $info['size'] !== $total) {
            throw new RuntimeException(__('Die finalisierte WebDAV-Datei konnte nicht bestätigt werden.', 'fg-backup-pro'));
        }

        return $remote_path;
    }

    public static function remove_partial_state(array $state) {
        $path = isset($state['remote_temp']) ? (string) $state['remote_temp'] : '';
        if ($path === '') {
            return;
        }
        try {
            $settings = self::settings();
            self::request($settings, 'DELETE', self::url_for_path($settings, $path), ['timeout' => 30]);
        } catch (Throwable $exception) {
            // Best effort.
        }
    }

    public static function list_backups() {
        self::assert_ready();
        $settings = self::settings();
        $directory = self::resolved_remote_dir($settings);
        $response = self::propfind($settings, $directory, 1, true);
        if ((int) $response['status'] === 404) {
            return [];
        }
        self::assert_status($response, [207], __('Die WebDAV-Dateiliste konnte nicht gelesen werden.', 'fg-backup-pro'));

        $files = [];
        foreach (self::parse_propfind($response['body']) as $item) {
            $name = isset($item['name']) ? $item['name'] : '';
            if (!self::is_backup_file_name($name)) {
                continue;
            }
            $size = isset($item['size']) ? (int) $item['size'] : 0;
            $mtime = isset($item['mtime']) ? (int) $item['mtime'] : 0;
            $files[] = [
                'name' => $name,
                'path' => rtrim($directory, '/') . '/' . $name,
                'size_bytes' => $size,
                'size' => size_format($size, 2),
                'mtime' => $mtime,
                'date' => $mtime > 0 ? wp_date('d.m.Y H:i', $mtime) : '–',
            ];
        }

        usort($files, static function ($left, $right) {
            return $right['mtime'] <=> $left['mtime'];
        });
        return array_slice($files, 0, 100);
    }

    public static function delete_backup($file_name) {
        $file_name = (string) $file_name;
        if ($file_name === '' || basename($file_name) !== $file_name || !self::is_backup_file_name($file_name)) {
            throw new RuntimeException(__('Ungültiger WebDAV-Dateiname.', 'fg-backup-pro'));
        }
        self::assert_ready();
        $settings = self::settings();
        $path = rtrim(self::resolved_remote_dir($settings), '/') . '/' . $file_name;
        $response = self::request($settings, 'DELETE', self::url_for_path($settings, $path), ['timeout' => 60]);
        if ((int) $response['status'] === 404) {
            throw new RuntimeException(__('Das WebDAV-Backup wurde nicht gefunden.', 'fg-backup-pro'));
        }
        self::assert_status($response, [200, 202, 204], __('Das WebDAV-Backup konnte nicht gelöscht werden.', 'fg-backup-pro'));
        try {
            self::request($settings, 'DELETE', self::url_for_path($settings, $path . '.json'), ['timeout' => 30]);
        } catch (Throwable $exception) {
            // Begleitendes Manifest wird bestmöglich entfernt.
        }
        return true;
    }

    private static function ensure_directory(array $settings, $directory) {
        $directory = self::sanitize_remote_dir($directory);
        if ($directory === '/') {
            return;
        }
        $segments = array_values(array_filter(explode('/', trim($directory, '/')), 'strlen'));
        $current = '';
        foreach ($segments as $segment) {
            $current .= '/' . $segment;
            $url = self::url_for_path($settings, $current);
            $check = self::propfind($settings, $current, 0, true);
            if (in_array((int) $check['status'], [200, 207], true)) {
                continue;
            }
            if ((int) $check['status'] !== 404) {
                self::assert_status($check, [200, 207, 404], __('Das WebDAV-Zielverzeichnis konnte nicht geprüft werden.', 'fg-backup-pro'));
            }
            $create = self::request($settings, 'MKCOL', $url, ['timeout' => 60]);
            self::assert_status($create, [201, 204, 405], __('Das WebDAV-Zielverzeichnis konnte nicht angelegt werden.', 'fg-backup-pro'));
        }
    }

    private static function unique_remote_path(array $settings, $directory, $file_name) {
        $file_name = basename(str_replace('\\', '/', (string) $file_name));
        if (!self::is_remote_artifact_file_name($file_name)) {
            throw new RuntimeException(__('Der Dateiname der Remote-Datei ist für WebDAV ungültig.', 'fg-backup-pro'));
        }
        $candidate = rtrim($directory, '/') . '/' . $file_name;
        if (self::remote_info($settings, $candidate) === null && self::remote_info($settings, $candidate . '.part') === null) {
            return $candidate;
        }

        list($base, $extension) = self::split_extension($file_name);
        for ($number = 2; $number < 1000; $number++) {
            $candidate = rtrim($directory, '/') . '/' . $base . '-' . $number . $extension;
            if (self::remote_info($settings, $candidate) === null && self::remote_info($settings, $candidate . '.part') === null) {
                return $candidate;
            }
        }
        throw new RuntimeException(__('Auf dem WebDAV-Ziel konnte kein freier Dateiname ermittelt werden.', 'fg-backup-pro'));
    }

    private static function split_extension($file_name) {
        foreach (['.sql.zip.json', '.sql.gz.json', '.tar.gz.json', '.tgz.json', '.zip.json', '.sql.json', '.sql.zip', '.sql.gz', '.tar.gz', '.tgz', '.zip', '.sql'] as $known) {
            if (substr(strtolower($file_name), -strlen($known)) === $known) {
                return [substr($file_name, 0, -strlen($known)), substr($file_name, -strlen($known))];
            }
        }
        return [$file_name, ''];
    }

    private static function is_backup_file_name($name) {
        return is_string($name)
            && $name !== ''
            && basename($name) === $name
            && substr($name, -5) !== '.part'
            && (bool) preg_match('/(?:\.sql\.zip|\.sql\.gz|\.tar\.gz|\.tgz|\.zip|\.sql)$/i', $name);
    }

    private static function is_remote_artifact_file_name($name) {
        if (self::is_backup_file_name($name)) {
            return true;
        }

        if (!is_string($name) || substr(strtolower($name), -5) !== '.json') {
            return false;
        }

        return self::is_backup_file_name(substr($name, 0, -5));
    }

    private static function rotate(array $settings, $directory, $keep) {
        try {
            $response = self::propfind($settings, $directory, 1, true);
            if ((int) $response['status'] !== 207) {
                return;
            }
            $files = [];
            foreach (self::parse_propfind($response['body']) as $item) {
                if (!self::is_backup_file_name(isset($item['name']) ? $item['name'] : '')) {
                    continue;
                }
                $path = rtrim($directory, '/') . '/' . $item['name'];
                $files[] = [
                    'name' => $item['name'],
                    'path' => $path,
                    'mtime' => isset($item['mtime']) ? (int) $item['mtime'] : 0,
                    'full' => self::is_full_backup_name($item['name']),
                    'valid' => self::remote_manifest_valid($settings, $path . '.json'),
                ];
            }
            usort($files, static function ($left, $right) {
                return $right['mtime'] <=> $left['mtime'];
            });

            $valid_full = 0;
            $full = 0;
            foreach ($files as $file) {
                if (!empty($file['full'])) {
                    $full++;
                    if (!empty($file['valid'])) {
                        $valid_full++;
                    }
                }
            }

            foreach (array_slice($files, max(1, (int) $keep)) as $file) {
                if (!empty($file['full'])) {
                    if (!empty($file['valid']) && $valid_full <= 1) {
                        continue;
                    }
                    if ($valid_full === 0 && $full <= 1) {
                        continue;
                    }
                }
                $delete = self::request($settings, 'DELETE', self::url_for_path($settings, $file['path']), ['timeout' => 30]);
                if (in_array((int) $delete['status'], [200, 202, 204, 404], true)) {
                    self::request($settings, 'DELETE', self::url_for_path($settings, $file['path'] . '.json'), ['timeout' => 30]);
                    if (!empty($file['full'])) {
                        $full--;
                        if (!empty($file['valid'])) {
                            $valid_full--;
                        }
                    }
                }
            }
        } catch (Throwable $exception) {
            // Rotation ist best effort und darf ein erfolgreiches Backup nicht nachträglich entwerten.
        }
    }

    private static function is_full_backup_name($name) {
        return self::is_backup_file_name($name) && !preg_match('/\.sql(?:\.gz|\.zip)?$/i', (string) $name);
    }

    private static function remote_manifest_valid(array $settings, $path) {
        try {
            $response = self::request($settings, 'GET', self::url_for_path($settings, $path), ['timeout' => 30]);
            if ((int) $response['status'] !== 200) {
                return false;
            }
            $data = json_decode((string) $response['body'], true);
            return is_array($data) && !empty($data['validation']['status']) && $data['validation']['status'] === 'valid';
        } catch (Throwable $exception) {
            return false;
        }
    }

    public static function read_remote_manifest($manifest_path) {
        try {
            $settings = self::settings();
            $response = self::request($settings, 'GET', self::url_for_path($settings, (string) $manifest_path), ['timeout' => 30]);
            if ((int) $response['status'] !== 200) {
                return [];
            }
            $data = json_decode((string) $response['body'], true);
            return is_array($data) ? $data : [];
        } catch (Throwable $exception) {
            return [];
        }
    }

    public static function rotate_now() {
        try {
            $settings = self::settings();
            self::rotate($settings, self::resolved_remote_dir($settings), $settings['retention']);
        } catch (Throwable $exception) {
            // Rotation ist best effort.
        }
    }

    private static function remote_info(array $settings, $path) {
        $response = self::propfind($settings, $path, 0);
        if ((int) $response['status'] === 404) {
            return null;
        }
        if (!in_array((int) $response['status'], [200, 207], true)) {
            return null;
        }
        $items = self::parse_propfind($response['body']);
        return $items ? reset($items) : null;
    }

    private static function propfind(array $settings, $path, $depth, $collection = false) {
        $body = '<?xml version="1.0" encoding="utf-8" ?>'
            . '<d:propfind xmlns:d="DAV:"><d:prop>'
            . '<d:resourcetype/><d:getcontentlength/><d:getlastmodified/><d:displayname/>'
            . '</d:prop></d:propfind>';
        return self::request($settings, 'PROPFIND', self::url_for_path($settings, $path, (bool) $collection), [
            'body' => $body,
            'headers' => [
                'Depth: ' . (int) $depth,
                'Content-Type: application/xml; charset=utf-8',
            ],
            'timeout' => 60,
        ]);
    }

    private static function parse_propfind($xml) {
        if (!is_string($xml) || trim($xml) === '' || !class_exists('DOMDocument')) {
            return [];
        }
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return [];
        }
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('d', 'DAV:');
        $items = [];
        foreach ($xpath->query('//d:response') as $response) {
            $href = self::xpath_value($xpath, './d:href', $response);
            $display = self::xpath_value($xpath, './/d:displayname', $response);
            $decoded_path = rawurldecode((string) wp_parse_url($href, PHP_URL_PATH));
            $name = $display !== '' ? $display : basename(rtrim($decoded_path, '/'));
            $size_value = self::xpath_value($xpath, './/d:getcontentlength', $response);
            $modified = self::xpath_value($xpath, './/d:getlastmodified', $response);
            $is_collection = $xpath->query('.//d:resourcetype/d:collection', $response)->length > 0;
            $items[] = [
                'name' => $name,
                'href' => $href,
                'size' => $size_value !== '' ? (int) $size_value : 0,
                'mtime' => $modified !== '' ? (int) strtotime($modified) : 0,
                'collection' => $is_collection,
            ];
        }
        return $items;
    }

    private static function xpath_value(DOMXPath $xpath, $query, DOMNode $context) {
        $nodes = $xpath->query($query, $context);
        return $nodes && $nodes->length ? trim((string) $nodes->item(0)->textContent) : '';
    }

    private static function url_for_path(array $settings, $path, $collection = false) {
        $segments = array_values(array_filter(explode('/', trim((string) $path, '/')), 'strlen'));
        $encoded = array_map('rawurlencode', $segments);
        $url = rtrim($settings['base_url'], '/') . ($encoded ? '/' . implode('/', $encoded) : '');

        // WebDAV collections need a canonical trailing slash on servers such as
        // Apache / Hetzner Storage Box. Otherwise PROPFIND may return HTTP 301.
        if ($collection && substr($url, -1) !== '/') {
            $url .= '/';
        }

        return $url;
    }

    private static function assert_safe_url($url, $allow_private) {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || strtolower(isset($parts['scheme']) ? $parts['scheme'] : '') !== 'https' || empty($parts['host'])) {
            throw new RuntimeException(__('WebDAV benötigt eine vollständige HTTPS-URL.', 'fg-backup-pro'));
        }
        if (!empty($parts['user']) || !empty($parts['pass'])) {
            throw new RuntimeException(__('Benutzername und Passwort dürfen nicht Bestandteil der WebDAV-URL sein.', 'fg-backup-pro'));
        }
        $host = trim((string) $parts['host'], '[]');
        if ($allow_private) {
            return [];
        }
        if (strtolower($host) === 'localhost') {
            throw new RuntimeException(__('Lokale WebDAV-Adressen sind standardmäßig blockiert.', 'fg-backup-pro'));
        }
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $resolved = @gethostbynamel($host);
            if (is_array($resolved)) {
                $ips = array_merge($ips, $resolved);
            }
            if (function_exists('dns_get_record') && defined('DNS_AAAA')) {
                $records = @dns_get_record($host, DNS_AAAA);
                if (is_array($records)) {
                    foreach ($records as $record) {
                        if (!empty($record['ipv6'])) {
                            $ips[] = $record['ipv6'];
                        }
                    }
                }
            }
        }
        if (!$ips) {
            throw new RuntimeException(__('Die WebDAV-Adresse konnte nicht sicher aufgelöst werden.', 'fg-backup-pro'));
        }
        $ips = array_values(array_unique($ips));
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException(__('Private oder reservierte WebDAV-Adressen sind blockiert. Aktiviere sie nur bewusst für NAS oder interne Server.', 'fg-backup-pro'));
            }
        }
        return $ips;
    }

    private static function request(array $settings, $method, $url, array $options = []) {
        $resolved_ips = self::assert_safe_url($url, !empty($settings['allow_private']));
        $handle = curl_init();
        if ($handle === false) {
            throw new RuntimeException(__('WebDAV konnte cURL nicht initialisieren.', 'fg-backup-pro'));
        }

        $headers = isset($options['headers']) && is_array($options['headers']) ? $options['headers'] : [];
        $response_headers = [];
        $upload_handle = null;
        $timeout = array_key_exists('timeout', $options) ? (int) $options['timeout'] : 60;

        curl_setopt($handle, CURLOPT_URL, $url);
        if ($resolved_ips) {
            $parts = wp_parse_url($url);
            $host = isset($parts['host']) ? trim((string) $parts['host'], '[]') : '';
            $port = !empty($parts['port']) ? (int) $parts['port'] : 443;
            $ip = reset($resolved_ips);
            if ($host !== '' && is_string($ip) && $ip !== '' && !filter_var($host, FILTER_VALIDATE_IP)) {
                $resolved_address = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '[' . $ip . ']' : $ip;
                curl_setopt($handle, CURLOPT_RESOLVE, [$host . ':' . $port . ':' . $resolved_address]);
            }
        }
        curl_setopt($handle, CURLOPT_CUSTOMREQUEST, strtoupper((string) $method));
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($handle, CURLOPT_TIMEOUT, max(0, $timeout));
        curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, 2);
        // Use Basic authentication directly instead of CURLAUTH_ANY. With streamed
        // PUT uploads, CURLAUTH_ANY first probes the server and may then need to
        // resend the request body. PHP's cURL stream cannot always be rewound,
        // which results in CURLE_SEND_FAIL_REWIND (65).
        curl_setopt($handle, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($handle, CURLOPT_USERPWD, $settings['username'] . ':' . self::password());
        curl_setopt($handle, CURLOPT_HEADERFUNCTION, static function ($curl, $line) use (&$response_headers) {
            $length = strlen($line);
            $line = trim($line);
            if ($line !== '' && strpos($line, ':') !== false) {
                list($name, $value) = array_map('trim', explode(':', $line, 2));
                $response_headers[strtolower($name)] = $value;
            }
            return $length;
        });

        if (!empty($options['upload_file'])) {
            $upload_handle = fopen($options['upload_file'], 'rb');
            if (!$upload_handle) {
                curl_close($handle);
                throw new RuntimeException(__('Die lokale Datei konnte für WebDAV nicht geöffnet werden.', 'fg-backup-pro'));
            }
            $size = (int) filesize($options['upload_file']);
            curl_setopt($handle, CURLOPT_UPLOAD, true);
            curl_setopt($handle, CURLOPT_INFILE, $upload_handle);
            curl_setopt($handle, CURLOPT_INFILESIZE, $size);
            curl_setopt($handle, CURLOPT_NOPROGRESS, false);
            $cancel_callback = isset($options['cancel_callback']) ? $options['cancel_callback'] : null;
            $progress_callback = isset($options['progress_callback']) ? $options['progress_callback'] : null;
            curl_setopt($handle, CURLOPT_XFERINFOFUNCTION, static function ($curl, $download_total, $downloaded, $upload_total, $uploaded) use ($cancel_callback, $progress_callback, $size) {
                if (is_callable($progress_callback)) {
                    call_user_func($progress_callback, (int) $uploaded, $upload_total > 0 ? (int) $upload_total : $size);
                }
                return is_callable($cancel_callback) && call_user_func($cancel_callback) ? 1 : 0;
            });
        } elseif (array_key_exists('body', $options)) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, (string) $options['body']);
        }

        if ($headers) {
            curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
        }

        $body = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if (is_resource($upload_handle)) {
            fclose($upload_handle);
        }

        if ($errno !== 0) {
            if ($errno === CURLE_ABORTED_BY_CALLBACK) {
                throw new RuntimeException(__('Der WebDAV-Upload wurde abgebrochen.', 'fg-backup-pro'));
            }
            if (defined('CURLE_SEND_FAIL_REWIND') && $errno === CURLE_SEND_FAIL_REWIND) {
                throw new RuntimeException(__('Der WebDAV-Server hat den Upload erneut angefordert, aber der Dateistream konnte nicht zurückgespult werden.', 'fg-backup-pro'));
            }
            throw new RuntimeException(sprintf(__('WebDAV-Verbindungsfehler: %s', 'fg-backup-pro'), $error !== '' ? $error : (string) $errno));
        }

        return [
            'status' => $status,
            'body' => is_string($body) ? $body : '',
            'headers' => $response_headers,
        ];
    }

    private static function assert_status(array $response, array $allowed, $message) {
        if (in_array((int) $response['status'], $allowed, true)) {
            return;
        }
        $detail = trim(wp_strip_all_tags((string) $response['body']));
        if (strlen($detail) > 240) {
            $detail = substr($detail, 0, 240) . '…';
        }
        $status = (int) $response['status'];
        $error_message = $detail !== ''
            ? sprintf('%s HTTP %d: %s', $message, $status, $detail)
            : sprintf('%s HTTP %d', $message, $status);

        if ($status === 403) {
            $error_message .= ' ' . __('Prüfe Benutzername, Passwort, Schreibrechte und ob WebDAV beim Anbieter aktiviert ist.', 'fg-backup-pro');
        }

        throw new RuntimeException($error_message);
    }
}
