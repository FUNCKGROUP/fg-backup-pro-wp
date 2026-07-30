# Changelog

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
