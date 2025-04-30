jQuery(document).ready(function ($) {
    $('#start-async-backup').on('click', function (e) {
        e.preventDefault();

        const $button = $(this);
        const $status = $('#backup-status');
        const $progress = $('.fg-backup-progress');

        $button.prop('disabled', true).text('Backup wird erstellt…');

        function pollStatus(job_id) {
            $.post(ajaxurl, { action: 'fg_backup_check_status', job_id: job_id }, function (res) {
                if (res.success) {
                    const data = res.data;

                    if (data.status === 'completed') {
                        $progress.width('100%');
                        $status.html('<p>✅ Backup abgeschlossen!</p>');
                        $button.text('Backup erstellt ✅').prop('disabled', false);
                        location.reload(); // Refresh page to show new backup
                    } else if (data.status === 'failed') {
                        $status.html('<p>❌ Backup fehlgeschlagen: ' + data.error + '</p>');
                        $button.text('Erneut versuchen').prop('disabled', false);
                    } else {
                        $progress.width(data.progress + '%');
                        setTimeout(() => pollStatus(job_id), 1000);
                    }
                } else {
                    $status.html('<p>⚠️ Fehler beim Statuscheck.</p>');
                }
            });
        }

        $.post(ajaxurl, { action: 'fg_backup_start_async' }, function (res) {
            if (res.success) {
                pollStatus(res.data.job_id);
            } else {
                $status.html('<p>❌ Konnte Backup nicht starten.</p>');
                $button.prop('disabled', false);
            }
        });
    });
});