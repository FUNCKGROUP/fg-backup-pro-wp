<?php

class FgBackup_Cron {

    public static function init() {
        add_filter('cron_schedules', [__CLASS__, 'add_custom_intervals']);
        add_action('admin_init', [__CLASS__, 'schedule_backup']);
        add_action('fg_scheduled_backup', [__CLASS__, 'run_scheduled_backup']);
    }

    public static function add_custom_intervals($schedules) {
        $schedules['daily'] = ['interval' => DAY_IN_SECONDS, 'display' => __('Täglich')];
        $schedules['weekly'] = ['interval' => WEEK_IN_SECONDS, 'display' => __('Wöchentlich')];
        $schedules['monthly'] = ['interval' => MONTH_IN_SECONDS, 'display' => __('Monatlich')];
        return $schedules;
    }

    public static function schedule_backup() {
        if (!wp_next_scheduled('fg_scheduled_backup')) {
            wp_schedule_event(strtotime('tomorrow 02:00'), 'daily', 'fg_scheduled_backup');
        }
    }

    public static function run_scheduled_backup() {
        $type = get_option('fg_backup_type', 'full');
        $targets = get_option('fg_backup_targets', ['local']);
        FgBackup_Async::queue_backup($type, $targets);
    }
}