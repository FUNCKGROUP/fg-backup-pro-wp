<?php defined('ABSPATH') || exit; ?>

<div class="fg-backup-panel">
    <div class="fg-backup-run">
        <label for="fg-backup-type"><strong><?php esc_html_e('Neues Backup', 'fg-backup-pro'); ?></strong></label>
        <select id="fg-backup-type">
            <option value="full"><?php esc_html_e('Vollständig (Dateien und Datenbank)', 'fg-backup-pro'); ?></option>
            <option value="db"><?php esc_html_e('Nur Datenbank', 'fg-backup-pro'); ?></option>
        </select>
        <button type="button" class="button button-primary" id="fg-backup-start" <?php disabled((bool) $active_job); ?>>
            <?php esc_html_e('Backup erstellen', 'fg-backup-pro'); ?>
        </button>
    </div>

    <div id="fg-backup-status" class="fg-backup-status" <?php echo $active_job ? '' : 'hidden'; ?>
        <div class="fg-backup-progress"><span style="width: <?php echo $active_job ? esc_attr((int) $active_job['progress']) : 0; ?>%"></span></div>
        <p><?php echo $active_job ? esc_html__('Ein Backup läuft bereits.', 'fg-backup-pro') : ''; ?></p>
    </div>
</div>

<h2><?php esc_html_e('Lokale Backups', 'fg-backup-pro'); ?></h2>

<?php if (!$backups) : ?>
    <p><?php esc_html_e('Noch keine Backups vorhanden.', 'fg-backup-pro'); ?></p>
<?php else : ?>
    <table class="widefat striped fg-backup-table">
        <thead>
        <tr>
            <th><?php esc_html_e('Datei', 'fg-backup-pro'); ?></th>
            <th><?php esc_html_e('Inhalt', 'fg-backup-pro'); ?></th>
            <th><?php esc_html_e('Größe', 'fg-backup-pro'); ?></th>
            <th><?php esc_html_e('Erstellt', 'fg-backup-pro'); ?></th>
            <th><?php esc_html_e('Aktion', 'fg-backup-pro'); ?></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($backups as $backup) :
            $download_url = wp_nonce_url(
                add_query_arg([
                    'action' => 'fg_backup_download',
                    'file' => $backup['name'],
                ], admin_url('admin-post.php')),
                'fg_backup_download_' . $backup['name']
            );
            $delete_url = wp_nonce_url(
                add_query_arg([
                    'action' => 'fg_backup_delete',
                    'file' => $backup['name'],
                ], admin_url('admin-post.php')),
                'fg_backup_delete_' . $backup['name']
            );
            ?>
            <tr>
                <td>
                    <strong><?php echo esc_html($backup['name']); ?></strong>
                    <?php if ($backup['checksum']) : ?>
                        <code class="fg-backup-checksum" title="SHA-256"><?php echo esc_html(substr($backup['checksum'], 0, 16)); ?>…</code>
                    <?php endif; ?>
                </td>
                <td><?php echo $backup['type'] === 'full' ? esc_html__('Dateien + Datenbank', 'fg-backup-pro') : esc_html__('Datenbank', 'fg-backup-pro'); ?></td>
                <td><?php echo esc_html($backup['size']); ?></td>
                <td><?php echo esc_html($backup['date']); ?></td>
                <td>
                    <a href="<?php echo esc_url($download_url); ?>"><?php esc_html_e('Herunterladen', 'fg-backup-pro'); ?></a>
                    <span aria-hidden="true"> · </span>
                    <a href="<?php echo esc_url($delete_url); ?>" class="fg-backup-delete"><?php esc_html_e('Löschen', 'fg-backup-pro'); ?></a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php if (!empty($history)) : ?>
    <h2><?php esc_html_e('Letzte Läufe', 'fg-backup-pro'); ?></h2>
    <table class="widefat striped fg-backup-table">
        <thead>
        <tr>
            <th><?php esc_html_e('Status', 'fg-backup-pro'); ?></th>
            <th><?php esc_html_e('Typ', 'fg-backup-pro'); ?></th>
            <th><?php esc_html_e('Start', 'fg-backup-pro'); ?></th>
            <th><?php esc_html_e('Dauer', 'fg-backup-pro'); ?></th>
            <th><?php esc_html_e('Ergebnis', 'fg-backup-pro'); ?></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach (array_slice((array) $history, 0, 10) as $entry) :
            $duration = max(0, (int) $entry['finished_at'] - (int) $entry['started_at']);
            ?>
            <tr>
                <td><?php echo $entry['status'] === 'completed' ? esc_html__('Erfolgreich', 'fg-backup-pro') : esc_html__('Fehlgeschlagen', 'fg-backup-pro'); ?></td>
                <td><?php echo $entry['type'] === 'full' ? esc_html__('Vollständig', 'fg-backup-pro') : esc_html__('Datenbank', 'fg-backup-pro'); ?></td>
                <td><?php echo esc_html(wp_date('d.m.Y H:i', (int) $entry['started_at'])); ?></td>
                <td><?php echo esc_html(human_time_diff((int) $entry['started_at'], (int) $entry['finished_at'])); ?></td>
                <td><?php echo !empty($entry['error']) ? esc_html($entry['error']) : esc_html($entry['file']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
