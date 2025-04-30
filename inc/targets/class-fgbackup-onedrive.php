<?php

class FgBackup_OneDrive implements FgBackup_Target_Interface {

    private $access_token;

    public function set_credentials(array $credentials) {
        $this->access_token = $credentials['access_token'];
    }

    public function upload($file_path) {
        $filename = basename($file_path);
        $upload_url = 'https://graph.microsoft.com/v1.0/me/drive/root:/'.rawurlencode($filename).':/content';

        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->access_token,
                'Content-Type' => 'application/octet-stream',
                'Content-Length' => filesize($file_path)
            ],
            'body' => file_get_contents($file_path)
        ];

        $response = wp_remote_put($upload_url, $args);

        if (is_wp_error($response)) {
            throw new Exception("OneDrive Upload fehlgeschlagen: " . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200 && $code !== 201) {
            $body = wp_remote_retrieve_body($response);
            throw new Exception("OneDrive Fehler ({$code}): " . $body);
        }
    }
}