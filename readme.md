# FG Backup Pro

FG Backup Pro erstellt strukturell geprüfte WordPress-Sicherungen im geschützten FUNCKGROUP-Verzeichnis und überträgt sie optional zu mehreren Remote-Zielen. Die Verwaltung erfolgt als eigener Eintrag **FG Backup Pro** innerhalb von FG Core.

## Version 2.3.0

- vollständige Backups als ZIP oder TGZ
- Datenbank-Backups als SQL, SQL.GZ oder SQL.ZIP
- asynchrone Verarbeitung mit Fortschritt in der Backup-Seite und WordPress-Adminleiste
- kontrolliertes Abbrechen und Bereinigung unvollständiger Dateien
- frei definierbare Dateinamen mit Live-Vorschau und Platzhaltern
- strukturelle Prüfung, lokale Rotation und geschützte Downloads
- tägliche, wöchentliche oder monatliche Zeitplanung
- sichtbare Speicherbedarfsschätzung und Prüfung des freien Speicherplatzes
- optionale Notiz für manuelle Backups
- gleichzeitige Nutzung mehrerer Remote-Ziele
- SFTP über phpseclib 3 mit Passwort oder SSH-Schlüssel
- WebDAV für Nextcloud, QNAP, Hetzner Storage Box, NAS und andere kompatible Server
- Dropbox über OAuth 2.0, PKCE und Refresh-Token
- S3-kompatibler Object Storage über AWS Signature Version 4
- praktisch getestet mit Amazon S3 und Backblaze B2
- Multipart-Uploads für große S3-Backups ohne AWS-SDK-Abhängigkeit
- Remote-Verbindungstest, Dateiliste, Löschen und Rotation
- gemeinsame lokale Aufbewahrung zentral für alle Remote-Ziele festlegen
- lokale Sicherung bleibt erhalten, sobald ein Remote-Ziel fehlschlägt
- schließbare Admin-Notice nach dem Speichern von Einstellungen
- Backup-Gesundheitsstatus für letzten Lauf, lokale Datei, Zeitplan, aktiven Prozess und alle aktivierten Remote-Ziele
- manuelle und tägliche Remote-Prüfung auf Erreichbarkeit, Vorhandensein und Dateigröße
- Warnung im WordPress-Admin und in der Adminleiste bei fehlgeschlagenen, überfälligen oder fehlenden Sicherungen
- E-Mail-Modi „nur Fehler und Warnungen“ oder „jeder abgeschlossene Lauf“ mit frei wählbarem Empfänger
- tägliche Gesundheitswarnungen mit Schutz vor wiederholten identischen E-Mails
- Mehrfachauswahl und gemeinsames Löschen lokaler Backups
- direkte Aktualisierung der Gesundheitsanzeige über „Jetzt prüfen“ ohne Seitenreload
- robuste Erkennung der neuesten tatsächlich vorhandenen lokalen Sicherung

## Voraussetzungen

- WordPress 5.8 oder neuer
- PHP 7.4 oder neuer
- Schreibzugriff auf `wp-content/.fg-private/`
- `ZipArchive` für ZIP und SQL.ZIP
- `PharData` und GZIP/Zlib für TGZ
- GZIP/Zlib für SQL.GZ
- PHP-cURL für WebDAV, Dropbox und S3
- PHP-DOM für WebDAV und S3-XML-Antworten
- WebDAV-Server mit Basic-Authentifizierung über HTTPS

Eine installierbare Repository-Version enthält `vendor/`, `composer.lock` und den synchronisierten FG Core. Auf der WordPress-Zielseite ist kein Composer-Befehl erforderlich.

## Installation

1. Plugin-Dateien einschließlich `vendor/` und `composer.lock` nach WordPress übertragen.
2. Plugin aktivieren.
3. **FUNCKGROUP → FG Backup Pro** öffnen.

Backups unter `wp-content/.fg-private/fg-backup-pro/` bleiben beim Austausch des Plugin-Ordners erhalten.

## Composer und Entwicklung

Composer liegt im Root des Plugins. Die Laufzeitabhängigkeit für SFTP ist `phpseclib/phpseclib`. WebDAV, Dropbox und S3 verwenden cURL beziehungsweise die WordPress HTTP API und benötigen keine zusätzliche PHP-Bibliothek. Der S3-Adapter signiert die REST-Aufrufe direkt mit AWS Signature Version 4, damit PHP 7.4 unterstützt bleibt. FG Core wird als Entwicklungsabhängigkeit bezogen und nach `includes/fg-core/` synchronisiert.

```bash
composer update
composer audit
```

Die Composer-Plattform ist auf PHP 7.4 gesetzt. Im Repository werden mitgeführt:

```text
composer.json
composer.lock
vendor/
includes/fg-core/
```

