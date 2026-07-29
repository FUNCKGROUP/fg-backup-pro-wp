<?php

defined('ABSPATH') || exit;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;

class FgBackup_Sftp {
    const CHUNK_SIZE = 4194304;

    public static function available() {
        return class_exists('phpseclib3\\Net\\SFTP') && class_exists('phpseclib3\\Crypt\\PublicKeyLoader');
    }

    public static function enabled() {
        return (bool) get_option('fg_backup_sftp_enabled', 0);
    }

    public static function settings() {
        return [
            'host' => trim((string) get_option('fg_backup_sftp_host', '')),
            'port' => max(1, min(65535, (int) get_option('fg_backup_sftp_port', 22))),
            'username' => trim((string) get_option('fg_backup_sftp_username', '')),
            'auth' => get_option('fg_backup_sftp_auth', 'password') === 'key' ? 'key' : 'password',
            'remote_dir' => self::sanitize_remote_dir(get_option('fg_backup_sftp_remote_dir', '/backups/%host')),
            'retention' => max(1, min(100, (int) get_option('fg_backup_sftp_retention', 10))),
            'keep_local' => (bool) get_option('fg_backup_sftp_keep_local', 1),
            'host_key' => (string) get_option('fg_backup_sftp_host_key', ''),
            'host_key_target' => (string) get_option('fg_backup_sftp_host_key_target', ''),
        ];
    }

    public static function password() {
        return defined('FG_BACKUP_SFTP_PASSWORD')
            ? (string) FG_BACKUP_SFTP_PASSWORD
            : FgBackup_Secrets::decrypt((string) get_option('fg_backup_sftp_password', ''));
    }

    public static function private_key_path() {
        return defined('FG_BACKUP_SFTP_PRIVATE_KEY_PATH')
            ? (string) FG_BACKUP_SFTP_PRIVATE_KEY_PATH
            : trim((string) get_option('fg_backup_sftp_private_key_path', ''));
    }

    public static function key_passphrase() {
        return defined('FG_BACKUP_SFTP_KEY_PASSPHRASE')
            ? (string) FG_BACKUP_SFTP_KEY_PASSPHRASE
            : FgBackup_Secrets::decrypt((string) get_option('fg_backup_sftp_key_passphrase', ''));
    }

    public static function target_id(array $settings = null) {
        $settings = $settings ?: self::settings();
        return strtolower($settings['host']) . ':' . (int) $settings['port'];
    }

    public static function sanitize_remote_dir($value) {
        $value = trim(str_replace('\\', '/', (string) $value));
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', '', $value);
        $value = preg_replace('#/+#', '/', (string) $value);
        $value = preg_replace('#(?:^|/)\.{1,2}(?=/|$)#', '', (string) $value);
        $value = preg_replace('#/+#', '/', (string) $value);
        $value = preg_replace('/[^A-Za-z0-9%._\/-]+/u', '-', (string) $value);
        $value = '/' . ltrim((string) $value, '/');
        return rtrim($value, '/') ?: '/';
    }

    public static function resolved_remote_dir(array $settings = null) {
        $settings = $settings ?: self::settings();
        $host = sanitize_title(preg_replace('/^www\./i', '', (string) wp_parse_url(home_url('/'), PHP_URL_HOST)));
        $site = sanitize_title((string) get_bloginfo('name'));

        return self::sanitize_remote_dir(strtr($settings['remote_dir'], [
            '%host' => $host !== '' ? $host : 'wordpress',
            '%site' => $site !== '' ? $site : 'wordpress',
        ]));
    }

    public static function fingerprint($host_key) {
        return 'SHA256:' . rtrim(base64_encode(hash('sha256', (string) $host_key, true)), '=');
    }

