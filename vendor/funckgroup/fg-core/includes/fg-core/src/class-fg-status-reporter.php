<?php

namespace Funckgroup\FGCore;

defined('ABSPATH') || exit;

/**
 * Sends one aggregated installation status for all detected FUNCKGROUP plugins.
 *
 * No users, posts, credentials or database contents are included.
 */
final class StatusReporter
{
    private const CRON_HOOK = 'fg_core_send_installation_status';
    private const OPTION_INSTALLATION_ID = 'fg_core_installation_id';
    private const OPTION_INSTALLATION_TOKEN = 'fg_core_installation_token';
    private const OPTION_INSTALLATION_HOME = 'fg_core_installation_home_url';
    private const OPTION_LAST_REPORT = 'fg_core_status_last_report';
    private const OPTION_LAST_ERROR = 'fg_core_status_last_error';
    private const OPTION_LAST_HTTP_CODE = 'fg_core_status_last_http_code';

    private static ?AdminCore $core = null;
    private static bool $booted = false;

    public static function boot(AdminCore $core): void
    {
        if (self::$booted) {
            return;
        }

        self::$core = $core;
        self::$booted = true;

        add_action(self::CRON_HOOK, [self::class, 'send_status']);
        add_action('init', [self::class, 'ensure_schedule'], 50);
    }

    public static function ensure_schedule(): void
    {
        if (!self::is_enabled() || wp_next_scheduled(self::CRON_HOOK)) {
            return;
        }

        // First report shortly after installation/update, then once per day.
        $first_run = time() + wp_rand(120, 900);
        wp_schedule_event($first_run, 'daily', self::CRON_HOOK);
    }

    public static function send_status(): bool
    {
        if (!self::is_enabled()) {
            return false;
        }

        $endpoint = self::get_endpoint();
        if ($endpoint === '') {
            self::store_error('Kein Status-Endpunkt konfiguriert.', 0);
            return false;
        }

        $payload = self::build_payload();
        $installation_token = self::get_installation_token();
        $body = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($body) || $body === '') {
            self::store_error('Status-Payload konnte nicht serialisiert werden.', 0);
            return false;
        }

        $response = wp_remote_post(
            $endpoint,
            [
                'timeout'     => 15,
                'redirection' => 2,
                'headers'     => [
                    'Content-Type'       => 'application/json; charset=utf-8',
                    'Accept'             => 'application/json',
                    'X-FG-Install-ID'    => (string) $payload['installation_id'],
                    'X-FG-Install-Token' => $installation_token,
                    'X-FG-Core-Version'  => defined('FG_CORE_VERSION') ? (string) FG_CORE_VERSION : '',
                ],
                'body'        => $body,
                'user-agent'  => 'FG-Core/' . (defined('FG_CORE_VERSION') ? FG_CORE_VERSION : 'unknown') . '; ' . home_url('/'),
                'data_format' => 'body',
            ]
        );

        if (is_wp_error($response)) {
            self::store_error($response->get_error_message(), 0);
            return false;
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);
        if ($http_code < 200 || $http_code >= 300) {
            $response_body = trim((string) wp_remote_retrieve_body($response));
            self::store_error(
                sprintf(
                    'Status-Endpunkt antwortete mit HTTP %d%s.',
                    $http_code,
                    $response_body !== '' ? ': ' . wp_strip_all_tags($response_body) : ''
                ),
                $http_code
            );
            return false;
        }

        update_option(
            self::OPTION_LAST_REPORT,
            [
                'sent_at'      => time(),
                'plugin_count' => count($payload['plugins']),
                'endpoint'     => $endpoint,
            ],
            false
        );
        delete_option(self::OPTION_LAST_ERROR);
        update_option(self::OPTION_LAST_HTTP_CODE, $http_code, false);

