# Changelog

## 2.4.2 – 01.08.2026

- große Dropbox-Dateien werden weiterhin über Upload Sessions übertragen, der bestätigte Offset wird jetzt nach jedem erfolgreichen Datenblock dauerhaft gespeichert
- Dropbox-Uploads synchronisieren sich nach einer unterbrochenen oder uneindeutig beantworteten Anfrage über den von Dropbox gemeldeten `correct_offset`
- begrenzte Wiederholungen für Netzwerkfehler, HTTP 429 und vorübergehende Dropbox-Fehler; keine Endlosschleifen
- Datenblöcke für Dropbox auf 32 MiB vergrößert, um die Zahl der API-Aufrufe bei sehr großen Sicherungen deutlich zu reduzieren
- Dropbox-Finalisierung ist idempotent: Eine bereits vollständig angelegte Remote-Datei wird nach Worker-Abbruch über Pfad und Größe bestätigt statt erneut finalisiert
- PHP-CLI-Worker übergeben lange Aufträge ungefähr alle fünf Minuten geordnet an einen neuen Worker-Prozess und umgehen damit Hosting-Limits von etwa 30 Minuten pro Prozess
- ein unerwartet beendeter Worker kann einen laufenden Dropbox-Upload bis zu drei Mal ab dem gespeicherten Offset wieder aufnehmen
- Worker-Heartbeat und Remote-Offset werden während des Uploads nach jedem Block aktualisiert
- bei bewusstem Abbruch wird das für den aktuellen Lauf erzeugte lokale Backup samt JSON-Manifest entfernt, wenn „lokal behalten“ deaktiviert ist
- bei einem automatischen Remote-Fehler bleibt das verifizierte lokale Backup weiterhin zur Sicherheit erhalten
- Fehlermeldungen unterscheiden jetzt klar zwischen bereinigten temporären Daten und einem absichtlich erhaltenen lokalen Backup

## 2.4.1 – 01.08.2026

- ZIP-Vollbackups werden nicht mehr in 150-Datei-AJAX-Batches wiederholt geöffnet und geschlossen
- separater PHP-CLI-Worker für lange Backup-Schritte mit automatischer Erkennung von PHP-CLI, `proc_open`, `exec` und `shell_exec`
- Browser und AJAX starten den Auftrag nur noch und lesen anschließend den dauerhaft gespeicherten Status
- ZIP wird einmal geöffnet, vollständig aus `files.jsonl` befüllt und einmal geschlossen
- tatsächlicher Archivfortschritt nach Dateien und Bytes; zusätzlicher libzip-Schließfortschritt über `registerProgressCallback`, sofern verfügbar
- kontrollierter ZIP-Abbruch über `cancel.flag` und `registerCancelCallback`, sofern verfügbar
- Heartbeat, Ausführungsmodus, Worker-PID, letzter Aktivitätszeitpunkt und aktueller Versuch werden im Jobstatus gespeichert
- abgestürzte HTTP- oder CLI-Prozesse werden erkannt und erhalten einen konkreten Fehlerstatus
- derselbe abgebrochene Archivierungsschritt wird nicht automatisch erneut ausgeführt
- begrenzter HTTP-/WP-Cron-Fallback für kleine Aufträge; große ZIP-Aufträge ohne startfähigen CLI-Worker werden mit verständlicher Hosting-Meldung beendet
- verwaiste `backup.zip.part.*`-Dateien, alte temporäre Jobordner und alte Joblogs werden automatisch bereinigt
- Abbruch wird auch bei bereits fehlendem temporärem Jobordner vollständig abgeschlossen
- manuelle Aufräumaktion für fehlgeschlagene und abgebrochene Jobs
- kleines dauerhaftes `job.log` pro Auftrag mit Größenbegrenzung
- Fortschrittsanzeige trennt Datenbankexport, Dateiscan, ZIP-Hinzufügen, ZIP-Abschluss, Validierung und Remote-Upload
- zusätzliche Ausschlüsse werden relativ zu `ABSPATH` normalisiert, doppelte Slashes entfernt, Backslashes vereinheitlicht und Pfade mit `..` verworfen
- bereits komprimierte Dateiformate werden im ZIP ohne erneuten Kompressionsversuch gespeichert, sofern libzip dies unterstützt
- keine künstliche ZIP-Größenbegrenzung im CLI-Worker

