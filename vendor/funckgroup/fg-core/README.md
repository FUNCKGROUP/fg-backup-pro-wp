# FG Core Dummy

Installierbares Referenz-Plugin für die Einbindung von FG Core in FUNCKGROUP-WordPress-Plugins.

## Zweck

Das Plugin zeigt die später verbindliche Struktur:

```text
fg-core-dummy/
├── fg-core-dummy.php
├── includes/
│   ├── class-fg-core-dummy-admin.php
│   └── fg-core/
│       ├── bootstrap.php
│       ├── manifest.php
│       ├── assets/
│       └── src/
├── tools/
├── docs/
└── uninstall.php
```

## Laufzeit

WordPress benötigt weder Composer noch eine Verbindung zu GitHub. Das ausgelieferte Plugin enthält FG Core vollständig unter `includes/fg-core/`.

Die Hauptdatei lädt zuerst:

```php
require_once FG_CORE_DUMMY_DIR . 'includes/fg-core/bootstrap.php';
```

Danach werden die Plugin-Klassen geladen. Die Admin-Klasse registriert das Plugin über:

```php
fg_core_register_plugin([...]);
```

## Entwicklung

FG Core wird zentral im privaten Repository `funckgroup/fg-core` gepflegt. Für ein Plugin-Repository gibt es zwei sinnvolle Entwicklungswege:

1. `FG_CORE_SOURCE=/pfad/zum/fg-core/includes/fg-core composer fg-core:sync`
2. FG Core als private Composer-Abhängigkeit in `vendor/funckgroup/fg-core` installieren und danach `composer fg-core:sync` ausführen.

Das Sync-Skript kopiert ausschließlich `vendor/funckgroup/fg-core/includes/fg-core/` nach `includes/fg-core/`.

## Release

Vor dem Erstellen eines Plugin-ZIPs muss `includes/fg-core/` vorhanden und aktuell sein. Das Release-ZIP enthält den eingebetteten Core, aber normalerweise nicht `vendor/`, `.git/` oder lokale Entwicklungsdateien.