    /**
     * Verbindet sich, prüft Schreib-/Löschrechte und speichert den Host-Key erst
     * nach einer vollständig erfolgreichen Anmeldung und Testdatei.
     */
    public static function test_and_pin() {
        $settings = self::settings();
        self::assert_configuration($settings, false);
        $sftp = self::connect($settings, true);
        $directory = self::resolved_remote_dir($settings);
        self::ensure_directory($sftp, $directory);

        if (!$sftp->is_dir($directory)) {
            throw new RuntimeException(__('Das SFTP-Zielverzeichnis konnte nicht geöffnet werden.', 'fg-backup-pro'));
        }

        $test_name = '.fg-backup-test-' . strtolower(wp_generate_password(12, false, false)) . '.tmp';
        $test_path = rtrim($directory, '/') . '/' . $test_name;
        $payload = 'FG Backup Pro ' . gmdate('c');

        $test_error = null;
        $key = '';

        try {
            if (!$sftp->put($test_path, $payload, SFTP::SOURCE_STRING)) {
                throw new RuntimeException(__('Im SFTP-Zielverzeichnis konnte keine Testdatei geschrieben werden.', 'fg-backup-pro'));
            }

            // Manche SFTP-Server liefern direkt nach dem Schreiben keine verlässlichen
            // Stat-/Größenangaben. Beim kleinen Verbindungstest vergleichen wir deshalb
            // den tatsächlich wieder eingelesenen Inhalt bytegenau.
            $remote_payload = $sftp->get($test_path);
            if (!is_string($remote_payload) || !hash_equals($payload, $remote_payload)) {
                throw new RuntimeException(__('Die geschriebene SFTP-Testdatei konnte nicht korrekt zurückgelesen werden.', 'fg-backup-pro'));
            }

            $key = (string) $sftp->getServerPublicHostKey();
            if ($key === '') {
                throw new RuntimeException(__('Der öffentliche SFTP-Serverschlüssel konnte nicht gelesen werden.', 'fg-backup-pro'));
            }
        } catch (Throwable $exception) {
            $test_error = $exception;
        }

        // Die Testdatei immer aufräumen – auch wenn Lesen oder Host-Key-Prüfung fehlschlagen.
        $deleted = $sftp->delete($test_path);
        if (!$deleted && $test_error === null) {
            $test_error = new RuntimeException(__('Die SFTP-Testdatei konnte nicht wieder gelöscht werden.', 'fg-backup-pro'));
        }

        if ($test_error instanceof Throwable) {
            throw $test_error;
        }

        update_option('fg_backup_sftp_host_key', $key, false);
        update_option('fg_backup_sftp_host_key_target', self::target_id($settings), false);

        return [
            'directory' => $directory,
            'fingerprint' => self::fingerprint($key),
            'target' => self::target_id($settings),
        ];
    }

    public static function connect(array $settings = null, $allow_first_trust = false) {
        $settings = $settings ?: self::settings();
        self::assert_configuration($settings, true, $allow_first_trust);

        $sftp = new SFTP($settings['host'], (int) $settings['port'], 15);
        $server_key = $sftp->getServerPublicHostKey();
        if (!is_string($server_key) || $server_key === '') {
            throw new RuntimeException(__('Der öffentliche SFTP-Serverschlüssel konnte nicht gelesen werden.', 'fg-backup-pro'));
        }

        $target = self::target_id($settings);
        if ($settings['host_key'] !== '') {
            if ($settings['host_key_target'] !== '' && !hash_equals($settings['host_key_target'], $target)) {
                throw new RuntimeException(__('Host oder Port wurden geändert. Bitte den gespeicherten Serverschlüssel zurücksetzen.', 'fg-backup-pro'));
            }
            if (!hash_equals($settings['host_key'], $server_key)) {
                throw new RuntimeException(__('Der SFTP-Serverschlüssel hat sich geändert. Die Verbindung wurde abgebrochen.', 'fg-backup-pro'));
            }
        } elseif (!$allow_first_trust) {
            throw new RuntimeException(__('Bitte die SFTP-Verbindung zuerst testen.', 'fg-backup-pro'));
        }

        if (!$sftp->login($settings['username'], self::credential($settings))) {
            throw new RuntimeException(__('SFTP-Anmeldung fehlgeschlagen. Bitte Zugangsdaten prüfen.', 'fg-backup-pro'));
        }

        return $sftp;
    }

