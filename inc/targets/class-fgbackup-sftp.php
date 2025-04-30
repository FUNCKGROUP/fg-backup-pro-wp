<?php

class FgBackup_SFTP {

    public static function upload($file_path, $host, $user, $pass, $port = 22, $remote_dir = '/') {
        $connection = @ssh2_connect($host, $port);
        if (!$connection) {
            throw new Exception("Verbindung zum SFTP-Server {$host} fehlgeschlagen.");
        }

        if (!@ssh2_auth_password($connection, $user, $pass)) {
            throw new Exception("Login auf SFTP-Server mit Benutzer '{$user}' fehlgeschlagen.");
        }

        $sftp = @ssh2_sftp($connection);
        if (!$sftp) {
            throw new Exception("SFTP-Kanal konnte nicht geöffnet werden.");
        }

        $remote_path = $remote_dir . basename($file_path);
        $stream = @fopen("ssh2.sftp://$sftp$remote_path", 'w');

        if (!$stream) {
            throw new Exception("Kann in Remotedatei '{$remote_path}' nicht schreiben.");
        }

        $content = file_get_contents($file_path);
        fwrite($stream, $content);
        fclose($stream);
    }
}