<?php

require_once FG_BACKUP_DIR . 'vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class FgBackup_S3 implements FgBackup_Target_Interface {

    private $client;
    private $bucket;
    private $prefix;

    public function set_credentials(array $credentials) {
        $this->client = new S3Client([
            'version' => 'latest',
            'region' => $credentials['region'],
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
            error_log('AWS S3 Upload fehlgeschlagen: ' . $e->getMessage());
            throw $e;
        }
    }
}