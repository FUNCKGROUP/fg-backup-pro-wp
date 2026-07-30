<?php

defined('ABSPATH') || exit;

$has_password = defined('FG_BACKUP_WEBDAV_PASSWORD') || get_option('fg_backup_webdav_password', '') !== '';
?>

<?php FgBackup_Admin::render_settings_notices('fg_backup_webdav_settings'); ?>

<?php if (!FgBackup_Webdav::available()) : ?>
    <div class="notice notice-error inline"><p><?php esc_html_e('Die PHP-cURL-Erweiterung fehlt. WebDAV kann auf diesem Server nicht verwendet werden.', 'fg-backup-pro'); ?></p></div>
<?php endif; ?>

<form method="post" action="options.php" class="fg-backup-settings" id="fg-backup-webdav-form">
    <?php settings_fields('fg_backup_webdav_settings'); ?>
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><?php esc_html_e('WebDAV', 'fg-backup-pro'); ?></th>
            <td><label>
                <input type="hidden" name="fg_backup_webdav_enabled" value="0">
                <input type="checkbox" name="fg_backup_webdav_enabled" value="1" <?php checked((int) get_option('fg_backup_webdav_enabled', 0), 1); ?>>
                <?php esc_html_e('Neue Backups zusätzlich per WebDAV hochladen', 'fg-backup-pro'); ?>
            </label></td>
        </tr>
        <tr>
            <th scope="row"><label for="fg-backup-webdav-url"><?php esc_html_e('WebDAV-URL', 'fg-backup-pro'); ?></label></th>
            <td>
                <input type="url" class="large-text code" name="fg_backup_webdav_base_url" id="fg-backup-webdav-url" value="<?php echo esc_attr($webdav_settings['base_url']); ?>" placeholder="https://cloud.example.com/remote.php/dav/files/benutzer">
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="fg-backup-webdav-username"><?php esc_html_e('Benutzername', 'fg-backup-pro'); ?></label></th>
            <td><input type="text" class="regular-text" name="fg_backup_webdav_username" id="fg-backup-webdav-username" value="<?php echo esc_attr($webdav_settings['username']); ?>" autocomplete="username"></td>
        </tr>
        <tr>
            <th scope="row"><label for="fg-backup-webdav-password"><?php esc_html_e('Passwort / App-Passwort', 'fg-backup-pro'); ?></label></th>
            <td>
                <input type="password" class="regular-text" name="fg_backup_webdav_password" id="fg-backup-webdav-password" value="" autocomplete="new-password" <?php disabled(defined('FG_BACKUP_WEBDAV_PASSWORD')); ?>>
                <p class="description">
                    <?php
                    if (defined('FG_BACKUP_WEBDAV_PASSWORD')) {
                        esc_html_e('Wird über FG_BACKUP_WEBDAV_PASSWORD in wp-config.php bereitgestellt.', 'fg-backup-pro');
                    } elseif ($has_password) {
                        esc_html_e('Ein Passwort ist gespeichert. Leer lassen, um es beizubehalten.', 'fg-backup-pro');
                    } else {
                        esc_html_e('Wird verschlüsselt gespeichert.', 'fg-backup-pro');
                    }
                    ?>
                </p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="fg-backup-webdav-directory"><?php esc_html_e('Zielverzeichnis', 'fg-backup-pro'); ?></label></th>
            <td>
                <input type="text" class="regular-text code" name="fg_backup_webdav_remote_dir" id="fg-backup-webdav-directory" value="<?php echo esc_attr($webdav_settings['remote_dir']); ?>">
                <p class="description"><?php esc_html_e('Platzhalter: %host und %site.', 'fg-backup-pro'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="fg-backup-webdav-retention"><?php esc_html_e('Remote-Aufbewahrung', 'fg-backup-pro'); ?></label></th>
            <td><input type="number" min="1" max="100" name="fg_backup_webdav_retention" id="fg-backup-webdav-retention" value="<?php echo esc_attr($webdav_settings['retention']); ?>" class="small-text"> <?php esc_html_e('Backups', 'fg-backup-pro'); ?></td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e('Lokale Datei', 'fg-backup-pro'); ?></th>
            <td><label>
                <input type="hidden" name="fg_backup_webdav_keep_local" value="0">
                <input type="checkbox" name="fg_backup_webdav_keep_local" value="1" <?php checked($webdav_settings['keep_local']); ?>>
                <?php esc_html_e('Nach erfolgreichen Remote-Uploads lokal behalten', 'fg-backup-pro'); ?>
            </label></td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e('Interne Server', 'fg-backup-pro'); ?></th>
            <td><label>
                <input type="hidden" name="fg_backup_webdav_allow_private" value="0">
                <input type="checkbox" name="fg_backup_webdav_allow_private" value="1" <?php checked($webdav_settings['allow_private']); ?>>
                <?php esc_html_e('Private IP-Adressen für NAS oder interne Nextcloud zulassen', 'fg-backup-pro'); ?>
            </label></td>
        </tr>
    </table>
    <?php submit_button(__('WebDAV-Einstellungen speichern', 'fg-backup-pro')); ?>
    <button type="button" class="button" id="fg-backup-webdav-test" <?php disabled(!FgBackup_Webdav::available()); ?>><?php esc_html_e('Verbindung testen', 'fg-backup-pro'); ?></button>
    <span id="fg-backup-webdav-result" class="fg-backup-inline-result" aria-live="polite"></span>
</form>

<hr class="fg-backup-section-divider">
<div class="fg-backup-remote-section" data-remote="webdav">
    <h2><?php esc_html_e('Remote-Backups', 'fg-backup-pro'); ?></h2>
    <p><button type="button" class="button fg-backup-remote-list" data-target="webdav" <?php disabled(!FgBackup_Webdav::available()); ?>><?php esc_html_e('Remote-Dateien laden', 'fg-backup-pro'); ?></button>
        <span class="fg-backup-inline-result fg-backup-remote-list-result" aria-live="polite"></span></p>
    <table class="widefat striped fg-backup-table fg-backup-remote-table" hidden>
        <thead><tr><th><?php esc_html_e('Datei', 'fg-backup-pro'); ?></th><th><?php esc_html_e('Größe', 'fg-backup-pro'); ?></th><th><?php esc_html_e('Erstellt', 'fg-backup-pro'); ?></th><th><?php esc_html_e('Aktion', 'fg-backup-pro'); ?></th></tr></thead>
        <tbody></tbody>
    </table>
</div>
