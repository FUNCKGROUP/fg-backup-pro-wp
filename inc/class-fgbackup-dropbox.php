<?php

defined('ABSPATH') || exit;

class FgBackup_Dropbox {
    const API_URL = 'https://api.dropboxapi.com/2/';
    const CONTENT_URL = 'https://content.dropboxapi.com/2/';
    const TOKEN_URL = 'https://api.dropboxapi.com/oauth2/token';
    const AUTHORIZE_URL = 'https://www.dropbox.com/oauth2/authorize';
    const CHUNK_SIZE = 8388608;
    const PENDING_OPTION = 'fg_backup_dropbox_oauth_pending';
    const OAUTH_ERROR_OPTION = 'fg_backup_dropbox_oauth_error';

    public static function available() {
        return function_exists('wp_remote_post');
    }

    public static function enabled() {
        return (bool) get_option('fg_backup_dropbox_enabled', 0);
    }

    public static function settings() {
        return [
            'app_key' => self::configured_app_key(),
            'relay_url' => self::relay_base_url(),
            'remote_dir' => self::sanitize_remote_dir(get_option('fg_backup_dropbox_remote_dir', '/backups/%host')),
            'retention' => max(1, min(100, (int) get_option('fg_backup_dropbox_retention', 10))),
        ];
    }

    public static function app_key() {
        $connected_key = trim((string) get_option('fg_backup_dropbox_connected_app_key', ''));
        return $connected_key !== '' ? $connected_key : self::configured_app_key();
    }

    public static function configured_app_key() {
        return defined('FG_BACKUP_DROPBOX_APP_KEY')
            ? trim((string) FG_BACKUP_DROPBOX_APP_KEY)
            : trim((string) get_option('fg_backup_dropbox_app_key', ''));
    }

    public static function relay_base_url() {
        $value = defined('FG_BACKUP_DROPBOX_RELAY_URL')
            ? (string) FG_BACKUP_DROPBOX_RELAY_URL
            : (string) get_option('fg_backup_dropbox_relay_url', 'https://lizenz.funckgroup-server.com/wp-json/fg-dropbox-relay/v1');
        return rtrim(esc_url_raw(trim($value), ['https']), '/');
    }

