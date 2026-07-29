<?php
/**
 * Plugin Name:       FG Core Dummy
 * Plugin URI:        https://funckgroup.com/
 * Description:       Referenz-Plugin für die Einbindung und Verwendung von FG Core in FUNCKGROUP-WordPress-Plugins.
 * Version:           1.1.3
 * Author:            FUNCKGROUP
 * Author URI:        https://funckgroup.com/
 * Text Domain:       fg-core-dummy
 * Requires at least: 6.0
 * Requires PHP:      7.4
 */

defined('ABSPATH') || exit;

define('FG_CORE_DUMMY_VERSION', '1.1.3');
define('FG_CORE_DUMMY_FILE', __FILE__);
define('FG_CORE_DUMMY_DIR', plugin_dir_path(__FILE__));
define('FG_CORE_DUMMY_URL', plugin_dir_url(__FILE__));

/*
 * 1. FG Core möglichst früh laden.
 *
 * Jedes FUNCKGROUP-Plugin liefert seinen eigenen eingebetteten Core-Kandidaten
 * mit. Der Loader wählt nach dem Laden aller Plugins automatisch die höchste
 * verfügbare kompatible Version aus.
 */
$core_bootstrap = FG_CORE_DUMMY_DIR . 'includes/fg-core/bootstrap.php';

if (is_readable($core_bootstrap)) {
    require_once $core_bootstrap;
}

/*
 * 2. Plugin-eigene Klassen laden.
 *
 * Fachlogik und Admin-Ausgabe bleiben im Plugin. FG Core stellt nur die
 * gemeinsame Infrastruktur wie Menü, Registry und Tabs bereit.
 */
require_once FG_CORE_DUMMY_DIR . 'includes/class-fg-core-dummy-admin.php';

/*
 * 3. Plugin nach dem Core-Bootstrap initialisieren.
 *
 * Der ausgewählte Core startet auf plugins_loaded mit sehr früher Priorität.
 * Die Plugin-Initialisierung kann deshalb danach normal erfolgen.
 */
function fg_core_dummy_boot(): void
{
    if (is_admin()) {
        FG_Core_Dummy_Admin::get_instance();
    }
}
add_action('plugins_loaded', 'fg_core_dummy_boot', 20);
