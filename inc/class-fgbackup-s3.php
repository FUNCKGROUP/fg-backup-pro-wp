<?php

defined('ABSPATH') || exit;

/**
 * Lightweight S3-compatible remote target.
 *
 * Uses the S3 REST API with AWS Signature Version 4 directly so FG Backup Pro
 * remains compatible with PHP 7.4 and does not require the full AWS SDK.
 */
class FgBackup_S3 {
    const TEST_FILE_PREFIX = '.fg-backup-test-';
    const MIN_PART_SIZE = 5242880; // S3 requires at least 5 MiB for every non-final part.
    const DEFAULT_PART_SIZE = 8388608; // 8 MiB.
    const MAX_PARTS = 10000;

    public static function available() {
        return function_exists('curl_init')
            && function_exists('curl_exec')
            && function_exists('hash_hmac')
            && class_exists('DOMDocument');
    }

    public static function enabled() {
        return (bool) get_option('fg_backup_s3_enabled', 0);
    }

    public static function settings() {
        return [
            'provider' => self::sanitize_provider(get_option('fg_backup_s3_provider', 'custom')),
            'endpoint' => self::sanitize_endpoint(get_option('fg_backup_s3_endpoint', '')),
            'region' => self::sanitize_region(get_option('fg_backup_s3_region_name', 'us-east-1')),
            'bucket' => self::sanitize_bucket(get_option('fg_backup_s3_bucket_name', '')),
            'path_style' => (bool) get_option('fg_backup_s3_path_style', 1),
            'remote_dir' => self::sanitize_remote_dir(get_option('fg_backup_s3_remote_dir', '/backups/%host')),
            'retention' => max(1, min(100, (int) get_option('fg_backup_s3_retention', 10))),
            'allow_private' => (bool) get_option('fg_backup_s3_allow_private', 0),
            'allow_http' => (bool) get_option('fg_backup_s3_allow_http', 0),
        ];
    }

    public static function access_key() {
        return defined('FG_BACKUP_S3_ACCESS_KEY')
            ? trim((string) FG_BACKUP_S3_ACCESS_KEY)
            : trim(FgBackup_Secrets::decrypt((string) get_option('fg_backup_s3_access_key', '')));
    }

    public static function secret_key() {
        return defined('FG_BACKUP_S3_SECRET_KEY')
            ? (string) FG_BACKUP_S3_SECRET_KEY
            : FgBackup_Secrets::decrypt((string) get_option('fg_backup_s3_secret_key', ''));
    }

    public static function session_token() {
        return defined('FG_BACKUP_S3_SESSION_TOKEN')
            ? (string) FG_BACKUP_S3_SESSION_TOKEN
            : FgBackup_Secrets::decrypt((string) get_option('fg_backup_s3_session_token', ''));
    }

    public static function sanitize_provider($value) {
        $allowed = ['custom', 'aws', 'hetzner', 'cloudflare', 'backblaze', 'wasabi', 'minio'];
        $value = sanitize_key((string) $value);
        return in_array($value, $allowed, true) ? $value : 'custom';
    }

    public static function sanitize_endpoint($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $value = esc_url_raw($value, ['http', 'https']);
        if ($value === '') {
            return '';
        }

        $parts = wp_parse_url($value);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        if (!empty($parts['user']) || !empty($parts['pass']) || !empty($parts['query']) || !empty($parts['fragment'])) {
            return '';
        }

        return rtrim($value, '/');
    }

