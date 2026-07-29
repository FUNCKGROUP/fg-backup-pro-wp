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

        function showStatus(stage, detail, progress) {
            var value = Math.max(0, Math.min(100, parseInt(progress, 10) || 0));
            $status.removeAttr('hidden');
            $stage.text(stage || 'Backup läuft');
            $detail.text(detail || '');
            $percent.text(value + ' %');
            $progress.css('width', value + '%');
        }

        function setRunning(running) {
            $button.prop('disabled', running);
            if (running) {
                $cancelButton.removeAttr('hidden').prop('disabled', false);
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
                    setRunning(false);
                    return;
                }

                var job = response.data;
                showStatus(job.stage, job.detail, job.progress);

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
                    setRunning(false);
                    return;
                }
                currentJobId = response.data.job_id;
                poll(currentJobId);
            }).fail(function () {
                showStatus(fgBackupPro.failedText, '', 0);
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

        $('.fg-backup-delete').on('click', function (event) {
            if (!window.confirm('Backup wirklich löschen?')) {
                event.preventDefault();
            }
        });
    });
}(jQuery));
