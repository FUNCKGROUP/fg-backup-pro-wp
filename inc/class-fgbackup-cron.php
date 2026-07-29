<?php

defined('ABSPATH') || exit;

class FgBackup_Cron {

    const HOOK = 'fg_backup_scheduled_backup';

    public static function init() {
        add_filter('cron_schedules', [__CLASS__, 'add_schedules']);
        add_action(self::HOOK, [__CLASS__, 'run']);
        add_action('update_option_fg_backup_schedule', [__CLASS__, 'reschedule'], 10, 2);
        add_action('update_option_fg_backup_hour', [__CLASS__, 'reschedule'], 10, 2);
    }

    public static function add_schedules($schedules) {
        $schedules['fg_backup_weekly'] = [
            'interval' => WEEK_IN_SECONDS,
            'display' => __('Wöchentlich', 'fg-backup-pro'),
        ];
        $schedules['fg_backup_monthly'] = [
            'interval' => 30 * DAY_IN_SECONDS,
            'display' => __('Monatlich', 'fg-backup-pro'),
        ];
        return $schedules;
    }

    public static function reschedule($old_value = null, $new_value = null) {
        wp_clear_scheduled_hook(self::HOOK);
        self::schedule();
    }

    public static function schedule() {
        $setting = get_option('fg_backup_schedule', 'disabled');
        if ($setting === 'disabled' || wp_next_scheduled(self::HOOK)) {
            return;
        }

        $recurrences = [
            'daily' => 'daily',
            'weekly' => 'fg_backup_weekly',
            'monthly' => 'fg_backup_monthly',
        ];

        if (!isset($recurrences[$setting])) {
            return;
        }

        $hour = max(0, min(23, (int) get_option('fg_backup_hour', 2)));
        $now = current_datetime();
        $next = $now->setTime($hour, 0, 0);
        if ($next <= $now) {
            $next = $next->modify('+1 day');
        }

        wp_schedule_event($next->getTimestamp(), $recurrences[$setting], self::HOOK);
    }

    public static function run() {
        $type = get_option('fg_backup_type', 'full');
        FgBackup_Async::queue_backup($type, 'scheduled', '', '');
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::HOOK);
        wp_clear_scheduled_hook(FgBackup_Async::CRON_HOOK);
    }
}
