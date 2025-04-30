<?php

require_once FG_BACKUP_DIR . 'vendor/autoload.php'; // AWS SDK

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class FgBackup_S3_Compatible implements FgBackup_Target_Interface {

    private $client;
    private $bucket;
    private $prefix;

    public function set_credentials(array $credentials) {
        $this->client = new S3Client([
            'version' => 'latest',
            'endpoint' => $credentials['endpoint'],
            'region' => $credentials['region'] ?? 'us-east-1',
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => $credentials['key'],
                'secret' => $credentials['secret']
            ]
        ]);

        $this->bucket = $credentials['bucket'];
        $this->prefix = isset($credentials['prefix']) ? ltrim($credentials['prefix'], '/') . '/' : '';
    }

    public function upload($file_path) {
        try {
            $result = $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $this->prefix . basename($file_path),
                'SourceFile' => $file_path
            ]);
            return $result;
        } catch (AwsException $e) {
            error_log("S3-kompatibel Upload fehlgeschlagen: " . $e->getMessage());
            throw $e;
        }
    }
}