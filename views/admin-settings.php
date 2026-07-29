<?php defined('ABSPATH') || exit; ?>

<form method="post" action="options.php" class="fg-backup-settings">
    <?php settings_fields('fg_backup_settings'); ?>

    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><label for="fg-backup-default-type"><?php esc_html_e('Geplantes Backup', 'fg-backup-pro'); ?></label></th>
            <td>
                <select name="fg_backup_type" id="fg-backup-default-type">
                    <option value="full" <?php selected(get_option('fg_backup_type', 'full'), 'full'); ?>><?php esc_html_e('Dateien und Datenbank', 'fg-backup-pro'); ?></option>
                    <option value="db" <?php selected(get_option('fg_backup_type', 'full'), 'db'); ?>><?php esc_html_e('Nur Datenbank', 'fg-backup-pro'); ?></option>
                </select>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="fg-backup-archive-format"><?php esc_html_e('Vollständiges Backup', 'fg-backup-pro'); ?></label></th>
            <td>
                <select name="fg_backup_archive_format" id="fg-backup-archive-format">
                    <option value="zip" <?php selected(get_option('fg_backup_archive_format', 'zip'), 'zip'); ?> <?php disabled(!FgBackup_Backup::supports_zip()); ?>>ZIP<?php echo !FgBackup_Backup::supports_zip() ? ' – ' . esc_html__('nicht verfügbar', 'fg-backup-pro') : ''; ?></option>
                    <option value="tgz" <?php selected(get_option('fg_backup_archive_format', 'zip'), 'tgz'); ?> <?php disabled(!FgBackup_Backup::supports_tgz()); ?>>TGZ<?php echo !FgBackup_Backup::supports_tgz() ? ' – ' . esc_html__('nicht verfügbar', 'fg-backup-pro') : ''; ?></option>
                </select>
                <p class="description"><?php esc_html_e('TGZ benötigt zunächst ein unkomprimiertes TAR-Archiv und ist deshalb meist deutlich langsamer als ZIP.', 'fg-backup-pro'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="fg-backup-database-format"><?php esc_html_e('Datenbank-Backup', 'fg-backup-pro'); ?></label></th>
            <td>
                <select name="fg_backup_database_format" id="fg-backup-database-format">
                    <option value="sql" <?php selected(get_option('fg_backup_database_format', 'gz'), 'sql'); ?>>SQL</option>
                    <option value="gz" <?php selected(get_option('fg_backup_database_format', 'gz'), 'gz'); ?> <?php disabled(!FgBackup_Backup::supports_gzip()); ?>>SQL.GZ<?php echo !FgBackup_Backup::supports_gzip() ? ' – ' . esc_html__('nicht verfügbar', 'fg-backup-pro') : ''; ?></option>
                    <option value="zip" <?php selected(get_option('fg_backup_database_format', 'gz'), 'zip'); ?> <?php disabled(!FgBackup_Backup::supports_zip()); ?>>SQL.ZIP<?php echo !FgBackup_Backup::supports_zip() ? ' – ' . esc_html__('nicht verfügbar', 'fg-backup-pro') : ''; ?></option>
                </select>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="fg-backup-filename-pattern"><?php esc_html_e('Dateiname', 'fg-backup-pro'); ?></label></th>
            <td>
                <input type="text" name="fg_backup_filename_pattern" id="fg-backup-filename-pattern" class="regular-text code" value="<?php echo esc_attr(get_option('fg_backup_filename_pattern', FgBackup_Backup::default_filename_pattern())); ?>">
                <p class="description"><code>%Y %y %m %d %H %M %S %host %site %type %format %id</code></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="fg-backup-schedule"><?php esc_html_e('Zeitplan', 'fg-backup-pro'); ?></label></th>
            <td>
                <select name="fg_backup_schedule" id="fg-backup-schedule">
                    <option value="disabled" <?php selected(get_option('fg_backup_schedule', 'disabled'), 'disabled'); ?>><?php esc_html_e('Deaktiviert', 'fg-backup-pro'); ?></option>
                    <option value="daily" <?php selected(get_option('fg_backup_schedule', 'disabled'), 'daily'); ?>><?php esc_html_e('Täglich', 'fg-backup-pro'); ?></option>
                    <option value="weekly" <?php selected(get_option('fg_backup_schedule', 'disabled'), 'weekly'); ?>><?php esc_html_e('Wöchentlich', 'fg-backup-pro'); ?></option>
                    <option value="monthly" <?php selected(get_option('fg_backup_schedule', 'disabled'), 'monthly'); ?>><?php esc_html_e('Monatlich', 'fg-backup-pro'); ?></option>
                </select>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="fg-backup-hour"><?php esc_html_e('Startzeit', 'fg-backup-pro'); ?></label></th>
            <td>
                <select name="fg_backup_hour" id="fg-backup-hour">
                    <?php for ($hour = 0; $hour < 24; $hour++) : ?>
                        <option value="<?php echo esc_attr($hour); ?>" <?php selected((int) get_option('fg_backup_hour', 2), $hour); ?>>
                            <?php echo esc_html(sprintf('%02d:00', $hour)); ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="fg-backup-rotation"><?php esc_html_e('Aufbewahrung', 'fg-backup-pro'); ?></label></th>
            <td>
                <select name="fg_backup_rotation" id="fg-backup-rotation">
                    <?php foreach ([3, 5, 10, 20] as $count) : ?>
                        <option value="<?php echo esc_attr($count); ?>" <?php selected((int) get_option('fg_backup_rotation', 5), $count); ?>><?php echo esc_html($count); ?></option>
                    <?php endforeach; ?>
                </select>
                <span><?php esc_html_e('Backups', 'fg-backup-pro'); ?></span>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e('E-Mail', 'fg-backup-pro'); ?></th>
            <td>
                <label>
                    <input type="hidden" name="fg_backup_notifications" value="0">
                    <input type="checkbox" name="fg_backup_notifications" value="1" <?php checked((int) get_option('fg_backup_notifications', 0), 1); ?>>
                    <?php esc_html_e('Nach Erfolg oder Fehler benachrichtigen', 'fg-backup-pro'); ?>
                </label>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="fg-backup-exclusions"><?php esc_html_e('Zusätzliche Ausschlüsse', 'fg-backup-pro'); ?></label></th>
            <td>
                <textarea name="fg_backup_exclusions" id="fg-backup-exclusions" rows="5" class="large-text code"><?php echo esc_textarea(get_option('fg_backup_exclusions', '')); ?></textarea>
                <p class="description"><?php esc_html_e('Ein Pfadteil pro Zeile, zum Beispiel /wp-content/uploads/temp/.', 'fg-backup-pro'); ?></p>
            </td>
        </tr>
    </table>

    <?php submit_button(); ?>
</form>

<p class="description fg-backup-location">
    <?php esc_html_e('Speicherort:', 'fg-backup-pro'); ?>
    <code><?php echo esc_html(FgBackup_Storage::get_backup_dir()); ?></code>
</p>
