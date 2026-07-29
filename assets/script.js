(function ($) {
    'use strict';

    $(function () {
        var $button = $('#fg-backup-start');
        var $cancelButton = $('#fg-backup-cancel');
        var $type = $('#fg-backup-type');
        var $status = $('#fg-backup-status');
        var $stage = $status.find('.fg-backup-stage');
        var $detail = $status.find('.fg-backup-detail');
        var $percent = $status.find('.fg-backup-percent');
        var $progress = $status.find('.fg-backup-progress span');
        var currentJobId = fgBackupPro.activeJobId || '';
        var $filenamePattern = $('#fg-backup-filename-pattern');
        var $filenamePreview = $('#fg-backup-filename-preview');
        var $filenameHelp = $('#fg-backup-filename-help');
        var $filenamePopover = $('#fg-backup-filename-popover');
        var $defaultType = $('#fg-backup-default-type');
        var $archiveFormat = $('#fg-backup-archive-format');
        var $databaseFormat = $('#fg-backup-database-format');

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

        function getAdminBarItem() {
            return $('#wp-admin-bar-fg-backup-pro-status');
        }

        function ensureAdminBarItem() {
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
            } else if (status === 'canceled') {
                label = 'FG Backup Pro: Abgebrochen';
            } else if (status === 'failed') {
                label = 'FG Backup Pro: Fehlgeschlagen';
            } else {
                label = 'FG Backup Pro: ' + progress + ' %';
            }

            $item.find('> .ab-item').text(label).attr('title', job && job.stage ? job.stage : 'Backup läuft');

            if (status === 'completed' || status === 'canceled' || status === 'failed') {
                window.setTimeout(function () {
                    $item.remove();
                }, 5000);
            }
        }

        function setRunning(running) {
            $button.prop('disabled', running);
            if (running) {
                $cancelButton.removeAttr('hidden').prop('disabled', false);
                updateAdminBar({status: 'queued', progress: 0, stage: 'Backup startet'});
            } else {
                $cancelButton.attr('hidden', 'hidden').prop('disabled', false);
                currentJobId = '';
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
                updateAdminBar(job);

                if (job.status === 'completed') {
                    var detail = job.file || '';
                    if (job.size) {
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

        $button.on('click', function () {
            setRunning(true);
            showStatus('Wartet auf Start', '', 0);

            $.post(fgBackupPro.ajaxUrl, {
                action: 'fg_backup_start',
                security: fgBackupPro.nonce,
                backup_type: $type.val()
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

        $('.fg-backup-delete').on('click', function (event) {
            if (!window.confirm('Backup wirklich löschen?')) {
                event.preventDefault();
            }
        });
    });
}(jQuery));
