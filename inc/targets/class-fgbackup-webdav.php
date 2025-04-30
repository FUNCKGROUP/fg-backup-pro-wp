<?php

class FgBackup_WebDAV implements FgBackup_Target_Interface {

    private $base_url;
    private $user;
    private $pass;

    public function set_credentials(array $credentials) {
        $this->base_url = trailingslashit(rtrim($credentials['webdav_url'], '/'));
        $this->user = $credentials['user'];
        $this->pass = $credentials['password'];
    }

    public function upload($file_path) {
        $remote_url = $this->base_url . rawurlencode(basename($file_path));

        $args = [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->user . ':' . $this->pass),
                'Content-Type' => 'application/octet-stream'
            ],
            'body' => file_get_contents($file_path),
            'method' => 'PUT'
        ];

        $response = wp_remote_request($remote_url, $args);

        if (is_wp_error($response)) {
            throw new Exception("WebDAV Upload fehlgeschlagen: " . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200 && $code !== 201 && $code !== 204) {
            $body = wp_remote_retrieve_body($response);
            throw new Exception("WebDAV Fehler ({$code}): " . $body);
        }
    }
}