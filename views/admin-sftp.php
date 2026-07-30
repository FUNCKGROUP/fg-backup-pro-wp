<?php

defined('ABSPATH') || exit;

$has_password = defined('FG_BACKUP_SFTP_PASSWORD') || get_option('fg_backup_sftp_password', '') !== '';
$has_passphrase = defined('FG_BACKUP_SFTP_KEY_PASSPHRASE') || get_option('fg_backup_sftp_key_passphrase', '') !== '';
$key_path_constant = defined('FG_BACKUP_SFTP_PRIVATE_KEY_PATH');
$host_key = (string) get_option('fg_backup_sftp_host_key', '');
$host_key_target = (string) get_option('fg_backup_sftp_host_key_target', '');
?>

<?php FgBackup_Admin::render_settings_notices('fg_backup_sftp_settings'); ?>

<?php if (!FgBackup_Sftp::available()) : ?>
    <div class="notice notice-error inline"><p>
        <?php esc_html_e('phpseclib ist noch nicht installiert. Bitte im Plugin-Root „composer update“ ausführen. Für eine Release-ZIP müssen vendor und composer.lock anschließend enthalten sein.', 'fg-backup-pro'); ?>
    </p></div>
<?php endif; ?>

<form method="post" action="options.php" class="fg-backup-settings" id="fg-backup-sftp-form">
    <?php settings_fields('fg_backup_sftp_settings'); ?>

    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><?php esc_html_e('SFTP', 'fg-backup-pro'); ?></th>
            <td>
                <label>
                    <input type="hidden" name="fg_backup_sftp_enabled" value="0">
                    <input type="checkbox" name="fg_backup_sftp_enabled" value="1" <?php checked((int) get_option('fg_backup_sftp_enabled', 0), 1); ?>>
                    <?php esc_html_e('Neue Backups zusätzlich per SFTP hochladen', 'fg-backup-pro'); ?>
                </label>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="fg-backup-sftp-host"><?php esc_html_e('Server', 'fg-backup-pro'); ?></label></th>
            <td>
                <input type="text" class="regular-text code" name="fg_backup_sftp_host" id="fg-backup-sftp-host" value="<?php echo esc_attr($sftp_settings['host']); ?>" placeholder="backup.example.com" autocomplete="off">
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="fg-backup-sftp-port"><?php esc_html_e('Port', 'fg-backup-pro'); ?></label></th>
            <td><input type="number" min="1" max="65535" name="fg_backup_sftp_port" id="fg-backup-sftp-port" value="<?php echo esc_attr($sftp_settings['port']); ?>" class="small-text"></td>
        </tr>
        <tr>
            <th scope="row"><label for="fg-backup-sftp-username"><?php esc_html_e('Benutzername', 'fg-backup-pro'); ?></label></th>
            <td><input type="text" class="regular-text" name="fg_backup_sftp_username" id="fg-backup-sftp-username" value="<?php echo esc_attr($sftp_settings['username']); ?>" autocomplete="username"></td>
        </tr>
        <tr>
            <th scope="row"><label for="fg-backup-sftp-auth"><?php esc_html_e('Anmeldung', 'fg-backup-pro'); ?></label></th>
            <td>
                <select name="fg_backup_sftp_auth" id="fg-backup-sftp-auth">
                    <option value="password" <?php selected($sftp_settings['auth'], 'password'); ?>><?php esc_html_e('Passwort', 'fg-backup-pro'); ?></option>
                    <option value="key" <?php selected($sftp_settings['auth'], 'key'); ?>><?php esc_html_e('Privater SSH-Schlüssel', 'fg-backup-pro'); ?></option>
                </select>
            </td>
        </tr>
        <tr class="fg-backup-sftp-password-row">
            <th scope="row"><label for="fg-backup-sftp-password"><?php esc_html_e('Passwort', 'fg-backup-pro'); ?></label></th>
            <td>
                <input type="password" class="regular-text" name="fg_backup_sftp_password" id="fg-backup-sftp-password" value="" autocomplete="new-password" <?php disabled(defined('FG_BACKUP_SFTP_PASSWORD')); ?>>
                <p class="description">
                    <?php
                    if (defined('FG_BACKUP_SFTP_PASSWORD')) {
                        esc_html_e('Wird über FG_BACKUP_SFTP_PASSWORD in wp-config.php bereitgestellt.', 'fg-backup-pro');
                    } elseif ($has_password) {
                        esc_html_e('Ein Passwort ist gespeichert. Leer lassen, um es beizubehalten.', 'fg-backup-pro');
                    } else {
                        esc_html_e('Das Passwort wird verschlüsselt in den WordPress-Optionen gespeichert.', 'fg-backup-pro');
                    }
                    ?>
                </p>
            </td>
        </tr>
        <tr class="fg-backup-sftp-key-row">
            <th scope="row"><label for="fg-backup-sftp-key-path"><?php esc_html_e('Privater Schlüssel', 'fg-backup-pro'); ?></label></th>
            <td>
                <input type="text" class="large-text code" name="fg_backup_sftp_private_key_path" id="fg-backup-sftp-key-path" value="<?php echo esc_attr(FgBackup_Sftp::private_key_path()); ?>" placeholder="/var/www/.ssh/backup_ed25519" <?php disabled($key_path_constant); ?>>
                <p class="description">
                    <?php echo $key_path_constant
                        ? esc_html__('Wird über FG_BACKUP_SFTP_PRIVATE_KEY_PATH in wp-config.php bereitgestellt.', 'fg-backup-pro')
                        : esc_html__('Absoluter Pfad auf dem WordPress-Server. Die Datei muss für den PHP-Benutzer lesbar sein.', 'fg-backup-pro'); ?>
                </p>
            </td>
        </tr>
        <tr class="fg-backup-sftp-key-row">
            <th scope="row"><label for="fg-backup-sftp-passphrase"><?php esc_html_e('Key-Passphrase', 'fg-backup-pro'); ?></label></th>
            <td>
                <input type="password" class="regular-text" name="fg_backup_sftp_key_passphrase" id="fg-backup-sftp-passphrase" value="" autocomplete="new-password" <?php disabled(defined('FG_BACKUP_SFTP_KEY_PASSPHRASE')); ?>>
                <p class="description">
                    <?php
                    if (defined('FG_BACKUP_SFTP_KEY_PASSPHRASE')) {
                        esc_html_e('Wird über FG_BACKUP_SFTP_KEY_PASSPHRASE in wp-config.php bereitgestellt.', 'fg-backup-pro');
                    } elseif ($has_passphrase) {
                        esc_html_e('Eine Passphrase ist gespeichert. Leer lassen, um sie beizubehalten.', 'fg-backup-pro');
                    } else {
                        esc_html_e('Nur bei einem passwortgeschützten privaten Schlüssel erforderlich.', 'fg-backup-pro');
                    }
                    ?>
                </p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="fg-backup-sftp-directory"><?php esc_html_e('Zielverzeichnis', 'fg-backup-pro'); ?></label></th>
            <td>
                <input type="text" class="regular-text code" name="fg_backup_sftp_remote_dir" id="fg-backup-sftp-directory" value="<?php echo esc_attr($sftp_settings['remote_dir']); ?>">
                <p class="description"><?php esc_html_e('Erlaubte Platzhalter: %host und %site. Fehlende Verzeichnisse werden angelegt.', 'fg-backup-pro'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="fg-backup-sftp-retention"><?php esc_html_e('Remote-Aufbewahrung', 'fg-backup-pro'); ?></label></th>
            <td>
                <input type="number" min="1" max="100" name="fg_backup_sftp_retention" id="fg-backup-sftp-retention" value="<?php echo esc_attr($sftp_settings['retention']); ?>" class="small-text">
                <span><?php esc_html_e('Backups', 'fg-backup-pro'); ?></span>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e('Lokale Datei', 'fg-backup-pro'); ?></th>
            <td>
                <label>
                    <input type="hidden" name="fg_backup_sftp_keep_local" value="0">
                    <input type="checkbox" name="fg_backup_sftp_keep_local" value="1" <?php checked($sftp_settings['keep_local']); ?>>
                    <?php esc_html_e('Nach erfolgreichen Remote-Uploads lokal behalten', 'fg-backup-pro'); ?>
                </label>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e('Serverschlüssel', 'fg-backup-pro'); ?></th>
            <td>
                <div id="fg-backup-sftp-key-status">
                    <?php if ($host_key !== '') : ?>
                        <strong><?php esc_html_e('Gespeichert', 'fg-backup-pro'); ?></strong>
                        <code><?php echo esc_html(FgBackup_Sftp::fingerprint($host_key)); ?></code>
                        <?php if ($host_key_target !== '') : ?><span><?php echo esc_html($host_key_target); ?></span><?php endif; ?>
                    <?php else : ?>
                        <span><?php esc_html_e('Noch nicht bestätigt. Bitte Einstellungen speichern und anschließend die Verbindung testen.', 'fg-backup-pro'); ?></span>
                    <?php endif; ?>
                </div>
                <p class="description"><?php esc_html_e('Beim ersten Verbindungstest wird der SSH-Serverschlüssel gespeichert. Änderungen werden danach blockiert.', 'fg-backup-pro'); ?></p>
            </td>
        </tr>
    </table>

    <?php submit_button(__('SFTP-Einstellungen speichern', 'fg-backup-pro')); ?>
    <button type="button" class="button" id="fg-backup-sftp-test" <?php disabled(!FgBackup_Sftp::available()); ?>><?php esc_html_e('Verbindung testen', 'fg-backup-pro'); ?></button>
    <?php if ($host_key !== '') : ?>
        <button type="button" class="button" id="fg-backup-sftp-reset-key"><?php esc_html_e('Serverschlüssel zurücksetzen', 'fg-backup-pro'); ?></button>
    <?php endif; ?>
    <span id="fg-backup-sftp-result" class="fg-backup-inline-result" aria-live="polite"></span>
</form>

<hr class="fg-backup-section-divider">

<div class="fg-backup-remote-section">
    <h2><?php esc_html_e('Remote-Backups', 'fg-backup-pro'); ?></h2>
    <p class="description"><?php esc_html_e('Die Liste wird nur auf Anfrage vom SFTP-Server geladen und verlangsamt den normalen Seitenaufruf nicht.', 'fg-backup-pro'); ?></p>
    <p>
        <button type="button" class="button" id="fg-backup-sftp-list" <?php disabled(!FgBackup_Sftp::available() || $host_key === ''); ?>>
            <?php esc_html_e('Remote-Dateien laden', 'fg-backup-pro'); ?>
        </button>
        <span id="fg-backup-sftp-list-result" class="fg-backup-inline-result" aria-live="polite"></span>
    </p>

    <table class="widefat striped fg-backup-table" id="fg-backup-sftp-table" hidden>
        <thead>
        <tr>
            <th><?php esc_html_e('Datei', 'fg-backup-pro'); ?></th>
            <th><?php esc_html_e('Größe', 'fg-backup-pro'); ?></th>
            <th><?php esc_html_e('Geändert', 'fg-backup-pro'); ?></th>
            <th><?php esc_html_e('Aktion', 'fg-backup-pro'); ?></th>
        </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>

