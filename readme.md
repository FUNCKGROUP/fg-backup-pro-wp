# FG Backup Pro: WordPress Backup Plugin

Ein leistungsstarkes Backup-Plugin für WordPress mit asynchroner Erstellung, Upload zu verschiedenen Zielen, automatischer Planung und Rotation.

## ✅ Features

- Volles Backup (Dateien + Datenbank) oder nur DB
- Asynchrone Ausführung – kein Timeout bei Shared Hosting
- Multi-Ziel-Upload:
  - Dropbox
  - Google Drive
  - Amazon S3
  - S3-kompatible Anbieter (Wasabi, B2, MinIO)
  - SFTP / FTP
  - WebDAV (Nextcloud, ownCloud)
  - Microsoft OneDrive
- Automatische Rotation von Backups (3/5/10/20)
- WP-Cron-basierte Planung (täglich/wöchentlich/monatlich)
- Direkter Download & Löschfunktion für lokale Backups

## ⚙️ Installation

### Voraussetzungen
- WordPress 5.0+
- PHP 7.2+

### Option 1: Mit Composer (empfohlen)

Falls du Zugriff auf einen Server mit Composer hast:

Wechsel in dein Plugin-Verzeichnis:

- Wechsel in dein Plugin-Verzeichnis:

   cd wp-content/plugins/fg-backup

Führe aus:

   composer require aws/aws-sdk-php

Das installiert das AWS SDK und alle Abhängigkeiten.

---

### Option 2: Ohne Composer (Shared Hosting)

Wenn der Zielserver keinen Composer unterstützt:

1. Lade das [AWS SDK v3 für PHP](https://github.com/aws/aws-sdk-php/releases) herunter.
2. Entpacke es lokal.
3. Verschiebe den Inhalt des Ordners `aws-sdk-php/src/` nach `/wp-content/plugins/fg-backup/vendor/aws/`.
4. Erstelle eine Datei namens `autoload.php` im Verzeichnis `/vendor/`.
5. Füge die notwendigen Klassen manuell hinzu oder verwende Lightweight-Alternativen.

---

> 💡 Tipp: Für manuelle Installation ohne Composer solltest du entweder:
> - Eine minimierte Version des benötigten SDKs einbinden
> - ODER alternative Uploadmethoden nutzen, z. B. rein über HTTP oder ZipArchive

---

## 🔄 Cron-Jobs

Das Plugin nutzt WP-Cron für geplante Backups. Standardmäßig wird täglich um 02:00 Uhr ein Backup gestartet.

Beim ersten Aufruf des Plugins wird der erste Cron-Job angelegt.

---

## 📂 Backup-Verzeichnis

Alle Backups werden im Ordner `/wp-content/backups/` abgelegt.

> ⚠️ Sicherheitshinweis: Blockiere diesen Ordner via `.htaccess` oder Nginx-Konfiguration, damit er nicht öffentlich zugänglich ist.

---

## 📎 Supportierte Upload-Ziele

| Ziel | Erforderliche Angaben |
|------|------------------------|
| Dropbox | Access Token |
| Google Drive | Access Token |
| Amazon S3 | S3 Key, Secret, Bucket, Region |
| S3-kompatibel | Key, Secret, Bucket, Endpoint |
| SFTP | Host, Port, Benutzer, Passwort, Zielverzeichnis |
| WebDAV | URL, Benutzer, Passwort |
| OneDrive | Microsoft Access Token |

---

## 📦 Einstellungen

Du findest das Plugin im Hauptmenü unter:

🔧 Admin-Menü → Backups → Einstellungen

Dort kannst du:
- Backup-Typ auswählen
- Upload-Ziele konfigurieren
- E-Mail-Benachrichtigung aktivieren
- Die maximale Anzahl an Backups festlegen (Rotation)

---

## 🧰 Enthalten sind

/includes/
├── class-fgbackup-admin.php
├── class-fgbackup-async.php
├── class-fgbackup-backup.php
├── class-fgbackup-cleanup.php
├── class-fgbackup-cron.php
├── class-fgbackup-notifications.php
└── class-fgbackup-target.php

/targets/
├── class-fgbackup-dropbox.php
├── class-fgbackup-google-drive.php
├── class-fgbackup-s3.php
├── class-fgbackup-s3-compatible.php
├── class-fgbackup-sftp.php
├── class-fgbackup-webdav.php
└── class-fgbackup-onedrive.php

/templates/
├── admin-main.php
└── admin-settings.php

/assets/
├── style.css
└── script.js

---

## 📌 Templates

admin-main.php: Hauptseite zur Backup-Erstellung  
admin-settings.php: Unterseite für Ziele, Zeitplanung und Rotation

---

## 🎨 Assets

✅ CSS (`style.css`) für Menü und Statusbalken  
✅ JavaScript (`script.js`) für AJAX-Fortschrittsanzeige  
✅ SVG-Icon (`icon-backup.svg`) für eigenständiges Menüsymbol