        do_action('fg_core_status_report_sent', $payload, $response);
        return true;
    }

    /**
     * @return array<string,mixed>
     */
    public static function get_diagnostics(): array
    {
        return [
            'enabled'          => self::is_enabled(),
            'endpoint'         => self::get_endpoint(),
            'installation_id'  => self::get_installation_id(),
            'next_scheduled'   => wp_next_scheduled(self::CRON_HOOK) ?: 0,
            'last_report'      => get_option(self::OPTION_LAST_REPORT, []),
            'last_error'       => (string) get_option(self::OPTION_LAST_ERROR, ''),
            'last_http_code'   => (int) get_option(self::OPTION_LAST_HTTP_CODE, 0),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function build_payload(): array
    {
        global $wp_version;

        $runtime = self::$core ? self::$core->get_runtime() : [];
        $site_url = site_url('/');
        $home_url = home_url('/');
        $domain = (string) wp_parse_url($home_url, PHP_URL_HOST);

        $payload = [
            'schema_version'  => 1,
            'installation_id' => self::get_installation_id(),
            'domain'          => strtolower($domain),
            'site_url'        => esc_url_raw($site_url),
            'home_url'        => esc_url_raw($home_url),
            'environment'     => function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production',
            'is_multisite'    => is_multisite(),
            'network_id'      => is_multisite() ? (int) get_current_network_id() : 0,
            'blog_id'         => (int) get_current_blog_id(),
            'wordpress'       => [
                'version' => isset($wp_version) ? (string) $wp_version : (string) get_bloginfo('version'),
                'locale'  => (string) get_locale(),
                'timezone'=> (string) wp_timezone_string(),
            ],
            'php'             => [
                'version' => PHP_VERSION,
            ],
            'fg_core'         => [
                'version'     => defined('FG_CORE_VERSION') ? (string) FG_CORE_VERSION : '',
                'api_version' => defined('FG_CORE_API_VERSION') ? (int) FG_CORE_API_VERSION : 0,
                'provider'    => isset($runtime['provider']) ? sanitize_key((string) $runtime['provider']) : '',
            ],
            'plugins'         => self::collect_fg_plugins(),
            'reported_at'     => gmdate('c'),
        ];

        /** @var array<string,mixed> $payload */
        return apply_filters('fg_core_status_payload', $payload);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function collect_fg_plugins(): array
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $registered = self::$core ? self::$core->get_plugins() : [];
        $registered_by_file = [];

        foreach ($registered as $plugin) {
            $plugin_file = isset($plugin['plugin_file']) ? (string) $plugin['plugin_file'] : '';
            if ($plugin_file === '') {
                continue;
            }

            $relative_file = plugin_basename($plugin_file);
            $registered_by_file[$relative_file] = $plugin;
        }

        $plugins = [];

        foreach ($all_plugins as $plugin_file => $headers) {
            $absolute_file = WP_PLUGIN_DIR . '/' . ltrim((string) $plugin_file, '/');
            $plugin_dir = dirname($absolute_file);
            $manifest_file = $plugin_dir . '/includes/fg-core/manifest.php';
            $author = isset($headers['Author']) ? wp_strip_all_tags((string) $headers['Author']) : '';
            $has_embedded_core = is_readable($manifest_file);
            $is_registered = isset($registered_by_file[$plugin_file]);
            $is_funckgroup = $has_embedded_core || $is_registered || stripos($author, 'FUNCKGROUP') !== false;

            if (!$is_funckgroup) {
                continue;
            }

            $registered_plugin = $registered_by_file[$plugin_file] ?? [];
            $slug = isset($registered_plugin['slug'])
                ? sanitize_key((string) $registered_plugin['slug'])
                : self::slug_from_plugin_file((string) $plugin_file);

            $embedded_core_version = '';
            if ($has_embedded_core) {
                $manifest = require $manifest_file;
                if (is_array($manifest) && isset($manifest['version'])) {
                    $embedded_core_version = sanitize_text_field((string) $manifest['version']);
                }
            }

            $plugins[] = [
                'slug'                  => $slug,
                'name'                  => sanitize_text_field((string) ($headers['Name'] ?? $slug)),
                'version'               => sanitize_text_field((string) ($headers['Version'] ?? '')),
                'plugin_file'           => sanitize_text_field((string) $plugin_file),
                'active'                => function_exists('is_plugin_active') ? is_plugin_active((string) $plugin_file) : false,
                'network_active'        => function_exists('is_plugin_active_for_network') ? is_plugin_active_for_network((string) $plugin_file) : false,
                'registered_with_core'  => $is_registered,
                'embedded_core_version' => $embedded_core_version,
            ];
        }

        usort(
            $plugins,
            static fn(array $left, array $right): int => strcasecmp((string) $left['name'], (string) $right['name'])
        );

        return $plugins;
    }

    private static function slug_from_plugin_file(string $plugin_file): string
    {
        $directory = dirname($plugin_file);
        if ($directory !== '.' && $directory !== '') {
            return sanitize_key(basename($directory));
        }

        return sanitize_key(pathinfo($plugin_file, PATHINFO_FILENAME));
    }

    private static function is_enabled(): bool
    {
        if (defined('FG_CORE_STATUS_REPORTING') && FG_CORE_STATUS_REPORTING === false) {
            return false;
        }

        return (bool) apply_filters('fg_core_status_reporting_enabled', true);
    }

    private static function get_endpoint(): string
    {
        $default = defined('FG_CORE_STATUS_ENDPOINT')
            ? (string) FG_CORE_STATUS_ENDPOINT
            : 'https://lizenz.funckgroup-server.com/wp-json/fg-lizenz/v1/status';

        return esc_url_raw((string) apply_filters('fg_core_status_endpoint', $default));
    }

    private static function get_installation_id(): string
    {
        $current_home = untrailingslashit(strtolower(home_url('/')));
        $stored_home = untrailingslashit(strtolower((string) get_option(self::OPTION_INSTALLATION_HOME, '')));
        $installation_id = (string) get_option(self::OPTION_INSTALLATION_ID, '');

        // A copied database on another domain must become a separate installation.
        if ($stored_home !== '' && $stored_home !== $current_home) {
            $installation_id = '';
            delete_option(self::OPTION_INSTALLATION_TOKEN);
        }

        if (!wp_is_uuid($installation_id)) {
            $installation_id = wp_generate_uuid4();
            update_option(self::OPTION_INSTALLATION_ID, $installation_id, false);
        }

        update_option(self::OPTION_INSTALLATION_HOME, $current_home, false);
        return $installation_id;
    }

    private static function get_installation_token(): string
    {
        $token = (string) get_option(self::OPTION_INSTALLATION_TOKEN, '');
        if (strlen($token) >= 48) {
            return $token;
        }

        $token = wp_generate_password(64, false, false);
        update_option(self::OPTION_INSTALLATION_TOKEN, $token, false);
        return $token;
    }

    private static function store_error(string $message, int $http_code): void
    {
        update_option(self::OPTION_LAST_ERROR, sanitize_text_field($message), false);
        update_option(self::OPTION_LAST_HTTP_CODE, $http_code, false);
        do_action('fg_core_status_report_failed', $message, $http_code);
    }
}
