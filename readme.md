# FG Backup Pro

FG Backup Pro erstellt lokale WordPress-Sicherungen im geschützten FUNCKGROUP-Verzeichnis und wird über FG Core im WordPress-Admin eingebunden.

## Version 1.1.0

- vollständige Sicherung von Dateien und Datenbank
- reines Datenbank-Backup
- asynchrone Verarbeitung in kleinen WP-Cron-Schritten
- Prüfung des fertigen Backups und SHA-256-Prüfsumme
- geschützter Download über WordPress
- automatische Rotation und Job-Historie
- tägliche, wöchentliche oder monatliche Planung
- Erkennung klassischer WordPress- und Bedrock-Strukturen
- Integration in FG Core 1.1.3
- Unterstützung durch FG GitHub Update

Remote-Speicherziele sind für Version 2 vorgesehen.

## Voraussetzungen

- WordPress 5.8 oder neuer
- PHP 7.4 oder neuer
- `ZipArchive` für vollständige Backups
- Schreibzugriff auf `wp-content/.fg-private/`

Datenbank-Backups funktionieren auch ohne `ZipArchive`.

## Speicherort

```text
wp-content/.fg-private/fg-backup-pro/
├── backups/
└── temporary/
```

FG Backup Pro ergänzt fehlende Schutzdateien, überschreibt aber keine vorhandenen Schutzdateien in `.fg-private`.

Alte lokale Sicherungen aus `wp-content/fg-backup-pro/` oder `wp-content/backups/` werden beim Update in den neuen Ordner verschoben.

## Composer

Composer liegt im Root des Plugins und dient der Entwicklung sowie der Synchronisierung von FG Core.

```bash
composer update funckgroup/fg-core
composer audit
```

Der eingebettete Runtime-Core liegt unter:

```text
includes/fg-core/
```

Auf der WordPress-Installation wird Composer nicht benötigt.

## Standardmäßig ausgeschlossene Verzeichnisse

- `.git`
- `.svn`
- `node_modules`
- WordPress-Cache- und Upgrade-Verzeichnisse
- bekannte Backup-Verzeichnisse anderer Plugins
- `wp-content/.fg-private`

Zusätzliche Pfadteile können in den Einstellungen zeilenweise eingetragen werden.

## GitHub Update

Repository:

```text
https://github.com/FUNCKGROUP/fg-backup-pro-wp
```

Der Release-Ordner muss `fg-backup-pro-wp` heißen und die Hauptdatei `fg-backup-pro.php` enthalten.
