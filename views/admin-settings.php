<?php

defined('ABSPATH') || exit;

FgBackup_Admin::render_settings_notices('fg_backup_settings');

$filename_pattern = get_option('fg_backup_filename_pattern', FgBackup_Backup::default_filename_pattern());
$preview_type = get_option('fg_backup_type', 'full') === 'db' ? 'db' : 'full';
$preview_format = $preview_type === 'full'
    ? get_option('fg_backup_archive_format', 'zip')
    : get_option('fg_backup_database_format', 'gz');
$preview_filename = FgBackup_Backup::build_filename(
    $filename_pattern,
    $preview_type,
    $preview_format,
    'backup_demo1234',
    time()
);
?>

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
                <div class="fg-backup-filename-field">
                    <input type="text" name="fg_backup_filename_pattern" id="fg-backup-filename-pattern" class="regular-text code" value="<?php echo esc_attr($filename_pattern); ?>" autocomplete="off">

                    <span class="fg-backup-filename-example">
                        <span><?php esc_html_e('Beispiel:', 'fg-backup-pro'); ?></span>
                        <code id="fg-backup-filename-preview" aria-live="polite"><?php echo esc_html($preview_filename); ?></code>
                    </span>

                    <div class="fg-backup-help-wrap">
                        <button
                            type="button"
                            class="fg-backup-help-button"
                            id="fg-backup-filename-help"
                            aria-label="<?php esc_attr_e('Platzhalter anzeigen', 'fg-backup-pro'); ?>"
                            aria-controls="fg-backup-filename-popover"
                            aria-expanded="false"
                        >?</button>

                        <div class="fg-backup-filename-popover" id="fg-backup-filename-popover" role="dialog" aria-label="<?php esc_attr_e('Platzhalter für Dateinamen', 'fg-backup-pro'); ?>" hidden>
                            <dl>
                                <dt><code>%Y</code></dt><dd><?php esc_html_e('Jahr, vierstellig', 'fg-backup-pro'); ?></dd>
                                <dt><code>%y</code></dt><dd><?php esc_html_e('Jahr, zweistellig', 'fg-backup-pro'); ?></dd>
                                <dt><code>%m</code></dt><dd><?php esc_html_e('Monat', 'fg-backup-pro'); ?></dd>
                                <dt><code>%d</code></dt><dd><?php esc_html_e('Tag', 'fg-backup-pro'); ?></dd>
                                <dt><code>%H</code></dt><dd><?php esc_html_e('Stunde', 'fg-backup-pro'); ?></dd>
                                <dt><code>%M</code></dt><dd><?php esc_html_e('Minute', 'fg-backup-pro'); ?></dd>
                                <dt><code>%S</code></dt><dd><?php esc_html_e('Sekunde', 'fg-backup-pro'); ?></dd>
                                <dt><code>%host</code></dt><dd><?php esc_html_e('Domain', 'fg-backup-pro'); ?></dd>
                                <dt><code>%site</code></dt><dd><?php esc_html_e('Website-Name', 'fg-backup-pro'); ?></dd>
                                <dt><code>%type</code></dt><dd><?php esc_html_e('Backup-Typ', 'fg-backup-pro'); ?></dd>
                                <dt><code>%format</code></dt><dd><?php esc_html_e('Dateiformat', 'fg-backup-pro'); ?></dd>
                                <dt><code>%id</code></dt><dd><?php esc_html_e('Kurze Job-ID', 'fg-backup-pro'); ?></dd>
                            </dl>
                            <span class="fg-backup-popover-note"><?php esc_html_e('Die passende Dateiendung wird automatisch ergänzt.', 'fg-backup-pro'); ?></span>
                        </div>
                    </div>
                </div>
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
