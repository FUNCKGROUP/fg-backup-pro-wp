<?php

defined('ABSPATH') || exit;

$app_key_constant = defined('FG_BACKUP_DROPBOX_APP_KEY');
$relay_constant = defined('FG_BACKUP_DROPBOX_RELAY_URL');
$connected = FgBackup_Dropbox::connected();
$account_label = trim($dropbox_account['name'] . ($dropbox_account['email'] !== '' ? ' · ' . $dropbox_account['email'] : ''));
?>

<?php FgBackup_Admin::render_settings_notices('fg_backup_dropbox_settings'); ?>

<form method="post" action="options.php" class="fg-backup-settings" id="fg-backup-dropbox-form">
    <?php settings_fields('fg_backup_dropbox_settings'); ?>
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><?php esc_html_e('Dropbox', 'fg-backup-pro'); ?></th>
            <td><label>
                <input type="hidden" name="fg_backup_dropbox_enabled" value="0">
                <input type="checkbox" name="fg_backup_dropbox_enabled" value="1" <?php checked((int) get_option('fg_backup_dropbox_enabled', 0), 1); ?> <?php disabled(!$connected); ?>>
                <?php esc_html_e('Neue Backups zusätzlich zu Dropbox hochladen', 'fg-backup-pro'); ?>
            </label>
            <?php if (!$connected) : ?><p class="description"><?php esc_html_e('Dropbox kann erst nach erfolgreicher Verbindung aktiviert werden.', 'fg-backup-pro'); ?></p><?php endif; ?></td>
        </tr>
        <tr>
            <th scope="row"><label for="fg-backup-dropbox-app-key"><?php esc_html_e('App-Key', 'fg-backup-pro'); ?></label></th>
            <td>
                <input type="text" class="regular-text code" name="fg_backup_dropbox_app_key" id="fg-backup-dropbox-app-key" value="<?php echo esc_attr($dropbox_settings['app_key']); ?>" autocomplete="off" <?php disabled($app_key_constant); ?>>
                <?php if ($app_key_constant) : ?>
                    <p class="description"><?php esc_html_e('Wird über FG_BACKUP_DROPBOX_APP_KEY bereitgestellt.', 'fg-backup-pro'); ?></p>
                <?php else : ?>
                    <p class="description"><?php esc_html_e('Nur für „Manuell verbinden“ oder eine eigene Dropbox-App erforderlich. Die Standardverbindung erhält den öffentlichen App-Key vom Callback-Relay.', 'fg-backup-pro'); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="fg-backup-dropbox-relay-url"><?php esc_html_e('Callback-Relay', 'fg-backup-pro'); ?></label></th>
            <td>
                <input type="url" class="large-text code" name="fg_backup_dropbox_relay_url" id="fg-backup-dropbox-relay-url" value="<?php echo esc_attr($dropbox_settings['relay_url']); ?>" <?php disabled($relay_constant); ?>>
                <p class="description"><?php esc_html_e('Der Relay leitet nur den einmaligen Autorisierungscode weiter. PKCE-Verifier, Tokens, Dateilisten und Backups bleiben auf dieser Website. Änderungen bitte vor dem Verbinden speichern.', 'fg-backup-pro'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e('Verbindung', 'fg-backup-pro'); ?></th>
            <td>
                <?php if ($connected) : ?>
                    <strong class="fg-backup-connection-ok"><?php esc_html_e('Verbunden', 'fg-backup-pro'); ?></strong>
                    <?php if ($account_label !== '') : ?><span><?php echo esc_html($account_label); ?></span><?php endif; ?>
                <?php else : ?>
                    <span><?php esc_html_e('Nicht verbunden', 'fg-backup-pro'); ?></span>
                <?php endif; ?>
                <div class="fg-backup-dropbox-actions">
                    <?php if (!$connected) : ?>
                        <button type="button" class="button button-primary" id="fg-backup-dropbox-connect"><?php esc_html_e('Mit Dropbox verbinden', 'fg-backup-pro'); ?></button>
                        <button type="button" class="button" id="fg-backup-dropbox-manual-start"><?php esc_html_e('Manuell verbinden', 'fg-backup-pro'); ?></button>
                        <a href="#" target="_blank" rel="noopener noreferrer" id="fg-backup-dropbox-relay-link" hidden><?php esc_html_e('Dropbox-Freigabe öffnen', 'fg-backup-pro'); ?></a>
                    <?php else : ?>
                        <button type="button" class="button" id="fg-backup-dropbox-test"><?php esc_html_e('Verbindung testen', 'fg-backup-pro'); ?></button>
                        <button type="button" class="button" id="fg-backup-dropbox-disconnect"><?php esc_html_e('Verbindung trennen', 'fg-backup-pro'); ?></button>
                    <?php endif; ?>
                    <span id="fg-backup-dropbox-result" class="fg-backup-inline-result" aria-live="polite"></span>
                </div>
                <div id="fg-backup-dropbox-manual" class="fg-backup-dropbox-manual" hidden>
                    <p><a href="#" target="_blank" rel="noopener noreferrer" id="fg-backup-dropbox-manual-link"><?php esc_html_e('Dropbox-Freigabe öffnen', 'fg-backup-pro'); ?></a></p>
                    <label for="fg-backup-dropbox-code"><?php esc_html_e('Angezeigten Code einfügen', 'fg-backup-pro'); ?></label>
                    <div class="fg-backup-inline-controls">
                        <input type="text" class="regular-text code" id="fg-backup-dropbox-code" autocomplete="off">
                        <button type="button" class="button button-primary" id="fg-backup-dropbox-code-submit"><?php esc_html_e('Code übernehmen', 'fg-backup-pro'); ?></button>
                    </div>
                </div>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="fg-backup-dropbox-directory"><?php esc_html_e('Zielverzeichnis', 'fg-backup-pro'); ?></label></th>
            <td>
                <input type="text" class="regular-text code" name="fg_backup_dropbox_remote_dir" id="fg-backup-dropbox-directory" value="<?php echo esc_attr($dropbox_settings['remote_dir']); ?>">
                <p class="description"><?php esc_html_e('Innerhalb des App-Ordners. Platzhalter: %host und %site.', 'fg-backup-pro'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="fg-backup-dropbox-retention"><?php esc_html_e('Remote-Aufbewahrung', 'fg-backup-pro'); ?></label></th>
            <td><input type="number" min="1" max="100" name="fg_backup_dropbox_retention" id="fg-backup-dropbox-retention" value="<?php echo esc_attr($dropbox_settings['retention']); ?>" class="small-text"> <?php esc_html_e('Backups', 'fg-backup-pro'); ?></td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e('Lokale Datei', 'fg-backup-pro'); ?></th>
            <td><label>
                <input type="hidden" name="fg_backup_dropbox_keep_local" value="0">
                <input type="checkbox" name="fg_backup_dropbox_keep_local" value="1" <?php checked($dropbox_settings['keep_local']); ?>>
                <?php esc_html_e('Nach erfolgreichen Remote-Uploads lokal behalten', 'fg-backup-pro'); ?>
            </label></td>
        </tr>
    </table>
    <?php submit_button(__('Dropbox-Einstellungen speichern', 'fg-backup-pro')); ?>
</form>

<?php if ($connected) : ?>
    <hr class="fg-backup-section-divider">
    <div class="fg-backup-remote-section" data-remote="dropbox">
        <h2><?php esc_html_e('Remote-Backups', 'fg-backup-pro'); ?></h2>
        <p><button type="button" class="button fg-backup-remote-list" data-target="dropbox"><?php esc_html_e('Remote-Dateien laden', 'fg-backup-pro'); ?></button>
            <span class="fg-backup-inline-result fg-backup-remote-list-result" aria-live="polite"></span></p>
        <table class="widefat striped fg-backup-table fg-backup-remote-table" hidden>
            <thead><tr><th><?php esc_html_e('Datei', 'fg-backup-pro'); ?></th><th><?php esc_html_e('Größe', 'fg-backup-pro'); ?></th><th><?php esc_html_e('Erstellt', 'fg-backup-pro'); ?></th><th><?php esc_html_e('Aktion', 'fg-backup-pro'); ?></th></tr></thead>
            <tbody></tbody>
        </table>
    </div>
<?php endif; ?>
