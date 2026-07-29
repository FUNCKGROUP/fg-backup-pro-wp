# FG Backup Pro

FG Backup Pro erstellt lokale WordPress-Sicherungen im geschützten FUNCKGROUP-Verzeichnis und kann fertige Backups anschließend per SFTP übertragen. Die Verwaltung erfolgt über FG Core im WordPress-Admin.

## Version 2.0.0 – Test-Build

- alle Funktionen aus Version 1.1.1
- SFTP über phpseclib 3
- Anmeldung mit Passwort oder privatem SSH-Schlüssel
- optionale Key-Passphrase
- verschlüsselte Speicherung von Passwort und Passphrase
- alternativ sensible Werte über Konstanten in `wp-config.php`
- Prüfung und feste Speicherung des SSH-Serverschlüssels
- Schreib-, Größen- und Löschtest beim Verbindungstest
- Upload in Blöcken mit Fortschritt und kontrolliertem Abbruch
- unvollständige Remote-Dateien erhalten vorübergehend die Endung `.part`
- Größenprüfung vor und nach dem finalen Umbenennen
- Remote-Rotation
- Remote-Dateiliste auf Abruf und geschütztes Löschen
- lokale Datei nach erfolgreichem Upload wahlweise behalten oder löschen
- bei einem SFTP-Fehler bleibt das bereits geprüfte lokale Backup erhalten
- deutlich sichtbare Speicherbedarfsschätzung vor dem Start

S3, WebDAV, Dropbox, Google Drive und OneDrive sind noch nicht Bestandteil dieses Test-Builds.

## Voraussetzungen

- WordPress 5.8 oder neuer
- PHP 7.4 oder neuer
- Schreibzugriff auf `wp-content/.fg-private/`
- `ZipArchive` für ZIP und SQL.ZIP
- `PharData` und GZIP/Zlib für TGZ
- GZIP/Zlib für SQL.GZ
- Composer für den Entwicklungs- beziehungsweise Source-Build

## Composer

Composer liegt im Root des Plugins. Version 2.0 benötigt phpseclib für SFTP und synchronisiert weiterhin FG Core:

```bash
composer update
composer audit
```

Die Plattform ist in `composer.json` auf PHP 7.4 gesetzt, damit der erzeugte Abhängigkeitsstand auch auf älteren PHP-7.4-Projekten einsetzbar bleibt.

Eine fertige Release-ZIP muss enthalten:

```text
composer.json
composer.lock
vendor/                  Runtime-Abhängigkeiten einschließlich phpseclib
includes/fg-core/        synchronisierter FG Core
```

`vendor/funckgroup/fg-core/` ist nur für Entwicklung und Synchronisierung erforderlich. Auf einer WordPress-Installation ist Composer nicht nötig, wenn eine vollständige Release-ZIP verwendet wird.

## SFTP einrichten

1. SFTP-Einstellungen speichern.
2. `Verbindung testen` ausführen.
3. Den angezeigten SSH-Host-Key beziehungsweise Fingerprint prüfen.
4. SFTP aktivieren.
5. Ein kleines Datenbank-Backup testen.

Beim ersten erfolgreichen Verbindungstest wird der öffentliche SSH-Serverschlüssel fest gespeichert. Ändert er sich später, wird die Verbindung blockiert, bis der gespeicherte Schlüssel bewusst zurückgesetzt und erneut geprüft wurde.

Das Zielverzeichnis unterstützt:

```text
%host   Domain der WordPress-Installation
%site   Website-Titel
```

Beispiel:

```text
/backups/%host
```

### Zugangsdaten über wp-config.php

```php
define('FG_BACKUP_SFTP_PASSWORD', '...');
define('FG_BACKUP_SFTP_PRIVATE_KEY_PATH', '/absoluter/pfad/backup_ed25519');
define('FG_BACKUP_SFTP_KEY_PASSPHRASE', '...');
```

Nur die zur gewählten Anmeldeart benötigten Konstanten müssen gesetzt werden.

## SFTP-Verhalten

Das lokale Backup wird zuerst vollständig erstellt und geprüft. Erst danach startet der SFTP-Upload. Während des Uploads wird eine temporäre Datei mit `.part` verwendet. Nach vollständiger Größenprüfung wird sie in den endgültigen Dateinamen umbenannt.

Wird der Upload abgebrochen oder schlägt er fehl, wird die `.part`-Datei nach Möglichkeit entfernt. Das geprüfte lokale Backup bleibt erhalten. Die lokale Datei wird nur gelöscht, wenn der SFTP-Upload vollständig erfolgreich war und die entsprechende Option deaktiviert ist.

## Speicherbedarf

Auf der Backup-Seite wird vor dem Start der geschätzte temporäre Speicherbedarf für den gewählten Backup-Typ deutlich angezeigt. Bei vollständigen Backups ist dies zunächst eine Startreserve. Nach dem Dateiscan erfolgt zusätzlich eine genauere Prüfung anhand der tatsächlich erfassten Dateigröße.

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

## Speicherort

```text
wp-content/.fg-private/fg-backup-pro/
├── backups/
└── temporary/
```

FG Backup Pro ergänzt fehlende Schutzdateien, überschreibt aber keine vorhandenen Schutzdateien in `.fg-private`.

## GitHub Update

Repository:

```text
https://github.com/FUNCKGROUP/fg-backup-pro-wp
```

Der Release-Ordner muss `fg-backup-pro-wp` heißen und die Hauptdatei `fg-backup-pro.php` enthalten.

## Changelog

Die Änderungen stehen in [`CHANGELOG.md`](CHANGELOG.md).