## 2.4.0 – 31.07.2026

- versioniertes JSON-Manifest als gleichnamige Sidecar-Datei neben jeder Sicherung
- Manifest wird gemeinsam mit dem Backup auf SFTP, WebDAV, Dropbox und S3 übertragen
- eingebettetes `fg-backup.json` in vollständigen ZIP- und TGZ-Backups
- automatische Tiefenvalidierung neuer Backups vor der Remote-Verteilung
- vollständiges Lesen aller Archivdateien sowie Prüfung von ZIP-/TGZ-Struktur und sicheren relativen Pfaden
- Kontrolle auf enthaltene Datenbank und gültiges eingebettetes Manifest
- SHA-256-Prüfsumme und unveränderliche ursprüngliche Dateigröße zur Erkennung späterer Manipulationen
- beobachtete Größe und Prüfsumme werden bei jeder erneuten Validierung getrennt protokolliert
- eindeutige SQL-Abschlussmarkierung für neue Datenbankexporte
- Legacy-SQL-Backups ohne Abschlussmarkierung werden bei vollständiger Lesbarkeit als Warnung bewertet
- Tabellen- und Zeilenzählung sowie Prüfung des SQL-Kopfes und des vollständigen Datenstroms
- Validierungsstatus „Gültig“, „Warnung“, „Ungültig“ oder „Nicht geprüft“ in der lokalen Backup-Liste
- manuelle Einzel- und Mehrfachvalidierung bestehender Backups
- detaillierter Prüfbericht als Modal und geschützter Download der JSON-Metadaten
- Gesundheitsprüfung verwendet ausschließlich gültig validierte Sicherungen
- Remote-Gesundheitsprüfung kontrolliert zusätzlich das vorhandene und gültige JSON-Manifest
- lokales und entferntes Löschen entfernt jeweils auch das zugehörige Manifest
- lokale und Remote-Rotation schützen das letzte gültige vollständige Backup
- manuell validierte Legacy-Backups ohne bisherigen Lauf werden für die Gesundheitsprüfung nachgetragen
- JSON-Sidecars werden von SFTP, WebDAV und Dropbox als gültige Begleitdateien akzeptiert und gemeinsam mit dem Backup verwaltet
- S3-Gesundheitsprüfung normalisiert gespeicherte `s3://`-Anzeigepfade korrekt auf den tatsächlichen Objektschlüssel
- JSON-Manifeste erhalten bei S3 den Content-Type `application/json; charset=utf-8`
- E-Mail-Benachrichtigungen formatieren Remote-Ziele übersichtlich jeweils in einer eigenen Zeile
- lokaler Speicherort wahlweise unter `wp-content/.fg-private`, automatisch außerhalb des Webroots oder als benutzerdefinierter absoluter Pfad
- automatischer websitespezifischer Speicherpfad oberhalb des erkannten Webroots mit sicherem Fallback auf `wp-content/.fg-private`
- Prüfung des lokalen Speicherorts durch Verzeichnisanlage sowie Schreiben, Zurücklesen und Löschen einer Testdatei
- Anzeige des tatsächlich aktiven Backup-Ordners, freien Speicherplatzes, Schreibstatus und Lage außerhalb des Webroots
- eigener verwalteter Unterordner `fg-backup-pro` mit Schutzdateien und Storage-Markierung
- aktiver, benutzerdefinierter und standardmäßiger `.fg-private`-Pfad werden zuverlässig vom vollständigen Backup ausgeschlossen
- benutzerdefinierter absoluter Speicherpfad wird aus Datenbankexporten entfernt
- vorhandene Backups werden bei einem Wechsel des Speicherorts bewusst nicht automatisch verschoben

## 2.3.0 – 30.07.2026

