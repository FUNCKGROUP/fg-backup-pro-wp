# FG Core Dummy – verbindliche Hinweise

Dieses Repository ist ein Referenz-Plugin für die Integration von FG Core.

- FG Core ist kein separates WordPress-Plugin.
- Das auslieferbare Plugin enthält FG Core unter `includes/fg-core/`.
- Die Hauptdatei lädt zuerst `includes/fg-core/bootstrap.php`.
- Ein Plugin registriert genau einen Menüpunkt unter FUNCKGROUP.
- Mehrere Hauptbereiche werden über die zentrale `tabs`-Registrierung umgesetzt.
- Plugins mit nur einer Seite verwenden `callback` statt künstlicher Tabs.
- Bestehende Slugs, Capabilities, Nonces und Fachlogik bleiben bei Migrationen möglichst erhalten.
- Keine direkten `add_menu_page()`-Aufrufe für migrierte FG-Plugins ergänzen.
- FG Core wird zentral entwickelt und vor Releases per Sync/Composer in das Plugin eingebettet.
- WordPress darf FG Core zur Laufzeit nicht von GitHub herunterladen oder selbständig PHP-Dateien ersetzen.
