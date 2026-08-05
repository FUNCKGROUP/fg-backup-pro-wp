#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "FG Backup Pro worker requires PHP CLI.\n");
    exit(1);
}

$options = getopt('', ['root:', 'job:', 'token:']);
$root = isset($options['root']) ? (string) $options['root'] : '';
$job_id = isset($options['job']) ? (string) $options['job'] : '';
$token = isset($options['token']) ? (string) $options['token'] : '';

$root = rtrim(str_replace('\\', '/', $root), '/');
$wp_load = $root . '/wp-load.php';

if ($root === '' || !is_file($wp_load) || $job_id === '' || $token === '') {
    fwrite(STDERR, "Invalid FG Backup Pro worker arguments.\n");
    exit(2);
}

define('FG_BACKUP_CLI_WORKER', true);
define('WP_USE_THEMES', false);
require_once $wp_load;

if (!class_exists('FgBackup_Async')) {
    fwrite(STDERR, "FG Backup Pro is not active.\n");
    exit(3);
}

$result = FgBackup_Async::run_cli_worker($job_id, $token);
exit($result ? 0 : 4);