- neues Remote-Ziel für Amazon S3 und S3-kompatiblen Object Storage
- direkte AWS-Signature-Version-4-Signierung ohne zusätzliches AWS SDK
- weiterhin kompatibel mit PHP 7.4
- Anbieterzuordnung für Amazon S3, Hetzner Object Storage, Cloudflare R2, Backblaze B2, Wasabi, MinIO und benutzerdefinierte S3-Endpunkte
- Unterstützung für Path-Style und Virtual-Host-Style
- verschlüsselte Speicherung von Access Key, Secret Key und optionalem Session Token
- alternative Bereitstellung der S3-Zugangsdaten über Konstanten
- Verbindungstest mit Schreiben, Größenprüfung, Rücklesen, Bucket-Liste und Löschen einer temporären Datei
- atomare Einzeluploads für kleine Backups
- blockweise Multipart-Uploads für große Backups mit mindestens 5 MiB großen Teilen
- kontrollierter Abbruch und Bereinigung unvollständiger Multipart-Uploads
- Prüfung der fertigen Remote-Dateigröße
- automatische S3-Rotation, Remote-Dateiliste und geschütztes Löschen
- HTTPS- und SSRF-Schutz mit bewusster Freigabe privater beziehungsweise unverschlüsselter interner Endpoints
- S3 in Backup-Gesundheitsprüfung, Laufhistorie, Benachrichtigungen und generischer Remote-Pipeline integriert
- S3-Zugangsdaten werden aus SQL-Backups entfernt
- Aktivierung aller Remote-Ziele und gemeinsame lokale Aufbewahrung zentral in den Einstellungen zusammengeführt
- erfolgreich getestet mit Amazon S3 und Backblaze B2 einschließlich Upload, Dateiliste und Löschen

## 2.2.0 – 30.07.2026

- zentrale Backup-Gesundheitsanzeige auf der Backup-Seite
- Prüfung des letzten verwendbaren und des letzten fehlgeschlagenen Backup-Laufs
- Kontrolle der neuesten lokalen Backup-Datei auf Vorhandensein, Lesbarkeit und Größe
- Überwachung des bestehenden Zeitplans und des nächsten WordPress-Cron-Laufs
- Warnung bei überfälligen Sicherungen und fehlgeschlagenen automatischen Läufen
- Erkennung möglicherweise festhängender Backup-Prozesse
- Live-Prüfung aller aktivierten Remote-Ziele über die vorhandenen Dateilisten
- Kontrolle, ob das zuletzt hochgeladene Remote-Backup vorhanden ist und dieselbe Dateigröße besitzt
- täglicher automatischer Gesundheitscheck
- Warnungen im WordPress-Admin und in der Adminleiste
- E-Mail-Modi „deaktiviert“, „nur Fehler und Warnungen“ sowie „jeder abgeschlossene Lauf“
- frei wählbarer E-Mail-Empfänger
- Schutz vor wiederholten identischen Gesundheitswarnungen innerhalb von 24 Stunden
- bestehende Benachrichtigungseinstellung wird beim Update automatisch übernommen
- erfolgreiche Backups bleiben auch bei Fehlern in Gesundheitsprüfung oder E-Mail-Nachbearbeitung abgeschlossen
- Gesundheitsstatus verwendet die neueste tatsächlich vorhandene lokale Sicherung
- manuelle Gesundheitsprüfung aktualisiert die Anzeige direkt und dauerhaft
- doppelte Gesundheitswarnung auf der FG-Backup-Pro-Seite entfernt
- Checkboxen, Gesamtauswahl und gemeinsames Löschen mehrerer lokaler Backups
- sichere unveränderte Verarbeitung von Dateinamen mit Mehrfachendungen wie `.db.sql.zip` beim Download und Löschen

## 2.1.0 – 30.07.2026

