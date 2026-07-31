<?php

defined('ABSPATH') || exit;

class FgBackup_Storage {

    const MODE_CONTENT = 'content';
    const MODE_AUTO = 'auto';
    const MODE_CUSTOM = 'custom';

    private static $resolved = null;

    public static function get_default_private_root() {
        return trailingslashit(WP_CONTENT_DIR) . '.fg-private/';
    }

    public static function get_document_root() {
        $document_root = isset($_SERVER['DOCUMENT_ROOT']) ? (string) $_SERVER['DOCUMENT_ROOT'] : '';
        $document_root = trim(wp_normalize_path($document_root));

        if ($document_root !== '' && is_dir($document_root)) {
            $real = realpath($document_root);
            return trailingslashit(wp_normalize_path($real !== false ? $real : $document_root));
        }

        $absolute = realpath(ABSPATH);
        if ($absolute !== false) {
            return trailingslashit(wp_normalize_path($absolute));
        }

        return trailingslashit(wp_normalize_path(ABSPATH));
    }

    public static function get_auto_candidate_root() {
        $document_root = untrailingslashit(self::get_document_root());
        if ($document_root === '' || $document_root === DIRECTORY_SEPARATOR) {
            return '';
        }

        $parent = dirname($document_root);
        if ($parent === '' || $parent === '.' || $parent === DIRECTORY_SEPARATOR) {
            return '';
        }

        $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        $host = preg_replace('/^www\./i', '', $host);
        $site_slug = sanitize_title($host !== '' ? $host : 'wordpress-' . get_current_blog_id());
        if (is_multisite()) {
            $site_slug .= '-' . get_current_blog_id();
        }

        return trailingslashit(wp_normalize_path($parent)) . '.fg-private/' . $site_slug . '/';
    }

    public static function get_private_root() {
        $resolved = self::resolve();
        return $resolved['base_root'];
    }

    public static function get_plugin_root() {
        $resolved = self::resolve();
        return $resolved['plugin_root'];
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
        self::resolve();
    }

    public static function reset_cache() {
        self::$resolved = null;
    }

    public static function status() {
        $resolved = self::resolve();
        $backup_dir = $resolved['plugin_root'] . 'backups/';
        $free = @disk_free_space($resolved['plugin_root']);

        return [
            'configured_mode' => $resolved['configured_mode'],
            'active_mode' => $resolved['active_mode'],
            'base_root' => $resolved['base_root'],
            'plugin_root' => $resolved['plugin_root'],
            'backup_dir' => $backup_dir,
            'writable' => is_dir($backup_dir) && is_writable($backup_dir),
            'free_bytes' => is_numeric($free) ? (float) $free : null,
            'outside_webroot' => !self::path_is_within($resolved['plugin_root'], self::get_document_root()),
            'fallback' => !empty($resolved['fallback']),
            'fallback_reason' => isset($resolved['fallback_reason']) ? (string) $resolved['fallback_reason'] : '',
        ];
    }

    public static function test_configuration($mode, $custom_path = '') {
        $mode = self::sanitize_mode_value($mode);
        $base_root = self::base_root_for_configuration($mode, $custom_path);
        $fallback = false;
        $fallback_reason = '';

        if ($base_root === '') {
            if ($mode !== self::MODE_AUTO) {
                throw new RuntimeException(__('Für diesen Speichermodus konnte kein lokales Basisverzeichnis ermittelt werden.', 'fg-backup-pro'));
            }
            $fallback = true;
            $fallback_reason = __('Oberhalb des Webroots konnte kein geeigneter Pfad ermittelt werden. Der Standardordner in wp-content ist nutzbar.', 'fg-backup-pro');
            $base_root = self::get_default_private_root();
        }

        try {
            $prepared = self::prepare_root($base_root);
        } catch (Throwable $exception) {
            if ($mode !== self::MODE_AUTO) {
                throw $exception;
            }
            $fallback = true;
            $fallback_reason = sprintf(
                __('Der automatisch ermittelte Pfad ist nicht nutzbar (%s). Der Standardordner in wp-content ist nutzbar.', 'fg-backup-pro'),
                $exception->getMessage()
            );
            $prepared = self::prepare_root(self::get_default_private_root());
        }

        $test_file = $prepared['plugin_root'] . '.fg-backup-write-test-' . wp_generate_password(12, false, false) . '.tmp';
        $content = 'FG Backup Pro ' . gmdate('c');

        if (@file_put_contents($test_file, $content, LOCK_EX) !== strlen($content)) {
            @unlink($test_file);
            throw new RuntimeException(__('Die Testdatei konnte nicht vollständig geschrieben werden.', 'fg-backup-pro'));
        }

        $read = @file_get_contents($test_file);
        @unlink($test_file);
        if ($read !== $content) {
            throw new RuntimeException(__('Die Testdatei konnte nicht korrekt zurückgelesen werden.', 'fg-backup-pro'));
        }

        $free = @disk_free_space($prepared['plugin_root']);

        return [
            'path' => $prepared['backup_dir'],
            'writable' => true,
            'free_bytes' => is_numeric($free) ? (float) $free : null,
            'outside_webroot' => !self::path_is_within($prepared['plugin_root'], self::get_document_root()),
            'fallback' => $fallback,
            'fallback_reason' => $fallback_reason,
        ];
    }

