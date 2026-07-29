# FG Backup Pro

FG Backup Pro erstellt lokale WordPress-Sicherungen im geschützten FUNCKGROUP-Verzeichnis und wird über FG Core im WordPress-Admin eingebunden.

## Version 1.1.0

- vollständige Sicherung von Dateien und Datenbank als ZIP oder TGZ
- Datenbank-Backup als SQL, SQL.GZ oder SQL.ZIP
- frei definierbares Dateinamensmuster mit Platzhaltern
- sichtbarer Live-Status mit Arbeitsschritt und Fortschritt
- laufende Sicherungen werden zusätzlich in der WordPress-Adminleiste angezeigt
- laufende Backups können kontrolliert abgebrochen werden
- asynchrone Verarbeitung in kleinen, aktiv angestoßenen WP-Cron-Schritten
- strukturelle Prüfung des fertigen Backups
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
- Schreibzugriff auf `wp-content/.fg-private/`
- `ZipArchive` für ZIP und SQL.ZIP
- `PharData` und GZIP/Zlib für TGZ
- GZIP/Zlib für SQL.GZ

Nicht verfügbare Formate werden in der Oberfläche deaktiviert. TGZ wird zunächst als unkomprimiertes TAR aufgebaut und anschließend mit GZIP komprimiert; bei großen Installationen ist es deshalb meist deutlich langsamer als ZIP.

## Dateinamen

Standard:

```text
fg-%type-%host-%Y%m%d-%H%M%S
```

Platzhalter:

```text
%Y      Jahr, vierstellig
%y      Jahr, zweistellig
%m      Monat
%d      Tag
%H      Stunde
%M      Minute
%S      Sekunde
%host   Domain
%site   Website-Titel
%type   full oder db
%format zip, tgz, sql, sql-gz oder sql-zip
%id     kurze Job-ID
```

Die passende Dateiendung wird automatisch ergänzt.

## Status

Ein Backup gilt erst als abgeschlossen, wenn das Archiv beziehungsweise die SQL-Sicherung lesbar ist, die erwarteten Pflichtdateien enthält und in den endgültigen Backup-Ordner verschoben wurde. Während des Laufs werden Arbeitsschritt, Detail und Fortschritt auf der Backup-Seite sowie der aktuelle Fortschritt in der WordPress-Adminleiste angezeigt. Ein laufender Job kann über `Abbrechen` beendet werden; ein noch nicht aktiver Arbeitsschritt wird sofort abgebrochen, während ein bereits laufender Arbeitsschritt kontrolliert ausläuft. Temporäre Dateien werden anschließend entfernt. Danach erscheint der Eintrag mit dem Status `Abgeschlossen` oder `Abgebrochen`. Die Prüfung kontrolliert die Archivstruktur und die Pflichtinhalte; sie vergleicht nicht jede einzelne Quelldatei bytegenau.

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
