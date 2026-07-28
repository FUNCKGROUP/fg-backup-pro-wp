<?php

defined('ABSPATH') || exit;

class FgBackup_Storage {

    public static function get_private_root() {
        return trailingslashit(WP_CONTENT_DIR) . '.fg-private/';
    }

    public static function get_plugin_root() {
        return self::get_private_root() . 'fg-backup-pro/';
    }

    public static function get_backup_dir() {
        self::ensure();
        return self::get_plugin_root() . 'backups/';
    }

    public static function get_temp_root() {
        self::ensure();
        return self::get_plugin_root() . 'temporary/';
    }

    public static function create_job_temp_dir($job_id) {
        $safe_job_id = sanitize_key($job_id);
        if ($safe_job_id === '') {
            throw new RuntimeException(__('Ungültige Job-ID.', 'fg-backup-pro'));
        }

        $dir = self::get_temp_root() . $safe_job_id . '/';
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            throw new RuntimeException(__('Das temporäre Backup-Verzeichnis konnte nicht erstellt werden.', 'fg-backup-pro'));
        }

        return $dir;
    }

    public static function ensure() {
        $directories = [
            self::get_private_root(),
            self::get_plugin_root(),
            self::get_plugin_root() . 'backups/',
            self::get_plugin_root() . 'temporary/',
        ];

        foreach ($directories as $directory) {
            if (!is_dir($directory) && !wp_mkdir_p($directory)) {
                throw new RuntimeException(sprintf(
                    __('Verzeichnis konnte nicht erstellt werden: %s', 'fg-backup-pro'),
                    $directory
                ));
            }

            if (!is_writable($directory)) {
                throw new RuntimeException(sprintf(
                    __('Verzeichnis ist nicht beschreibbar: %s', 'fg-backup-pro'),
                    $directory
                ));
            }
        }

        self::write_protection_files(self::get_private_root());
        self::write_protection_files(self::get_plugin_root());
        self::write_protection_files(self::get_plugin_root() . 'backups/');
        self::write_protection_files(self::get_plugin_root() . 'temporary/');
    }

    private static function write_protection_files($directory) {
        $files = [
            'index.php' => "<?php\n// Silence is golden.\n",
            '.htaccess' => "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n",
            'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <security>\n      <authorization>\n        <remove users=\"*\" roles=\"\" verbs=\"\" />\n        <add accessType=\"Deny\" users=\"*\" />\n      </authorization>\n    </security>\n  </system.webServer>\n</configuration>\n",
        ];

        foreach ($files as $name => $content) {
            $path = trailingslashit($directory) . $name;
            if (!file_exists($path)) {
                @file_put_contents($path, $content, LOCK_EX);
            }
        }
    }

    public static function migrate_legacy_backups() {
        self::ensure();

        $legacy_directories = [
            trailingslashit(WP_CONTENT_DIR) . 'fg-backup-pro/',
            trailingslashit(WP_CONTENT_DIR) . 'backups/',
        ];
        $target = self::get_backup_dir();

        foreach ($legacy_directories as $legacy) {
            if (!is_dir($legacy) || self::same_path($legacy, $target)) {
                continue;
            }

            $items = scandir($legacy);
            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                if (!preg_match('/\.(zip|sql)$/i', $item)) {
                    continue;
                }

                $source = trailingslashit($legacy) . $item;
                if (!is_file($source)) {
                    continue;
                }

                $destination = $target . sanitize_file_name($item);
                if (file_exists($destination)) {
                    $destination = $target . wp_unique_filename($target, sanitize_file_name($item));
                }

                if (!@rename($source, $destination)) {
                    if (@copy($source, $destination)) {
                        @unlink($source);
                    }
                }
            }
        }
    }

    private static function same_path($left, $right) {
        $left_real = realpath($left);
        $right_real = realpath($right);

        return $left_real !== false && $right_real !== false && $left_real === $right_real;
    }

    public static function remove_tree($path) {
        if (!file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        $items = scandir($path);
        if (is_array($items)) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                self::remove_tree(trailingslashit($path) . $item);
            }
        }

        @rmdir($path);
    }

    public static function is_path_inside($path, $directory) {
        $path_real = realpath($path);
        $directory_real = realpath($directory);

        if ($path_real === false || $directory_real === false) {
            return false;
        }

        $directory_real = trailingslashit(wp_normalize_path($directory_real));
        $path_real = wp_normalize_path($path_real);

        return strpos($path_real, $directory_real) === 0;
    }
}
