# FG Backup Pro

FG Backup Pro erstellt strukturell geprüfte WordPress-Sicherungen an einem geschützten, frei wählbaren lokalen Speicherort und überträgt sie optional zu mehreren Remote-Zielen. Die Verwaltung erfolgt als eigener Eintrag **FG Backup Pro** innerhalb von FG Core.

## Version 2.4.3

- vollständige Backups als ZIP oder TGZ
- Datenbank-Backups als SQL, SQL.GZ oder SQL.ZIP
- unabhängiger PHP-CLI-Worker für lange Backup-Schritte mit bestätigtem Start und sicherem HTTP-/WP-Cron-Fallback
- geordnete Worker-Übergabe bei langen Läufen, damit Prozesslimits des Hostings nicht den gesamten Auftrag beenden
- ZIP-Vollbackups werden einmal geöffnet, vollständig befüllt und einmal geschlossen
- echter Fortschritt nach Dateien, Bytes und – sofern von libzip unterstützt – beim finalen ZIP-Schreibvorgang
- Heartbeat, letzte Aktivität, Worker-Status und kleines Joblog pro Backup-Auftrag
- asynchrone Statusanzeige in der Backup-Seite und WordPress-Adminleiste
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
- fortsetzbare Dropbox-Upload-Sessions mit dauerhaftem Offset, begrenzten Wiederholungen und Synchronisierung nach Verbindungsabbrüchen
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
- JSON-Manifest als gleichnamige `.json`-Datei neben jedem lokalen und entfernten Backup
- eingebettetes `fg-backup.json` in vollständigen ZIP- und TGZ-Sicherungen
- automatische Tiefenvalidierung neuer Sicherungen vor jedem Remote-Upload
- vollständiges Lesen aller Archiveinträge einschließlich Pfad-, Struktur- und SQL-Prüfung
- dauerhafte SHA-256-Prüfsumme zur Erkennung nachträglicher Veränderungen
- eindeutige SQL-Abschlussmarkierung für Exporte ab Version 2.4.0
- manuelle Einzel- und Mehrfachvalidierung bestehender Sicherungen
- detaillierter Prüfbericht und geschützter JSON-Download im WordPress-Admin
- Remote-Gesundheitsprüfung kontrolliert zusätzlich das zugehörige JSON-Manifest
- lokale und entfernte Rotation schützt das letzte gültige vollständige Backup
- lokaler Speicherort wahlweise unter `wp-content/.fg-private`, automatisch außerhalb des Webroots oder als benutzerdefinierter absoluter Pfad
- Schreib-, Lese- und Löschtest des lokalen Speicherorts mit Anzeige von freiem Speicher und Webroot-Status

## Voraussetzungen

- WordPress 5.8 oder neuer
- PHP 7.4 oder neuer
- Schreibzugriff auf den gewählten lokalen Backup-Pfad; als sicherer Standard steht `wp-content/.fg-private/` zur Verfügung
- `ZipArchive` für ZIP und SQL.ZIP
- `PharData` und GZIP/Zlib für TGZ
- GZIP/Zlib für SQL.GZ
- PHP-cURL für WebDAV, Dropbox und S3
- PHP-DOM für WebDAV und S3-XML-Antworten
- für große ZIP-Vollbackups eine ausführbare PHP-CLI-Datei und mindestens eine verfügbare Startfunktion: `proc_open`, `exec` oder `shell_exec`
- WebDAV-Server mit Basic-Authentifizierung über HTTPS

Eine installierbare Repository-Version enthält `vendor/`, `composer.lock` und den synchronisierten FG Core. Auf der WordPress-Zielseite ist kein Composer-Befehl erforderlich.

## Installation

1. Plugin-Dateien einschließlich `vendor/` und `composer.lock` nach WordPress übertragen.
2. Plugin aktivieren.
3. **FUNCKGROUP → FG Backup Pro** öffnen.

Backups am gewählten lokalen Speicherort bleiben beim Austausch des Plugin-Ordners erhalten. Bei einem Wechsel des Speicherorts werden vorhandene Sicherungen bewusst nicht automatisch verschoben.

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


## Hintergrund-Worker und Shared Hosting

