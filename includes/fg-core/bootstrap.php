<?php

defined('ABSPATH') || exit;

/*
 * FG Core Bootstrap
 *
 * Diese Datei bleibt bewusst klein. Jede eingebettete FG-Core-Kopie meldet
 * ihren Kandidaten. Nach dem Laden aller Plugins startet ausschließlich der
 * Kandidat mit der höchsten Version.
 */

if (!isset($GLOBALS['fg_core_loader_state']) || !is_array($GLOBALS['fg_core_loader_state'])) {
    $GLOBALS['fg_core_loader_state'] = [
        'candidates' => [],
        'plugins'    => [],
        'errors'     => [],
        'booted'     => false,
        'selected'   => null,
    ];
}

if (!function_exists('fg_core_register_plugin')) {
    /**
     * Registriert ein FUNCKGROUP-Plugin beim aktiven FG Core.
     *
     * Vor dem Core-Start wird die Registrierung zwischengespeichert. Nach dem
     * Core-Start wird sie direkt an die aktive Runtime weitergereicht.
     */
    function fg_core_register_plugin(array $plugin): bool
    {
        $slug = isset($plugin['slug']) ? sanitize_key((string) $plugin['slug']) : '';
        if ($slug === '') {
            return false;
        }

        $plugin['slug'] = $slug;

        if (class_exists('Funckgroup\\FGCore\\AdminCore', false)) {
            return \Funckgroup\FGCore\AdminCore::get_instance()->register_plugin($plugin);
        }

        $GLOBALS['fg_core_loader_state']['plugins'][$slug] = $plugin;
        return true;
    }
}

if (!function_exists('fg_core')) {
    /**
     * Liefert die aktive FG-Core-Instanz oder null, solange der Core nicht läuft.
     */
    function fg_core(): ?\Funckgroup\FGCore\AdminCore
    {
        if (!class_exists('Funckgroup\\FGCore\\AdminCore', false)) {
            return null;
        }

        return \Funckgroup\FGCore\AdminCore::get_instance();
    }
}

$manifest_file = __DIR__ . '/manifest.php';
$manifest = is_readable($manifest_file) ? require $manifest_file : null;

if (!is_array($manifest)) {
    $GLOBALS['fg_core_loader_state']['errors'][] = [
        'provider' => basename(dirname(__DIR__, 2)),
        'message'  => 'FG-Core-Manifest fehlt oder ist ungültig.',
    ];
    return;
}

$version      = isset($manifest['version']) ? (string) $manifest['version'] : '';
$api_version  = isset($manifest['api_version']) ? (int) $manifest['api_version'] : 0;
$requires_php = isset($manifest['requires_php']) ? (string) $manifest['requires_php'] : '7.4';
$entry_file   = isset($manifest['entry_file']) ? (string) $manifest['entry_file'] : '';
$provider     = basename(dirname(__DIR__, 2));

if ($version === '' || $api_version < 1 || $entry_file === '' || !is_readable($entry_file)) {
    $GLOBALS['fg_core_loader_state']['errors'][] = [
        'provider' => $provider,
        'message'  => 'FG-Core-Kandidat ist unvollständig oder nicht lesbar.',
    ];
    return;
}

if (version_compare(PHP_VERSION, $requires_php, '<')) {
    $GLOBALS['fg_core_loader_state']['errors'][] = [
        'provider' => $provider,
        'message'  => sprintf(
            'FG Core %s benötigt PHP %s oder neuer.',
            $version,
            $requires_php
        ),
    ];
    return;
}

$candidate = [
    'id'             => sha1($provider . '|' . $version . '|' . __FILE__),
    'version'        => $version,
    'api_version'    => $api_version,
    'requires_php'   => $requires_php,
    'provider'       => $provider,
    'entry_file'     => $entry_file,
    'bootstrap_file' => __FILE__,
    'base_path'      => __DIR__,
    'base_url'       => function_exists('plugins_url') ? plugins_url('', __FILE__) : '',
];

$GLOBALS['fg_core_loader_state']['candidates'][$candidate['id']] = $candidate;

/*
 * Jede Core-Kopie registriert ihren eigenen Boot-Callback. Nur der Callback
 * des tatsächlich höchsten Kandidaten startet die Runtime. Damit entscheidet
 * nicht die WordPress-Plugin-Ladereihenfolge über die aktive Core-Version.
 */
add_action(
    'plugins_loaded',
    static function () use ($candidate): void {
        if (!empty($GLOBALS['fg_core_loader_state']['booted'])) {
            return;
        }

        $candidates = array_values($GLOBALS['fg_core_loader_state']['candidates']);
        if ($candidates === []) {
            return;
        }

        usort(
            $candidates,
            static function (array $left, array $right): int {
                $version_compare = version_compare($right['version'], $left['version']);
                if ($version_compare !== 0) {
                    return $version_compare;
                }

                $api_compare = $right['api_version'] <=> $left['api_version'];
                if ($api_compare !== 0) {
                    return $api_compare;
                }

                return strcmp((string) $left['provider'], (string) $right['provider']);
            }
        );

        $selected = $candidates[0];
        if ($selected['id'] !== $candidate['id']) {
            return;
        }

        if (!defined('FG_CORE_VERSION')) {
            define('FG_CORE_VERSION', (string) $selected['version']);
        }
        if (!defined('FG_CORE_API_VERSION')) {
            define('FG_CORE_API_VERSION', (int) $selected['api_version']);
        }
        if (!defined('FG_CORE_PROVIDER')) {
            define('FG_CORE_PROVIDER', (string) $selected['provider']);
        }

        require_once $selected['entry_file'];

        if (!class_exists('Funckgroup\\FGCore\\AdminCore', false)) {
            $GLOBALS['fg_core_loader_state']['errors'][] = [
                'provider' => $selected['provider'],
                'message'  => 'Die ausgewählte FG-Core-Runtime konnte nicht geladen werden.',
            ];
            return;
        }

        if (class_exists('FG_Admin_Core', false)
            && !is_a('FG_Admin_Core', 'Funckgroup\\FGCore\\AdminCore', true)
        ) {
            $GLOBALS['fg_core_loader_state']['errors'][] = [
                'provider' => $selected['provider'],
                'message'  => 'Eine ältere FG_Admin_Core-Klasse war bereits geladen. Alte Plugins sollten auf den Bootstrap-Loader umgestellt werden.',
            ];
        }

        $GLOBALS['fg_core_loader_state']['booted']   = true;
        $GLOBALS['fg_core_loader_state']['selected'] = $selected;

        $runtime = $selected;
        $runtime['candidates'] = $candidates;
        $runtime['errors']     = $GLOBALS['fg_core_loader_state']['errors'];

        $core = \Funckgroup\FGCore\AdminCore::get_instance();
        $core->boot($runtime, $GLOBALS['fg_core_loader_state']['plugins']);

        do_action('fg_core_loaded', $core, $runtime);
    },
    -9999
);
