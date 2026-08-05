<?php defined('ABSPATH') || exit; ?>

<?php
$health_status = isset($health['status']) ? sanitize_key($health['status']) : 'unknown';
$health_checks = isset($health['checks']) && is_array($health['checks']) ? $health['checks'] : [];
$health_generated = !empty($health['generated_at']) ? (int) $health['generated_at'] : 0;
$deleted_count = isset($_GET['fg_backup_deleted']) ? max(0, (int) $_GET['fg_backup_deleted']) : 0;
$delete_failed = isset($_GET['fg_backup_delete_failed']) ? max(0, (int) $_GET['fg_backup_delete_failed']) : 0;
?>

<?php if ($deleted_count > 0 || $delete_failed > 0) : ?>
    <div class="notice <?php echo $delete_failed > 0 ? 'notice-warning' : 'notice-success'; ?> is-dismissible">
        <p>
            <?php
            if ($deleted_count > 0) {
                echo esc_html(sprintf(_n('%d Backup wurde gelöscht.', '%d Backups wurden gelöscht.', $deleted_count, 'fg-backup-pro'), $deleted_count));
            }
            if ($delete_failed > 0) {
                echo $deleted_count > 0 ? ' ' : '';
                echo esc_html(sprintf(_n('%d Backup konnte nicht gelöscht werden.', '%d Backups konnten nicht gelöscht werden.', $delete_failed, 'fg-backup-pro'), $delete_failed));
            }
            ?>
        </p>
    </div>
<?php endif; ?>