FG Backup Pro prüft bei jedem neuen Auftrag, ob eine tatsächlich startfähige PHP-CLI-Version und eine erlaubte Prozessfunktion verfügbar sind. Unterstützt werden `proc_open`, `exec` und `shell_exec`. Mögliche PHP-Dateien werden mit einem kurzen CLI-Test auf `PHP_SAPI`, Mindestversion und `mysqli` geprüft. Ein von der Shell zurückgelieferter Prozess-PID gilt noch nicht als erfolgreicher Start: Der neue Prozess muss WordPress laden, den Job-Token bestätigen und einen Heartbeat mit neuer Worker-Generation speichern. Erfolgt diese Bestätigung nicht, wird der vermeintliche CLI-Start verworfen und ein noch nicht begonnener kleiner Auftrag automatisch über WP-Cron fortgesetzt.

Der bestätigte Worker läuft unabhängig von der geöffneten WordPress-Adminseite und führt die vorhandenen Schritte für Datenbankexport, Dateiscan, Archivierung, Validierung und Remote-Upload weiter. Lange Aufträge werden ungefähr alle fünf Minuten geordnet an einen neuen CLI-Prozess übergeben. Auch diese Übergabe gilt erst nach erfolgreichem Handshake als abgeschlossen. Dadurch kann ein Hosting-Limit von beispielsweise 30 Minuten pro PHP-Prozess einen mehrstündigen Dropbox-Upload nicht mehr vollständig beenden.

Auf Plesk-Systemen werden neben dem PHP-Binary des aktuellen Prozesses auch typische Pfade unter `/opt/plesk/php/*/bin/php` geprüft. Bei einer abweichenden Installation kann der CLI-Pfad in `wp-config.php` fest vorgegeben werden:

```php
define('FG_BACKUP_PHP_CLI', '/opt/plesk/php/8.3/bin/php');
```

Der CLI-Worker kann auf einem Hosting bewusst deaktiviert werden. Kleine Datenbank- und andere begrenzte Aufträge verwenden dann direkt den HTTP-/WP-Cron-Fallback:

```php
define('FG_BACKUP_DISABLE_CLI_WORKER', true);
```

Für große ZIP-Aufträge bleibt weiterhin ein bestätigter CLI-Worker erforderlich; die Konstante ist daher keine Lösung für sehr große Vollbackups.

Kann kein CLI-Worker gestartet werden, versucht das Plugin kleine Aufträge genau einmal über den vorhandenen HTTP-/WP-Cron-Weg. Ein großer ZIP-Auftrag wird in diesem begrenzten Fallback nicht blind gestartet, sondern mit einer konkreten Meldung beendet. Das ist keine ZIP-Format- oder Dateigrößenbegrenzung: Im CLI-Modus gibt es kein künstliches 65-MB-Limit.

Jeder Auftrag schreibt ein begrenztes Log unter dem verwalteten Speicherpfad in `fg-backup-pro/logs/<job-id>.log`. Verwaiste temporäre Jobordner und alte Logs werden automatisch bereinigt.

## Zusätzliche Ausschlüsse

Zusätzliche Ausschlüsse werden zeilenweise und relativ zu `ABSPATH` verarbeitet. Doppelte Slashes und Backslashes werden normalisiert; führende und abschließende Slashes sind zulässig. Pfade mit `..`, URL-Schemata oder Zielen außerhalb des WordPress-Roots werden verworfen. Beispiele:

```text
/assets/video/
/temp/
```


## JSON-Manifest und Validierung

Zu jeder Sicherung legt FG Backup Pro eine gleichnamige JSON-Datei an:

```text
20260730-1407.example-de.full.zip
20260730-1407.example-de.full.zip.json
```

Das versionierte Manifest enthält ausschließlich technische Backup-Metadaten, darunter Typ, Format, Erstellungszeit, Größe, SHA-256-Prüfsumme, Dateianzahl, WordPress-/PHP-/Datenbankversion, Tabellen- und Zeilenzahl sowie den detaillierten Prüfbericht. Passwörter, Tokens, private Schlüssel, Datenbankzugänge und absolute Serverpfade werden nicht gespeichert.

Neue Backups werden vor dem Remote-Upload vollständig validiert. Dabei werden unter anderem geprüft:

- Lesbarkeit und tatsächliches Dateiformat
- vollständige Archivstruktur und sichere relative Pfade
- Lesbarkeit sämtlicher ZIP-/TGZ-Einträge
- eingebettetes `fg-backup.json` und enthaltene Datenbank
- SQL-Kopf, Tabellenmarkierungen und Abschlussmarkierung
- Dateianzahl im Archiv gegenüber dem eingebetteten Manifest
- unveränderte Größe und SHA-256-Prüfsumme bei späteren Prüfungen