    private static function credential(array $settings) {
        if ($settings['auth'] === 'key') {
            $path = self::private_key_path();
            if ($path === '' || !is_file($path) || !is_readable($path)) {
                throw new RuntimeException(__('Der private SSH-Schlüssel ist nicht vorhanden oder für PHP nicht lesbar.', 'fg-backup-pro'));
            }

            $contents = file_get_contents($path);
            if (!is_string($contents) || $contents === '') {
                throw new RuntimeException(__('Der private SSH-Schlüssel konnte nicht gelesen werden.', 'fg-backup-pro'));
            }

            try {
                return PublicKeyLoader::load($contents, self::key_passphrase());
            } catch (Throwable $exception) {
                throw new RuntimeException(__('Der private SSH-Schlüssel oder dessen Passphrase ist ungültig.', 'fg-backup-pro'));
            }
        }

        $password = self::password();
        if ($password === '') {
            throw new RuntimeException(__('Für die SFTP-Anmeldung wurde kein Passwort gespeichert.', 'fg-backup-pro'));
        }

        return $password;
    }

    private static function assert_configuration(array $settings, $require_pin, $allow_first_trust = false) {
        if (!self::available()) {
            throw new RuntimeException(__('phpseclib ist nicht installiert. Bitte im Plugin-Root Composer ausführen.', 'fg-backup-pro'));
        }
        if ($settings['host'] === '' || $settings['username'] === '') {
            throw new RuntimeException(__('SFTP-Host und Benutzername müssen angegeben werden.', 'fg-backup-pro'));
        }
        if ($require_pin && !$allow_first_trust && $settings['host_key'] === '') {
            throw new RuntimeException(__('Bitte die SFTP-Verbindung zuerst testen.', 'fg-backup-pro'));
        }
    }

    public static function prepare_upload($local_path, $file_name) {
        if (!is_file($local_path) || !is_readable($local_path)) {
            throw new RuntimeException(__('Die lokale Backup-Datei ist nicht lesbar.', 'fg-backup-pro'));
        }

        $settings = self::settings();
        $sftp = self::connect($settings);
        $directory = self::resolved_remote_dir($settings);
        self::ensure_directory($sftp, $directory);
        $remote_path = self::unique_remote_path($sftp, $directory, basename($file_name));
        $temporary = $remote_path . '.part';

        if ($sftp->file_exists($temporary) && !$sftp->delete($temporary)) {
            throw new RuntimeException(__('Eine alte unvollständige SFTP-Datei konnte nicht entfernt werden.', 'fg-backup-pro'));
        }

        return [
            'remote_path' => $remote_path,
            'remote_temp' => $temporary,
            'remote_dir' => $directory,
            'remote_offset' => 0,
            'remote_total' => (int) filesize($local_path),
        ];
    }

    public static function upload_chunk($local_path, $remote_temp, $offset) {
        return self::upload_batch($local_path, $remote_temp, $offset, 1);
    }

    public static function upload_batch($local_path, $remote_temp, $offset, $max_chunks = 4, $cancel_callback = null) {
        $offset = max(0, (int) $offset);
        $max_chunks = max(1, min(16, (int) $max_chunks));
        $handle = @fopen($local_path, 'rb');
        if (!$handle || fseek($handle, $offset) !== 0) {
            if ($handle) {
                fclose($handle);
            }
            throw new RuntimeException(__('Die lokale Backup-Datei konnte für den SFTP-Upload nicht geöffnet werden.', 'fg-backup-pro'));
        }

        $sftp = self::connect();
        $written_total = 0;

        try {
            for ($chunk = 0; $chunk < $max_chunks; $chunk++) {
                if (is_callable($cancel_callback) && call_user_func($cancel_callback)) {
                    break;
                }

                $data = fread($handle, self::CHUNK_SIZE);
                if ($data === false) {
                    throw new RuntimeException(__('Die lokale Backup-Datei konnte nicht gelesen werden.', 'fg-backup-pro'));
                }
                if ($data === '') {
                    break;
                }

                $remote_offset = $offset + $written_total;
                if (!$sftp->put($remote_temp, $data, SFTP::SOURCE_STRING, $remote_offset)) {
                    throw new RuntimeException(__('Ein SFTP-Datenblock konnte nicht hochgeladen werden.', 'fg-backup-pro'));
                }

                $written_total += strlen($data);
                $expected = $offset + $written_total;
                $remote_size = self::remote_file_size($sftp, $remote_temp);
                if ($remote_size === false || (int) $remote_size < $expected) {
                    throw new RuntimeException(__('Der hochgeladene SFTP-Datenblock konnte nicht bestätigt werden.', 'fg-backup-pro'));
                }
            }
        } finally {
            fclose($handle);
        }

        return $written_total;
    }

