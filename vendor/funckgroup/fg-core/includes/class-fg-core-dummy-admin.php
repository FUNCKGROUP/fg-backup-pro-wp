<?php

defined('ABSPATH') || exit;

final class FG_Core_Dummy_Admin
{
    private static ?FG_Core_Dummy_Admin $instance = null;

    public static function get_instance(): FG_Core_Dummy_Admin
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        /*
         * Registrierung vor FG Cores admin_menu-Priorität 99.
         * Die alte add_menu_page()-Logik entfällt im Plugin vollständig.
         */
        add_action('admin_menu', [$this, 'register_with_fg_core'], 30);
    }

    public function register_with_fg_core(): void
    {
        if (!function_exists('fg_core_register_plugin')) {
            return;
        }

        fg_core_register_plugin([
            'slug'        => 'fg-core-dummy',
            'title'       => __('FG Core Dummy', 'fg-core-dummy'),
            'menu_title'  => __('FG Core Dummy', 'fg-core-dummy'),
            'description' => __('Referenzstruktur für FUNCKGROUP-WordPress-Plugins.', 'fg-core-dummy'),
            'capability'  => 'manage_options',
            'version'     => FG_CORE_DUMMY_VERSION,
            'plugin_file' => FG_CORE_DUMMY_FILE,
            'position'    => 10,
            'default_tab' => 'overview',
            'tabs'        => [
                'overview' => [
                    'title'    => __('Übersicht', 'fg-core-dummy'),
                    'callback' => [$this, 'render_overview'],
                    'position' => 10,
                ],
                'settings' => [
                    'title'    => __('Einstellungen', 'fg-core-dummy'),
                    'callback' => [$this, 'render_settings'],
                    'position' => 20,
                ],
                'diagnostics' => [
                    'title'    => __('Diagnose', 'fg-core-dummy'),
                    'callback' => [$this, 'render_diagnostics'],
                    'position' => 30,
                ],
            ],
        ]);
    }

    /**
     * FG Core rendert bereits Seitenkopf, Beschreibung, Tabs und Wrapper.
     * Der Tab-Callback liefert deshalb nur den eigentlichen Tab-Inhalt.
     *
     * @param array<string,mixed> $plugin
     */
    public function render_overview(array $plugin = [], string $tab_slug = ''): void
    {
        echo '<div class="card" style="max-width:820px">';
        echo '<h2>' . esc_html__('So wird FG Core eingebunden', 'fg-core-dummy') . '</h2>';
        echo '<p>' . esc_html__('Dieses Dummy-Plugin ist gleichzeitig installierbarer Funktionstest und Vorlage für weitere FUNCKGROUP-Plugins.', 'fg-core-dummy') . '</p>';
        echo '<ol>';
        echo '<li><code>includes/fg-core/bootstrap.php</code> in der Hauptdatei laden.</li>';
        echo '<li>Plugin-eigene Klassen laden und initialisieren.</li>';
        echo '<li>Das Plugin über <code>fg_core_register_plugin()</code> registrieren.</li>';
        echo '<li>Eine einzelne Seite über <code>callback</code> oder mehrere Bereiche über <code>tabs</code> bereitstellen.</li>';
        echo '</ol>';
        echo '<p><strong>' . esc_html__('Wichtig:', 'fg-core-dummy') . '</strong> ';
        echo esc_html__('FG Core enthält gemeinsame Infrastruktur. Die Fachlogik bleibt immer im jeweiligen Plugin.', 'fg-core-dummy');
        echo '</p>';
        echo '</div>';
    }

    /**
     * Beispiel für ein normales Plugin-Formular innerhalb eines Core-Tabs.
     * FG Core ersetzt keine Nonces, Capabilities oder Sanitizing-Regeln.
     *
     * @param array<string,mixed> $plugin
     */
    public function render_settings(array $plugin = [], string $tab_slug = ''): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Du hast keine Berechtigung für diese Seite.', 'fg-core-dummy'));
        }

        if (isset($_POST['fg_core_dummy_save'])) {
            check_admin_referer('fg_core_dummy_save_settings', 'fg_core_dummy_nonce');

            $example_text = isset($_POST['fg_core_dummy_example_text'])
                ? sanitize_text_field(wp_unslash((string) $_POST['fg_core_dummy_example_text']))
                : '';

            update_option('fg_core_dummy_example_text', $example_text, false);

            add_settings_error(
                'fg_core_dummy_messages',
                'fg_core_dummy_saved',
                __('Einstellungen gespeichert.', 'fg-core-dummy'),
                'success'
            );
        }

        settings_errors('fg_core_dummy_messages');

        $value = (string) get_option('fg_core_dummy_example_text', '');

        echo '<form method="post">';
        wp_nonce_field('fg_core_dummy_save_settings', 'fg_core_dummy_nonce');

        echo '<table class="form-table" role="presentation"><tbody><tr>';
        echo '<th scope="row"><label for="fg-core-dummy-example-text">' . esc_html__('Beispielwert', 'fg-core-dummy') . '</label></th>';
        echo '<td>';
        echo '<input class="regular-text" type="text" id="fg-core-dummy-example-text" name="fg_core_dummy_example_text" value="' . esc_attr($value) . '">';
        echo '<p class="description">' . esc_html__('Dieses Feld zeigt, dass Formulare weiterhin vollständig im Plugin verarbeitet werden.', 'fg-core-dummy') . '</p>';
        echo '</td></tr></tbody></table>';

        submit_button(__('Speichern', 'fg-core-dummy'), 'primary', 'fg_core_dummy_save');
        echo '</form>';
    }

    /**
     * @param array<string,mixed> $plugin
     */
    public function render_diagnostics(array $plugin = [], string $tab_slug = ''): void
    {
        $core = function_exists('fg_core') ? fg_core() : null;
        $runtime = $core ? $core->get_runtime() : [];
        $registered_plugins = $core ? $core->get_plugins() : [];

        $rows = [
            __('Dummy-Plugin-Version', 'fg-core-dummy') => FG_CORE_DUMMY_VERSION,
            __('Aktive FG-Core-Version', 'fg-core-dummy') => defined('FG_CORE_VERSION') ? FG_CORE_VERSION : '–',
            __('FG-Core-API', 'fg-core-dummy') => defined('FG_CORE_API_VERSION') ? (string) FG_CORE_API_VERSION : '–',
            __('Core bereitgestellt von', 'fg-core-dummy') => isset($runtime['provider']) ? (string) $runtime['provider'] : '–',
            __('Registrierte FG-Plugins', 'fg-core-dummy') => (string) count($registered_plugins),
            __('WordPress-Version', 'fg-core-dummy') => get_bloginfo('version'),
            __('PHP-Version', 'fg-core-dummy') => PHP_VERSION,
        ];

        echo '<table class="widefat striped" style="max-width:820px"><tbody>';
        foreach ($rows as $label => $value) {
            echo '<tr><th style="width:260px">' . esc_html((string) $label) . '</th><td><code>' . esc_html((string) $value) . '</code></td></tr>';
        }
        echo '</tbody></table>';

        if (!empty($runtime['candidates']) && is_array($runtime['candidates'])) {
            echo '<h2>' . esc_html__('Gefundene Core-Kandidaten', 'fg-core-dummy') . '</h2>';
            echo '<table class="widefat striped" style="max-width:820px"><thead><tr>';
            echo '<th>' . esc_html__('Provider', 'fg-core-dummy') . '</th>';
            echo '<th>' . esc_html__('Version', 'fg-core-dummy') . '</th>';
            echo '<th>' . esc_html__('API', 'fg-core-dummy') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($runtime['candidates'] as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                echo '<tr>';
                echo '<td>' . esc_html((string) ($candidate['provider'] ?? '–')) . '</td>';
                echo '<td><code>' . esc_html((string) ($candidate['version'] ?? '–')) . '</code></td>';
                echo '<td><code>' . esc_html((string) ($candidate['api_version'] ?? '–')) . '</code></td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }
    }
}
