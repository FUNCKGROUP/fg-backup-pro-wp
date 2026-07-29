# FG Backup Pro

FG Backup Pro erstellt lokale WordPress-Sicherungen im geschützten FUNCKGROUP-Verzeichnis und überträgt fertige Backups optional per SFTP. Die Verwaltung erfolgt als eigener Eintrag **FG Backup Pro** innerhalb von FG Core.

## Version 2.0.0

- vollständige Backups als ZIP oder TGZ
- Datenbank-Backups als SQL, SQL.GZ oder SQL.ZIP
- asynchrone Verarbeitung mit Fortschritt in der Backup-Seite und WordPress-Adminleiste
- kontrolliertes Abbrechen und Bereinigung unvollständiger Dateien
- frei definierbare Dateinamen mit Live-Vorschau und Platzhaltern
- strukturelle Prüfung fertiger Backups
- lokale Rotation und geschützte Downloads
- tägliche, wöchentliche oder monatliche Zeitplanung
- sichtbare Speicherbedarfsschätzung und Prüfung des freien Speicherplatzes
- optionale Notiz für manuelle Backups
- SFTP über phpseclib 3
- Anmeldung mit Passwort oder privatem SSH-Schlüssel
- optionale Key-Passphrase
- verschlüsselte Speicherung sensibler Zugangsdaten
- SSH-Host-Key-Pinning nach erfolgreichem Verbindungstest
- blockweiser Upload mit Fortschritt und Abbruch
- temporäre Remote-Dateien mit `.part`
- Remote-Rotation, Dateiliste und geschütztes Löschen
- lokale Sicherung nach erfolgreichem Upload wahlweise behalten oder löschen

Weitere Cloud-Speicher wie S3, WebDAV, Dropbox, Google Drive und OneDrive sind nicht Bestandteil von Version 2.0.0.

## Voraussetzungen

- WordPress 5.8 oder neuer
- PHP 7.4 oder neuer
- Schreibzugriff auf `wp-content/.fg-private/`
- `ZipArchive` für ZIP und SQL.ZIP
- `PharData` und GZIP/Zlib für TGZ
- GZIP/Zlib für SQL.GZ

Eine vollständige Release-ZIP enthält alle Runtime-Abhängigkeiten. Auf der WordPress-Installation ist deshalb kein Composer-Befehl erforderlich.

## Installation

1. Vorhandenes FG-Backup-Pro-Plugin deaktivieren.
2. Den bisherigen Plugin-Ordner löschen oder über den WordPress-Upload ersetzen.
3. Die vollständige Release-ZIP installieren und aktivieren.
4. FG Backup Pro unter **FUNCKGROUP → FG Backup Pro** öffnen.

Backups unter `wp-content/.fg-private/fg-backup-pro/` bleiben beim Austausch des Plugin-Ordners erhalten.

## Composer und Entwicklung

Composer liegt im Root des Plugins. Die Laufzeitabhängigkeit für SFTP ist `phpseclib/phpseclib`. FG Core wird als Entwicklungsabhängigkeit bezogen und anschließend nach `includes/fg-core/` synchronisiert.

```bash
composer update
composer audit
```

Die Composer-Plattform ist auf PHP 7.4 gesetzt, damit der erzeugte Abhängigkeitsstand auf bestehenden PHP-7.4-Projekten eingesetzt werden kann.

Eine fertige Release-ZIP enthält mindestens:

```text
composer.json
composer.lock
vendor/                  Runtime-Abhängigkeiten einschließlich phpseclib
includes/fg-core/        synchronisierter FG Core
```

`vendor/funckgroup/fg-core/` ist nur während der Entwicklung und Synchronisierung erforderlich. Das Skript `tools/build-release.sh` erstellt aus dem Repository eine installierbare Release-ZIP.

## SFTP einrichten

1. SFTP-Einstellungen speichern.
2. **Verbindung testen** ausführen.
3. Den angezeigten SSH-Fingerprint mit dem Server vergleichen.
4. SFTP aktivieren.
5. Zunächst ein kleines Datenbank-Backup testen.

Beim ersten erfolgreichen Verbindungstest wird der öffentliche SSH-Serverschlüssel fest gespeichert. Ändert sich Host, Port oder Serverschlüssel später, blockiert FG Backup Pro die Verbindung, bis der gespeicherte Schlüssel bewusst zurückgesetzt und erneut geprüft wurde.

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

Nur die zur ausgewählten Anmeldeart benötigten Konstanten müssen gesetzt werden.

## SFTP-Verhalten

Das lokale Backup wird zuerst vollständig erstellt und geprüft. Erst danach beginnt der SFTP-Upload. Während des Uploads verwendet FG Backup Pro eine temporäre Datei mit `.part`. Nach erfolgreicher Übertragung und Größenprüfung wird sie in den endgültigen Dateinamen umbenannt.

Wird der Upload abgebrochen oder schlägt er fehl, wird die `.part`-Datei nach Möglichkeit entfernt. Das lokale Backup bleibt erhalten. Eine lokale Datei wird nur dann gelöscht, wenn der SFTP-Upload vollständig erfolgreich war und die entsprechende Einstellung aktiviert ist.

## Speicherbedarf

Vor dem Start zeigt die Backup-Seite den geschätzten temporären Speicherbedarf für den gewählten Backup-Typ an. Bei vollständigen Backups ist dies zunächst eine Startreserve. Nach dem Dateiscan erfolgt eine genauere Prüfung anhand der tatsächlich erfassten Dateigröße.

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

FG Backup Pro ergänzt fehlende Schutzdateien, überschreibt aber keine bereits vorhandenen Schutzdateien im gemeinsamen Verzeichnis `.fg-private`.

## GitHub Update

Repository:

```text
https://github.com/FUNCKGROUP/fg-backup-pro-wp
```

Der Ordner innerhalb der Release-ZIP muss `fg-backup-pro-wp` heißen und die Hauptdatei `fg-backup-pro.php` enthalten.

## Changelog

Die Änderungen stehen in [`CHANGELOG.md`](CHANGELOG.md).
