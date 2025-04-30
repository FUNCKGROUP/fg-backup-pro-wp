<?php

class FgBackup_Cleanup {

    public static function rotate_backups() {
        $max_backups = intval(get_option('fg_backup_rotation', 5));
        $backups = FgBackup_Backup::list_backups();

        usort($backups, function($a, $b) {
            return $b['date'] <=> $a['date'];
        });

        if (count($backups) > $max_backups) {
            $to_delete = array_slice($backups, $max_backups);
            foreach ($to_delete as $backup) {
                unlink($backup['path']);
            }
        }
    }
}