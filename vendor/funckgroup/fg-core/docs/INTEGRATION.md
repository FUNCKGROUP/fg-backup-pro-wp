# Integration in ein bestehendes Plugin

## 1. Ordner übernehmen

Den aktuellen Inhalt des zentralen Core-Repositories aus `package/` nach folgendem Ziel kopieren:

```text
mein-plugin/includes/fg-core/
```

## 2. Konstanten in der Hauptdatei

```php
define('MY_PLUGIN_VERSION', '1.0.0');
define('MY_PLUGIN_FILE', __FILE__);
define('MY_PLUGIN_DIR', plugin_dir_path(__FILE__));
```

## 3. Core laden

```php
$core_bootstrap = MY_PLUGIN_DIR . 'includes/fg-core/bootstrap.php';
if (is_readable($core_bootstrap)) {
    require_once $core_bootstrap;
}
```

## 4. Plugin registrieren

Einzelne Seite:

```php
fg_core_register_plugin([
    'slug'        => 'my-plugin',
    'title'       => 'My Plugin',
    'menu_title'  => 'My Plugin',
    'description' => 'Beschreibung.',
    'callback'    => [$this, 'render_page'],
    'version'     => MY_PLUGIN_VERSION,
    'plugin_file' => MY_PLUGIN_FILE,
]);
```

Mehrere Bereiche:

```php
fg_core_register_plugin([
    'slug'        => 'my-plugin',
    'title'       => 'My Plugin',
    'menu_title'  => 'My Plugin',
    'description' => 'Beschreibung.',
    'default_tab' => 'overview',
    'tabs'        => [
        'overview' => [
            'title'    => 'Übersicht',
            'callback' => [$this, 'render_overview'],
            'position' => 10,
        ],
        'settings' => [
            'title'    => 'Einstellungen',
            'callback' => [$this, 'render_settings'],
            'position' => 20,
        ],
    ],
]);
```

## 5. Alte Menüregistrierung entfernen

Die bisherigen `add_menu_page()`- und `add_submenu_page()`-Aufrufe werden entfernt. Bestehende Render-Callbacks können in der Regel weiterverwendet werden.

## Automatische Installations-Statusmeldung

FG Core 1.1 sendet einmal täglich einen gebündelten Status der WordPress-Installation an den zentralen Status-Endpunkt. Der Versand läuft ausschließlich automatisch im Hintergrund; es gibt keine manuelle Sende-Schaltfläche. Erfasst werden nur technische Installationsdaten:

- Domain, Site- und Home-URL
- WordPress-, PHP- und FG-Core-Version
- erkannte FUNCKGROUP-Plugins inklusive Version und Aktivstatus
- zufällige Installations-ID

Es werden keine Benutzer, Beiträge, Passwörter oder Datenbankinhalte übertragen.

Der Endpunkt kann in `wp-config.php` überschrieben werden:

```php
define('FG_CORE_STATUS_ENDPOINT', 'https://lizenz.funckgroup-server.com/wp-json/fg-lizenz/v1/status');
```

Die Meldung kann vollständig deaktiviert werden:

```php
define('FG_CORE_STATUS_REPORTING', false);
```
