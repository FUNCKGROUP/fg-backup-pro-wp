<?php

class FgBackup_GoogleDrive implements FgBackup_Target_Interface {

    private $access_token;

    public function set_credentials(array $credentials) {
        $this->access_token = $credentials['access_token'];
    }

    public function upload($file_path) {
        if (empty($this->access_token)) {
            throw new Exception('Google Drive Access Token nicht gesetzt.');
        }

        $upload_url = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=media';
        $file_name = basename($file_path);

        $boundary = wp_generate_password(24);
        $delimiter = '-------------' . $boundary;

        $data = "--$delimiter\r\n"
              . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
              . json_encode(['name' => $file_name]) . "\r\n"
              . "--$delimiter\r\n"
              . "Content-Transfer-Encoding: binary\r\n"
              . "Content-Type: application/octet-stream\r\n\r\n"
              . file_get_contents($file_path) . "\r\n"
              . "--$delimiter--\r\n";

        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->access_token,
                'Content-Type' => 'multipart/form-data; boundary=' . $delimiter,
                'Content-Length' => strlen($data)
            ],
            'body' => $data,
            'method' => 'POST'
        ];

        $response = wp_remote_post('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart', $args);

        if (is_wp_error($response)) {
            throw new Exception('Fehler beim Upload zu Google Drive: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code != 200 && $code != 201) {
            $body = wp_remote_retrieve_body($response);
            throw new Exception("Google Drive Fehler ({$code}): " . $body);
        }
    }
}