    public static function sanitize_region($value) {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9._-]+/', '', $value);
        return $value !== '' ? substr($value, 0, 100) : 'us-east-1';
    }

    public static function sanitize_bucket($value) {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9._-]+/', '', $value);
        return substr($value, 0, 255);
    }

    public static function sanitize_remote_dir($value) {
        $value = trim(str_replace('\\', '/', (string) $value));
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', '', $value);
        $value = preg_replace('#/+#', '/', (string) $value);
        $value = preg_replace('#(?:^|/)\.{1,2}(?=/|$)#', '', (string) $value);
        $value = preg_replace('/[^A-Za-z0-9%._\/-]+/u', '-', (string) $value);
        return trim((string) $value, '/');
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
            throw new RuntimeException(__('S3 ist aktiviert, aber PHP-cURL oder PHP-DOM fehlt.', 'fg-backup-pro'));
        }

        $settings = self::settings();
        if ($settings['endpoint'] === '' || $settings['bucket'] === '' || $settings['region'] === '') {
            throw new RuntimeException(__('S3 ist nicht vollständig eingerichtet. Endpoint, Region und Bucket werden benötigt.', 'fg-backup-pro'));
        }
        if (self::access_key() === '' || self::secret_key() === '') {
            throw new RuntimeException(__('S3-Zugangsschlüssel und Secret Key fehlen.', 'fg-backup-pro'));
        }

        self::assert_safe_endpoint($settings);
    }

    public static function test_connection() {
        self::assert_ready();
        $settings = self::settings();
        $directory = self::resolved_remote_dir($settings);
        $name = self::TEST_FILE_PREFIX . strtolower(wp_generate_password(12, false, false)) . '.tmp';
        $key = self::join_key($directory, $name);
        $payload = 'FG Backup Pro ' . gmdate('c');
        $error = null;

        try {
            self::request('PUT', $key, [], [], $payload, [200, 201, 204]);

            $info = self::object_info($key);
            if (!$info || (int) $info['size_bytes'] !== strlen($payload)) {
                throw new RuntimeException(__('Die S3-Testdatei besitzt nicht die erwartete Größe.', 'fg-backup-pro'));
            }

            $read = self::request('GET', $key, [], [], '', [200, 206]);
            if (!is_string($read['body']) || !hash_equals($payload, $read['body'])) {
                throw new RuntimeException(__('Die S3-Testdatei wurde nicht korrekt zurückgelesen.', 'fg-backup-pro'));
            }

            $listed = self::list_objects($directory, true);
            $found = false;
            foreach ($listed as $item) {
                if (isset($item['key']) && hash_equals($key, (string) $item['key'])) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                throw new RuntimeException(__('Die S3-Testdatei konnte nicht über die Bucket-Dateiliste gefunden werden. Prüfe die ListBucket-Berechtigung.', 'fg-backup-pro'));
            }
        } catch (Throwable $exception) {
            $error = $exception;
        }

        try {
            self::request('DELETE', $key, [], [], '', [200, 202, 204, 404]);
        } catch (Throwable $delete_error) {
            if ($error === null) {
                $error = $delete_error;
            }
        }

        if ($error instanceof Throwable) {
            throw $error;
        }

        return [
            'directory' => $directory !== '' ? $directory : '/',
            'bucket' => $settings['bucket'],
            'endpoint' => $settings['endpoint'],
        ];
    }

    public static function prepare_upload($local_path, $file_name) {
        self::assert_ready();
        if (!is_file($local_path) || !is_readable($local_path)) {
            throw new RuntimeException(__('Die lokale Backup-Datei ist für S3 nicht lesbar.', 'fg-backup-pro'));
        }

        $settings = self::settings();
        $directory = self::resolved_remote_dir($settings);
        $key = self::unique_key($directory, $file_name);
        $total = (int) filesize($local_path);
        if ($total <= 0) {
            throw new RuntimeException(__('Die lokale Backup-Datei ist leer.', 'fg-backup-pro'));
        }

        $part_size = self::part_size($total);
        $multipart = $total > self::MIN_PART_SIZE;
        $upload_id = '';

        if ($multipart) {
            $response = self::request('POST', $key, ['uploads' => ''], [], '', [200]);
            $upload_id = self::xml_value($response['body'], 'UploadId');
            if ($upload_id === '') {
                throw new RuntimeException(__('S3 hat keine Upload-ID für den Multipart-Upload geliefert.', 'fg-backup-pro'));
            }
        }

        return [
            'remote_path' => self::display_path($key),
            'remote_temp' => $multipart ? 'multipart:' . $upload_id : '',
            'remote_dir' => $directory,
            'key' => $key,
            'mode' => $multipart ? 'multipart' : 'single',
            'upload_id' => $upload_id,
            'part_size' => $part_size,
            'next_part' => 1,
            'parts' => [],
            'offset' => 0,
            'total' => $total,
            'done' => false,
            'object_uploaded' => false,
        ];
    }

    public static function upload_state($local_path, array $state, $cancel_callback = null, $progress_callback = null) {
        $total = isset($state['total']) ? max(0, (int) $state['total']) : (int) filesize($local_path);
        $offset = isset($state['offset']) ? max(0, (int) $state['offset']) : 0;
        if (!empty($state['done']) || $offset >= $total) {
            return ['offset' => $total, 'total' => $total, 'done' => true];
        }
        if (is_callable($cancel_callback) && call_user_func($cancel_callback)) {
            throw new RuntimeException(__('Der S3-Upload wurde abgebrochen.', 'fg-backup-pro'));
        }

        $key = isset($state['key']) ? (string) $state['key'] : '';
        if ($key === '') {
            throw new RuntimeException(__('Der S3-Objektschlüssel fehlt.', 'fg-backup-pro'));
        }

        $mode = isset($state['mode']) ? (string) $state['mode'] : 'single';
        if ($mode === 'single') {
            $payload = file_get_contents($local_path);
            if (!is_string($payload)) {
                throw new RuntimeException(__('Die lokale Datei konnte für S3 nicht gelesen werden.', 'fg-backup-pro'));
            }
            if (is_callable($cancel_callback) && call_user_func($cancel_callback)) {
                throw new RuntimeException(__('Der S3-Upload wurde abgebrochen.', 'fg-backup-pro'));
            }

            self::request('PUT', $key, [], [], $payload, [200, 201, 204], 300);
            if (is_callable($progress_callback)) {
                call_user_func($progress_callback, $total, $total);
            }

            return [
                'offset' => $total,
                'total' => $total,
                'done' => true,
                'object_uploaded' => true,
            ];
        }

        $upload_id = isset($state['upload_id']) ? (string) $state['upload_id'] : '';
        $part_number = isset($state['next_part']) ? max(1, (int) $state['next_part']) : 1;
        $part_size = isset($state['part_size']) ? max(self::MIN_PART_SIZE, (int) $state['part_size']) : self::DEFAULT_PART_SIZE;
        if ($upload_id === '') {
            throw new RuntimeException(__('Die S3-Multipart-Upload-ID fehlt.', 'fg-backup-pro'));
        }
        if ($part_number > self::MAX_PARTS) {
            throw new RuntimeException(__('Das S3-Backup überschreitet die maximal unterstützte Anzahl von Upload-Teilen.', 'fg-backup-pro'));
        }

        $remaining = $total - $offset;
        $length = min($part_size, $remaining);
        $handle = fopen($local_path, 'rb');
        if (!$handle) {
            throw new RuntimeException(__('Die lokale Datei konnte für den S3-Upload nicht geöffnet werden.', 'fg-backup-pro'));
        }

        try {
            if (fseek($handle, $offset) !== 0) {
                throw new RuntimeException(__('Die lokale Datei konnte nicht an der benötigten Position gelesen werden.', 'fg-backup-pro'));
            }
            $payload = '';
            while (strlen($payload) < $length && !feof($handle)) {
                $chunk = fread($handle, min(1048576, $length - strlen($payload)));
                if ($chunk === false) {
                    throw new RuntimeException(__('Ein S3-Upload-Teil konnte nicht gelesen werden.', 'fg-backup-pro'));
                }
                $payload .= $chunk;
            }
        } finally {
            fclose($handle);
        }

        if (strlen($payload) !== $length) {
            throw new RuntimeException(__('Ein S3-Upload-Teil besitzt nicht die erwartete Größe.', 'fg-backup-pro'));
        }
        if (is_callable($cancel_callback) && call_user_func($cancel_callback)) {
            throw new RuntimeException(__('Der S3-Upload wurde abgebrochen.', 'fg-backup-pro'));
        }

        $response = self::request('PUT', $key, [
            'partNumber' => (string) $part_number,
            'uploadId' => $upload_id,
        ], [], $payload, [200], 300);

        $etag = isset($response['headers']['etag']) ? trim((string) $response['headers']['etag']) : '';
        $etag = trim($etag, "\"' ");
        if ($etag === '') {
            throw new RuntimeException(__('S3 hat für einen Upload-Teil keinen ETag geliefert.', 'fg-backup-pro'));
        }

        $parts = isset($state['parts']) && is_array($state['parts']) ? $state['parts'] : [];
        $parts[] = [
            'part_number' => $part_number,
            'etag' => $etag,
        ];
        $new_offset = $offset + $length;
        if (is_callable($progress_callback)) {
            call_user_func($progress_callback, $new_offset, $total);
        }

        return [
            'parts' => $parts,
            'next_part' => $part_number + 1,
            'offset' => $new_offset,
            'total' => $total,
            'done' => $new_offset >= $total,
        ];
    }

    public static function finalize_state(array $state) {
        $key = isset($state['key']) ? (string) $state['key'] : '';
        $total = isset($state['total']) ? max(0, (int) $state['total']) : 0;
        $mode = isset($state['mode']) ? (string) $state['mode'] : 'single';
        if ($key === '' || $total <= 0) {
            throw new RuntimeException(__('Der S3-Upload-Status ist unvollständig.', 'fg-backup-pro'));
        }

        $existing_info = self::object_info($key);
        $already_complete = $existing_info && (int) $existing_info['size_bytes'] === $total;

        if ($mode === 'multipart' && !$already_complete) {
            $upload_id = isset($state['upload_id']) ? (string) $state['upload_id'] : '';
            $parts = isset($state['parts']) && is_array($state['parts']) ? $state['parts'] : [];
            if ($upload_id === '' || !$parts) {
                throw new RuntimeException(__('Der S3-Multipart-Upload kann nicht abgeschlossen werden.', 'fg-backup-pro'));
            }

            usort($parts, static function ($a, $b) {
                return (int) $a['part_number'] <=> (int) $b['part_number'];
            });
            $xml = '<CompleteMultipartUpload>';
            foreach ($parts as $part) {
                $number = max(1, (int) $part['part_number']);
                $etag = htmlspecialchars('"' . trim((string) $part['etag'], "\"' ") . '"', ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $xml .= '<Part><PartNumber>' . $number . '</PartNumber><ETag>' . $etag . '</ETag></Part>';
            }
            $xml .= '</CompleteMultipartUpload>';

            try {
                $response = self::request('POST', $key, ['uploadId' => $upload_id], [
                    'content-type' => 'application/xml',
                ], $xml, [200], 300);
                if (stripos((string) $response['body'], '<Error>') !== false) {
                    throw new RuntimeException(self::error_message($response['body'], 200, __('S3 konnte den Multipart-Upload nicht abschließen.', 'fg-backup-pro')));
                }
            } catch (Throwable $exception) {
                // CompleteMultipartUpload kann serverseitig erfolgreich gewesen sein,
                // obwohl die Antwort den PHP-Prozess nicht mehr erreicht hat.
                $completed_info = self::object_info($key);
                if (!$completed_info || (int) $completed_info['size_bytes'] !== $total) {
                    throw $exception;
                }
            }
        }

        $info = self::object_info($key);
        if (!$info) {
            throw new RuntimeException(__('Die fertige S3-Datei wurde nicht gefunden.', 'fg-backup-pro'));
        }
        if ((int) $info['size_bytes'] !== $total) {
            throw new RuntimeException(sprintf(
                __('Die S3-Dateigröße stimmt nicht: erwartet %1$s, gefunden %2$s.', 'fg-backup-pro'),
                size_format($total, 2),
                size_format((int) $info['size_bytes'], 2)
            ));
        }

        $settings = self::settings();
        self::rotate(self::resolved_remote_dir($settings), $settings['retention']);
        return self::display_path($key);
    }

    public static function remove_partial_state(array $state) {
        $key = isset($state['key']) ? (string) $state['key'] : '';
        if ($key === '') {
            return;
        }

        $mode = isset($state['mode']) ? (string) $state['mode'] : 'single';
        if ($mode === 'multipart' && !empty($state['upload_id'])) {
            self::request('DELETE', $key, ['uploadId' => (string) $state['upload_id']], [], '', [200, 202, 204, 404]);
            // Falls CompleteMultipartUpload bereits serverseitig abgeschlossen war,
            // entfernt der Abort-Aufruf das fertige Objekt nicht. Bei einem als
            // fehlgeschlagen gewerteten Job wird deshalb auch der eindeutige Key
            // bestmöglich bereinigt.
            self::request('DELETE', $key, [], [], '', [200, 202, 204, 404]);
            return;
        }

        if (!empty($state['object_uploaded'])) {
            self::request('DELETE', $key, [], [], '', [200, 202, 204, 404]);
        }
    }

    public static function list_backups() {
        self::assert_ready();
        $items = self::list_objects(self::resolved_remote_dir(), false);
        $files = [];

        foreach ($items as $item) {
            $name = basename((string) $item['key']);
            if (!self::is_backup_file_name($name)) {
                continue;
            }
            $timestamp = !empty($item['modified']) ? strtotime((string) $item['modified']) : false;
            $files[] = [
                'name' => $name,
                'path' => self::display_path((string) $item['key']),
                'size' => size_format((int) $item['size_bytes'], 2),
                'size_bytes' => (int) $item['size_bytes'],
                'timestamp' => $timestamp ? (int) $timestamp : 0,
                'date' => $timestamp ? wp_date('d.m.Y H:i', (int) $timestamp) : '',
            ];
        }

        usort($files, static function ($a, $b) {
            return (int) $b['timestamp'] <=> (int) $a['timestamp'];
        });
        return $files;
    }

    public static function delete_backup($file_name) {
        self::assert_ready();
        $file_name = basename(str_replace('\\', '/', (string) $file_name));
        if (!self::is_backup_file_name($file_name)) {
            throw new RuntimeException(__('Ungültiger S3-Dateiname.', 'fg-backup-pro'));
        }

        $key = self::join_key(self::resolved_remote_dir(), $file_name);
        $response = self::request('DELETE', $key, [], [], '', [200, 202, 204, 404]);
        if ((int) $response['status'] === 404) {
            throw new RuntimeException(__('Das S3-Backup wurde nicht gefunden.', 'fg-backup-pro'));
        }
    }

    private static function part_size($total) {
        $configured = defined('FG_BACKUP_S3_PART_SIZE') ? (int) FG_BACKUP_S3_PART_SIZE : self::DEFAULT_PART_SIZE;
        $configured = max(self::MIN_PART_SIZE, min(33554432, $configured));
        $required = (int) ceil($total / 9000);
        if ($required > $configured) {
            $configured = (int) ceil($required / 1048576) * 1048576;
        }
        if ((int) ceil($total / $configured) > self::MAX_PARTS) {
            throw new RuntimeException(__('Die Backup-Datei ist für den konfigurierten S3-Part-Upload zu groß.', 'fg-backup-pro'));
        }
        return $configured;
    }

    private static function unique_key($directory, $file_name) {
        $file_name = basename(str_replace('\\', '/', (string) $file_name));
        $key = self::join_key($directory, $file_name);
        if (!self::object_info($key)) {
            return $key;
        }

        list($base, $extension) = self::split_extension($file_name);
        for ($index = 2; $index <= 999; $index++) {
            $candidate = self::join_key($directory, $base . '-' . $index . $extension);
            if (!self::object_info($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException(__('Für das S3-Backup konnte kein freier Dateiname erzeugt werden.', 'fg-backup-pro'));
    }

    private static function object_info($key) {
        $response = self::request('HEAD', $key, [], [], '', [200, 404]);
        if ((int) $response['status'] === 404) {
            return null;
        }

        $size = isset($response['headers']['content-length']) ? (int) $response['headers']['content-length'] : 0;
        return [
            'key' => $key,
            'size_bytes' => $size,
            'etag' => isset($response['headers']['etag']) ? trim((string) $response['headers']['etag'], "\"' ") : '',
            'modified' => isset($response['headers']['last-modified']) ? (string) $response['headers']['last-modified'] : '',
        ];
    }

    private static function list_objects($directory, $include_non_backups) {
        $prefix = trim((string) $directory, '/');
        if ($prefix !== '') {
            $prefix .= '/';
        }

        $objects = [];
        $continuation = '';
        $pages = 0;
        do {
            $query = [
                'list-type' => '2',
                'max-keys' => '1000',
                'prefix' => $prefix,
            ];
            if ($continuation !== '') {
                $query['continuation-token'] = $continuation;
            }

            $response = self::request('GET', '', $query, [], '', [200]);
            $document = self::xml_document($response['body']);
            foreach ($document->getElementsByTagName('Contents') as $content) {
                $key = self::child_value($content, 'Key');
                if ($key === '' || substr($key, -1) === '/') {
                    continue;
                }
                $name = basename($key);
                if (!$include_non_backups && !self::is_backup_file_name($name)) {
                    continue;
                }
                $objects[] = [
                    'key' => $key,
                    'size_bytes' => (int) self::child_value($content, 'Size'),
                    'modified' => self::child_value($content, 'LastModified'),
                    'etag' => trim(self::child_value($content, 'ETag'), "\"' "),
                ];
            }

            $truncated = strtolower(self::xml_value_from_document($document, 'IsTruncated')) === 'true';
            $continuation = $truncated ? self::xml_value_from_document($document, 'NextContinuationToken') : '';
            $pages++;
        } while ($truncated && $continuation !== '' && $pages < 20);

        if ($truncated && $continuation !== '') {
            throw new RuntimeException(__('Die S3-Dateiliste ist zu groß und konnte nicht vollständig geladen werden.', 'fg-backup-pro'));
        }

        return $objects;
    }

    private static function rotate($directory, $keep) {
        $keep = max(1, min(100, (int) $keep));
        $items = self::list_objects($directory, false);
        usort($items, static function ($a, $b) {
            $a_time = !empty($a['modified']) ? strtotime((string) $a['modified']) : 0;
            $b_time = !empty($b['modified']) ? strtotime((string) $b['modified']) : 0;
            return $b_time <=> $a_time;
        });

        foreach (array_slice($items, $keep) as $item) {
            self::request('DELETE', (string) $item['key'], [], [], '', [200, 202, 204, 404]);
        }
    }

    private static function request($method, $key = '', array $query = [], array $headers = [], $body = '', array $allowed_statuses = [200], $timeout = 60) {
        self::assert_ready_without_recursion();
        $settings = self::settings();
        $target = self::request_target($settings, $key, $query);
        $resolved_ips = self::assert_safe_url($target['url'], $settings);
        $payload = is_string($body) ? $body : (string) $body;
        $payload_hash = hash('sha256', $payload);
        $now = gmdate('Ymd\THis\Z');
        $date = substr($now, 0, 8);

        $sign_headers = [];
        foreach ($headers as $name => $value) {
            $name = strtolower(trim((string) $name));
            if ($name === '' || in_array($name, ['authorization', 'content-length', 'expect', 'user-agent'], true)) {
                continue;
            }
            $sign_headers[$name] = self::normalize_header_value($value);
        }
        $sign_headers['host'] = $target['host_header'];
        $sign_headers['x-amz-content-sha256'] = $payload_hash;
        $sign_headers['x-amz-date'] = $now;
        $token = self::session_token();
        if ($token !== '') {
            $sign_headers['x-amz-security-token'] = self::normalize_header_value($token);
        }
        ksort($sign_headers, SORT_STRING);

        $canonical_headers = '';
        foreach ($sign_headers as $name => $value) {
            $canonical_headers .= $name . ':' . $value . "\n";
        }
        $signed_headers = implode(';', array_keys($sign_headers));
        $canonical_request = strtoupper((string) $method) . "\n"
            . $target['canonical_uri'] . "\n"
            . $target['canonical_query'] . "\n"
            . $canonical_headers . "\n"
            . $signed_headers . "\n"
            . $payload_hash;

        $scope = $date . '/' . $settings['region'] . '/s3/aws4_request';
        $string_to_sign = "AWS4-HMAC-SHA256\n" . $now . "\n" . $scope . "\n" . hash('sha256', $canonical_request);
        $date_key = hash_hmac('sha256', $date, 'AWS4' . self::secret_key(), true);
        $region_key = hash_hmac('sha256', $settings['region'], $date_key, true);
        $service_key = hash_hmac('sha256', 's3', $region_key, true);
        $signing_key = hash_hmac('sha256', 'aws4_request', $service_key, true);
        $signature = hash_hmac('sha256', $string_to_sign, $signing_key);
        $authorization = 'AWS4-HMAC-SHA256 Credential=' . self::access_key() . '/' . $scope
            . ',SignedHeaders=' . $signed_headers
            . ',Signature=' . $signature;

        $curl_headers = [];
        foreach ($sign_headers as $name => $value) {
            $curl_headers[] = $name . ': ' . $value;
        }
        $curl_headers[] = 'Authorization: ' . $authorization;
        if ($payload !== '' || in_array(strtoupper((string) $method), ['PUT', 'POST'], true)) {
            $curl_headers[] = 'Content-Length: ' . strlen($payload);
        }
        $curl_headers[] = 'Expect:';

        $handle = curl_init();
        if ($handle === false) {
            throw new RuntimeException(__('S3 konnte cURL nicht initialisieren.', 'fg-backup-pro'));
        }

        $response_headers = [];
        curl_setopt($handle, CURLOPT_URL, $target['url']);
        if (defined('CURLOPT_PATH_AS_IS')) {
            curl_setopt($handle, CURLOPT_PATH_AS_IS, true);
        }
        if ($resolved_ips) {
            $ip = reset($resolved_ips);
            if (is_string($ip) && $ip !== '' && !filter_var($target['host'], FILTER_VALIDATE_IP)) {
                $resolved_address = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '[' . $ip . ']' : $ip;
                curl_setopt($handle, CURLOPT_RESOLVE, [$target['host'] . ':' . $target['port'] . ':' . $resolved_address]);
            }
        }
        curl_setopt($handle, CURLOPT_CUSTOMREQUEST, strtoupper((string) $method));
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($handle, CURLOPT_TIMEOUT, max(0, (int) $timeout));
        curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($handle, CURLOPT_HTTPHEADER, $curl_headers);
        curl_setopt($handle, CURLOPT_HEADERFUNCTION, static function ($curl, $line) use (&$response_headers) {
            $length = strlen($line);
            $trimmed = trim($line);
            if ($trimmed !== '' && strpos($trimmed, ':') !== false) {
                list($name, $value) = array_map('trim', explode(':', $trimmed, 2));
                $response_headers[strtolower($name)] = $value;
            }
            return $length;
        });

        if (strtoupper((string) $method) === 'HEAD') {
            curl_setopt($handle, CURLOPT_NOBODY, true);
        } elseif ($payload !== '' || in_array(strtoupper((string) $method), ['PUT', 'POST'], true)) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $payload);
        }

        $response_body = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($errno !== 0) {
            throw new RuntimeException(sprintf(__('S3-Verbindungsfehler: %s', 'fg-backup-pro'), $error !== '' ? $error : (string) $errno));
        }

        $response = [
            'status' => $status,
            'body' => is_string($response_body) ? $response_body : '',
            'headers' => $response_headers,
        ];
        if (!in_array($status, $allowed_statuses, true)) {
            throw new RuntimeException(self::error_message($response['body'], $status, __('S3-API-Aufruf fehlgeschlagen.', 'fg-backup-pro')));
        }

        return $response;
    }

    /**
     * Readiness check used inside request() without causing assert_ready() recursion.
     */
    private static function assert_ready_without_recursion() {
        if (!self::available()) {
            throw new RuntimeException(__('S3 ist auf diesem Server nicht verfügbar.', 'fg-backup-pro'));
        }
        $settings = self::settings();
        if ($settings['endpoint'] === '' || $settings['bucket'] === '' || $settings['region'] === '' || self::access_key() === '' || self::secret_key() === '') {
            throw new RuntimeException(__('S3 ist nicht vollständig eingerichtet.', 'fg-backup-pro'));
        }
    }

    private static function request_target(array $settings, $key, array $query) {
        $parts = wp_parse_url($settings['endpoint']);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new RuntimeException(__('Der S3-Endpoint ist ungültig.', 'fg-backup-pro'));
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = trim((string) $parts['host'], '[]');
        $port = !empty($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        $base_path = isset($parts['path']) ? rtrim((string) $parts['path'], '/') : '';
        $key = ltrim(str_replace('\\', '/', (string) $key), '/');

        if (!empty($settings['path_style'])) {
            $request_host = $host;
            $path = $base_path . '/' . self::uri_encode($settings['bucket'], true);
            if ($key !== '') {
                $path .= '/' . self::uri_encode($key, false);
            } elseif ($path === '') {
                $path = '/';
            }
        } else {
            $request_host = $settings['bucket'] . '.' . $host;
            $path = $base_path . '/';
            if ($key !== '') {
                $path .= self::uri_encode($key, false);
            }
        }

        $path = $path !== '' ? $path : '/';
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        $canonical_query = self::canonical_query($query);
        $host_for_url = filter_var($request_host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '[' . $request_host . ']' : $request_host;
        $default_port = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
        $authority = $host_for_url . ($default_port ? '' : ':' . $port);
        $url = $scheme . '://' . $authority . $path . ($canonical_query !== '' ? '?' . $canonical_query : '');

        return [
            'url' => $url,
            'canonical_uri' => $path,
            'canonical_query' => $canonical_query,
            'host' => $request_host,
            'host_header' => $authority,
            'port' => $port,
        ];
    }

    private static function canonical_query(array $query) {
        $pairs = [];
        foreach ($query as $name => $value) {
            $encoded_name = self::uri_encode((string) $name, true);
            if (is_array($value)) {
                foreach ($value as $item) {
                    $pairs[] = [$encoded_name, self::uri_encode((string) $item, true)];
                }
            } else {
                $pairs[] = [$encoded_name, self::uri_encode((string) $value, true)];
            }
        }

        usort($pairs, static function ($a, $b) {
            $name_compare = strcmp($a[0], $b[0]);
            return $name_compare !== 0 ? $name_compare : strcmp($a[1], $b[1]);
        });

        $result = [];
        foreach ($pairs as $pair) {
            $result[] = $pair[0] . '=' . $pair[1];
        }
        return implode('&', $result);
    }

    private static function uri_encode($value, $encode_slash) {
        $bytes = (string) $value;
        $result = '';
        $length = strlen($bytes);
        for ($index = 0; $index < $length; $index++) {
            $char = $bytes[$index];
            $ord = ord($char);
            $unreserved = ($ord >= 65 && $ord <= 90)
                || ($ord >= 97 && $ord <= 122)
                || ($ord >= 48 && $ord <= 57)
                || in_array($char, ['-', '.', '_', '~'], true);
            if ($unreserved || (!$encode_slash && $char === '/')) {
                $result .= $char;
            } else {
                $result .= sprintf('%%%02X', $ord);
            }
        }
        return $result;
    }

    private static function normalize_header_value($value) {
        return preg_replace('/\s+/', ' ', trim((string) $value));
    }

    private static function assert_safe_endpoint(array $settings) {
        self::assert_safe_url($settings['endpoint'], $settings);
    }

    private static function assert_safe_url($url, array $settings) {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new RuntimeException(__('Der S3-Endpoint ist ungültig.', 'fg-backup-pro'));
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException(__('Der S3-Endpoint muss HTTP oder HTTPS verwenden.', 'fg-backup-pro'));
        }
        if ($scheme !== 'https' && empty($settings['allow_http'])) {
            throw new RuntimeException(__('Der S3-Endpoint muss HTTPS verwenden. HTTP kann nur bewusst für ein internes Testsystem freigegeben werden.', 'fg-backup-pro'));
        }
        if (!empty($parts['user']) || !empty($parts['pass'])) {
            throw new RuntimeException(__('Zugangsdaten dürfen nicht Bestandteil des S3-Endpoints sein.', 'fg-backup-pro'));
        }

        $host = trim((string) $parts['host'], '[]');
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $resolved = gethostbynamel($host);
            if (is_array($resolved)) {
                $ips = array_values(array_unique(array_filter($resolved)));
            }
            if (function_exists('dns_get_record') && defined('DNS_AAAA')) {
                $aaaa = @dns_get_record($host, DNS_AAAA);
                if (is_array($aaaa)) {
                    foreach ($aaaa as $record) {
                        if (!empty($record['ipv6'])) {
                            $ips[] = (string) $record['ipv6'];
                        }
                    }
                }
            }
        }

        if (!$ips) {
            throw new RuntimeException(__('Der S3-Endpoint konnte nicht aufgelöst werden.', 'fg-backup-pro'));
        }

        if (empty($settings['allow_private'])) {
            foreach ($ips as $ip) {
                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    throw new RuntimeException(__('Private oder reservierte S3-Endpoints sind standardmäßig blockiert.', 'fg-backup-pro'));
                }
            }
        }

        return array_values(array_unique($ips));
    }

    private static function error_message($body, $status, $fallback) {
        $code = '';
        $message = '';
        if (is_string($body) && trim($body) !== '') {
            try {
                $document = self::xml_document($body);
                $code = self::xml_value_from_document($document, 'Code');
                $message = self::xml_value_from_document($document, 'Message');
            } catch (Throwable $exception) {
                $message = trim(wp_strip_all_tags($body));
            }
        }
        if (strlen($message) > 300) {
            $message = substr($message, 0, 300) . '…';
        }

        $detail = trim($code . ($code !== '' && $message !== '' ? ': ' : '') . $message);
        return $detail !== ''
            ? sprintf('%s HTTP %d: %s', $fallback, (int) $status, $detail)
            : sprintf('%s HTTP %d', $fallback, (int) $status);
    }

    private static function xml_document($xml) {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadXML((string) $xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new RuntimeException(__('Die S3-API hat eine ungültige XML-Antwort geliefert.', 'fg-backup-pro'));
        }
        return $document;
    }

    private static function xml_value($xml, $tag) {
        return self::xml_value_from_document(self::xml_document($xml), $tag);
    }

    private static function xml_value_from_document(DOMDocument $document, $tag) {
        $nodes = $document->getElementsByTagName((string) $tag);
        return $nodes->length ? trim((string) $nodes->item(0)->textContent) : '';
    }

    private static function child_value(DOMNode $node, $tag) {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && ($child->localName === $tag || $child->nodeName === $tag)) {
                return trim((string) $child->textContent);
            }
        }
        return '';
    }

    private static function join_key($directory, $name) {
        $directory = trim((string) $directory, '/');
        $name = ltrim(str_replace('\\', '/', (string) $name), '/');
        return $directory !== '' ? $directory . '/' . $name : $name;
    }

    private static function display_path($key) {
        $settings = self::settings();
        return 's3://' . $settings['bucket'] . '/' . ltrim((string) $key, '/');
    }

    private static function split_extension($file_name) {
        $extensions = ['.db.sql.zip', '.db.sql.gz', '.full.tar.gz', '.full.zip', '.full.tgz', '.sql.zip', '.sql.gz', '.tar.gz', '.zip', '.tgz', '.sql'];
        foreach ($extensions as $extension) {
            if (substr($file_name, -strlen($extension)) === $extension) {
                return [substr($file_name, 0, -strlen($extension)), $extension];
            }
        }
        $position = strrpos($file_name, '.');
        return $position === false ? [$file_name, ''] : [substr($file_name, 0, $position), substr($file_name, $position)];
    }

    private static function is_backup_file_name($name) {
        $name = basename(str_replace('\\', '/', (string) $name));
        if ($name === '' || strpos($name, '..') !== false) {
            return false;
        }
        return (bool) preg_match('/\.(?:zip|tgz|tar\.gz|sql|sql\.gz|sql\.zip)$/i', $name);
    }
}
