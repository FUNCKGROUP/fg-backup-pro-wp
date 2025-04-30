<?php
$backups = FgBackup_Backup::list_backups();
$message = isset($_GET['backup']) && $_GET['backup'] === 'success' ? 'Backup wurde erfolgreich erstellt.' : '';
?>

<div class="wrap">
    <h1>Backup Management</h1>

    <?php if ($message): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html($message); ?></p>
        </div>
    <?php endif; ?>

    <form method="post" id="fg-backup-form">
        <?php wp_nonce_field('fg_backup_nonce'); ?>
        <input type="hidden" name="action" value="fg_backup_start_async">

        <select name="backup_type">
            <option value="full">Volles Backup (Dateien + DB)</option>
            <option value="db">Nur Datenbank-Backup</option>
        </select><br><br>

        <label>
            <input type="checkbox" name="send_email" value="1">
            DB per E-Mail schicken
        </label><br><br>

        <button type="submit" class="button button-primary">Backup starten</button>
    </form>

    <h2>Vorhandene Backups</h2>

    <?php if (!empty($backups)): ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Datei</th>
                    <th>Datum</th>
                    <th>Größe</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($backups as $backup): ?>
                    <tr>
                        <td><?= esc_html($backup['name']) ?></td>
                        <td><?= esc_html($backup['date']) ?></td>
                        <td><?= esc_html($backup['size']) ?></td>
                        <td>
                            <a href="<?= content_url('/backups/' . urlencode($backup['name'])); ?>" class="button button-small">Download</a>
                            <a href="<?= admin_url('admin-post.php?action=fg_delete_backup&file=' . urlencode($backup['name'])); ?>"
                               onclick="return confirm('Backup wirklich löschen?')"
                               class="button button-small button-link-delete">Löschen</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>Keine lokalen Backups gefunden.</p>
    <?php endif; ?>

    <div id="backup-status" class="fg-backup-status"></div>
</div>