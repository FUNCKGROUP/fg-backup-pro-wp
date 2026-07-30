<?php

defined('ABSPATH') || exit;

$access_key_constant = defined('FG_BACKUP_S3_ACCESS_KEY');
$secret_key_constant = defined('FG_BACKUP_S3_SECRET_KEY');
$session_token_constant = defined('FG_BACKUP_S3_SESSION_TOKEN');
$has_access_key = FgBackup_S3::access_key() !== '';
$has_secret_key = FgBackup_S3::secret_key() !== '';
$has_session_token = FgBackup_S3::session_token() !== '';
?>

<?php FgBackup_Admin::render_settings_notices('fg_backup_s3_settings'); ?>

<form method="post" action="options.php" class="fg-backup-settings" id="fg-backup-s3-form">
    <?php settings_fields('fg_backup_s3_settings'); ?>
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><label for="fg-backup-s3-provider"><?php esc_html_e('Anbieter', 'fg-backup-pro'); ?></label></th>
            <td>
                <select name="fg_backup_s3_provider" id="fg-backup-s3-provider">
                    <option value="custom" <?php selected($s3_settings['provider'], 'custom'); ?>><?php esc_html_e('S3-kompatibel / Benutzerdefiniert', 'fg-backup-pro'); ?></option>
                    <option value="aws" <?php selected($s3_settings['provider'], 'aws'); ?>>Amazon S3</option>
                    <option value="hetzner" <?php selected($s3_settings['provider'], 'hetzner'); ?>>Hetzner Object Storage</option>
                    <option value="cloudflare" <?php selected($s3_settings['provider'], 'cloudflare'); ?>>Cloudflare R2</option>
                    <option value="backblaze" <?php selected($s3_settings['provider'], 'backblaze'); ?>>Backblaze B2</option>
                    <option value="wasabi" <?php selected($s3_settings['provider'], 'wasabi'); ?>>Wasabi</option>
                    <option value="minio" <?php selected($s3_settings['provider'], 'minio'); ?>>MinIO</option>
                </select>
                <p class="description"><?php esc_html_e('Die Auswahl dient der Zuordnung. Endpoint und Region werden immer ausdrücklich eingetragen.', 'fg-backup-pro'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="fg-backup-s3-endpoint"><?php esc_html_e('Endpoint', 'fg-backup-pro'); ?></label></th>
            <td>
                <input type="url" class="large-text code" name="fg_backup_s3_endpoint" id="fg-backup-s3-endpoint" value="<?php echo esc_attr($s3_settings['endpoint']); ?>" placeholder="https://fsn1.your-objectstorage.com">
                <p class="description"><?php esc_html_e('Basis-URL ohne Bucket, Zugangsdaten, Query-Parameter oder abschließenden Pfad zur Backup-Datei.', 'fg-backup-pro'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="fg-backup-s3-region"><?php esc_html_e('Region', 'fg-backup-pro'); ?></label></th>
            <td>
                <input type="text" class="regular-text code" name="fg_backup_s3_region_name" id="fg-backup-s3-region" value="<?php echo esc_attr($s3_settings['region']); ?>" placeholder="us-east-1">
                <p class="description"><?php esc_html_e('Zum Beispiel eu-central-1, fsn1 oder bei Cloudflare R2 auto.', 'fg-backup-pro'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="fg-backup-s3-bucket"><?php esc_html_e('Bucket', 'fg-backup-pro'); ?></label></th>
            <td><input type="text" class="regular-text code" name="fg_backup_s3_bucket_name" id="fg-backup-s3-bucket" value="<?php echo esc_attr($s3_settings['bucket']); ?>" autocomplete="off"></td>
        </tr>
        <tr>
            <th scope="row"><label for="fg-backup-s3-access-key"><?php esc_html_e('Access Key', 'fg-backup-pro'); ?></label></th>
            <td>
                <input type="password" class="regular-text code" name="fg_backup_s3_access_key" id="fg-backup-s3-access-key" value="" autocomplete="new-password" <?php disabled($access_key_constant); ?> placeholder="<?php echo esc_attr($has_access_key ? __('Gespeichert – leer lassen zum Beibehalten', 'fg-backup-pro') : ''); ?>">
                <?php if ($access_key_constant) : ?><p class="description"><?php esc_html_e('Wird über FG_BACKUP_S3_ACCESS_KEY bereitgestellt.', 'fg-backup-pro'); ?></p><?php elseif ($has_access_key) : ?><p class="description"><?php esc_html_e('Ein Access Key ist verschlüsselt gespeichert.', 'fg-backup-pro'); ?></p><?php endif; ?>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="fg-backup-s3-secret-key"><?php esc_html_e('Secret Key', 'fg-backup-pro'); ?></label></th>
            <td>
                <input type="password" class="regular-text code" name="fg_backup_s3_secret_key" id="fg-backup-s3-secret-key" value="" autocomplete="new-password" <?php disabled($secret_key_constant); ?> placeholder="<?php echo esc_attr($has_secret_key ? __('Gespeichert – leer lassen zum Beibehalten', 'fg-backup-pro') : ''); ?>">
                <?php if ($secret_key_constant) : ?><p class="description"><?php esc_html_e('Wird über FG_BACKUP_S3_SECRET_KEY bereitgestellt.', 'fg-backup-pro'); ?></p><?php elseif ($has_secret_key) : ?><p class="description"><?php esc_html_e('Ein Secret Key ist verschlüsselt gespeichert.', 'fg-backup-pro'); ?></p><?php endif; ?>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="fg-backup-s3-session-token"><?php esc_html_e('Session Token', 'fg-backup-pro'); ?></label></th>
            <td>
                <textarea class="large-text code" rows="3" name="fg_backup_s3_session_token" id="fg-backup-s3-session-token" autocomplete="off" <?php disabled($session_token_constant); ?> placeholder="<?php echo esc_attr($has_session_token ? __('Gespeichert – leer lassen zum Beibehalten', 'fg-backup-pro') : __('Optional für temporäre Zugangsdaten', 'fg-backup-pro')); ?>"></textarea>
                <?php if ($session_token_constant) : ?><p class="description"><?php esc_html_e('Wird über FG_BACKUP_S3_SESSION_TOKEN bereitgestellt.', 'fg-backup-pro'); ?></p><?php endif; ?>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e('Adressierung', 'fg-backup-pro'); ?></th>
            <td><label>
                <input type="hidden" name="fg_backup_s3_path_style" value="0">
                <input type="checkbox" name="fg_backup_s3_path_style" value="1" <?php checked($s3_settings['path_style']); ?>>
                <?php esc_html_e('Path-Style verwenden: endpoint/bucket/datei', 'fg-backup-pro'); ?>
            </label><p class="description"><?php esc_html_e('Für Hetzner, R2, MinIO und viele kompatible Anbieter empfohlen. Deaktiviert wird bucket.endpoint/datei verwendet.', 'fg-backup-pro'); ?></p></td>
        </tr>
        <tr>
            <th scope="row"><label for="fg-backup-s3-directory"><?php esc_html_e('Zielpfad', 'fg-backup-pro'); ?></label></th>
            <td>
                <input type="text" class="regular-text code" name="fg_backup_s3_remote_dir" id="fg-backup-s3-directory" value="<?php echo esc_attr('/' . ltrim($s3_settings['remote_dir'], '/')); ?>">
                <p class="description"><?php esc_html_e('Objekt-Prefix im Bucket. Platzhalter: %host und %site.', 'fg-backup-pro'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="fg-backup-s3-retention"><?php esc_html_e('Remote-Aufbewahrung', 'fg-backup-pro'); ?></label></th>
            <td><input type="number" min="1" max="100" name="fg_backup_s3_retention" id="fg-backup-s3-retention" value="<?php echo esc_attr($s3_settings['retention']); ?>" class="small-text"> <?php esc_html_e('Backups', 'fg-backup-pro'); ?></td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e('Interne Endpoints', 'fg-backup-pro'); ?></th>
            <td>
                <label><input type="hidden" name="fg_backup_s3_allow_private" value="0"><input type="checkbox" name="fg_backup_s3_allow_private" value="1" <?php checked($s3_settings['allow_private']); ?>> <?php esc_html_e('Private IP-Adressen für internes MinIO oder NAS zulassen', 'fg-backup-pro'); ?></label><br>
                <label><input type="hidden" name="fg_backup_s3_allow_http" value="0"><input type="checkbox" name="fg_backup_s3_allow_http" value="1" <?php checked($s3_settings['allow_http']); ?>> <?php esc_html_e('HTTP ohne TLS zulassen', 'fg-backup-pro'); ?></label>
                <p class="description"><?php esc_html_e('Beides nur in einem vertrauenswürdigen internen Netz verwenden.', 'fg-backup-pro'); ?></p>
            </td>
        </tr>
    </table>
    <?php submit_button(__('S3-Einstellungen speichern', 'fg-backup-pro')); ?>
    <button type="button" class="button" id="fg-backup-s3-test" <?php disabled(!FgBackup_S3::available()); ?>><?php esc_html_e('Verbindung testen', 'fg-backup-pro'); ?></button>
    <span id="fg-backup-s3-result" class="fg-backup-inline-result" aria-live="polite"></span>
</form>

<hr class="fg-backup-section-divider">
<div class="fg-backup-remote-section" data-remote="s3">
    <h2><?php esc_html_e('Remote-Backups', 'fg-backup-pro'); ?></h2>
    <p><button type="button" class="button fg-backup-remote-list" data-target="s3" <?php disabled(!FgBackup_S3::available()); ?>><?php esc_html_e('Remote-Dateien laden', 'fg-backup-pro'); ?></button>
        <span class="fg-backup-inline-result fg-backup-remote-list-result" aria-live="polite"></span></p>
    <table class="widefat striped fg-backup-table fg-backup-remote-table" hidden>
        <thead><tr><th><?php esc_html_e('Datei', 'fg-backup-pro'); ?></th><th><?php esc_html_e('Größe', 'fg-backup-pro'); ?></th><th><?php esc_html_e('Erstellt', 'fg-backup-pro'); ?></th><th><?php esc_html_e('Aktion', 'fg-backup-pro'); ?></th></tr></thead>
        <tbody></tbody>
    </table>
</div>