## Backup-Gesundheit

Die Backup-Seite zeigt einen kompakten Gesundheitsstatus für:

- letzten verwendbaren und letzten fehlgeschlagenen Lauf
- vorhandene lokale Sicherung
- aktiven oder möglicherweise festhängenden Prozess
- aktivierten Zeitplan und nächsten WordPress-Cron-Lauf
- SFTP, WebDAV, Dropbox und S3

Über **Jetzt prüfen** werden alle aktivierten Remote-Ziele live abgefragt. Dabei kontrolliert FG Backup Pro, ob das zuletzt erfolgreich hochgeladene Backup weiterhin vorhanden ist und ob die Remote-Dateigröße mit der erzeugten Sicherung übereinstimmt.

Zusätzlich läuft einmal täglich eine automatische Prüfung. Kritische oder auffällige Zustände erscheinen im WordPress-Admin und optional per E-Mail. Identische Gesundheitswarnungen werden höchstens einmal innerhalb von 24 Stunden erneut versendet.
Auf der Backup-Seite kann die lokale Dateiliste per Checkbox gefiltert beziehungsweise gesammelt ausgewählt und sicher in einem Schritt gelöscht werden. Dateinamen mit mehreren Endungen wie `.db.sql.zip` werden dabei unverändert und pfadsicher verarbeitet.

## Remote-Ziele

Mehrere Ziele können gleichzeitig aktiviert werden. FG Backup Pro erstellt und prüft das lokale Backup zuerst und arbeitet anschließend SFTP, WebDAV, Dropbox und S3 nacheinander ab.

Die aktiven Remote-Ziele und die gemeinsame lokale Aufbewahrung werden zentral im Tab **Einstellungen** gewählt. Die einzelnen Remote-Tabs enthalten nur noch Verbindung, Zielpfad, Aufbewahrung und Dateiverwaltung.

Eine lokale Datei wird nur gelöscht, wenn:

1. alle aktivierten Remote-Ziele erfolgreich abgeschlossen wurden und
2. die gemeinsame Option „Backup nach erfolgreichen Remote-Uploads lokal behalten“ deaktiviert ist.

Fehlschläge einzelner Remote-Ziele werden in der Laufhistorie getrennt angezeigt. Andere aktivierte Ziele werden trotzdem weiterverarbeitet.

Remote-Passwörter sowie Dropbox Access-/Refresh-Tokens werden im SQL-Export bewusst geleert. Nach einer vollständigen Wiederherstellung müssen Remote-Verbindungen deshalb neu bestätigt werden. So enthält ein entwendetes Backup nicht gleichzeitig die Zugangsdaten zu seinen externen Speicherzielen.

## SFTP

SFTP verwendet phpseclib 3 und unterstützt:

- Passwort oder privaten SSH-Schlüssel
- optionale Key-Passphrase
- verschlüsselte Zugangsdaten
- SSH-Host-Key-Pinning
- blockweisen Upload mit `.part`-Datei
- Größenprüfung, Rotation, Dateiliste und Löschen

Beim ersten Verbindungstest wird der öffentliche SSH-Serverschlüssel gespeichert. Ändert er sich später, wird die Verbindung blockiert, bis der Schlüssel bewusst zurückgesetzt wurde.

Optionale Konstanten:

```php
define('FG_BACKUP_SFTP_PASSWORD', '...');
define('FG_BACKUP_SFTP_PRIVATE_KEY_PATH', '/absoluter/pfad/backup_ed25519');
define('FG_BACKUP_SFTP_KEY_PASSPHRASE', '...');
```

## WebDAV

Die WebDAV-URL muss auf den Benutzerbereich des Servers zeigen, zum Beispiel bei Nextcloud:

```text
https://cloud.example.com/remote.php/dav/files/benutzer
```

FG Backup Pro verwendet `PROPFIND`, `MKCOL`, `PUT`, `GET`, `MOVE` und `DELETE`. Uploads erfolgen zunächst als `.part` und werden nach Größenprüfung in den endgültigen Dateinamen verschoben.
WebDAV-Verzeichnisse werden mit ihrer kanonischen URL inklusive abschließendem Slash angesprochen. Gestreamte Uploads verwenden direkte Basic-Authentifizierung, damit der Dateistream nicht nach einer vorgeschalteten Authentifizierungsanfrage erneut gesendet werden muss.

Standardmäßig sind private und reservierte IP-Adressen blockiert. Für ein internes NAS oder eine interne Nextcloud kann dies bewusst freigeschaltet werden. HTTPS und eine gültige Zertifikatsprüfung bleiben erforderlich.

Optionales Passwort über `wp-config.php`:

```php
define('FG_BACKUP_WEBDAV_PASSWORD', '...');
```

## Dropbox

