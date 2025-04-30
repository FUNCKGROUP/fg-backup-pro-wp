<?php

class FgBackup_Dropbox implements FgBackup_Target_Interface {

    private $access_token;

    public function set_credentials(array $credentials) {
        $this->access_token = $credentials['access_token'];
    }

    public function upload($file_path) {
        if (empty($this->access_token)) {
            throw new Exception('Dropbox Access Token nicht gesetzt.');
        }

        $dropbox_file_name = '/' . basename($file_path);
        $url = 'https://content.dropboxapi.com/2/files/upload';

        $headers = [
            'Authorization' => 'Bearer ' . $this->access_token,
            'Content-Type' => 'application/octet-stream',
            'Dropbox-API-Arg' => json_encode([
                "path" => $dropbox_file_name,
                "mode" => "add",
                "autorename" => true,
                "mute" => false
            ])
        ];

        $args = [
            'headers' => $headers,
            'body' => file_get_contents($file_path),
            'timeout' => 60
        ];

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            throw new Exception('Fehler beim Upload zu Dropbox: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $body = wp_remote_retrieve_body($response);
            throw new Exception("Dropbox Fehler ({$code}): " . $body);
        }
    }
}