<div id="fg-backup-health" class="fg-backup-health fg-backup-health--<?php echo esc_attr($health_status); ?>">
    <div class="fg-backup-health-header">
        <div>
            <span class="fg-backup-health-kicker"><?php esc_html_e('Backup-Gesundheit', 'fg-backup-pro'); ?></span>
            <h2 class="fg-backup-health-status-label"><?php echo esc_html(FgBackup_Health::status_label($health_status)); ?></h2>
            <p class="fg-backup-health-summary"><?php echo esc_html(!empty($health['summary']) ? $health['summary'] : __('Der Backup-Status wurde noch nicht geprüft.', 'fg-backup-pro')); ?></p>
        </div>
        <div class="fg-backup-health-actions">
            <?php if ($health_generated) : ?>
                <span id="fg-backup-health-generated"><?php echo esc_html(sprintf(__('Stand: %s', 'fg-backup-pro'), wp_date('d.m.Y H:i', $health_generated))); ?></span>
            <?php endif; ?>
            <button type="button" class="button" id="fg-backup-health-check">
                <?php esc_html_e('Jetzt prüfen', 'fg-backup-pro'); ?>
            </button>
            <span class="fg-backup-inline-result" id="fg-backup-health-result" aria-live="polite"></span>
        </div>
    </div>

    <?php if ($health_checks) : ?>
        <div class="fg-backup-health-grid">
            <?php foreach ($health_checks as $check_key => $check) :
                $check_status = isset($check['status']) ? sanitize_key($check['status']) : 'unknown';
                ?>
                <div class="fg-backup-health-check fg-backup-health-check--<?php echo esc_attr($check_status); ?>" data-check-key="<?php echo esc_attr(sanitize_key((string) $check_key)); ?>">
                    <div class="fg-backup-health-check-title">
                        <strong><?php echo esc_html(isset($check['label']) ? $check['label'] : __('Backup', 'fg-backup-pro')); ?></strong>
                        <span class="fg-backup-health-check-status"><?php echo esc_html(FgBackup_Health::status_label($check_status)); ?></span>
                    </div>
                    <p class="fg-backup-health-check-detail"><?php echo esc_html(isset($check['detail']) ? $check['detail'] : ''); ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="fg-backup-panel">
    <div class="fg-backup-run">
        <label for="fg-backup-type"><strong><?php esc_html_e('Neues Backup', 'fg-backup-pro'); ?></strong></label>
        <select id="fg-backup-type" <?php disabled((bool) $active_job); ?>>
            <option value="full"><?php esc_html_e('Vollständig', 'fg-backup-pro'); ?></option>
            <option value="db"><?php esc_html_e('Nur Datenbank', 'fg-backup-pro'); ?></option>
        </select>
        <label for="fg-backup-note" class="screen-reader-text"><?php esc_html_e('Backup-Notiz', 'fg-backup-pro'); ?></label>
        <input type="text" id="fg-backup-note" class="regular-text" maxlength="160"
               placeholder="<?php esc_attr_e('Notiz, z. B. vor PHP-Update', 'fg-backup-pro'); ?>"
               <?php disabled((bool) $active_job); ?>>
        <button type="button" class="button button-primary" id="fg-backup-start" <?php disabled((bool) $active_job); ?>>
            <?php esc_html_e('Backup erstellen', 'fg-backup-pro'); ?>
        </button>
        <button type="button" class="button" id="fg-backup-cancel" <?php echo $active_job ? '' : 'hidden'; ?>>
            <?php esc_html_e('Abbrechen', 'fg-backup-pro'); ?>
        </button>
    </div>

    <div class="fg-backup-space-estimate" id="fg-backup-space-estimate"
         data-full-required="<?php echo esc_attr(size_format((int) $full_space['required'], 1)); ?>"
         data-db-required="<?php echo esc_attr(size_format((int) $db_space['required'], 1)); ?>"
         data-available="<?php echo esc_attr($full_space['available'] > 0 ? size_format((int) $full_space['available'], 1) : __('nicht ermittelbar', 'fg-backup-pro')); ?>">
        <span class="fg-backup-space-label"><?php esc_html_e('Speicherbedarf vor dem Start', 'fg-backup-pro'); ?></span>
        <strong class="fg-backup-space-text"><?php echo esc_html(sprintf(
            __('Vollständiges Backup: mindestens %1$s temporärer Speicher; nach dem Dateiscan erfolgt eine genauere Prüfung.', 'fg-backup-pro'),
            size_format((int) $full_space['required'], 1)
        )); ?></strong>
        <span class="fg-backup-space-available"><?php echo esc_html(sprintf(
            __('Frei: %s', 'fg-backup-pro'),
            $full_space['available'] > 0 ? size_format((int) $full_space['available'], 1) : __('nicht ermittelbar', 'fg-backup-pro')
        )); ?></span>
    </div>

    <div id="fg-backup-status" class="fg-backup-status" <?php echo $active_job ? '' : 'hidden'; ?>>
        <div class="fg-backup-progress"><span style="width: <?php echo $active_job ? esc_attr((int) $active_job['progress']) : 0; ?>%"></span></div>
        <div class="fg-backup-status-line">
            <strong class="fg-backup-stage"><?php echo $active_job && !empty($active_job['stage']) ? esc_html($active_job['stage']) : ''; ?></strong>
            <span class="fg-backup-percent"><?php echo $active_job ? esc_html((int) $active_job['progress'] . ' %') : ''; ?></span>
        </div>
        <p class="fg-backup-detail"><?php echo $active_job && !empty($active_job['detail']) ? esc_html($active_job['detail']) : ''; ?></p>
        <div class="fg-backup-runtime-meta">
            <span class="fg-backup-last-activity">
                <?php echo $active_job && !empty($active_job['updated_at'])
                    ? esc_html(sprintf(__('Letzte Aktivität: %s', 'fg-backup-pro'), wp_date('d.m.Y H:i:s', (int) $active_job['updated_at'])))
                    : ''; ?>
            </span>
            <span class="fg-backup-execution-mode">
                <?php echo $active_job && !empty($active_job['execution_mode'])
                    ? esc_html($active_job['execution_mode'] === 'cli' ? __('PHP-CLI-Worker', 'fg-backup-pro') : __('HTTP-/WP-Cron-Fallback', 'fg-backup-pro'))
                    : ''; ?>
            </span>
            <button type="button" class="button-link" id="fg-backup-cleanup-job" hidden><?php esc_html_e('Temporäre Jobdaten aufräumen', 'fg-backup-pro'); ?></button>
        </div>
    </div>