    public static function finalize_upload($remote_temp, $remote_path, $expected_size) {
        $sftp = self::connect();
        $remote_size = self::remote_file_size($sftp, $remote_temp);
        if ($remote_size === false || (int) $remote_size !== (int) $expected_size) {
            throw new RuntimeException(__('Die Größe der SFTP-Datei stimmt nicht mit dem lokalen Backup überein.', 'fg-backup-pro'));
        }
        if ($sftp->file_exists($remote_path) && !$sftp->delete($remote_path)) {
            throw new RuntimeException(__('Eine vorhandene SFTP-Zieldatei konnte nicht ersetzt werden.', 'fg-backup-pro'));
        }
        if (!$sftp->rename($remote_temp, $remote_path)) {
            throw new RuntimeException(__('Die SFTP-Datei konnte nicht finalisiert werden.', 'fg-backup-pro'));
        }

        $final_size = self::remote_file_size($sftp, $remote_path);
        if ($final_size === false || (int) $final_size !== (int) $expected_size) {
            throw new RuntimeException(__('Die finalisierte SFTP-Datei konnte nicht bestätigt werden.', 'fg-backup-pro'));
        }

        self::rotate($sftp, dirname($remote_path), self::settings()['retention']);
        return true;
    }

    public static function remove_partial($remote_temp) {
        if (!$remote_temp) {
            return;
        }

        try {
            $sftp = self::connect();
            if ($sftp->file_exists($remote_temp)) {
                $sftp->delete($remote_temp);
            }
        } catch (Throwable $exception) {
            // Best effort bei Abbruch oder Fehler.
        }
    }

    public static function list_backups() {
        $settings = self::settings();
        $sftp = self::connect($settings);
        $directory = self::resolved_remote_dir($settings);

        if (!$sftp->is_dir($directory)) {
            return [];
        }

        $list = $sftp->rawlist($directory);
        if (!is_array($list)) {
            throw new RuntimeException(__('Die Remote-Dateiliste konnte nicht gelesen werden.', 'fg-backup-pro'));
        }

        $files = [];
        foreach ($list as $name => $info) {
            if (!self::is_backup_file_name($name)) {
                continue;
            }

            $mtime = is_array($info) && isset($info['mtime']) ? (int) $info['mtime'] : 0;
            $size = is_array($info) && isset($info['size']) ? (int) $info['size'] : 0;
            $files[] = [
                'name' => $name,
                'path' => rtrim($directory, '/') . '/' . $name,
                'size_bytes' => $size,
                'size' => size_format($size, 2),
                'mtime' => $mtime,
                'date' => $mtime > 0 ? wp_date('d.m.Y H:i', $mtime) : '–',
            ];
        }

        usort($files, static function ($left, $right) {
            return $right['mtime'] <=> $left['mtime'];
        });

        return array_slice($files, 0, 100);
    }

    public static function delete_backup($file_name) {
        $file_name = (string) $file_name;
        if ($file_name === '' || basename($file_name) !== $file_name || !self::is_backup_file_name($file_name)) {
            throw new RuntimeException(__('Ungültiger Remote-Dateiname.', 'fg-backup-pro'));
        }

        $settings = self::settings();
        $sftp = self::connect($settings);
        $directory = self::resolved_remote_dir($settings);
        $path = rtrim($directory, '/') . '/' . $file_name;

        if (!$sftp->file_exists($path)) {
            throw new RuntimeException(__('Das Remote-Backup wurde nicht gefunden.', 'fg-backup-pro'));
        }
        if (!$sftp->delete($path)) {
            throw new RuntimeException(__('Das Remote-Backup konnte nicht gelöscht werden.', 'fg-backup-pro'));
        }

        return true;
    }

