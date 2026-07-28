(function ($) {
    'use strict';

    $(function () {
        var $button = $('#fg-backup-start');
        var $type = $('#fg-backup-type');
        var $status = $('#fg-backup-status');
        var $statusText = $status.find('p');
        var $progress = $status.find('.fg-backup-progress span');

        function showMessage(message) {
            $status.removeAttr('hidden');
            $statusText.text(message);
        }

        function poll(jobId) {
            $.post(fgBackupPro.ajaxUrl, {
                action: 'fg_backup_status',
                security: fgBackupPro.nonce,
                job_id: jobId
            }).done(function (response) {
                if (!response || !response.success) {
                    showMessage(response && response.data && response.data.message ? response.data.message : fgBackupPro.failedText);
                    $button.prop('disabled', false);
                    return;
                }

                var job = response.data;
                $progress.css('width', Math.max(0, Math.min(100, parseInt(job.progress, 10) || 0)) + '%');

                if (job.status === 'completed') {
                    showMessage(fgBackupPro.completedText);
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 500);
                    return;
                }

                if (job.status === 'failed') {
                    showMessage(job.error || fgBackupPro.failedText);
                    $button.prop('disabled', false);
                    return;
                }

                showMessage(fgBackupPro.runningText);
                window.setTimeout(function () {
                    poll(jobId);
                }, 1500);
            }).fail(function () {
                showMessage(fgBackupPro.failedText);
                $button.prop('disabled', false);
            });
        }

        $button.on('click', function () {
            $button.prop('disabled', true);
            $progress.css('width', '0%');
            showMessage(fgBackupPro.runningText);

            $.post(fgBackupPro.ajaxUrl, {
                action: 'fg_backup_start',
                security: fgBackupPro.nonce,
                backup_type: $type.val()
            }).done(function (response) {
                if (!response || !response.success) {
                    showMessage(response && response.data && response.data.message ? response.data.message : fgBackupPro.failedText);
                    $button.prop('disabled', false);
                    return;
                }
                poll(response.data.job_id);
            }).fail(function () {
                showMessage(fgBackupPro.failedText);
                $button.prop('disabled', false);
            });
        });

        if (fgBackupPro.activeJobId) {
            $button.prop('disabled', true);
            showMessage(fgBackupPro.runningText);
            poll(fgBackupPro.activeJobId);
        }

        $('.fg-backup-delete').on('click', function (event) {
            if (!window.confirm('Backup wirklich löschen?')) {
                event.preventDefault();
            }
        });
    });
}(jQuery));