</div>

<h2><?php esc_html_e('Lokale Backups', 'fg-backup-pro'); ?></h2>

<?php if (!$backups) : ?>
    <p><?php esc_html_e('Noch keine Backups vorhanden.', 'fg-backup-pro'); ?></p>
<?php else : ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="fg-backup-bulk-form">
        <input type="hidden" name="action" value="fg_backup_bulk_delete">
        <?php wp_nonce_field('fg_backup_bulk_delete'); ?>

        <div class="fg-backup-bulk-actions">
            <button type="button" class="button" id="fg-backup-bulk-validate" disabled>
                <?php esc_html_e('Ausgewählte validieren', 'fg-backup-pro'); ?>
            </button>
            <button type="submit" class="button" id="fg-backup-bulk-delete" disabled>
                <?php esc_html_e('Ausgewählte löschen', 'fg-backup-pro'); ?>
            </button>
            <span id="fg-backup-selection-count" aria-live="polite"></span>
            <span id="fg-backup-validation-result" class="fg-backup-inline-result" aria-live="polite"></span>
        </div>

        <table class="widefat striped fg-backup-table fg-backup-local-table">
            <thead>
            <tr>
                <td class="manage-column check-column">
                    <input type="checkbox" id="fg-backup-select-all" aria-label="<?php esc_attr_e('Alle Backups auswählen', 'fg-backup-pro'); ?>">
                </td>
                <th><?php esc_html_e('Datei', 'fg-backup-pro'); ?></th>
                <th><?php esc_html_e('Inhalt', 'fg-backup-pro'); ?></th>
                <th><?php esc_html_e('Format', 'fg-backup-pro'); ?></th>
                <th><?php esc_html_e('Validierung', 'fg-backup-pro'); ?></th>
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
                $manifest_url = wp_nonce_url(
                    add_query_arg([
                        'action' => 'fg_backup_manifest',
                        'file' => $backup['name'],
                    ], admin_url('admin-post.php')),
                    'fg_backup_manifest_' . $backup['name']
                );
                ?>
                <tr data-backup-file="<?php echo esc_attr($backup['name']); ?>">
                    <th scope="row" class="check-column">
                        <input type="checkbox" class="fg-backup-select" name="backups[]" value="<?php echo esc_attr($backup['name']); ?>" aria-label="<?php echo esc_attr(sprintf(__('Backup %s auswählen', 'fg-backup-pro'), $backup['name'])); ?>">
                    </th>
                    <td>
                        <strong><?php echo esc_html($backup['name']); ?></strong>
                        <?php if (!empty($backup['note'])) : ?>
                            <span class="fg-backup-note"><?php echo esc_html($backup['note']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $backup['type'] === 'full' ? esc_html__('Dateien + Datenbank', 'fg-backup-pro') : esc_html__('Datenbank', 'fg-backup-pro'); ?></td>
                    <td><?php echo esc_html($backup['format_label']); ?></td>
                    <td class="fg-backup-validation-cell">
                        <span class="fg-backup-validation-badge fg-backup-validation-badge--<?php echo esc_attr($backup['validation_status']); ?>">
                            <?php echo esc_html(FgBackup_Validator::status_label($backup['validation_status'])); ?>
                        </span>
                        <?php if (!empty($backup['validated_at'])) : ?>
                            <span class="fg-backup-validation-time"><?php echo esc_html(wp_date('d.m.Y H:i', (int) $backup['validated_at'])); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($backup['size']); ?></td>
                    <td><?php echo esc_html($backup['date']); ?></td>
                    <td class="fg-backup-row-actions">
                        <a href="<?php echo esc_url($download_url); ?>"><?php esc_html_e('Herunterladen', 'fg-backup-pro'); ?></a>
                        <span aria-hidden="true"> · </span>
                        <button type="button" class="button-link fg-backup-validate" data-file="<?php echo esc_attr($backup['name']); ?>"><?php esc_html_e('Validieren', 'fg-backup-pro'); ?></button>
                        <span aria-hidden="true"> · </span>
                        <button type="button" class="button-link fg-backup-report" data-file="<?php echo esc_attr($backup['name']); ?>" <?php disabled(empty($backup['manifest_exists'])); ?>><?php esc_html_e('Prüfbericht', 'fg-backup-pro'); ?></button>
                        <span aria-hidden="true"> · </span>
                        <a class="fg-backup-manifest-link" href="<?php echo esc_url($manifest_url); ?>" <?php echo empty($backup['manifest_exists']) ? 'hidden' : ''; ?>><?php esc_html_e('JSON', 'fg-backup-pro'); ?></a>
                        <span aria-hidden="true"> · </span>
                        <a href="<?php echo esc_url($delete_url); ?>" class="fg-backup-delete"><?php esc_html_e('Löschen', 'fg-backup-pro'); ?></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </form>
<?php endif; ?>


<div id="fg-backup-report-modal" class="fg-backup-report-modal" hidden>
    <div class="fg-backup-report-dialog" role="dialog" aria-modal="true" aria-labelledby="fg-backup-report-title">
        <button type="button" class="fg-backup-report-close" aria-label="<?php esc_attr_e('Prüfbericht schließen', 'fg-backup-pro'); ?>">×</button>
        <h2 id="fg-backup-report-title"><?php esc_html_e('Backup-Prüfbericht', 'fg-backup-pro'); ?></h2>
        <div id="fg-backup-report-content"></div>
    </div>
</div>

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
                    } elseif ($entry['status'] === 'completed_with_errors') {
                        esc_html_e('Mit Fehlern', 'fg-backup-pro');
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
                    $remote_results = !empty($entry['remote_results']) && is_array($entry['remote_results'])
                        ? $entry['remote_results']
                        : [];
                    $remote_summary = FgBackup_Remotes::summarize($remote_results);

                    if ($entry['status'] === 'canceled') {
                        if (!empty($entry['file'])) {
                            echo esc_html(sprintf(__('Remote-Upload abgebrochen; lokale Datei vorhanden: %s', 'fg-backup-pro'), $entry['file']));
                        } else {
                            esc_html_e('Vom Benutzer abgebrochen', 'fg-backup-pro');
                        }
                    } elseif ($entry['status'] === 'failed') {
                        echo esc_html(!empty($entry['error']) ? $entry['error'] : __('Backup fehlgeschlagen.', 'fg-backup-pro'));
                        if (!empty($entry['file'])) {
                            echo '<span class="fg-backup-note">' . esc_html(sprintf(__('Lokale Datei vorhanden: %s', 'fg-backup-pro'), $entry['file'])) . '</span>';
                        }
                    } else {
                        if (!empty($entry['file'])) {
                            echo esc_html($entry['file']);
                        } elseif (!empty($entry['local_deleted'])) {
                            esc_html_e('Lokale Datei nach erfolgreichen Remote-Uploads gelöscht', 'fg-backup-pro');
                        }
                        if ($remote_summary !== '') {
                            echo '<span class="fg-backup-note">' . esc_html($remote_summary) . '</span>';
                        }
                        if (!empty($entry['remote_errors']) && is_array($entry['remote_errors'])) {
                            foreach ($entry['remote_errors'] as $target => $message) {
                                if ($target === 'local') {
                                    echo '<span class="fg-backup-note fg-backup-note-error">' . esc_html($message) . '</span>';
                                }
                            }
                        }
                    }
                    if (!empty($entry['note'])) {
                        echo '<span class="fg-backup-note">' . esc_html($entry['note']) . '</span>';
                    }
                    ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
