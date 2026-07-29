<?php

defined('ABSPATH') || exit;

class FgBackup_Secrets {
    public static function encrypt($value) {
        $value = (string) $value;
        if ($value === '') {
            return '';
        }
        $key = self::key();
        if (function_exists('sodium_crypto_secretbox') && function_exists('random_bytes')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return 'sodium:' . base64_encode($nonce . sodium_crypto_secretbox($value, $nonce, $key));
        }
        if (function_exists('openssl_encrypt') && function_exists('random_bytes')) {
            $nonce = random_bytes(12);
            $tag = '';
            $cipher = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
            if (is_string($cipher) && $tag !== '') {
                return 'openssl:' . base64_encode($nonce . $tag . $cipher);
            }
        }
        throw new RuntimeException(__('Zugangsdaten können auf diesem Server nicht sicher verschlüsselt werden.', 'fg-backup-pro'));
    }

    public static function decrypt($value) {
        $value = (string) $value;
        if ($value === '') {
            return '';
        }
        if (strpos($value, 'sodium:') === 0) {
            $decoded = base64_decode(substr($value, 7), true);
            if (!is_string($decoded) || !function_exists('sodium_crypto_secretbox_open') || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
                return '';
            }
            $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plain = sodium_crypto_secretbox_open(substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::key());
            return is_string($plain) ? $plain : '';
        }
        if (strpos($value, 'openssl:') === 0) {
            $decoded = base64_decode(substr($value, 8), true);
            if (!is_string($decoded) || !function_exists('openssl_decrypt') || strlen($decoded) <= 28) {
                return '';
            }
            $plain = openssl_decrypt(substr($decoded, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($decoded, 0, 12), substr($decoded, 12, 16));
            return is_string($plain) ? $plain : '';
        }
        return '';
    }

    private static function key() {
        $material = '';
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY'] as $constant) {
            if (defined($constant)) {
                $material .= (string) constant($constant);
            }
        }
        if ($material === '') {
            $material = wp_salt('auth') . wp_salt('secure_auth');
        }
        return hash('sha256', 'fg-backup-pro|' . $material, true);
    }
}
