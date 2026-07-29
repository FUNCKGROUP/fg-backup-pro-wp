<?php defined('ABSPATH') || exit; ?>

<div class="fg-backup-panel">
    <div class="fg-backup-run">
        <label for="fg-backup-type"><strong><?php esc_html_e('Neues Backup', 'fg-backup-pro'); ?></strong></label>
        <select id="fg-backup-type">
            <option value="full"><?php esc_html_e('Vollständig', 'fg-backup-pro'); ?></option>
            <option value="db"><?php esc_html_e('Nur Datenbank', 'fg-backup-pro'); ?></option>
        </select>
        <button type="button" class="button button-primary" id="fg-backup-start" <?php disabled((bool) $active_job); ?>>
            <?php esc_html_e('Backup erstellen', 'fg-backup-pro'); ?>
        </button>
        <button type="button" class="button" id="fg-backup-cancel" <?php echo $active_job ? '' : 'hidden'; ?>>
            <?php esc_html_e('Abbrechen', 'fg-backup-pro'); ?>
        </button>
    </div>

    <div id="fg-backup-status" class="fg-backup-status" <?php echo $active_job ? '' : 'hidden'; ?>>
        <div class="fg-backup-progress"><span style="width: <?php echo $active_job ? esc_attr((int) $active_job['progress']) : 0; ?>%"></span></div>
        <div class="fg-backup-status-line">
            <strong class="fg-backup-stage"><?php echo $active_job && !empty($active_job['stage']) ? esc_html($active_job['stage']) : ''; ?></strong>
            <span class="fg-backup-percent"><?php echo $active_job ? esc_html((int) $active_job['progress'] . ' %') : ''; ?></span>
        </div>
        <p class="fg-backup-detail"><?php echo $active_job && !empty($active_job['detail']) ? esc_html($active_job['detail']) : ''; ?></p>
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
            <th><?php esc_html_e('Format', 'fg-backup-pro'); ?></th>
            <th><?php esc_html_e('Status', 'fg-backup-pro'); ?></th>
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
                </td>
                <td><?php echo $backup['type'] === 'full' ? esc_html__('Dateien + Datenbank', 'fg-backup-pro') : esc_html__('Datenbank', 'fg-backup-pro'); ?></td>
                <td><?php echo esc_html($backup['format_label']); ?></td>
                <td><?php echo $backup['verified'] ? esc_html__('Abgeschlossen', 'fg-backup-pro') : esc_html__('Vorhanden', 'fg-backup-pro'); ?></td>
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
            <th><?php esc_html_e('Format', 'fg-backup-pro'); ?></th>
            <th><?php esc_html_e('Start', 'fg-backup-pro'); ?></th>
            <th><?php esc_html_e('Dauer', 'fg-backup-pro'); ?></th>
            <th><?php esc_html_e('Ergebnis', 'fg-backup-pro'); ?></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach (array_slice((array) $history, 0, 10) as $entry) :
            $duration = max(0, (int) $entry['finished_at'] - (int) $entry['started_at']);
            $entry_type = isset($entry['type']) ? $entry['type'] : 'full';
            $entry_format = isset($entry['format']) && $entry['format'] !== '' ? $entry['format'] : ($entry_type === 'full' ? 'zip' : 'sql');
            ?>
            <tr>
                <td>
                    <?php
                    if ($entry['status'] === 'completed') {
                        esc_html_e('Abgeschlossen', 'fg-backup-pro');
                    } elseif ($entry['status'] === 'canceled') {
                        esc_html_e('Abgebrochen', 'fg-backup-pro');
                    } else {
                        esc_html_e('Fehlgeschlagen', 'fg-backup-pro');
                    }
                    ?>
                </td>
                <td><?php echo $entry_type === 'full' ? esc_html__('Vollständig', 'fg-backup-pro') : esc_html__('Datenbank', 'fg-backup-pro'); ?></td>
                <td><?php echo esc_html(FgBackup_Backup::format_label($entry_type, $entry_format)); ?></td>
                <td><?php echo esc_html(wp_date('d.m.Y H:i', (int) $entry['started_at'])); ?></td>
                <td><?php echo esc_html(human_time_diff((int) $entry['started_at'], (int) $entry['finished_at'])); ?></td>
                <td>
                    <?php
                    if ($entry['status'] === 'canceled') {
                        esc_html_e('Vom Benutzer abgebrochen', 'fg-backup-pro');
                    } else {
                        echo !empty($entry['error']) ? esc_html($entry['error']) : esc_html($entry['file']);
                    }
                    ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
