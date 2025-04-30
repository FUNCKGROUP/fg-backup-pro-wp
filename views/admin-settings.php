<h2>FG Backup Pro – Einstellungen</h2>

<form method="post" action="options.php">
    <?php settings_fields('fg_backup_settings_group'); ?>
    <?php do_settings_sections('fg-backup-pro-settings-page'); ?>

    <div class="wrap">

        <h3 class="section-title">Allgemein</h3>
        <table class="form-table">
            <tr valign="top">
                <th scope="row">Backup-Typ</th>
                <td>
                    <select name="fg_backup_type">
                        <option value="full" <?= selected(get_option('fg_backup_type'), 'full') ?>>Volles Backup (Dateien + DB)</option>
                        <option value="db" <?= selected(get_option('fg_backup_type'), 'db') ?>>Nur Datenbank</option>
                    </select>
                </td>
            </tr>
            <tr valign="top">
                <th scope="row">Planung</th>
                <td>
                    <select name="fg_backup_schedule">
                        <option value="daily" <?= selected(get_option('fg_backup_schedule'), 'daily') ?>>Täglich</option>
                        <option value="weekly" <?= selected(get_option('fg_backup_schedule'), 'weekly') ?>>Wöchentlich</option>
                        <option value="monthly" <?= selected(get_option('fg_backup_schedule'), 'monthly') ?>>Monatlich</option>
                    </select>
                </td>
            </tr>
            <tr valign="top">
                <th scope="row">Maximale Backups (Rotation)</th>
                <td>
                    <select name="fg_backup_rotation">
                        <option value="3" <?= selected(get_option('fg_backup_rotation'), '3') ?>>3</option>
                        <option value="5" <?= selected(get_option('fg_backup_rotation'), '5') ?>>5</option>
                        <option value="10" <?= selected(get_option('fg_backup_rotation'), '10') ?>>10</option>
                        <option value="20" <?= selected(get_option('fg_backup_rotation'), '20') ?>>20</option>
                    </select>
                </td>
            </tr>
        </table>

        <h3 class="section-title">Backup-Ziele</h3>
        <table class="form-table">
            <tr valign="top">
                <th scope="row">Zielort(e)</th>
                <td>
                    <label><input type="checkbox" name="fg_backup_targets[]" value="local" <?= checked(in_array('local', (array)get_option('fg_backup_targets')), true, false) ?>> Lokal</label><br>
                    <label><input type="checkbox" name="fg_backup_targets[]" value="dropbox" <?= checked(in_array('dropbox', (array)get_option('fg_backup_targets')), true, false) ?>> Dropbox</label><br>
                    <label><input type="checkbox" name="fg_backup_targets[]" value="google_drive" <?= checked(in_array('google_drive', (array)get_option('fg_backup_targets')), true, false) ?>> Google Drive</label><br>
                    <label><input type="checkbox" name="fg_backup_targets[]" value="s3" <?= checked(in_array('s3', (array)get_option('fg_backup_targets')), true, false) ?>> Amazon S3</label><br>
                    <label><input type="checkbox" name="fg_backup_targets[]" value="s3_compatible" <?= checked(in_array('s3_compatible', (array)get_option('fg_backup_targets')), true, false) ?>> S3-kompatibel</label><br>
                    <label><input type="checkbox" name="fg_backup_targets[]" value="ftp" <?= checked(in_array('ftp', (array)get_option('fg_backup_targets')), true, false) ?>> SFTP</label><br>
                    <label><input type="checkbox" name="fg_backup_targets[]" value="webdav" <?= checked(in_array('webdav', (array)get_option('fg_backup_targets')), true, false) ?>> WebDAV</label><br>
                    <label><input type="checkbox" name="fg_backup_targets[]" value="onedrive" <?= checked(in_array('onedrive', (array)get_option('fg_backup_targets')), true, false) ?>> OneDrive</label>
                </td>
            </tr>
        </table>

        <h3 class="section-title">Dropbox</h3>
        <table class="form-table">
            <tr valign="top">
                <th scope="row">Access Token</th>
                <td><input type="text" name="fg_backup_dropbox_token" value="<?= esc_attr(get_option('fg_backup_dropbox_token')) ?>" style="width: 100%" /></td>
            </tr>
        </table>

        <h3 class="section-title">Google Drive</h3>
        <table class="form-table">
            <tr valign="top">
                <th scope="row">Access Token</th>
                <td><input type="text" name="fg_backup_gdrive_token" value="<?= esc_attr(get_option('fg_backup_gdrive_token')) ?>" style="width: 100%" /></td>
            </tr>
        </table>

        <h3 class="section-title">Amazon S3</h3>
        <table class="form-table">
            <tr valign="top">
                <th scope="row">Access Key</th>
                <td><input type="text" name="fg_backup_s3_key" value="<?= esc_attr(get_option('fg_backup_s3_key')) ?>" style="width: 100%" /></td>
            </tr>
            <tr valign="top">
                <th scope="row">Secret Key</th>
                <td><input type="text" name="fg_backup_s3_secret" value="<?= esc_attr(get_option('fg_backup_s3_secret')) ?>" style="width: 100%" /></td>
            </tr>
            <tr valign="top">
                <th scope="row">Region / Bucket</th>
                <td>
                    Region: <input type="text" name="fg_backup_s3_region" value="<?= esc_attr(get_option('fg_backup_s3_region', 'eu-west-1')) ?>" style="width: 48%" />
                    Bucket: <input type="text" name="fg_backup_s3_bucket" value="<?= esc_attr(get_option('fg_backup_s3_bucket')) ?>" style="width: 48%" />
                </td>
            </tr>
            <tr valign="top">
                <th scope="row">Präfix (optional)</th>
                <td><input type="text" name="fg_backup_s3_prefix" value="<?= esc_attr(get_option('fg_backup_s3_prefix')) ?>" style="width: 100%" /></td>
            </tr>
        </table>

        <h3 class="section-title">S3-kompatibel</h3>
        <table class="form-table">
            <tr valign="top">
                <th scope="row">Access Key</th>
                <td><input type="text" name="fg_backup_s3c_key" value="<?= esc_attr(get_option('fg_backup_s3c_key')) ?>" style="width: 100%" /></td>
            </tr>
            <tr valign="top">
                <th scope="row">Secret Key</th>
                <td><input type="text" name="fg_backup_s3c_secret" value="<?= esc_attr(get_option('fg_backup_s3c_secret')) ?>" style="width: 100%" /></td>
            </tr>
            <tr valign="top">
                <th scope="row">Endpoint / Bucket</th>
                <td>
                    Endpoint: <input type="url" name="fg_backup_s3c_endpoint" value="<?= esc_attr(get_option('fg_backup_s3c_endpoint')) ?>" style="width: 48%" />
                    Bucket: <input type="text" name="fg_backup_s3c_bucket" value="<?= esc_attr(get_option('fg_backup_s3c_bucket')) ?>" style="width: 48%" />
                </td>
            </tr>
            <tr valign="top">
                <th scope="row">Präfix (optional)</th>
                <td><input type="text" name="fg_backup_s3c_prefix" value="<?= esc_attr(get_option('fg_backup_s3c_prefix')) ?>" style="width: 100%" /></td>
            </tr>
        </table>

        <h3 class="section-title">SFTP</h3>
        <table class="form-table">
            <tr valign="top">
                <th scope="row">Host / Port</th>
                <td>
                    Host: <input type="text" name="fg_backup_ftp_host" value="<?= esc_attr(get_option('fg_backup_ftp_host')) ?>" style="width: 48%" />
                    Port: <input type="number" name="fg_backup_ftp_port" value="<?= esc_attr(get_option('fg_backup_ftp_port', '22')) ?>" style="width: 20%" />
                </td>
            </tr>
            <tr valign="top">
                <th scope="row">Benutzer / Passwort</th>
                <td>
                    Benutzer: <input type="text" name="fg_backup_ftp_user" value="<?= esc_attr(get_option('fg_backup_ftp_user')) ?>" style="width: 48%" />
                    Passwort: <input type="password" name="fg_backup_ftp_pass" value="<?= esc_attr(get_option('fg_backup_ftp_pass')) ?>" style="width: 48%" />
                </td>
            </tr>
            <tr valign="top">
                <th scope="row">Zielordner</th>
                <td><input type="text" name="fg_backup_ftp_dir" value="<?= esc_attr(get_option('fg_backup_ftp_dir', '/')) ?>" style="width: 100%" /></td>
            </tr>
        </table>

        <h3 class="section-title">WebDAV (Nextcloud / ownCloud)</h3>
        <table class="form-table">
            <tr valign="top">
                <th scope="row">URL / Benutzer</th>
                <td>
                    URL: <input type="url" name="fg_backup_webdav_url" value="<?= esc_attr(get_option('fg_backup_webdav_url')) ?>" style="width: 48%" />
                    Benutzer: <input type="text" name="fg_backup_webdav_user" value="<?= esc_attr(get_option('fg_backup_webdav_user')) ?>" style="width: 48%" />
                </td>
            </tr>
            <tr valign="top">
                <th scope="row">Passwort</th>
                <td><input type="password" name="fg_backup_webdav_pass" value="<?= esc_attr(get_option('fg_backup_webdav_pass')) ?>" style="width: 100%" /></td>
            </tr>
        </table>

        <h3 class="section-title">OneDrive</h3>
        <table class="form-table">
            <tr valign="top">
                <th scope="row">Access Token</th>
                <td><input type="text" name="fg_backup_onedrive_token" value="<?= esc_attr(get_option('fg_backup_onedrive_token')) ?>" style="width: 100%" /></td>
            </tr>
        </table>

        <p class="submit">
            <input type="submit" class="button button-primary" value="Speichern" />
        </p>
    </div>
</form>