Die Standardverbindung verwendet eine zentrale Dropbox-App mit **App-Folder-Zugriff**. Der Callback-Relay nimmt nur den einmaligen Autorisierungscode entgegen und leitet ihn an die anfragende WordPress-Website zurück.

Nicht über den Relay übertragen werden:

- PKCE-Verifier
- Access- oder Refresh-Token
- Dropbox-Dateilisten
- Backup-Dateien

Die Kundenwebsite tauscht den Code selbst gegen Tokens und speichert diese verschlüsselt. Uploads erfolgen direkt von WordPress zu Dropbox. Zielordner werden innerhalb des App-Ordners automatisch angelegt. Große Dateien werden über Dropbox Upload Sessions in Blöcken übertragen.

Standard-Relay:

```text
https://lizenz.funckgroup-server.com/wp-json/fg-dropbox-relay/v1
```

Alternativ ist eine manuelle Verbindung ohne FUNCKGROUP-Relay möglich. Dafür ist der öffentliche App-Key einer eigenen oder der zentralen Dropbox-App erforderlich:

```php
define('FG_BACKUP_DROPBOX_APP_KEY', 'APP_KEY');
```

Eine abweichende Relay-Adresse kann gesetzt werden mit:

```php
define('FG_BACKUP_DROPBOX_RELAY_URL', 'https://example.com/wp-json/fg-dropbox-relay/v1');
```

### Dropbox-App und Relay

Für die zentrale App:

1. Scoped Dropbox-App mit **App Folder** erstellen.
2. Scopes aktivieren: `account_info.read`, `files.content.read`, `files.content.write`, `files.metadata.read`, `files.metadata.write`.
3. Das separate Plugin **FG Dropbox Relay** auf dem Callback-Server installieren.
4. Die dort angezeigte Redirect-URI exakt in der Dropbox App Console eintragen.
5. Den App-Key im Relay speichern oder über `FG_DROPBOX_RELAY_APP_KEY` bereitstellen.

## S3-kompatibler Object Storage

Der S3-Adapter unterstützt Amazon S3 sowie kompatible Anbieter wie Hetzner Object Storage, Cloudflare R2, Backblaze B2, Wasabi und MinIO. Konfiguriert werden Endpoint, Region, Bucket und Zugangsdaten. Path-Style und Virtual-Host-Style werden unterstützt.

Kleine Backups werden atomar als einzelnes Objekt übertragen. Größere Dateien verwenden einen blockweisen Multipart-Upload. Ein abgebrochener Multipart-Upload wird bereinigt, fertige Objekte werden per HEAD auf ihre Dateigröße geprüft und anschließend rotiert. Dateiliste und Löschen funktionieren direkt im WordPress-Admin.

Standardmäßig sind HTTPS und öffentliche Endpoints erforderlich. Private IP-Adressen und HTTP können für ein internes MinIO oder NAS bewusst freigeschaltet werden.

Optionale Konstanten:

```php
define('FG_BACKUP_S3_ACCESS_KEY', '...');
define('FG_BACKUP_S3_SECRET_KEY', '...');
define('FG_BACKUP_S3_SESSION_TOKEN', '...'); // nur bei temporären Zugangsdaten
define('FG_BACKUP_S3_PART_SIZE', 8 * 1024 * 1024);
```

Die Zugangsdaten werden verschlüsselt gespeichert und aus Datenbank-Backups entfernt. Der verwendete API-Benutzer benötigt mindestens Berechtigungen zum Auflisten des Buckets sowie zum Lesen, Schreiben, Löschen und für Multipart-Uploads im gewählten Prefix.

## Zielverzeichnisse

SFTP, WebDAV, Dropbox und S3 unterstützen:

```text
%host   Domain der WordPress-Installation
%site   Website-Titel
```

Standard:

```text
/backups/%host
```

Bei Dropbox befindet sich dieser Pfad innerhalb des exklusiven App-Ordners.

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

Die Dateiendung wird automatisch ergänzt.

## Speicherort

```text
wp-content/.fg-private/fg-backup-pro/
├── backups/
└── temporary/
```

FG Backup Pro ergänzt fehlende Schutzdateien, überschreibt aber keine vorhandenen Schutzdateien im gemeinsamen Verzeichnis `.fg-private`.

## GitHub Update

Repository:

```text
https://github.com/FUNCKGROUP/fg-backup-pro-wp
```

Der Repository-Ordner muss `fg-backup-pro-wp` heißen und die Hauptdatei `fg-backup-pro.php` enthalten. `vendor/`, `composer.lock` und `includes/fg-core/` gehören bei diesem Projekt zum installierbaren Stand auf `main` und in den Tags.

## Changelog

Die Änderungen stehen in [`CHANGELOG.md`](CHANGELOG.md).