    /**
     * Liest die Dateigröße über die phpseclib-3-API.
     * Der Stat-Cache wird vorher geleert, damit unmittelbar nach einem Upload
     * nicht versehentlich ein veralteter Wert geprüft wird.
     *
     * @return int|false
     */
    private static function remote_file_size(SFTP $sftp, $path) {
        $path = (string) $path;
        $sftp->clearStatCache();

        $size = $sftp->filesize($path);
        if ($size !== false) {
            return (int) $size;
        }

        $stat = $sftp->stat($path);
        if (is_array($stat) && array_key_exists('size', $stat)) {
            return (int) $stat['size'];
        }

        $directory = dirname($path);
        $name = basename($path);
        $list = $sftp->rawlist($directory);
        if (is_array($list) && isset($list[$name]) && is_array($list[$name]) && array_key_exists('size', $list[$name])) {
            return (int) $list[$name]['size'];
        }

        return false;
    }

    private static function ensure_directory(SFTP $sftp, $directory) {
        if ($directory === '/' || $sftp->is_dir($directory)) {
            return;
        }
        if (!$sftp->mkdir($directory, -1, true) && !$sftp->is_dir($directory)) {
            throw new RuntimeException(__('Das SFTP-Zielverzeichnis konnte nicht angelegt werden.', 'fg-backup-pro'));
        }
    }

    private static function unique_remote_path(SFTP $sftp, $directory, $file_name) {
        $file_name = basename(str_replace('\\', '/', (string) $file_name));
        if (!self::is_backup_file_name($file_name)) {
            throw new RuntimeException(__('Der Dateiname des Backups ist für SFTP ungültig.', 'fg-backup-pro'));
        }

        $candidate = rtrim($directory, '/') . '/' . $file_name;
        if (!$sftp->file_exists($candidate) && !$sftp->file_exists($candidate . '.part')) {
            return $candidate;
        }

        $extension = '';
        $base = $file_name;
        foreach (['.sql.zip', '.sql.gz', '.tar.gz', '.tgz', '.zip', '.sql'] as $known) {
            if (substr(strtolower($file_name), -strlen($known)) === $known) {
                $extension = substr($file_name, -strlen($known));
                $base = substr($file_name, 0, -strlen($known));
                break;
            }
        }

        for ($number = 2; $number < 1000; $number++) {
            $candidate = rtrim($directory, '/') . '/' . $base . '-' . $number . $extension;
            if (!$sftp->file_exists($candidate) && !$sftp->file_exists($candidate . '.part')) {
                return $candidate;
            }
        }

        throw new RuntimeException(__('Auf dem SFTP-Ziel konnte kein freier Dateiname ermittelt werden.', 'fg-backup-pro'));
    }

    private static function is_backup_file_name($name) {
        if (!is_string($name) || $name === '' || basename($name) !== $name || substr($name, -5) === '.part') {
            return false;
        }

        return (bool) preg_match('/(?:\.sql\.zip|\.sql\.gz|\.tar\.gz|\.tgz|\.zip|\.sql)$/i', $name);
    }

    private static function rotate(SFTP $sftp, $directory, $keep) {
        $list = $sftp->rawlist($directory);
        if (!is_array($list)) {
            return;
        }

        $files = [];
        foreach ($list as $name => $info) {
            if (!self::is_backup_file_name($name)) {
                continue;
            }
            $files[] = [
                'name' => $name,
                'mtime' => is_array($info) && isset($info['mtime']) ? (int) $info['mtime'] : 0,
            ];
        }

        usort($files, static function ($left, $right) {
            return $right['mtime'] <=> $left['mtime'];
        });

        foreach (array_slice($files, max(1, (int) $keep)) as $file) {
            $sftp->delete(rtrim($directory, '/') . '/' . $file['name']);
        }
    }
}
