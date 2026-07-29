(function ($) {
    'use strict';

    $(function () {
        if (typeof fgBackupAdminBar === 'undefined' || !fgBackupAdminBar.jobId) {
            return;
        }

        var jobId = fgBackupAdminBar.jobId;
        var $item = $('#wp-admin-bar-fg-backup-pro-status');

        function labelFor(job) {
            var status = job && job.status ? job.status : '';
            var progress = Math.max(0, Math.min(100, parseInt(job && job.progress, 10) || 0));

            if (status === 'cancel_requested') {
                return 'FG Backup Pro: Abbruch …';
            }
            if (status === 'queued') {
                return 'FG Backup Pro: Startet …';
            }
            if (status === 'completed') {
                return 'FG Backup Pro: Abgeschlossen';
            }
            if (status === 'canceled') {
                return 'FG Backup Pro: Abgebrochen';
            }
            if (status === 'failed') {
                return 'FG Backup Pro: Fehlgeschlagen';
            }

            return 'FG Backup Pro: ' + progress + ' %';
        }

        function update(job) {
            if (!$item.length) {
                return;
            }

            $item.find('> .ab-item')
                .text(labelFor(job))
                .attr('href', fgBackupAdminBar.pageUrl || '#')
                .attr('title', job && job.stage ? job.stage : 'Backup läuft');
        }

        function finish(job) {
            update(job);
            window.setTimeout(function () {
                $item.remove();
            }, 5000);
        }

        function poll() {
            $.post(fgBackupAdminBar.ajaxUrl, {
                action: 'fg_backup_status',
                security: fgBackupAdminBar.nonce,
                job_id: jobId
            }).done(function (response) {
                if (!response || !response.success) {
                    window.setTimeout(poll, 5000);
                    return;
                }

                var job = response.data;
                if (job.status === 'completed' || job.status === 'failed' || job.status === 'canceled') {
                    finish(job);
                    return;
                }

                update(job);
                window.setTimeout(poll, 4000);
            }).fail(function () {
                window.setTimeout(poll, 5000);
            });
        }

        poll();
    });
}(jQuery));