Bestehende Sicherungen aus älteren Versionen können manuell validiert werden. Fehlt nur die ab 2.4.0 eingeführte SQL-Abschlussmarkierung, wird ein vollständig lesbares Legacy-Backup als Warnung statt automatisch als beschädigt bewertet.

Das externe JSON wird gemeinsam mit der Backup-Datei zu SFTP, WebDAV, Dropbox und S3 hochgeladen. Die Gesundheitsprüfung kontrolliert neben der Remote-Datei auch dieses Manifest. Die vollständige Remote-Datei wird dabei nicht erneut heruntergeladen; geprüft werden Vorhandensein, Dateigröße und die Übereinstimmung der Manifest-Metadaten.

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

Im normalen Ablauf wird eine lokale Datei nur gelöscht, wenn:

1. alle aktivierten Remote-Ziele erfolgreich abgeschlossen wurden und
2. die gemeinsame Option „Backup nach erfolgreichen Remote-Uploads lokal behalten“ deaktiviert ist.

Bei einem automatischen Remote-Fehler bleibt das bereits verifizierte lokale Backup zur Sicherheit erhalten. Bricht der Benutzer den laufenden Remote-Upload bewusst ab, wird das für diesen Auftrag erzeugte lokale Backup samt JSON-Manifest entfernt, sofern „lokal behalten“ deaktiviert ist.

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

Die Kundenwebsite tauscht den Code selbst gegen Tokens und speichert diese verschlüsselt. Uploads erfolgen direkt von WordPress zu Dropbox. Zielordner werden innerhalb des App-Ordners automatisch angelegt. Große Dateien werden über Dropbox Upload Sessions in 32-MiB-Blöcken übertragen. Der bestätigte Offset wird nach jedem Block gespeichert. Nach einem Verbindungs- oder Worker-Abbruch kann der Upload ab diesem Stand fortgesetzt und bei abweichendem Dropbox-Offset automatisch synchronisiert werden.

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

## Lokaler Speicherort

Im Tab **Einstellungen** stehen drei Modi zur Verfügung:

```text
wp-content/.fg-private verwenden
Automatisch außerhalb des Webroots
Benutzerdefinierter Pfad
```

Der kompatible Standard bleibt:

```text
wp-content/.fg-private/fg-backup-pro/
├── backups/
└── temporary/
```

Der automatische Modus versucht einen beschreibbaren, websitespezifischen Ordner oberhalb des erkannten Webroots. Ist dies auf dem Hosting nicht möglich, verwendet FG Backup Pro weiterhin sicher den Standardpfad unter `wp-content/.fg-private` und zeigt den Fallback im Admin an.

Bei einem benutzerdefinierten Speicherort wird ein absoluter lokaler Basispfad angegeben, zum Beispiel:

```text
/var/www/vhosts/example.de/private
```

FG Backup Pro verwaltet darin ausschließlich:

```text
/var/www/vhosts/example.de/private/fg-backup-pro/
├── backups/
└── temporary/
```

Über **Speicherort prüfen** werden Verzeichnisanlage, Schreiben, vollständiges Zurücklesen und Löschen einer Testdatei geprüft. Zusätzlich zeigt das Plugin den tatsächlich aktiven Backup-Ordner, den freien Speicherplatz und an, ob er außerhalb des Webroots liegt.

Der aktive Backup-Ordner sowie `wp-content/.fg-private` werden immer vom vollständigen Dateibackup ausgeschlossen. Dadurch können lokale Sicherungen, temporäre Dateien und FG-GitHub-Update-Backups nicht rekursiv in ein neues Backup geraten. Der benutzerdefinierte absolute Pfad wird im Datenbankexport geleert.

FG Backup Pro ergänzt Schutzdateien und eine Verwaltungsmarkierung ausschließlich in seinem eigenen Unterordner. Bestehende Backups werden beim Wechsel des Speicherorts nicht automatisch verschoben.

## GitHub Update

Repository:

```text
https://github.com/FUNCKGROUP/fg-backup-pro-wp
```

Der Repository-Ordner muss `fg-backup-pro-wp` heißen und die Hauptdatei `fg-backup-pro.php` enthalten. `vendor/`, `composer.lock` und `includes/fg-core/` gehören bei diesem Projekt zum installierbaren Stand auf `main` und in den Tags.

## Changelog

Die Änderungen stehen in [`CHANGELOG.md`](CHANGELOG.md).