    public static function sanitize_remote_dir($value) {
        $value = trim(str_replace('\\', '/', (string) $value));
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', '', $value);
        $value = preg_replace('#/+#', '/', (string) $value);
        $value = preg_replace('#(?:^|/)\.{1,2}(?=/|$)#', '', (string) $value);
        $value = preg_replace('/[^A-Za-z0-9%._\/-]+/u', '-', (string) $value);
        $value = '/' . ltrim((string) $value, '/');
        return rtrim($value, '/') ?: '';
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

    public static function connected() {
        return get_option('fg_backup_dropbox_refresh_token', '') !== '';
    }

    public static function account() {
        return [
            'name' => (string) get_option('fg_backup_dropbox_account_name', ''),
            'email' => (string) get_option('fg_backup_dropbox_account_email', ''),
            'account_id' => (string) get_option('fg_backup_dropbox_account_id', ''),
        ];
    }

    public static function assert_ready() {
        if (!self::available()) {
            throw new RuntimeException(__('Dropbox ist auf diesem Server nicht verfügbar.', 'fg-backup-pro'));
        }
        if (self::app_key() === '') {
            throw new RuntimeException(__('Der Dropbox App-Key fehlt.', 'fg-backup-pro'));
        }
        if (!self::connected()) {
            throw new RuntimeException(__('Dropbox ist noch nicht verbunden.', 'fg-backup-pro'));
        }
        self::access_token();
    }

    public static function begin_manual_oauth() {
        self::assert_app_key();
        $pending = self::create_pending('manual', '');
        self::save_pending($pending);
        delete_option(self::OAUTH_ERROR_OPTION);
        return [
            'authorization_url' => self::authorization_url($pending, ''),
            'request_id' => $pending['request_id'],
        ];
    }

    public static function begin_relay_oauth() {
        $relay = self::relay_base_url();
        if ($relay === '') {
            throw new RuntimeException(__('Die Dropbox-Relay-URL fehlt.', 'fg-backup-pro'));
        }

        $callback_url = rest_url('fg-backup-pro/v1/dropbox/callback');
        if (strtolower((string) wp_parse_url($callback_url, PHP_URL_SCHEME)) !== 'https') {
            throw new RuntimeException(__('Die Dropbox-Verbindung über den Relay benötigt eine öffentlich erreichbare HTTPS-Website.', 'fg-backup-pro'));
        }

        $pending = self::create_pending('relay', '');
        self::save_pending($pending);
        delete_option(self::OAUTH_ERROR_OPTION);

        $response = wp_safe_remote_post($relay . '/start', [
            'timeout' => 30,
            'redirection' => 0,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode([
                'callback_url' => $callback_url,
                'callback_secret' => self::pending_secret($pending),
                'client_state' => $pending['client_state'],
                'code_challenge' => $pending['code_challenge'],
                'site_name' => (string) get_bloginfo('name'),
            ]),
        ]);
        $data = self::decode_http_response($response, [200], __('Der Dropbox-Relay konnte nicht gestartet werden.', 'fg-backup-pro'));
        if (empty($data['authorization_url']) || empty($data['redirect_uri']) || empty($data['app_key'])) {
            self::clear_pending();
            throw new RuntimeException(__('Der Dropbox-Relay hat keine gültigen OAuth-Daten geliefert.', 'fg-backup-pro'));
        }

        $pending['redirect_uri'] = esc_url_raw($data['redirect_uri'], ['https']);
        $pending['app_key'] = self::sanitize_app_key($data['app_key']);
        $expected_redirect_uri = $relay . '/callback';
        if (
            $pending['redirect_uri'] === ''
            || $pending['app_key'] === ''
            || !hash_equals($expected_redirect_uri, $pending['redirect_uri'])
        ) {
            self::clear_pending();
            throw new RuntimeException(__('Der Dropbox-Relay hat ungültige OAuth-Daten geliefert.', 'fg-backup-pro'));
        }
        $authorization_url = self::validate_relay_authorization_url(
            (string) $data['authorization_url'],
            $pending['app_key'],
            $pending['redirect_uri'],
            $pending['code_challenge']
        );
        self::save_pending($pending);
        return [
            'authorization_url' => $authorization_url,
            'request_id' => $pending['request_id'],
        ];
    }

    public static function complete_manual_oauth($code) {
        $pending = self::get_pending();
        if (!$pending || $pending['mode'] !== 'manual') {
            throw new RuntimeException(__('Es wurde keine manuelle Dropbox-Verbindung gestartet.', 'fg-backup-pro'));
        }
        $code = trim((string) $code);
        if ($code === '') {
            throw new RuntimeException(__('Der Dropbox-Autorisierungscode fehlt.', 'fg-backup-pro'));
        }
        self::exchange_authorization_code($code, $pending, '');
        return self::account();
    }

    public static function rest_callback(WP_REST_Request $request) {
        $pending = self::get_pending();
        if (!$pending || $pending['mode'] !== 'relay') {
            return new WP_REST_Response(['success' => false, 'message' => 'No pending Dropbox connection.'], 409);
        }

        $action = sanitize_key((string) $request->get_param('action'));
        $secret = (string) $request->get_param('callback_secret');
        $client_state = (string) $request->get_param('client_state');
        if (!hash_equals(self::pending_secret($pending), $secret) || !hash_equals($pending['client_state'], $client_state)) {
            return new WP_REST_Response(['success' => false, 'message' => 'Invalid callback credentials.'], 403);
        }

        if ($action === 'verify') {
            return new WP_REST_Response(['success' => true, 'verified' => true], 200);
        }

        if ($action !== 'complete') {
            return new WP_REST_Response(['success' => false, 'message' => 'Unknown callback action.'], 400);
        }

        $error = sanitize_text_field((string) $request->get_param('error'));
        if ($error !== '') {
            update_option(self::OAUTH_ERROR_OPTION, $error, false);
            self::clear_pending();
            return new WP_REST_Response(['success' => false, 'message' => $error], 400);
        }

        $code = trim((string) $request->get_param('code'));
        $redirect_uri = esc_url_raw((string) $request->get_param('redirect_uri'), ['https']);
        if ($code === '' || $redirect_uri === '' || !hash_equals((string) $pending['redirect_uri'], $redirect_uri)) {
            return new WP_REST_Response(['success' => false, 'message' => 'Invalid Dropbox callback data.'], 400);
        }

        try {
            self::exchange_authorization_code($code, $pending, $redirect_uri);
            return new WP_REST_Response(['success' => true], 200);
        } catch (Throwable $exception) {
            update_option(self::OAUTH_ERROR_OPTION, $exception->getMessage(), false);
            self::clear_pending();
            return new WP_REST_Response(['success' => false, 'message' => $exception->getMessage()], 500);
        }
    }

    public static function oauth_status() {
        $error = (string) get_option(self::OAUTH_ERROR_OPTION, '');
        return [
            'connected' => self::connected(),
            'pending' => (bool) self::get_pending(),
            'error' => $error,
            'account' => self::account(),
        ];
    }

    public static function disconnect() {
        self::clear_connection_data();
        delete_option(self::PENDING_OPTION);
        delete_option(self::OAUTH_ERROR_OPTION);
    }

    public static function test_connection() {
        self::assert_ready();
        $account = self::fetch_account();
        self::store_account($account);
        $directory = self::resolved_remote_dir();
        self::ensure_directory($directory);
        $name = '.fg-backup-test-' . strtolower(wp_generate_password(12, false, false)) . '.tmp';
        $path = rtrim($directory, '/') . '/' . $name;
        $payload = 'FG Backup Pro ' . gmdate('c');
        $error = null;

        try {
            self::content_request('files/upload', [
                'path' => $path,
                'mode' => 'overwrite',
                'autorename' => false,
                'mute' => true,
                'strict_conflict' => false,
            ], $payload, [200]);
            $download = self::content_request('files/download', ['path' => $path], null, [200]);
            if (!hash_equals($payload, (string) $download['body'])) {
                throw new RuntimeException(__('Die Dropbox-Testdatei wurde nicht korrekt zurückgelesen.', 'fg-backup-pro'));
            }
        } catch (Throwable $exception) {
            $error = $exception;
        }

        try {
            self::rpc_request('files/delete_v2', ['path' => $path], [200, 409]);
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
            'account' => self::account(),
        ];
    }

    public static function prepare_upload($local_path, $file_name) {
        self::assert_ready();
        if (!is_file($local_path) || !is_readable($local_path)) {
            throw new RuntimeException(__('Die lokale Backup-Datei ist für Dropbox nicht lesbar.', 'fg-backup-pro'));
        }
        $directory = self::resolved_remote_dir();
        self::ensure_directory($directory);
        $remote_path = self::unique_remote_path($directory, $file_name);
        $response = self::content_request('files/upload_session/start', ['close' => false], '', [200]);
        $data = self::decode_json($response['body']);
        if (empty($data['session_id'])) {
            throw new RuntimeException(__('Dropbox konnte keine Upload-Session starten.', 'fg-backup-pro'));
        }
        return [
            'remote_path' => $remote_path,
            'remote_temp' => '',
            'remote_dir' => $directory,
            'session_id' => (string) $data['session_id'],
            'offset' => 0,
            'total' => (int) filesize($local_path),
            'done' => false,
        ];
    }

    public static function upload_state($local_path, array $state, $cancel_callback = null, $progress_callback = null) {
        $session_id = isset($state['session_id']) ? (string) $state['session_id'] : '';
        $offset = isset($state['offset']) ? max(0, (int) $state['offset']) : 0;
        $total = isset($state['total']) ? max(0, (int) $state['total']) : (int) filesize($local_path);
        if ($session_id === '') {
            throw new RuntimeException(__('Die Dropbox-Upload-Session fehlt.', 'fg-backup-pro'));
        }
        if ($offset >= $total) {
            return ['offset' => $total, 'total' => $total, 'done' => true];
        }

        $handle = fopen($local_path, 'rb');
        if (!$handle) {
            throw new RuntimeException(__('Die lokale Datei konnte für Dropbox nicht geöffnet werden.', 'fg-backup-pro'));
        }
        if (fseek($handle, $offset) !== 0) {
            fclose($handle);
            throw new RuntimeException(__('Die lokale Datei konnte für Dropbox nicht positioniert werden.', 'fg-backup-pro'));
        }

        $chunks = 0;
        try {
            while ($offset < $total && $chunks < 4) {
                if (is_callable($cancel_callback) && call_user_func($cancel_callback)) {
                    break;
                }
                $length = min(self::CHUNK_SIZE, $total - $offset);
                $data = fread($handle, $length);
                if (!is_string($data) || $data === '') {
                    throw new RuntimeException(__('Ein Dropbox-Datenblock konnte nicht gelesen werden.', 'fg-backup-pro'));
                }
                self::content_request('files/upload_session/append_v2', [
                    'cursor' => [
                        'session_id' => $session_id,
                        'offset' => $offset,
                    ],
                    'close' => false,
                ], $data, [200]);
                $offset += strlen($data);
                $chunks++;
                if (is_callable($progress_callback)) {
                    call_user_func($progress_callback, $offset, $total);
                }
            }
        } finally {
            fclose($handle);
        }

        return [
            'offset' => $offset,
            'total' => $total,
            'done' => $offset >= $total,
        ];
    }

    public static function finalize_state(array $state) {
        $session_id = isset($state['session_id']) ? (string) $state['session_id'] : '';
        $remote_path = isset($state['remote_path']) ? (string) $state['remote_path'] : '';
        $total = isset($state['total']) ? (int) $state['total'] : 0;
        if ($session_id === '' || $remote_path === '') {
            throw new RuntimeException(__('Die Dropbox-Upload-Daten fehlen.', 'fg-backup-pro'));
        }
        $response = self::content_request('files/upload_session/finish', [
            'cursor' => [
                'session_id' => $session_id,
                'offset' => $total,
            ],
            'commit' => [
                'path' => $remote_path,
                'mode' => 'add',
                'autorename' => false,
                'client_modified' => gmdate('Y-m-d\TH:i:s\Z'),
                'mute' => true,
                'strict_conflict' => true,
            ],
        ], '', [200]);
        $metadata = self::decode_json($response['body']);
        if (!isset($metadata['size']) || (int) $metadata['size'] !== $total) {
            throw new RuntimeException(__('Die finalisierte Dropbox-Datei konnte nicht bestätigt werden.', 'fg-backup-pro'));
        }
        self::rotate(dirname($remote_path), self::settings()['retention']);
        return $remote_path;
    }

    public static function remove_partial_state(array $state) {
        // Unvollständige Dropbox Upload-Sessions sind nicht als Datei sichtbar und verfallen automatisch.
    }

    public static function list_backups() {
        self::assert_ready();
        $directory = self::resolved_remote_dir();
        $entries = self::list_folder($directory);
        $files = [];
        foreach ($entries as $entry) {
            if (!isset($entry['.tag']) || $entry['.tag'] !== 'file' || !self::is_backup_file_name(isset($entry['name']) ? $entry['name'] : '')) {
                continue;
            }
            $mtime = !empty($entry['server_modified']) ? (int) strtotime($entry['server_modified']) : 0;
            $size = isset($entry['size']) ? (int) $entry['size'] : 0;
            $files[] = [
                'name' => (string) $entry['name'],
                'path' => isset($entry['path_display']) ? (string) $entry['path_display'] : rtrim($directory, '/') . '/' . $entry['name'],
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
            throw new RuntimeException(__('Ungültiger Dropbox-Dateiname.', 'fg-backup-pro'));
        }
        self::assert_ready();
        $path = rtrim(self::resolved_remote_dir(), '/') . '/' . $file_name;
        self::rpc_request('files/delete_v2', ['path' => $path], [200]);
        return true;
    }

    private static function create_pending($mode, $redirect_uri) {
        $verifier = self::base64_url(random_bytes(64));
        $secret = self::base64_url(random_bytes(32));
        return [
            'request_id' => str_replace('-', '', wp_generate_uuid4()),
            'mode' => $mode,
            'verifier' => FgBackup_Secrets::encrypt($verifier),
            'code_challenge' => self::base64_url(hash('sha256', $verifier, true)),
            'client_state' => self::base64_url(random_bytes(32)),
            'callback_secret' => FgBackup_Secrets::encrypt($secret),
            'redirect_uri' => $redirect_uri,
            'created_at' => time(),
        ];
    }

    private static function save_pending(array $pending) {
        update_option(self::PENDING_OPTION, $pending, false);
    }

    private static function get_pending() {
        $pending = get_option(self::PENDING_OPTION, false);
        if (!is_array($pending) || empty($pending['created_at']) || (int) $pending['created_at'] < time() - 15 * MINUTE_IN_SECONDS) {
            self::clear_pending();
            return false;
        }
        return $pending;
    }

    private static function clear_pending() {
        delete_option(self::PENDING_OPTION);
    }

    private static function pending_verifier(array $pending) {
        return FgBackup_Secrets::decrypt(isset($pending['verifier']) ? (string) $pending['verifier'] : '');
    }

    private static function pending_secret(array $pending) {
        return FgBackup_Secrets::decrypt(isset($pending['callback_secret']) ? (string) $pending['callback_secret'] : '');
    }

    private static function validate_relay_authorization_url($url, $app_key, $redirect_uri, $code_challenge) {
        $url = esc_url_raw((string) $url, ['https']);
        $parts = wp_parse_url($url);
        if (
            $url === ''
            || !is_array($parts)
            || strtolower(isset($parts['scheme']) ? (string) $parts['scheme'] : '') !== 'https'
            || strtolower(isset($parts['host']) ? (string) $parts['host'] : '') !== 'www.dropbox.com'
            || rtrim(isset($parts['path']) ? (string) $parts['path'] : '', '/') !== '/oauth2/authorize'
        ) {
            self::clear_pending();
            throw new RuntimeException(__('Der Dropbox-Relay hat keine gültige Dropbox-Freigabeadresse geliefert.', 'fg-backup-pro'));
        }

        $query = [];
        parse_str(isset($parts['query']) ? (string) $parts['query'] : '', $query);
        $valid = isset($query['client_id'], $query['response_type'], $query['redirect_uri'], $query['token_access_type'], $query['code_challenge'], $query['code_challenge_method'], $query['state'])
            && hash_equals((string) $app_key, (string) $query['client_id'])
            && (string) $query['response_type'] === 'code'
            && hash_equals((string) $redirect_uri, (string) $query['redirect_uri'])
            && (string) $query['token_access_type'] === 'offline'
            && hash_equals((string) $code_challenge, (string) $query['code_challenge'])
            && (string) $query['code_challenge_method'] === 'S256'
            && (bool) preg_match('/^[A-Za-z0-9_-]{32,180}$/', (string) $query['state']);
        if (!$valid) {
            self::clear_pending();
            throw new RuntimeException(__('Die OAuth-Daten des Dropbox-Relay stimmen nicht mit dieser Anfrage überein.', 'fg-backup-pro'));
        }
        return $url;
    }

    private static function authorization_url(array $pending, $redirect_uri) {
        $params = [
            'client_id' => self::oauth_app_key($pending),
            'response_type' => 'code',
            'token_access_type' => 'offline',
            'code_challenge' => $pending['code_challenge'],
            'code_challenge_method' => 'S256',
            'scope' => 'account_info.read files.content.read files.content.write files.metadata.read files.metadata.write',
        ];
        if ($redirect_uri !== '') {
            $params['redirect_uri'] = $redirect_uri;
            $params['state'] = $pending['client_state'];
        }
        return add_query_arg($params, self::AUTHORIZE_URL);
    }

    private static function exchange_authorization_code($code, array $pending, $redirect_uri) {
        $oauth_app_key = self::oauth_app_key($pending);
        $body = [
            'code' => $code,
            'grant_type' => 'authorization_code',
            'client_id' => $oauth_app_key,
            'code_verifier' => self::pending_verifier($pending),
        ];
        if ($redirect_uri !== '') {
            $body['redirect_uri'] = $redirect_uri;
        }
        $response = wp_safe_remote_post(self::TOKEN_URL, [
            'timeout' => 30,
            'redirection' => 0,
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => $body,
        ]);
        $data = self::decode_http_response($response, [200], __('Der Dropbox-Autorisierungscode konnte nicht eingelöst werden.', 'fg-backup-pro'));
        if (empty($data['access_token']) || empty($data['refresh_token'])) {
            throw new RuntimeException(__('Dropbox hat keinen Refresh-Token geliefert. Bitte die Verbindung erneut mit Offline-Zugriff herstellen.', 'fg-backup-pro'));
        }
        self::store_tokens($data);
        update_option('fg_backup_dropbox_connected_app_key', $oauth_app_key, false);
        try {
            $account = self::fetch_account();
            self::store_account($account);
        } catch (Throwable $exception) {
            self::clear_connection_data();
            throw $exception;
        }
        self::clear_pending();
        delete_option(self::OAUTH_ERROR_OPTION);
    }

    private static function store_tokens(array $data) {
        update_option('fg_backup_dropbox_access_token', FgBackup_Secrets::encrypt((string) $data['access_token']), false);
        update_option('fg_backup_dropbox_access_expires', time() + max(60, (int) (isset($data['expires_in']) ? $data['expires_in'] : 14400)), false);
        if (!empty($data['refresh_token'])) {
            update_option('fg_backup_dropbox_refresh_token', FgBackup_Secrets::encrypt((string) $data['refresh_token']), false);
        }
        if (!empty($data['account_id'])) {
            update_option('fg_backup_dropbox_account_id', sanitize_text_field($data['account_id']), false);
        }
    }

    private static function access_token() {
        $token = FgBackup_Secrets::decrypt((string) get_option('fg_backup_dropbox_access_token', ''));
        $expires = (int) get_option('fg_backup_dropbox_access_expires', 0);
        if ($token !== '' && $expires > time() + 90) {
            return $token;
        }
        $refresh = FgBackup_Secrets::decrypt((string) get_option('fg_backup_dropbox_refresh_token', ''));
        if ($refresh === '') {
            throw new RuntimeException(__('Der Dropbox Refresh-Token fehlt.', 'fg-backup-pro'));
        }
        $response = wp_safe_remote_post(self::TOKEN_URL, [
            'timeout' => 30,
            'redirection' => 0,
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refresh,
                'client_id' => self::app_key(),
            ],
        ]);
        $data = self::decode_http_response($response, [200], __('Der Dropbox-Zugriff konnte nicht erneuert werden.', 'fg-backup-pro'));
        if (empty($data['access_token'])) {
            throw new RuntimeException(__('Dropbox hat keinen neuen Access-Token geliefert.', 'fg-backup-pro'));
        }
        update_option('fg_backup_dropbox_access_token', FgBackup_Secrets::encrypt((string) $data['access_token']), false);
        update_option('fg_backup_dropbox_access_expires', time() + max(60, (int) (isset($data['expires_in']) ? $data['expires_in'] : 14400)), false);
        return (string) $data['access_token'];
    }

    private static function fetch_account() {
        $response = self::rpc_request('users/get_current_account', null, [200]);
        return self::decode_json($response['body']);
    }

    private static function store_account(array $account) {
        $name = '';
        if (!empty($account['name']['display_name'])) {
            $name = sanitize_text_field($account['name']['display_name']);
        }
        update_option('fg_backup_dropbox_account_name', $name, false);
        update_option('fg_backup_dropbox_account_email', !empty($account['email']) ? sanitize_email($account['email']) : '', false);
        update_option('fg_backup_dropbox_account_id', !empty($account['account_id']) ? sanitize_text_field($account['account_id']) : '', false);
    }

    private static function ensure_directory($directory) {
        $directory = self::sanitize_remote_dir($directory);
        if ($directory === '') {
            return;
        }

        $segments = array_values(array_filter(explode('/', trim($directory, '/')), 'strlen'));
        $current = '';
        foreach ($segments as $segment) {
            $current .= '/' . $segment;
            $metadata_response = self::rpc_request('files/get_metadata', ['path' => $current], [200, 409]);
            if ((int) $metadata_response['status'] === 200) {
                $metadata = self::decode_json($metadata_response['body']);
                if (!isset($metadata['.tag']) || $metadata['.tag'] !== 'folder') {
                    throw new RuntimeException(sprintf(__('Der Dropbox-Pfad %s ist kein Ordner.', 'fg-backup-pro'), $current));
                }
                continue;
            }

            $create_response = self::rpc_request('files/create_folder_v2', [
                'path' => $current,
                'autorename' => false,
            ], [200, 409]);
            if ((int) $create_response['status'] === 200) {
                continue;
            }

            // Ein paralleler Prozess kann den Ordner zwischen Prüfung und Anlage erstellt haben.
            $metadata_response = self::rpc_request('files/get_metadata', ['path' => $current], [200, 409]);
            $metadata = (int) $metadata_response['status'] === 200 ? self::decode_json($metadata_response['body']) : [];
            if (!isset($metadata['.tag']) || $metadata['.tag'] !== 'folder') {
                throw new RuntimeException(sprintf(__('Der Dropbox-Zielordner %s konnte nicht angelegt werden.', 'fg-backup-pro'), $current));
            }
        }
    }

    private static function clear_connection_data() {
        foreach ([
            'fg_backup_dropbox_access_token',
            'fg_backup_dropbox_access_expires',
            'fg_backup_dropbox_refresh_token',
            'fg_backup_dropbox_account_name',
            'fg_backup_dropbox_account_email',
            'fg_backup_dropbox_account_id',
            'fg_backup_dropbox_connected_app_key',
        ] as $option) {
            delete_option($option);
        }
    }

    private static function unique_remote_path($directory, $file_name) {
        $file_name = basename(str_replace('\\', '/', (string) $file_name));
        if (!self::is_backup_file_name($file_name)) {
            throw new RuntimeException(__('Der Dateiname des Backups ist für Dropbox ungültig.', 'fg-backup-pro'));
        }
        $candidate = rtrim($directory, '/') . '/' . $file_name;
        if (!self::path_exists($candidate)) {
            return $candidate;
        }
        list($base, $extension) = self::split_extension($file_name);
        for ($number = 2; $number < 1000; $number++) {
            $candidate = rtrim($directory, '/') . '/' . $base . '-' . $number . $extension;
            if (!self::path_exists($candidate)) {
                return $candidate;
            }
        }
        throw new RuntimeException(__('In Dropbox konnte kein freier Dateiname ermittelt werden.', 'fg-backup-pro'));
    }

    private static function path_exists($path) {
        $response = self::rpc_request('files/get_metadata', ['path' => $path], [200, 409]);
        return (int) $response['status'] === 200;
    }

    private static function list_folder($directory) {
        $entries = [];
        $response = self::rpc_request('files/list_folder', [
            'path' => $directory,
            'recursive' => false,
            'include_media_info' => false,
            'include_deleted' => false,
            'include_has_explicit_shared_members' => false,
            'include_mounted_folders' => true,
            'limit' => 2000,
        ], [200, 409]);
        if ((int) $response['status'] === 409) {
            return [];
        }
        $data = self::decode_json($response['body']);
        $entries = !empty($data['entries']) && is_array($data['entries']) ? $data['entries'] : [];
        $loops = 0;
        while (!empty($data['has_more']) && !empty($data['cursor']) && $loops < 10) {
            $response = self::rpc_request('files/list_folder/continue', ['cursor' => $data['cursor']], [200]);
            $data = self::decode_json($response['body']);
            if (!empty($data['entries']) && is_array($data['entries'])) {
                $entries = array_merge($entries, $data['entries']);
            }
            $loops++;
        }
        return $entries;
    }

    private static function rotate($directory, $keep) {
        try {
            $files = [];
            foreach (self::list_folder($directory) as $entry) {
                if (isset($entry['.tag']) && $entry['.tag'] === 'file' && self::is_backup_file_name(isset($entry['name']) ? $entry['name'] : '')) {
                    $files[] = [
                        'path' => isset($entry['path_display']) ? $entry['path_display'] : rtrim($directory, '/') . '/' . $entry['name'],
                        'mtime' => !empty($entry['server_modified']) ? (int) strtotime($entry['server_modified']) : 0,
                    ];
                }
            }
            usort($files, static function ($left, $right) {
                return $right['mtime'] <=> $left['mtime'];
            });
            foreach (array_slice($files, max(1, (int) $keep)) as $file) {
                self::rpc_request('files/delete_v2', ['path' => $file['path']], [200, 409]);
            }
        } catch (Throwable $exception) {
            // Rotation ist best effort.
        }
    }

    private static function split_extension($file_name) {
        foreach (['.sql.zip', '.sql.gz', '.tar.gz', '.tgz', '.zip', '.sql'] as $known) {
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
            && (bool) preg_match('/(?:\.sql\.zip|\.sql\.gz|\.tar\.gz|\.tgz|\.zip|\.sql)$/i', $name);
    }

    private static function rpc_request($endpoint, $payload, array $allowed_statuses) {
        $response = wp_safe_remote_post(self::API_URL . ltrim($endpoint, '/'), [
            'timeout' => 60,
            'redirection' => 0,
            'headers' => [
                'Authorization' => 'Bearer ' . self::access_token(),
                'Content-Type' => 'application/json',
            ],
            'body' => $payload === null ? 'null' : wp_json_encode($payload),
        ]);
        return self::normalize_response($response, $allowed_statuses, __('Dropbox-API-Aufruf fehlgeschlagen.', 'fg-backup-pro'));
    }

    private static function content_request($endpoint, array $argument, $body, array $allowed_statuses) {
        $response = wp_safe_remote_post(self::CONTENT_URL . ltrim($endpoint, '/'), [
            'timeout' => 120,
            'redirection' => 0,
            'headers' => [
                'Authorization' => 'Bearer ' . self::access_token(),
                'Content-Type' => 'application/octet-stream',
                'Dropbox-API-Arg' => wp_json_encode($argument, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ],
            'body' => $body === null ? '' : $body,
        ]);
        return self::normalize_response($response, $allowed_statuses, __('Dropbox-Dateiübertragung fehlgeschlagen.', 'fg-backup-pro'));
    }

    private static function normalize_response($response, array $allowed_statuses, $message) {
        if (is_wp_error($response)) {
            throw new RuntimeException($message . ' ' . $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if (!in_array($status, $allowed_statuses, true)) {
            $detail = self::dropbox_error($body);
            throw new RuntimeException(sprintf('%s HTTP %d%s', $message, $status, $detail !== '' ? ': ' . $detail : ''));
        }
        return ['status' => $status, 'body' => $body, 'headers' => wp_remote_retrieve_headers($response)];
    }

    private static function decode_http_response($response, array $allowed_statuses, $message) {
        $normalized = self::normalize_response($response, $allowed_statuses, $message);
        return self::decode_json($normalized['body']);
    }

    private static function decode_json($body) {
        $data = json_decode((string) $body, true);
        return is_array($data) ? $data : [];
    }

    private static function dropbox_error($body) {
        $data = self::decode_json($body);
        if (!empty($data['error_summary'])) {
            return sanitize_text_field($data['error_summary']);
        }
        if (!empty($data['error_description'])) {
            return sanitize_text_field($data['error_description']);
        }
        $text = trim(wp_strip_all_tags((string) $body));
        return strlen($text) > 240 ? substr($text, 0, 240) . '…' : $text;
    }

    private static function assert_app_key() {
        if (self::configured_app_key() === '') {
            throw new RuntimeException(__('Für die manuelle Verbindung fehlt der Dropbox App-Key.', 'fg-backup-pro'));
        }
    }

    private static function oauth_app_key(array $pending) {
        $key = !empty($pending['app_key']) ? self::sanitize_app_key($pending['app_key']) : self::configured_app_key();
        if ($key === '') {
            throw new RuntimeException(__('Der Dropbox App-Key für diese Verbindung fehlt.', 'fg-backup-pro'));
        }
        return $key;
    }

    private static function sanitize_app_key($value) {
        return preg_replace('/[^A-Za-z0-9_-]+/', '', (string) $value);
    }

    private static function base64_url($value) {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