- generische Remote-Pipeline für mehrere gleichzeitig aktivierte Speicherziele
- getrennte Erfolgs- und Fehlerergebnisse pro Remote-Ziel
- lokale Datei wird nur nach vollständig erfolgreichen Uploads zu allen aktivierten Zielen gelöscht
- WebDAV mit HTTPS, Verbindungstest, automatischer Verzeichnisanlage und SSRF-Schutz
- kanonische WebDAV-Verzeichnis-URLs mit abschließendem Slash für Apache- und Storage-Box-Server
- direkte Basic-Authentifizierung bei gestreamten WebDAV-Uploads ohne fehleranfälliges Zurückspulen
- WebDAV-Upload über temporäre `.part`-Datei, Größenprüfung und finales `MOVE`
- WebDAV-Rotation, Remote-Dateiliste und geschütztes Löschen
- bewusste Freigabe privater IP-Adressen für internes NAS oder Nextcloud
- Dropbox OAuth 2.0 mit PKCE und automatisch erneuerten Refresh-Tokens
- zentrale Relay-Verbindung ohne Übertragung von PKCE-Verifier, Tokens oder Backup-Dateien
- alternative manuelle Dropbox-Verbindung ohne FUNCKGROUP-Relay
- Dropbox App-Folder-Unterstützung, automatische Zielordneranlage, Upload Sessions und blockweiser Upload
- Dropbox-Rotation, Remote-Dateiliste, Löschen und Verbindungstest
- schließbare WordPress-Admin-Notice nach dem Speichern aller Einstellungsbereiche
- Status „Abgeschlossen mit Fehlern“ bei erfolgreichem lokalen Backup und einzelnen Remote-Fehlern
- generische Remote-Ergebnisse in Laufhistorie, Benachrichtigungen und Adminleiste
- separates Plugin `FG Dropbox Relay` für den zentralen Callback-Server
- Remote-Passwörter und Dropbox-Tokens werden in SQL-Backups aus Sicherheitsgründen geleert

## 2.0.0 – 29.07.2026

- SFTP über phpseclib 3 mit PHP-7.4-Kompatibilität
- Passwort- und SSH-Key-Anmeldung mit optionaler Passphrase
- verschlüsselte Speicherung sensibler SFTP-Zugangsdaten
- alternative Bereitstellung sensibler Werte über Konstanten in `wp-config.php`
- SSH-Host-Key-Pinning nach erfolgreichem Schreib-, Lese- und Löschtest
- bytegenauer Rücklesetest der SFTP-Testdatei für Server mit unzuverlässigen Stat-Angaben
- blockweiser Upload mit Fortschritt und kontrolliertem Abbruch
- temporäre Remote-Dateien mit `.part` und automatische Bereinigung
- robuste Remote-Größenprüfung über `filesize()`, `stat()` und `rawlist()`
- Größenprüfung vor und nach dem finalen Umbenennen
- automatische Remote-Rotation
- Remote-Dateiliste und geschütztes Löschen im WordPress-Admin
- lokale Datei nach erfolgreichem Upload optional löschen
- lokale Sicherung bleibt bei SFTP-Abbruch oder Fehler erhalten
- deutlich sichtbare Speicherbedarfsschätzung vor dem Backup-Start
- Composer-Abhängigkeit `phpseclib/phpseclib` im Plugin-Root

## 1.1.1 – 29.07.2026

- konservative Speicherplatzprüfung vor dem Datenbankexport
- zweite Speicherplatzprüfung vor der Archivierung vollständiger Backups
- Berücksichtigung des erhöhten temporären Speicherbedarfs von TGZ
- optionale Backup-Notiz bis 160 Zeichen
- Anzeige der Notiz bei lokalen Backups und in der Laufhistorie
- Speicherung der Notiz in der Metadatei vollständiger Backups

## 1.1.0 – 29.07.2026

- Integration in FG Core 1.1.3
- Unterstützung für FG GitHub Update
- sichere lokale Ablage unter `wp-content/.fg-private/fg-backup-pro/`
- vollständige Backups als ZIP oder TGZ
- Datenbank-Backups als SQL, SQL.GZ oder SQL.ZIP
- asynchrone Verarbeitung mit sichtbarem Status in Seite und Adminleiste
- kontrolliertes Abbrechen laufender Backups
- frei definierbare Dateinamen mit Live-Vorschau und Platzhaltern
- strukturelle Prüfung fertiger Backups
- geschützte Downloads und lokale Rotation
- tägliche, wöchentliche oder monatliche Zeitplanung
- Erkennung klassischer WordPress- und Bedrock-Strukturen
- deutlich beschleunigter Export kleiner und leerer Datenbanktabellen
- Composer-Konfiguration im Plugin-Root ohne Validate-Skript
