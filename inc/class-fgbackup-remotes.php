<?php

defined('ABSPATH') || exit;

class FgBackup_Remotes {

    public static function definitions() {
        return [
            'sftp' => [
                'label' => 'SFTP',
                'class' => 'FgBackup_Sftp',
            ],
            'webdav' => [
                'label' => 'WebDAV',
                'class' => 'FgBackup_Webdav',
            ],
            'dropbox' => [
                'label' => 'Dropbox',
                'class' => 'FgBackup_Dropbox',
            ],
            's3' => [
                'label' => 'S3',
                'class' => 'FgBackup_S3',
            ],
        ];
    }

    public static function enabled_ids() {
        $enabled = [];
        foreach (self::definitions() as $id => $definition) {
            $class = $definition['class'];
            if (class_exists($class) && is_callable([$class, 'enabled']) && $class::enabled()) {
                $enabled[] = $id;
            }
        }
        return $enabled;
    }

    public static function label($id) {
        $definitions = self::definitions();
        return isset($definitions[$id]['label']) ? $definitions[$id]['label'] : strtoupper((string) $id);
    }

    public static function class_name($id) {
        $definitions = self::definitions();
        if (!isset($definitions[$id]['class']) || !class_exists($definitions[$id]['class'])) {
            throw new RuntimeException(__('Unbekanntes Remote-Ziel.', 'fg-backup-pro'));
        }
        return $definitions[$id]['class'];
    }

    public static function assert_enabled_configuration() {
        foreach (self::enabled_ids() as $id) {
            $class = self::class_name($id);
            if (is_callable([$class, 'assert_ready'])) {
                $class::assert_ready();
            }
        }
    }

    public static function keep_local() {
        return (bool) get_option('fg_backup_keep_local', 1);
    }

    public static function prepare($id, $local_path, $file_name) {
        $class = self::class_name($id);
        if (!is_callable([$class, 'prepare_upload'])) {
            throw new RuntimeException(__('Das Remote-Ziel unterstützt keine Uploads.', 'fg-backup-pro'));
        }
        $state = $class::prepare_upload($local_path, $file_name);
        if (!is_array($state)) {
            throw new RuntimeException(__('Das Remote-Ziel hat keinen gültigen Upload-Status geliefert.', 'fg-backup-pro'));
        }
        $state['target'] = $id;
        $state['offset'] = isset($state['offset']) ? (int) $state['offset'] : (isset($state['remote_offset']) ? (int) $state['remote_offset'] : 0);
        $state['total'] = isset($state['total']) ? (int) $state['total'] : (isset($state['remote_total']) ? (int) $state['remote_total'] : (int) filesize($local_path));
        return $state;
    }

    public static function upload($id, $local_path, array $state, $cancel_callback = null, $progress_callback = null) {
        $class = self::class_name($id);
        if (!is_callable([$class, 'upload_state'])) {
            throw new RuntimeException(__('Das Remote-Ziel unterstützt keinen blockweisen Upload.', 'fg-backup-pro'));
        }
        $updated = $class::upload_state($local_path, $state, $cancel_callback, $progress_callback);
        if (!is_array($updated)) {
            throw new RuntimeException(__('Das Remote-Ziel hat keinen gültigen Upload-Fortschritt geliefert.', 'fg-backup-pro'));
        }
        return array_merge($state, $updated);
    }

    public static function finalize($id, array $state) {
        $class = self::class_name($id);
        if (!is_callable([$class, 'finalize_state'])) {
            throw new RuntimeException(__('Das Remote-Ziel kann den Upload nicht finalisieren.', 'fg-backup-pro'));
        }
        return $class::finalize_state($state);
    }

    public static function remove_partial($id, array $state) {
        try {
            $class = self::class_name($id);
            if (is_callable([$class, 'remove_partial_state'])) {
                $class::remove_partial_state($state);
            }
        } catch (Throwable $exception) {
            // Best effort bei Abbruch oder Fehler.
        }
    }

    public static function summarize(array $results) {
        $parts = [];
        foreach ($results as $id => $result) {
            $label = self::label($id);
            $status = isset($result['status']) ? $result['status'] : '';
            if ($status === 'completed') {
                $parts[] = $label . ': ' . (!empty($result['path']) ? $result['path'] : __('erfolgreich', 'fg-backup-pro'));
            } elseif ($status === 'failed') {
                $parts[] = $label . ': ' . (!empty($result['error']) ? $result['error'] : __('fehlgeschlagen', 'fg-backup-pro'));
            } elseif ($status === 'canceled') {
                $parts[] = $label . ': ' . __('abgebrochen', 'fg-backup-pro');
            }
        }
        return implode(' · ', $parts);
    }
}
