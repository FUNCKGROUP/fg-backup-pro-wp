(function ($) {
    'use strict';

    $(function () {
        var $button = $('#fg-backup-start');
        var $cancelButton = $('#fg-backup-cancel');
        var $type = $('#fg-backup-type');
        var $note = $('#fg-backup-note');
        var $status = $('#fg-backup-status');
        var $stage = $status.find('.fg-backup-stage');
        var $detail = $status.find('.fg-backup-detail');
        var $percent = $status.find('.fg-backup-percent');
        var $progress = $status.find('.fg-backup-progress span');
        var $lastActivity = $status.find('.fg-backup-last-activity');
        var $executionMode = $status.find('.fg-backup-execution-mode');
        var $cleanupJobButton = $('#fg-backup-cleanup-job');
        var currentJobId = fgBackupPro.activeJobId || '';
        var $filenamePattern = $('#fg-backup-filename-pattern');
        var $filenamePreview = $('#fg-backup-filename-preview');
        var $filenameHelp = $('#fg-backup-filename-help');
        var $filenamePopover = $('#fg-backup-filename-popover');
        var $defaultType = $('#fg-backup-default-type');
        var $archiveFormat = $('#fg-backup-archive-format');
        var $databaseFormat = $('#fg-backup-database-format');
        var $spaceEstimate = $('#fg-backup-space-estimate');
        var $sftpAuth = $('#fg-backup-sftp-auth');
        var $sftpResult = $('#fg-backup-sftp-result');
        var $sftpListButton = $('#fg-backup-sftp-list');
        var $sftpListResult = $('#fg-backup-sftp-list-result');
        var $sftpTable = $('#fg-backup-sftp-table');
        var $sftpTableBody = $sftpTable.find('tbody');
        var $webdavResult = $('#fg-backup-webdav-result');
        var $dropboxResult = $('#fg-backup-dropbox-result');
        var $s3Result = $('#fg-backup-s3-result');
        var $storageMode = $('#fg-backup-storage-mode');
        var $storageCustom = $('#fg-backup-storage-custom');
        var $storagePath = $('#fg-backup-storage-path');
        var $storageTestResult = $('#fg-backup-storage-test-result');
        var dropboxStatusTimer = null;
        var $healthButton = $('#fg-backup-health-check');
        var $healthResult = $('#fg-backup-health-result');
        var $healthPanel = $('#fg-backup-health');
        var $bulkForm = $('#fg-backup-bulk-form');
        var $bulkSelectAll = $('#fg-backup-select-all');
        var $bulkDelete = $('#fg-backup-bulk-delete');
        var $bulkValidate = $('#fg-backup-bulk-validate');
        var $selectionCount = $('#fg-backup-selection-count');
        var $validationResult = $('#fg-backup-validation-result');
        var $reportModal = $('#fg-backup-report-modal');
        var $reportContent = $('#fg-backup-report-content');

        function toggleStoragePath() {
            if (!$storageMode.length || !$storageCustom.length) {
                return;
            }
            if ($storageMode.val() === 'custom') {
                $storageCustom.removeAttr('hidden');
            } else {
                $storageCustom.attr('hidden', 'hidden');
            }
        }

        function trimFilename(value) {
            return value.replace(/^[ ._-]+|[ ._-]+$/g, '');
        }

        function sanitizeFilenamePattern(pattern) {
            var allowed = ['%Y', '%y', '%m', '%d', '%H', '%M', '%S', '%host', '%site', '%type', '%format', '%id'];
            var value = String(pattern || '')
                .replace(/<[^>]*>/g, '')
                .replace(/[\\/]/g, '-')
                .replace(/[\x00-\x1F\x7F]/g, '')
                .replace(/[^A-Za-z0-9%._-]+/g, '-')
                .replace(/(?:\.tar\.gz|\.sql\.gz|\.sql\.zip|\.zip|\.tgz|\.sql)$/i, '');

            value = trimFilename(value);
            value = value.replace(/%[A-Za-z]+/g, function (token) {
                return allowed.indexOf(token) !== -1 ? token : '';
            });
            value = value.replace(/-+/g, '-');
            value = trimFilename(value);

            return value || (fgBackupPro.filenamePreview && fgBackupPro.filenamePreview.defaultPattern) || 'fg-%type-%host-%Y%m%d-%H%M%S';
        }

        function filenameFormat(type) {
            if (type === 'db') {
                return $databaseFormat.val() || 'gz';
            }
            return $archiveFormat.val() || 'zip';
        }

        function filenameFormatToken(type, format) {
            if (type === 'full') {
                return format === 'tgz' ? 'tgz' : 'zip';
            }
            if (format === 'zip') {
                return 'sql-zip';
            }
            if (format === 'gz') {
                return 'sql-gz';
            }
            return 'sql';
        }

        function filenameExtension(type, format) {
            if (type === 'full') {
                return format === 'tgz' ? '.tgz' : '.zip';
            }
            if (format === 'zip') {
                return '.sql.zip';
            }
            if (format === 'gz') {
                return '.sql.gz';
            }
            return '.sql';
        }

        function updateFilenamePreview() {
            if (!$filenamePattern.length || !$filenamePreview.length) {
                return;
            }

            var preview = fgBackupPro.filenamePreview || {};
            var date = preview.date || {};
            var type = $defaultType.val() === 'db' ? 'db' : 'full';
            var format = filenameFormat(type);
            var replacements = {
                '%format': filenameFormatToken(type, format),
                '%type': type,
                '%host': preview.host || 'wordpress',
                '%site': preview.site || 'wordpress',
                '%id': preview.id || 'demo1234',
                '%Y': date.Y || '2026',
                '%y': date.y || '26',
                '%m': date.m || '01',
                '%d': date.d || '01',
                '%H': date.H || '12',
                '%M': date.M || '00',
                '%S': date.S || '00'
            };
            var base = sanitizeFilenamePattern($filenamePattern.val());

            Object.keys(replacements).forEach(function (token) {
                base = base.split(token).join(replacements[token]);
            });

            base = trimFilename(base.replace(/%/g, '').replace(/[^A-Za-z0-9._-]+/g, '-'));
            if (!base) {
                base = 'fg-' + type + '-' + (date.Y || '2026') + (date.m || '01') + (date.d || '01') + '-' + (date.H || '12') + (date.M || '00') + (date.S || '00');
            }

            $filenamePreview.text(base + filenameExtension(type, format));
        }

        function formatLocalized(template, value) {
            return String(template || '%s').replace(/%1?\$?s/, value);
        }

        function updateSpaceEstimate() {
            if (!$spaceEstimate.length) {
                return;
            }
            var type = $type.val() === 'db' ? 'db' : 'full';
            var required = $spaceEstimate.data(type + '-required') || '';
            var available = $spaceEstimate.data('available') || '';
            var template = type === 'full' ? fgBackupPro.spaceFullText : fgBackupPro.spaceDbText;
            $spaceEstimate.find('.fg-backup-space-text').text(formatLocalized(template, required));
            $spaceEstimate.find('.fg-backup-space-available').text(formatLocalized(fgBackupPro.spaceAvailableText, available));
        }

        function toggleSftpAuth() {
            if (!$sftpAuth.length) {
                return;
            }
            var keyMode = $sftpAuth.val() === 'key';
            $('.fg-backup-sftp-password-row').toggle(!keyMode);
            $('.fg-backup-sftp-key-row').toggle(keyMode);
        }


        function renderSftpFiles(files) {
            $sftpTableBody.empty();
            if (!files || !files.length) {
                $sftpTable.attr('hidden', 'hidden');
                $sftpListResult.removeClass('is-error').text(fgBackupPro.sftpListEmptyText || 'Keine Remote-Backups gefunden.');
                return;
            }

            files.forEach(function (file) {
                var $delete = $('<button>', {
                    type: 'button',
                    class: 'button-link-delete fg-backup-sftp-delete',
                    text: fgBackupPro.sftpDeleteText || 'Löschen'
                }).attr('data-file', file.name || '');

                $('<tr>').append(
                    $('<td>').append($('<strong>').text(file.name || '')),
                    $('<td>').text(file.size || ''),
                    $('<td>').text(file.date || ''),
                    $('<td>').append($delete)
                ).appendTo($sftpTableBody);
            });

            $sftpTable.removeAttr('hidden');
        }

        function loadSftpFiles() {
            if (!$sftpListButton.length) {
                return;
            }

            $sftpListButton.prop('disabled', true);
            $sftpListResult.removeClass('is-error is-success').text(fgBackupPro.sftpListLoadingText || 'Remote-Dateien werden geladen …');

            $.post(fgBackupPro.ajaxUrl, {
                action: 'fg_backup_sftp_list',
                security: fgBackupPro.nonce
            }).done(function (response) {
                if (!response || !response.success) {
                    $sftpTable.attr('hidden', 'hidden');
                    $sftpListResult.addClass('is-error').text(response && response.data && response.data.message ? response.data.message : fgBackupPro.failedText);
                    return;
                }
                renderSftpFiles(response.data.files || []);
                if (response.data.files && response.data.files.length) {
                    $sftpListResult.addClass('is-success').text(response.data.directory || '');
                }
            }).fail(function () {
                $sftpListResult.addClass('is-error').text(fgBackupPro.failedText);
            }).always(function () {
                $sftpListButton.prop('disabled', false);
            });
        }

        function remoteAction(target, suffix) {
            return 'fg_backup_' + target + '_' + suffix;
        }

        function renderRemoteFiles($section, target, files) {
            var $table = $section.find('.fg-backup-remote-table');
            var $body = $table.find('tbody');
            var $result = $section.find('.fg-backup-remote-list-result');
            $body.empty();
            if (!files || !files.length) {
                $table.attr('hidden', 'hidden');
                $result.removeClass('is-error is-success').text(fgBackupPro.remoteListEmptyText || 'Keine Remote-Backups gefunden.');
                return;
            }
            files.forEach(function (file) {
                var $delete = $('<button>', {
                    type: 'button',
                    class: 'button-link-delete fg-backup-remote-delete',
                    text: fgBackupPro.remoteDeleteText || 'Löschen'
                }).attr({'data-target': target, 'data-file': file.name || ''});
                $('<tr>').append(
                    $('<td>').append($('<strong>').text(file.name || '')),
                    $('<td>').text(file.size || ''),
                    $('<td>').text(file.date || ''),
                    $('<td>').append($delete)
                ).appendTo($body);
            });
            $table.removeAttr('hidden');
        }

        function loadRemoteFiles(target, $section) {
            var $button = $section.find('.fg-backup-remote-list');
            var $result = $section.find('.fg-backup-remote-list-result');
            var $table = $section.find('.fg-backup-remote-table');
            $button.prop('disabled', true);
            $result.removeClass('is-error is-success').text(fgBackupPro.remoteListLoadingText || 'Remote-Dateien werden geladen …');
            $.post(fgBackupPro.ajaxUrl, {
                action: remoteAction(target, 'list'),
                security: fgBackupPro.nonce
            }).done(function (response) {
                if (!response || !response.success) {
                    $table.attr('hidden', 'hidden');
                    $result.addClass('is-error').text(response && response.data && response.data.message ? response.data.message : fgBackupPro.failedText);
                    return;
                }
                renderRemoteFiles($section, target, response.data.files || []);
                if (response.data.files && response.data.files.length) {
                    $result.addClass('is-success').text(response.data.directory || '');
                }
            }).fail(function () {
                $result.addClass('is-error').text(fgBackupPro.failedText);
            }).always(function () {
                $button.prop('disabled', false);
            });
        }

        function pollDropboxConnection() {
            window.clearTimeout(dropboxStatusTimer);
            $.post(fgBackupPro.ajaxUrl, {
                action: 'fg_backup_dropbox_oauth_status',
                security: fgBackupPro.nonce
            }).done(function (response) {
                if (!response || !response.success) {
                    return;
                }
                if (response.data.connected) {
                    $dropboxResult.removeClass('is-error').addClass('is-success').text('Dropbox wurde verbunden.');
                    window.setTimeout(function () { window.location.reload(); }, 800);
                    return;
                }
                if (response.data.error) {
                    $dropboxResult.addClass('is-error').text(response.data.error);
                    return;
                }
                if (response.data.pending) {
                    dropboxStatusTimer = window.setTimeout(pollDropboxConnection, 2000);
                }
            });
        }

        function closeFilenamePopover() {
            if (!$filenamePopover.length) {
                return;
            }
            $filenamePopover.attr('hidden', 'hidden');
            $filenameHelp.attr('aria-expanded', 'false');
        }

        function showStatus(stage, detail, progress) {
            var value = Math.max(0, Math.min(100, parseInt(progress, 10) || 0));
            $status.removeAttr('hidden');
            $stage.text(stage || 'Backup läuft');
            $detail.text(detail || '');
            $percent.text(value + ' %');
            $progress.css('width', value + '%');
        }

        function updateRuntime(job) {
            if (!job) {
                $lastActivity.text('');
                $executionMode.text('');
                $cleanupJobButton.attr('hidden', 'hidden');
                return;
            }

            $lastActivity.text(job.updated_label ? 'Letzte Aktivität: ' + job.updated_label : '');
            if (job.execution_mode === 'cli') {
                $executionMode.text('PHP-CLI-Worker');
            } else if (job.execution_mode === 'http') {
                $executionMode.text('HTTP-/WP-Cron-Fallback');
            } else {
                $executionMode.text('');
            }

            if (job.status === 'failed' || job.status === 'canceled') {
                $cleanupJobButton.removeAttr('hidden').prop('disabled', false);
            } else {
                $cleanupJobButton.attr('hidden', 'hidden');
            }
        }

        function getAdminBarItem() {
            return $('#wp-admin-bar-fg-backup-pro-status');
        }

        function ensureAdminBarItem() {
            $('#wp-admin-bar-fg-backup-pro-health').remove();
            var $item = getAdminBarItem();
            if ($item.length || !$('#wpadminbar').length) {
                return $item;
            }

            var $target = $('#wp-admin-bar-root-default');
            if (!$target.length) {
                $target = $('#wp-admin-bar-top-secondary');
            }
            if (!$target.length) {
                return $item;
            }

            $item = $('<li>', {
                id: 'wp-admin-bar-fg-backup-pro-status',
                class: 'menupop'
            }).append(
                $('<a>', {
                    class: 'ab-item',
                    href: fgBackupPro.pageUrl || '#',
                    text: 'FG Backup Pro: Startet …'
                })
            );
            $target.append($item);
            return $item;
        }

        function updateAdminBar(job) {
            var $item = ensureAdminBarItem();
            if (!$item.length) {
                return;
            }

            var status = job && job.status ? job.status : 'queued';
            var progress = Math.max(0, Math.min(100, parseInt(job && job.progress, 10) || 0));
            var label;

            if (status === 'cancel_requested') {
                label = 'FG Backup Pro: Abbruch …';
            } else if (status === 'queued') {
                label = 'FG Backup Pro: Startet …';
            } else if (status === 'completed') {
                label = 'FG Backup Pro: Abgeschlossen';
            } else if (status === 'completed_with_errors') {
                label = 'FG Backup Pro: Mit Fehlern';
            } else if (status === 'canceled') {
                label = 'FG Backup Pro: Abgebrochen';
            } else if (status === 'failed') {
                label = 'FG Backup Pro: Fehlgeschlagen';
            } else {
                label = 'FG Backup Pro: ' + progress + ' %';
            }

            $item.find('> .ab-item').text(label).attr('title', job && job.stage ? job.stage : 'Backup läuft');

            if (status === 'completed' || status === 'completed_with_errors' || status === 'canceled' || status === 'failed') {
                window.setTimeout(function () {
                    $item.remove();
                }, 5000);
            }
        }

        function setRunning(running) {
            $button.prop('disabled', running);
            $type.prop('disabled', running);
            $note.prop('disabled', running);
            if (running) {
                $cancelButton.removeAttr('hidden').prop('disabled', false);
                updateAdminBar({status: 'queued', progress: 0, stage: 'Backup startet'});
            } else {
                $cancelButton.attr('hidden', 'hidden').prop('disabled', false);
            }
        }

        function poll(jobId) {
            currentJobId = jobId;
            $.post(fgBackupPro.ajaxUrl, {
                action: 'fg_backup_status',
                security: fgBackupPro.nonce,
                job_id: jobId
            }).done(function (response) {
                if (!response || !response.success) {
                    showStatus(fgBackupPro.failedText, response && response.data && response.data.message ? response.data.message : '', 0);
                    updateAdminBar({status: 'failed', progress: 0, stage: fgBackupPro.failedText});
                    setRunning(false);
                    return;
                }

                var job = response.data;
                showStatus(job.stage, job.detail, job.progress);
                updateRuntime(job);
                updateAdminBar(job);

                if (job.status === 'completed' || job.status === 'completed_with_errors') {
                    var detail = job.remote_summary || job.file || job.remote_path || '';
                    if (job.local_deleted) {
                        detail += (detail ? ' · ' : '') + (fgBackupPro.localDeletedText || 'Lokal gelöscht.');
                    } else if (job.size) {
                        detail += (detail ? ' · ' : '') + job.size;
                    }
                    showStatus(job.stage, detail, 100);
                    setRunning(false);
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 1000);
                    return;
                }

                if (job.status === 'failed') {
                    showStatus(job.stage || fgBackupPro.failedText, job.error || job.detail || '', job.progress);
                    setRunning(false);
                    return;
                }

                if (job.status === 'canceled') {
                    showStatus(job.stage || 'Abgebrochen', job.detail || '', job.progress);
                    setRunning(false);
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 1000);
                    return;
                }

                if (job.status === 'cancel_requested') {
                    $cancelButton.prop('disabled', true);
                }

                window.setTimeout(function () {
                    poll(jobId);
                }, 1500);
            }).fail(function () {
                showStatus(fgBackupPro.failedText, '', 0);
                updateAdminBar({status: 'failed', progress: 0, stage: fgBackupPro.failedText});
                setRunning(false);
            });
        }

        $cleanupJobButton.on('click', function () {
            if (!currentJobId) {
                return;
            }
            $cleanupJobButton.prop('disabled', true);
            $.post(fgBackupPro.ajaxUrl, {
                action: 'fg_backup_cleanup_job',
                security: fgBackupPro.nonce,
                job_id: currentJobId
            }).done(function (response) {
                if (!response || !response.success) {
                    $detail.text(response && response.data && response.data.message ? response.data.message : fgBackupPro.failedText);
                    $cleanupJobButton.prop('disabled', false);
                    return;
                }
                $detail.text(response.data.message || 'Temporäre Jobdaten wurden bereinigt.');
                $cleanupJobButton.attr('hidden', 'hidden');
            }).fail(function () {
                $cleanupJobButton.prop('disabled', false);
            });
        });

        $button.on('click', function () {
            setRunning(true);
            showStatus('Wartet auf Start', '', 0);

            $.post(fgBackupPro.ajaxUrl, {
                action: 'fg_backup_start',
                security: fgBackupPro.nonce,
                backup_type: $type.val(),
                backup_note: $note.val()
            }).done(function (response) {
                if (!response || !response.success) {
                    showStatus(fgBackupPro.failedText, response && response.data && response.data.message ? response.data.message : '', 0);
                    updateAdminBar({status: 'failed', progress: 0, stage: fgBackupPro.failedText});
                    setRunning(false);
                    return;
                }
                currentJobId = response.data.job_id;
                poll(currentJobId);
            }).fail(function () {
                showStatus(fgBackupPro.failedText, '', 0);
                updateAdminBar({status: 'failed', progress: 0, stage: fgBackupPro.failedText});
                setRunning(false);
            });
        });

        $cancelButton.on('click', function () {
            if (!currentJobId || !window.confirm(fgBackupPro.cancelConfirmText)) {
                return;
            }

            $cancelButton.prop('disabled', true);
            $.post(fgBackupPro.ajaxUrl, {
                action: 'fg_backup_cancel',
                security: fgBackupPro.nonce,
                job_id: currentJobId
            }).done(function (response) {
                if (!response || !response.success) {
                    showStatus(fgBackupPro.failedText, response && response.data && response.data.message ? response.data.message : '', 0);
                    $cancelButton.prop('disabled', false);
                    return;
                }

                showStatus(response.data.stage, response.data.detail, response.data.progress);
                updateAdminBar(response.data);
                poll(currentJobId);
            }).fail(function () {
                showStatus(fgBackupPro.failedText, '', 0);
                $cancelButton.prop('disabled', false);
            });
        });

        if (currentJobId) {
            setRunning(true);
            poll(currentJobId);
        }

        $type.on('change', updateSpaceEstimate);
        updateSpaceEstimate();

        $filenamePattern.on('input', updateFilenamePreview);
        $defaultType.add($archiveFormat).add($databaseFormat).on('change', updateFilenamePreview);
        updateFilenamePreview();

        $filenameHelp.on('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            var isOpen = !$filenamePopover.is('[hidden]');
            if (isOpen) {
                closeFilenamePopover();
                return;
            }
            $filenamePopover.removeAttr('hidden');
            $filenameHelp.attr('aria-expanded', 'true');
        });

        $filenamePopover.on('click', function (event) {
            event.stopPropagation();
        });

        $(document).on('click', closeFilenamePopover);
        $(document).on('keydown', function (event) {
            if (event.key === 'Escape') {
                closeFilenamePopover();
                $filenameHelp.trigger('focus');
            }
        });

        $storageMode.on('change', toggleStoragePath);
        toggleStoragePath();

        $('#fg-backup-storage-test').on('click', function () {
            var $test = $(this);
            $test.prop('disabled', true);
            $storageTestResult.removeClass('is-error is-success is-warning').text(fgBackupPro.storageTestText || 'Lokaler Speicherort wird geprüft …');

            $.post(fgBackupPro.ajaxUrl, {
                action: 'fg_backup_storage_test',
                security: fgBackupPro.nonce,
                mode: $storageMode.val() || 'content',
                path: $storagePath.val() || ''
            }).done(function (response) {
                if (!response || !response.success) {
                    $storageTestResult.addClass('is-error').text(response && response.data && response.data.message ? response.data.message : (fgBackupPro.storageTestFailedText || fgBackupPro.failedText));
                    return;
                }

                var resultClass = response.data && response.data.fallback ? 'is-warning' : 'is-success';
                $storageTestResult.addClass(resultClass).text(response.data.message || 'Speicherort ist nutzbar.');
            }).fail(function () {
                $storageTestResult.addClass('is-error').text(fgBackupPro.storageTestFailedText || fgBackupPro.failedText);
            }).always(function () {
                $test.prop('disabled', false);
            });
        });

        $sftpAuth.on('change', toggleSftpAuth);
        toggleSftpAuth();

        $('#fg-backup-sftp-test').on('click', function () {
            var $test = $(this);
            $test.prop('disabled', true);
            $sftpResult.removeClass('is-error is-success').text(fgBackupPro.sftpTestText || 'Verbindung wird getestet …');
            $.post(fgBackupPro.ajaxUrl, {
                action: 'fg_backup_sftp_test',
                security: fgBackupPro.nonce
            }).done(function (response) {
                if (!response || !response.success) {
                    $sftpResult.addClass('is-error').text(response && response.data && response.data.message ? response.data.message : fgBackupPro.failedText);
                    return;
                }
                $sftpResult.addClass('is-success').text(response.data.message + ' · ' + response.data.fingerprint);
                window.setTimeout(function () { window.location.reload(); }, 1200);
            }).fail(function () {
                $sftpResult.addClass('is-error').text(fgBackupPro.failedText);
            }).always(function () {
                $test.prop('disabled', false);
            });
        });

        $('#fg-backup-sftp-reset-key').on('click', function () {
            if (!window.confirm(fgBackupPro.sftpResetConfirmText || 'Serverschlüssel zurücksetzen?')) {
                return;
            }
            var $reset = $(this);
            $reset.prop('disabled', true);
            $.post(fgBackupPro.ajaxUrl, {
                action: 'fg_backup_sftp_reset_key',
                security: fgBackupPro.nonce
            }).done(function (response) {
                if (!response || !response.success) {
                    $sftpResult.addClass('is-error').text(response && response.data && response.data.message ? response.data.message : fgBackupPro.failedText);
                    return;
                }
                $sftpResult.removeClass('is-error').addClass('is-success').text(response.data.message);
                window.setTimeout(function () { window.location.reload(); }, 800);
            }).always(function () {
                $reset.prop('disabled', false);
            });
        });

        $sftpListButton.on('click', loadSftpFiles);

        $sftpTable.on('click', '.fg-backup-sftp-delete', function () {
            var $delete = $(this);
            var file = String($delete.attr('data-file') || '');
            if (!file || !window.confirm(fgBackupPro.sftpDeleteConfirmText || 'Remote-Backup wirklich löschen?')) {
                return;
            }

            $delete.prop('disabled', true);
            $sftpListResult.removeClass('is-error is-success').text('');
            $.post(fgBackupPro.ajaxUrl, {
                action: 'fg_backup_sftp_delete',
                security: fgBackupPro.nonce,
                file: file
            }).done(function (response) {
                if (!response || !response.success) {
                    $sftpListResult.addClass('is-error').text(response && response.data && response.data.message ? response.data.message : fgBackupPro.failedText);
                    $delete.prop('disabled', false);
                    return;
                }
                $sftpListResult.addClass('is-success').text(response.data.message || '');
                loadSftpFiles();
            }).fail(function () {
                $sftpListResult.addClass('is-error').text(fgBackupPro.failedText);
                $delete.prop('disabled', false);
            });
        });

        $('#fg-backup-webdav-test').on('click', function () {
            var $test = $(this);
            $test.prop('disabled', true);
            $webdavResult.removeClass('is-error is-success').text(fgBackupPro.webdavTestText || 'WebDAV-Verbindung wird getestet …');
            $.post(fgBackupPro.ajaxUrl, {
                action: 'fg_backup_webdav_test',
                security: fgBackupPro.nonce
            }).done(function (response) {
                if (!response || !response.success) {
                    $webdavResult.addClass('is-error').text(response && response.data && response.data.message ? response.data.message : fgBackupPro.failedText);
                    return;
                }
                $webdavResult.addClass('is-success').text(response.data.message || 'Verbindung erfolgreich.');
            }).fail(function () {
                $webdavResult.addClass('is-error').text(fgBackupPro.failedText);
            }).always(function () {
                $test.prop('disabled', false);
            });
        });


        $('#fg-backup-s3-test').on('click', function () {
            var $test = $(this);
            $test.prop('disabled', true);
            $s3Result.removeClass('is-error is-success').text(fgBackupPro.s3TestText || 'S3-Verbindung wird getestet …');
            $.post(fgBackupPro.ajaxUrl, {
                action: 'fg_backup_s3_test',
                security: fgBackupPro.nonce
            }).done(function (response) {
                if (!response || !response.success) {
                    $s3Result.addClass('is-error').text(response && response.data && response.data.message ? response.data.message : fgBackupPro.failedText);
                    return;
                }
                $s3Result.addClass('is-success').text(response.data.message || 'Verbindung erfolgreich.');
            }).fail(function () {
                $s3Result.addClass('is-error').text(fgBackupPro.failedText);
            }).always(function () {
                $test.prop('disabled', false);
            });
        });

        $('.fg-backup-remote-list').on('click', function () {
            var $section = $(this).closest('.fg-backup-remote-section');
            var target = String($(this).attr('data-target') || $section.attr('data-remote') || '');
            if (target) {
                loadRemoteFiles(target, $section);
            }
        });

        $(document).on('click', '.fg-backup-remote-delete', function () {
            var $delete = $(this);
            var target = String($delete.attr('data-target') || '');
            var file = String($delete.attr('data-file') || '');
            var $section = $delete.closest('.fg-backup-remote-section');
            var $result = $section.find('.fg-backup-remote-list-result');
            if (!target || !file || !window.confirm(fgBackupPro.remoteDeleteConfirmText || 'Remote-Backup wirklich löschen?')) {
                return;
            }
            $delete.prop('disabled', true);
            $.post(fgBackupPro.ajaxUrl, {
                action: remoteAction(target, 'delete'),
                security: fgBackupPro.nonce,
                file: file
            }).done(function (response) {
                if (!response || !response.success) {
                    $result.addClass('is-error').text(response && response.data && response.data.message ? response.data.message : fgBackupPro.failedText);
                    $delete.prop('disabled', false);
                    return;
                }
                $result.removeClass('is-error').addClass('is-success').text(response.data.message || '');
                loadRemoteFiles(target, $section);
            }).fail(function () {
                $result.addClass('is-error').text(fgBackupPro.failedText);
                $delete.prop('disabled', false);
            });
        });

        $('#fg-backup-dropbox-connect').on('click', function () {
            var $connect = $(this);
            var popup = window.open('', 'fgBackupDropbox', 'width=720,height=760,resizable=yes,scrollbars=yes');
            var $fallback = $('#fg-backup-dropbox-relay-link').attr('hidden', 'hidden');
            $connect.prop('disabled', true);
            $dropboxResult.removeClass('is-error is-success').text(fgBackupPro.dropboxConnectingText || 'Dropbox-Verbindung wird vorbereitet …');
            $.post(fgBackupPro.ajaxUrl, {
                action: 'fg_backup_dropbox_begin_relay',
                security: fgBackupPro.nonce
            }).done(function (response) {
                if (!response || !response.success) {
                    if (popup && !popup.closed) {
                        popup.close();
                    }
                    $dropboxResult.addClass('is-error').text(response && response.data && response.data.message ? response.data.message : fgBackupPro.failedText);
                    return;
                }
                if (popup && !popup.closed) {
                    popup.location.href = response.data.authorization_url;
                } else {
                    $fallback.attr('href', response.data.authorization_url).removeAttr('hidden');
                }
                $dropboxResult.text(fgBackupPro.dropboxWaitingText || 'Warte auf die Dropbox-Freigabe …');
                pollDropboxConnection();
            }).fail(function () {
                if (popup && !popup.closed) {
                    popup.close();
                }
                $dropboxResult.addClass('is-error').text(fgBackupPro.failedText);
            }).always(function () {
                $connect.prop('disabled', false);
            });
        });

        $('#fg-backup-dropbox-manual-start').on('click', function () {
            var $manual = $(this);
            var popup = window.open('', 'fgBackupDropboxManual', 'width=720,height=760,resizable=yes,scrollbars=yes');
            $manual.prop('disabled', true);
            $dropboxResult.removeClass('is-error is-success').text(fgBackupPro.dropboxConnectingText || 'Dropbox-Verbindung wird vorbereitet …');
            $.post(fgBackupPro.ajaxUrl, {
                action: 'fg_backup_dropbox_begin_manual',
                security: fgBackupPro.nonce
            }).done(function (response) {
                if (!response || !response.success) {
                    if (popup && !popup.closed) {
                        popup.close();
                    }
                    $dropboxResult.addClass('is-error').text(response && response.data && response.data.message ? response.data.message : fgBackupPro.failedText);
                    return;
                }
                $('#fg-backup-dropbox-manual-link').attr('href', response.data.authorization_url);
                $('#fg-backup-dropbox-manual').removeAttr('hidden');
                if (popup && !popup.closed) {
                    popup.location.href = response.data.authorization_url;
                }
                $dropboxResult.text('Code nach der Freigabe hier einfügen.');
            }).fail(function () {
                if (popup && !popup.closed) {
                    popup.close();
                }
                $dropboxResult.addClass('is-error').text(fgBackupPro.failedText);
            }).always(function () {
                $manual.prop('disabled', false);
            });
        });

        $('#fg-backup-dropbox-code-submit').on('click', function () {
            var $submit = $(this);
            var code = String($('#fg-backup-dropbox-code').val() || '').trim();
            if (!code) {
                $dropboxResult.addClass('is-error').text('Der Autorisierungscode fehlt.');
                return;
            }
            $submit.prop('disabled', true);
            $.post(fgBackupPro.ajaxUrl, {
                action: 'fg_backup_dropbox_complete_manual',
                security: fgBackupPro.nonce,
                code: code
            }).done(function (response) {
                if (!response || !response.success) {
                    $dropboxResult.addClass('is-error').text(response && response.data && response.data.message ? response.data.message : fgBackupPro.failedText);
                    return;
                }
                $dropboxResult.removeClass('is-error').addClass('is-success').text(response.data.message || 'Dropbox wurde verbunden.');
                window.setTimeout(function () { window.location.reload(); }, 800);
            }).fail(function () {
                $dropboxResult.addClass('is-error').text(fgBackupPro.failedText);
            }).always(function () {
                $submit.prop('disabled', false);
            });
        });

        $('#fg-backup-dropbox-test').on('click', function () {
            var $test = $(this);
            $test.prop('disabled', true);
            $dropboxResult.removeClass('is-error is-success').text(fgBackupPro.dropboxTestText || 'Dropbox-Verbindung wird getestet …');
            $.post(fgBackupPro.ajaxUrl, {
                action: 'fg_backup_dropbox_test',
                security: fgBackupPro.nonce
            }).done(function (response) {
                if (!response || !response.success) {
                    $dropboxResult.addClass('is-error').text(response && response.data && response.data.message ? response.data.message : fgBackupPro.failedText);
                    return;
                }
                $dropboxResult.addClass('is-success').text(response.data.message || 'Verbindung erfolgreich.');
            }).fail(function () {
                $dropboxResult.addClass('is-error').text(fgBackupPro.failedText);
            }).always(function () {
                $test.prop('disabled', false);
            });
        });

        $('#fg-backup-dropbox-disconnect').on('click', function () {
            if (!window.confirm(fgBackupPro.dropboxDisconnectConfirmText || 'Dropbox-Verbindung wirklich trennen?')) {
                return;
            }
            var $disconnect = $(this);
            $disconnect.prop('disabled', true);
            $.post(fgBackupPro.ajaxUrl, {
                action: 'fg_backup_dropbox_disconnect',
                security: fgBackupPro.nonce
            }).done(function (response) {
                if (!response || !response.success) {
                    $dropboxResult.addClass('is-error').text(response && response.data && response.data.message ? response.data.message : fgBackupPro.failedText);
                    return;
                }
                $dropboxResult.removeClass('is-error').addClass('is-success').text(response.data.message || '');
                window.setTimeout(function () { window.location.reload(); }, 800);
            }).always(function () {
                $disconnect.prop('disabled', false);
            });
        });


        function validationClass(status) {
            return ['valid', 'warning', 'invalid', 'unverified'].indexOf(status) !== -1 ? status : 'unverified';
        }

        function updateValidationRow(item) {
            if (!item || !item.file) {
                return;
            }
            var $row = $('.fg-backup-local-table tr[data-backup-file="' + String(item.file).replace(/"/g, '\\"') + '"]');
            if (!$row.length) {
                return;
            }
            var status = validationClass(String(item.status || 'invalid'));
            var $badge = $row.find('.fg-backup-validation-badge');
            $badge.removeClass(function (index, className) {
                return (className.match(/(^|\s)fg-backup-validation-badge--\S+/g) || []).join(' ');
            }).addClass('fg-backup-validation-badge--' + status).text(item.status_label || status);

            var $time = $row.find('.fg-backup-validation-time');
            if (!$time.length) {
                $time = $('<span>', { 'class': 'fg-backup-validation-time' }).appendTo($row.find('.fg-backup-validation-cell'));
            }
            $time.text(item.validated_at || '');
            if (item.manifest_exists) {
                $row.find('.fg-backup-report').prop('disabled', false);
                $row.find('.fg-backup-manifest-link').removeAttr('hidden');
            }
        }

        function openValidationReport(file) {
            if (!$reportModal.length) {
                return;
            }
            $reportContent.empty().append($('<p>').text(fgBackupPro.reportLoadingText || 'Prüfbericht wird geladen …'));
            $reportModal.removeAttr('hidden');
            $('body').addClass('fg-backup-modal-open');

            $.post(fgBackupPro.ajaxUrl, {
                action: 'fg_backup_validation_report',
                security: fgBackupPro.nonce,
                file: file
            }).done(function (response) {
                if (!response || !response.success) {
                    $reportContent.empty().append($('<p>', { 'class': 'fg-backup-report-error' }).text(response && response.data && response.data.message ? response.data.message : fgBackupPro.failedText));
                    return;
                }

                var manifest = response.data.manifest || {};
                var backup = manifest.backup || {};
                var website = manifest.website || {};
                var database = manifest.database || {};
                var validation = manifest.validation || {};
                var $summary = $('<dl>', { 'class': 'fg-backup-report-summary' });
                [
                    ['Datei', backup.filename || file],
                    ['Status', response.data.status_label || validation.status || ''],
                    ['Erstellt', backup.created_at || ''],
                    ['Größe', backup.size ? String(backup.size) + ' Bytes' : ''],
                    ['Dateien', backup.file_count !== undefined ? backup.file_count : ''],
                    ['WordPress', website.wordpress_version || ''],
                    ['PHP', website.php_version || ''],
                    ['Datenbanktabellen', database.tables !== undefined ? database.tables : ''],
                    ['Datenbankzeilen', database.rows !== undefined ? database.rows : '']
                ].forEach(function (item) {
                    if (item[1] === '' || item[1] === null) {
                        return;
                    }
                    $summary.append($('<dt>').text(item[0])).append($('<dd>').text(item[1]));
                });

                var $checks = $('<div>', { 'class': 'fg-backup-report-checks' });
                (validation.checks || []).forEach(function (check) {
                    var status = String(check.status || 'failed');
                    var label = status === 'passed' ? 'Bestanden' : (status === 'warning' ? 'Warnung' : 'Fehlgeschlagen');
                    var $check = $('<div>', { 'class': 'fg-backup-report-check fg-backup-report-check--' + status });
                    $check.append($('<div>', { 'class': 'fg-backup-report-check-title' })
                        .append($('<strong>').text(check.label || check.id || 'Prüfung'))
                        .append($('<span>').text(label)));
                    $check.append($('<p>').text(check.detail || ''));
                    $checks.append($check);
                });

                $reportContent.empty().append($summary).append($checks);
            }).fail(function () {
                $reportContent.empty().append($('<p>', { 'class': 'fg-backup-report-error' }).text(fgBackupPro.failedText));
            });
        }

        function closeValidationReport() {
            $reportModal.attr('hidden', 'hidden');
            $('body').removeClass('fg-backup-modal-open');
        }

        function validateBackups(files, showReport) {
            files = (files || []).filter(function (file, index, list) {
                return file && list.indexOf(file) === index;
            });
            if (!files.length) {
                window.alert(fgBackupPro.bulkValidateNoneText || 'Bitte mindestens ein Backup für die Validierung auswählen.');
                return;
            }

            var index = 0;
            var hasInvalid = false;
            var hasWarning = false;
            $bulkValidate.prop('disabled', true);
            $('.fg-backup-validate').prop('disabled', true);
            $validationResult.removeClass('is-error is-success is-warning');

            function finishValidation() {
                $validationResult.addClass(hasInvalid ? 'is-error' : (hasWarning ? 'is-warning' : 'is-success'))
                    .text(hasInvalid
                        ? 'Validierung abgeschlossen; mindestens ein Backup ist ungültig.'
                        : (hasWarning ? 'Validierung mit Warnungen abgeschlossen.' : (fgBackupPro.validateDoneText || 'Validierung abgeschlossen.')));
                $('.fg-backup-validate').prop('disabled', false);
                updateBulkSelection();
                if (showReport && files.length === 1) {
                    openValidationReport(files[0]);
                }
            }

            function validateNext() {
                if (index >= files.length) {
                    finishValidation();
                    return;
                }

                var file = files[index];
                $validationResult.text((fgBackupPro.validateText || 'Backup wird vollständig validiert …') + ' (' + (index + 1) + '/' + files.length + ')');
                $.post(fgBackupPro.ajaxUrl, {
                    action: 'fg_backup_validate',
                    security: fgBackupPro.nonce,
                    backups: [file]
                }).done(function (response) {
                    if (!response || !response.success) {
                        hasInvalid = true;
                        return;
                    }
                    (response.data.items || []).forEach(function (item) {
                        updateValidationRow(item);
                        hasInvalid = hasInvalid || item.status === 'invalid';
                        hasWarning = hasWarning || item.status === 'warning';
                    });
                }).fail(function () {
                    hasInvalid = true;
                }).always(function () {
                    index++;
                    validateNext();
                });
            }

            validateNext();
        }

        $reportModal.on('click', function (event) {
            if (event.target === this) {
                closeValidationReport();
            }
        });
        $reportModal.on('click', '.fg-backup-report-close', closeValidationReport);
        $(document).on('keydown', function (event) {
            if (event.key === 'Escape' && !$reportModal.is('[hidden]')) {
                closeValidationReport();
            }
        });
        $('.fg-backup-local-table').on('click', '.fg-backup-validate', function () {
            validateBackups([String($(this).data('file') || '')], true);
        });
        $('.fg-backup-local-table').on('click', '.fg-backup-report', function () {
            openValidationReport(String($(this).data('file') || ''));
        });

        function updateHealthPanel(report) {
            if (!$healthPanel.length || !report) {
                return;
            }

            var status = String(report.status || 'unknown');
            $healthPanel.removeClass(function (index, className) {
                return (className.match(/(^|\s)fg-backup-health--\S+/g) || []).join(' ');
            }).addClass('fg-backup-health--' + status);
            $healthPanel.find('.fg-backup-health-status-label').text(report.status_label || status);
            $healthPanel.find('.fg-backup-health-summary').text(report.summary || '');

            var $generated = $('#fg-backup-health-generated');
            if (!$generated.length) {
                $generated = $('<span>', { id: 'fg-backup-health-generated' }).prependTo($healthPanel.find('.fg-backup-health-actions'));
            }
            $generated.text(report.generated ? 'Stand: ' + report.generated : '');

            $.each(report.checks || {}, function (key, check) {
                var $check = $healthPanel.find('[data-check-key="' + key + '"]');
                if (!$check.length) {
                    return;
                }
                var checkStatus = String(check.status || 'unknown');
                $check.removeClass(function (index, className) {
                    return (className.match(/(^|\s)fg-backup-health-check--\S+/g) || []).join(' ');
                }).addClass('fg-backup-health-check--' + checkStatus);
                $check.find('.fg-backup-health-check-status').text(check.status_label || checkStatus);
                $check.find('.fg-backup-health-check-detail').text(check.detail || '');
            });
        }

        $healthButton.on('click', function () {
            $healthButton.prop('disabled', true);
            $healthResult.removeClass('is-error is-success is-warning').text(fgBackupPro.healthCheckingText || 'Backup-Status wird geprüft …');

            $.post(fgBackupPro.ajaxUrl, {
                action: 'fg_backup_health_check',
                security: fgBackupPro.nonce
            }).done(function (response) {
                if (!response || !response.success) {
                    $healthResult.addClass('is-error').text(response && response.data && response.data.message ? response.data.message : fgBackupPro.failedText);
                    return;
                }

                updateHealthPanel(response.data);
                var resultClass = response.data.status === 'healthy'
                    ? 'is-success'
                    : (response.data.status === 'critical' ? 'is-error' : 'is-warning');
                $healthResult.addClass(resultClass)
                    .text(response.data.message || fgBackupPro.healthCheckedText || 'Backup-Status wurde aktualisiert.');
            }).fail(function () {
                $healthResult.addClass('is-error').text(fgBackupPro.failedText);
            }).always(function () {
                $healthButton.prop('disabled', false);
            });
        });

        function updateBulkSelection() {
            if (!$bulkForm.length) {
                return;
            }
            var $boxes = $bulkForm.find('.fg-backup-select');
            var selected = $boxes.filter(':checked').length;
            $bulkDelete.prop('disabled', selected === 0);
            $bulkValidate.prop('disabled', selected === 0);
            $bulkSelectAll.prop('checked', selected > 0 && selected === $boxes.length);
            $bulkSelectAll.prop('indeterminate', selected > 0 && selected < $boxes.length);
            $selectionCount.text(selected > 0 ? String(fgBackupPro.bulkSelectedText || '%d ausgewählt').replace('%d', selected) : '');
        }

        $bulkSelectAll.on('change', function () {
            $bulkForm.find('.fg-backup-select').prop('checked', this.checked);
            updateBulkSelection();
        });

        $bulkForm.on('change', '.fg-backup-select', updateBulkSelection);
        $bulkValidate.on('click', function () {
            var files = $bulkForm.find('.fg-backup-select:checked').map(function () {
                return this.value;
            }).get();
            validateBackups(files, false);
        });

        $bulkForm.on('submit', function (event) {
            var selected = $bulkForm.find('.fg-backup-select:checked').length;
            if (!selected) {
                event.preventDefault();
                window.alert(fgBackupPro.bulkDeleteNoneText || 'Bitte mindestens ein Backup auswählen.');
                return;
            }
            if (!window.confirm(fgBackupPro.bulkDeleteConfirmText || 'Ausgewählte Backups wirklich löschen?')) {
                event.preventDefault();
            }
        });
        updateBulkSelection();

        $('.fg-backup-delete').on('click', function (event) {
            if (!window.confirm('Backup wirklich löschen?')) {
                event.preventDefault();
            }
        });
    });
}(jQuery));
