<?php

namespace Funckgroup\FGCore;

defined('ABSPATH') || exit;

require_once __DIR__ . '/class-fg-status-reporter.php';

final class AdminCore
{
    private static ?AdminCore $instance = null;

    /** @var array<string,array<string,mixed>> */
    private array $plugins = [];

    /** @var array<string,mixed> */
    private array $runtime = [];

    private string $parent_slug = 'fg-admin-core';
    private bool $booted = false;
    private bool $menu_registered = false;

    public static function get_instance(): AdminCore
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
    }

    /**
     * @param array<string,mixed>                 $runtime
     * @param array<string,array<string,mixed>>  $queued_plugins
     */
    public function boot(array $runtime, array $queued_plugins = []): void
    {
        if ($this->booted) {
            return;
        }

        $this->runtime = $runtime;

        foreach ($queued_plugins as $plugin) {
            $this->register_plugin($plugin);
        }

        add_action('admin_menu', [$this, 'register_menu'], 99);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        StatusReporter::boot($this);

        $this->booted = true;
    }

    /**
     * @param array<string,mixed> $plugin
     */
    public function register_plugin(array $plugin): bool
    {
        $slug = isset($plugin['slug']) ? sanitize_key((string) $plugin['slug']) : '';
        if ($slug === '') {
            return false;
        }

        $defaults = [
            'slug'        => $slug,
            'title'       => $slug,
            'menu_title'  => $slug,
            'description' => '',
            'capability'  => 'manage_options',
            'callback'    => null,
            'tabs'        => [],
            'default_tab' => '',
            'position'    => 50,
            'version'     => '',
            'plugin_file' => '',
        ];

        $plugin = wp_parse_args($plugin, $defaults);
        $plugin['slug']       = $slug;
        $plugin['title']      = (string) $plugin['title'];
        $plugin['menu_title'] = (string) $plugin['menu_title'];
        $plugin['capability'] = (string) $plugin['capability'];
        $plugin['position']   = (int) $plugin['position'];
        $plugin['tabs']       = $this->normalize_tabs((array) $plugin['tabs']);

        if ($plugin['tabs'] === [] && !$this->is_callback_definition($plugin['callback'])) {
            return false;
        }

        if ($plugin['tabs'] !== []) {
            $default_tab = sanitize_key((string) $plugin['default_tab']);
            if ($default_tab === '' || !isset($plugin['tabs'][$default_tab])) {
                $default_tab = (string) array_key_first($plugin['tabs']);
            }
            $plugin['default_tab'] = $default_tab;
        }

        $this->plugins[$slug] = $plugin;
        return true;
    }

    /**
     * Rückwärtskompatibler Alias für ältere Integrationen.
     *
     * @param array<string,mixed> $plugin
     */
    public static function register_page(array $plugin): bool
    {
        return self::get_instance()->register_plugin($plugin);
    }

    public function register_menu(): void
    {
        if ($this->menu_registered || !is_admin()) {
            return;
        }

        $parent_capability = (string) apply_filters('fg_core_parent_capability', 'manage_options');
        $menu_icon = (string) apply_filters('fg_core_menu_icon', 'dashicons-admin-generic');
        $menu_position = (int) apply_filters('fg_core_menu_position', 58);

        add_menu_page(
            'FUNCKGROUP',
            'FUNCKGROUP',
            $parent_capability,
            $this->parent_slug,
            [$this, 'render_overview'],
            $menu_icon,
            $menu_position
        );

        add_submenu_page(
            $this->parent_slug,
            'FUNCKGROUP',
            __('Übersicht', 'fg-core'),
            $parent_capability,
            $this->parent_slug,
            [$this, 'render_overview']
        );

        foreach ($this->get_sorted_plugins() as $plugin) {
            if (!current_user_can((string) $plugin['capability'])) {
                continue;
            }

            $callback = $plugin['tabs'] !== []
                ? function () use ($plugin): void {
                    $this->render_tabbed_plugin((string) $plugin['slug']);
                }
                : function () use ($plugin): void {
                    $this->render_simple_plugin((string) $plugin['slug']);
                };

            add_submenu_page(
                $this->parent_slug,
                (string) $plugin['title'],
                (string) $plugin['menu_title'],
                (string) $plugin['capability'],
                (string) $plugin['slug'],
                $callback
            );
        }

        $this->menu_registered = true;
    }

    public function render_overview(): void
    {
        if (!current_user_can((string) apply_filters('fg_core_parent_capability', 'manage_options'))) {
            wp_die(esc_html__('Du hast keine Berechtigung für diese Seite.', 'fg-core'));
        }

        echo '<div class="wrap fg-core-wrap">';
        echo '<h1>FUNCKGROUP</h1>';
        echo '<p>' . esc_html__('Installierte FUNCKGROUP-Plugins.', 'fg-core') . '</p>';

        $plugins = $this->get_sorted_plugins();

        if ($plugins === []) {
            echo '<div class="notice notice-info inline"><p>';
            echo esc_html__('Es sind noch keine Plugins bei FG Core registriert.', 'fg-core');
            echo '</p></div></div>';
            return;
        }

        echo '<div class="fg-core-grid">';

        foreach ($plugins as $plugin) {
            $url = admin_url('admin.php?page=' . rawurlencode((string) $plugin['slug']));

            echo '<a class="fg-core-card" href="' . esc_url($url) . '">';
            echo '<span class="fg-core-card__title">' . esc_html((string) $plugin['menu_title']) . '</span>';

            if ((string) $plugin['description'] !== '') {
                echo '<span class="fg-core-card__description">' . esc_html((string) $plugin['description']) . '</span>';
            }

            if ((string) $plugin['version'] !== '') {
                echo '<span class="fg-core-card__meta">Version ' . esc_html((string) $plugin['version']) . '</span>';
            }

            echo '</a>';
        }

        echo '</div>';

        $core_version = isset($this->runtime['version']) ? (string) $this->runtime['version'] : '';
        $provider = isset($this->runtime['provider']) ? (string) $this->runtime['provider'] : '';

        if ($core_version !== '') {
            echo '<p class="fg-core-runtime">FG Core ' . esc_html($core_version);
            if ($provider !== '') {
                echo ' · bereitgestellt von ' . esc_html($provider);
            }
            echo '</p>';
        }

        $status = StatusReporter::get_diagnostics();

        if (!$status['enabled']) {
            echo '<p class="fg-core-runtime">' . esc_html__('Statusmeldung: deaktiviert', 'fg-core') . '</p>';
        } elseif (!empty($status['last_report']['sent_at'])) {
            echo '<p class="fg-core-runtime">';
            echo esc_html(sprintf(__('Statusmeldung: zuletzt %s', 'fg-core'), wp_date('d.m.Y H:i', (int) $status['last_report']['sent_at'])));
            echo '</p>';
        }

        if (!empty($status['last_error'])) {
            echo '<p class="description">' . esc_html__('Letzter Fehler:', 'fg-core') . ' ' . esc_html((string) $status['last_error']) . '</p>';
        }

        echo '</div>';
    }

    public function enqueue_assets(): void
    {
        if (!$this->is_core_admin_page()) {
            return;
        }

        $base_url = isset($this->runtime['base_url']) ? rtrim((string) $this->runtime['base_url'], '/') : '';
        $version  = isset($this->runtime['version']) ? (string) $this->runtime['version'] : null;

        if ($base_url === '') {
            return;
        }

        wp_enqueue_style(
            'fg-core-admin',
            $base_url . '/assets/admin.css',
            [],
            $version
        );
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function get_plugins(): array
    {
        return $this->plugins;
    }

    public function is_registered(string $slug): bool
    {
        return isset($this->plugins[sanitize_key($slug)]);
    }

    /**
     * @return array<string,mixed>
     */
    public function get_runtime(): array
    {
        return $this->runtime;
    }

    private function render_simple_plugin(string $plugin_slug): void
    {
        if (!isset($this->plugins[$plugin_slug])) {
            wp_die(esc_html__('Das Plugin ist nicht registriert.', 'fg-core'));
        }

        $plugin = $this->plugins[$plugin_slug];

        if (!current_user_can((string) $plugin['capability'])) {
            wp_die(esc_html__('Du hast keine Berechtigung für diese Seite.', 'fg-core'));
        }

        if (is_callable($plugin['callback'])) {
            call_user_func($plugin['callback']);
            return;
        }

        echo '<div class="wrap"><div class="notice notice-error"><p>';
        echo esc_html__('Der Callback für diese Plugin-Seite ist nicht verfügbar.', 'fg-core');
        echo '</p></div></div>';
    }

    private function render_tabbed_plugin(string $plugin_slug): void
    {
        if (!isset($this->plugins[$plugin_slug])) {
            wp_die(esc_html__('Das Plugin ist nicht registriert.', 'fg-core'));
        }

        $plugin = $this->plugins[$plugin_slug];

        if (!current_user_can((string) $plugin['capability'])) {
            wp_die(esc_html__('Du hast keine Berechtigung für diese Seite.', 'fg-core'));
        }

        $tab_slug = isset($_GET['tab'])
            ? sanitize_key(wp_unslash((string) $_GET['tab']))
            : (string) $plugin['default_tab'];

        if (!isset($plugin['tabs'][$tab_slug])) {
            $tab_slug = (string) $plugin['default_tab'];
        }

        $tab = $plugin['tabs'][$tab_slug];
        $tab_capability = (string) ($tab['capability'] ?: $plugin['capability']);

        if (!current_user_can($tab_capability)) {
            wp_die(esc_html__('Du hast keine Berechtigung für diesen Bereich.', 'fg-core'));
        }

        echo '<div class="wrap fg-core-wrap">';
        echo '<div class="fg-core-page-header">';
        echo '<h1>' . esc_html((string) $plugin['title']) . '</h1>';
        if ((string) $plugin['description'] !== '') {
            echo '<p>' . esc_html((string) $plugin['description']) . '</p>';
        }
        echo '</div>';

        echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__('Plugin-Bereiche', 'fg-core') . '">';

        foreach ($plugin['tabs'] as $slug => $candidate_tab) {
            $candidate_capability = (string) ($candidate_tab['capability'] ?: $plugin['capability']);
            if (!current_user_can($candidate_capability)) {
                continue;
            }

            $url = add_query_arg(
                [
                    'page' => $plugin_slug,
                    'tab'  => $slug,
                ],
                admin_url('admin.php')
            );

            $class = 'nav-tab' . ($slug === $tab_slug ? ' nav-tab-active' : '');
            echo '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">';
            echo esc_html((string) $candidate_tab['title']);
            echo '</a>';
        }

        echo '</nav>';
        echo '<div class="fg-core-tab-content">';

        if (is_callable($tab['callback'])) {
            call_user_func($tab['callback'], $plugin, $tab_slug);
        } else {
            echo '<div class="notice notice-error inline"><p>';
            echo esc_html__('Der Callback für diesen Tab ist nicht verfügbar.', 'fg-core');
            echo '</p></div>';
        }

        echo '</div></div>';
    }

    /**
     * @param array<int|string,mixed> $tabs
     * @return array<string,array<string,mixed>>
     */
    private function normalize_tabs(array $tabs): array
    {
        $normalized = [];

        foreach ($tabs as $key => $tab) {
            if (!is_array($tab)) {
                continue;
            }

            $slug = is_string($key) ? sanitize_key($key) : sanitize_key((string) ($tab['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $callback = $tab['callback'] ?? null;
            if (!$this->is_callback_definition($callback)) {
                continue;
            }

            $normalized[$slug] = [
                'slug'       => $slug,
                'title'      => (string) ($tab['title'] ?? $slug),
                'callback'   => $callback,
                'capability' => isset($tab['capability']) ? (string) $tab['capability'] : '',
                'position'   => isset($tab['position']) ? (int) $tab['position'] : 50,
            ];
        }

        uasort(
            $normalized,
            static function (array $left, array $right): int {
                $position_compare = $left['position'] <=> $right['position'];
                if ($position_compare !== 0) {
                    return $position_compare;
                }

                return strcasecmp((string) $left['title'], (string) $right['title']);
            }
        );

        return $normalized;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function get_sorted_plugins(): array
    {
        $plugins = $this->plugins;

        uasort(
            $plugins,
            static function (array $left, array $right): int {
                $position_compare = $left['position'] <=> $right['position'];
                if ($position_compare !== 0) {
                    return $position_compare;
                }

                return strcasecmp((string) $left['menu_title'], (string) $right['menu_title']);
            }
        );

        return $plugins;
    }

    /**
     * Akzeptiert auch Callbacks auf Klassen, die erst später geladen werden.
     * Die tatsächliche Aufrufbarkeit wird erst beim Rendern geprüft.
     *
     * @param mixed $callback
     */
    private function is_callback_definition($callback): bool
    {
        if ($callback instanceof \Closure || is_string($callback)) {
            return $callback !== '';
        }

        return is_array($callback)
            && count($callback) === 2
            && (is_object($callback[0]) || is_string($callback[0]))
            && is_string($callback[1])
            && $callback[1] !== '';
    }

    private function is_core_admin_page(): bool
    {
        if (!is_admin() || !isset($_GET['page'])) {
            return false;
        }

        $page = sanitize_key(wp_unslash((string) $_GET['page']));
        return $page === $this->parent_slug || isset($this->plugins[$page]);
    }
}

/*
 * Kompatibilitätsalias für die bereits verwendete API:
 * FG_Admin_Core::get_instance()->register_plugin(...)
 */
if (!class_exists('FG_Admin_Core', false)) {
    class_alias(AdminCore::class, 'FG_Admin_Core');
}