    public static function get_excluded_roots() {
        $roots = [
            self::get_default_private_root(),
            self::get_plugin_root(),
        ];

        $custom = self::normalize_base_path((string) get_option('fg_backup_storage_path', ''));
        if ($custom !== '') {
            $roots[] = trailingslashit($custom) . 'fg-backup-pro/';
        }

        $unique = [];
        foreach ($roots as $root) {
            $root = trailingslashit(wp_normalize_path((string) $root));
            if ($root !== '/' && !in_array($root, $unique, true)) {
                $unique[] = $root;
            }
        }

        return $unique;
    }

    public static function sanitize_mode_value($mode) {
        return in_array($mode, [self::MODE_CONTENT, self::MODE_AUTO, self::MODE_CUSTOM], true)
            ? $mode
            : self::MODE_CONTENT;
    }

    public static function normalize_base_path($path) {
        $path = trim((string) $path);
        if ($path === '' || strpos($path, "\0") !== false || strpos($path, '://') !== false) {
            return '';
        }

        $path = wp_normalize_path($path);
        if (!self::is_absolute_path($path)) {
            return '';
        }

        $path = untrailingslashit($path);
        if ($path === '' || $path === '/' || preg_match('/^[A-Za-z]:$/', $path)) {
            return '';
        }

        return trailingslashit($path);
    }

    private static function resolve() {
        if (is_array(self::$resolved)) {
            return self::$resolved;
        }

        $configured_mode = self::sanitize_mode_value((string) get_option('fg_backup_storage_mode', self::MODE_CONTENT));
        $custom_path = (string) get_option('fg_backup_storage_path', '');
        $requested_root = self::base_root_for_configuration($configured_mode, $custom_path);
        $fallback = false;
        $fallback_reason = '';
        $active_mode = $configured_mode;

        if ($requested_root === '') {
            $fallback = true;
            $active_mode = self::MODE_CONTENT;
            $fallback_reason = __('Der konfigurierte Speicherort ist ungültig. Es wird der geschützte Standardordner in wp-content verwendet.', 'fg-backup-pro');
            $requested_root = self::get_default_private_root();
        }

        try {
            $prepared = self::prepare_root($requested_root);
        } catch (Throwable $exception) {
            if ($configured_mode === self::MODE_CONTENT) {
                throw $exception;
            }

            $fallback = true;
            $active_mode = self::MODE_CONTENT;
            $fallback_reason = sprintf(
                __('Der konfigurierte Speicherort ist nicht nutzbar (%s). Es wird der geschützte Standardordner in wp-content verwendet.', 'fg-backup-pro'),
                $exception->getMessage()
            );
            $prepared = self::prepare_root(self::get_default_private_root());
        }

        self::$resolved = [
            'configured_mode' => $configured_mode,
            'active_mode' => $active_mode,
            'base_root' => $prepared['base_root'],
            'plugin_root' => $prepared['plugin_root'],
            'fallback' => $fallback,
            'fallback_reason' => $fallback_reason,
        ];

        return self::$resolved;
    }

    private static function base_root_for_configuration($mode, $custom_path) {
        if ($mode === self::MODE_AUTO) {
            return self::get_auto_candidate_root();
        }

        if ($mode === self::MODE_CUSTOM) {
            return self::normalize_base_path($custom_path);
        }

        return self::get_default_private_root();
    }

    private static function prepare_root($base_root) {
        $base_root = self::normalize_base_path($base_root);
        if ($base_root === '') {
            throw new RuntimeException(__('Der lokale Backup-Pfad ist ungültig.', 'fg-backup-pro'));
        }

        $plugin_root = trailingslashit($base_root) . 'fg-backup-pro/';
        $backup_dir = $plugin_root . 'backups/';
        $temp_dir = $plugin_root . 'temporary/';

        foreach ([$base_root, $plugin_root, $backup_dir, $temp_dir] as $directory) {
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

        self::write_protection_files($plugin_root);
        self::write_protection_files($backup_dir);
        self::write_protection_files($temp_dir);
        self::write_marker($plugin_root);

        return [
            'base_root' => trailingslashit(wp_normalize_path($base_root)),
            'plugin_root' => trailingslashit(wp_normalize_path($plugin_root)),
            'backup_dir' => trailingslashit(wp_normalize_path($backup_dir)),
            'temp_dir' => trailingslashit(wp_normalize_path($temp_dir)),
        ];
    }

    private static function write_marker($plugin_root) {
        $path = trailingslashit($plugin_root) . '.fg-backup-pro-storage.json';
        $data = [
            'managed_by' => 'FG Backup Pro',
            'site_url' => home_url('/'),
            'created_or_checked_at' => gmdate('c'),
            'plugin_version' => defined('FG_BACKUP_VERSION') ? FG_BACKUP_VERSION : '',
        ];
        $json = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (is_string($json)) {
            @file_put_contents($path, $json . "\n", LOCK_EX);
        }
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

                if (!preg_match('/(?:\.zip|\.sql|\.sql\.gz|\.sql\.zip|\.tgz|\.tar\.gz)$/i', $item)) {
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

    private static function is_absolute_path($path) {
        return strpos($path, '/') === 0 || (bool) preg_match('/^[A-Za-z]:\//', $path);
    }

    private static function path_is_within($path, $directory) {
        $path = self::canonical_for_compare($path);
        $directory = self::canonical_for_compare($directory);

        if ($path === '' || $directory === '') {
            return false;
        }

        return strpos(trailingslashit($path), trailingslashit($directory)) === 0;
    }

    private static function canonical_for_compare($path) {
        $path = wp_normalize_path((string) $path);
        $real = realpath($path);
        if ($real !== false) {
            $path = wp_normalize_path($real);
        }
        return untrailingslashit($path);
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
