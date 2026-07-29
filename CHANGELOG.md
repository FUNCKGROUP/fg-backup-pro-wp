# Changelog

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

Remote-Speicherziele folgen in Version 2.0.
