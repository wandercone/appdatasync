<?php

declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Settings.php';

use UnraidAppdataSync\Config;
use UnraidAppdataSync\Settings;

const LOG_FILE = '/tmp/appdatasync-cron.log';

function logMessage(string $message): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . trim($message) . "\n";
    file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

if ( ! isset($argv)) {
    $argv = [];
}

$command = $argv[1] ?? '';
$dryRun  = in_array('--dry-run', $argv, true);
$groups  = null;

foreach ($argv as $i => $arg) {
    if ($arg === '--groups' && isset($argv[$i + 1])) {
        $groups = $argv[$i + 1];
        break;
    }
}

if ($command === '--install-cron') {
    Settings::installCron();
    exit(0);
}

if ($command !== 'backup') {
    fwrite(STDERR, "Usage: cron.php backup [--dry-run] [--groups group1,group2]\n");
    fwrite(STDERR, "       cron.php --install-cron\n");
    exit(1);
}

$settings = Settings::load();
if ( ! ($settings['schedule_enabled'] ?? false)) {
    logMessage('Scheduled backups are disabled; exiting.');
    exit(0);
}

$python = 'python3';
if (is_executable('/usr/bin/python3')) {
    $python = '/usr/bin/python3';
}

$args = '';
if ($groups !== null && $groups !== '') {
    $args .= ' --group ' . escapeshellarg($groups);
}
if ($dryRun) {
    $args .= ' --dry-run';
}

$env = 'APPDATA_BACKUP_CONFIG=' . escapeshellarg(Config::configPath()) . ' ';
$cmd = sprintf(
    '%s%s %s %s >> %s 2>&1',
    $env,
    escapeshellarg($python),
    escapeshellarg('/usr/local/emhttp/plugins/appdatasync/backup.py'),
    $args,
    escapeshellarg(LOG_FILE)
);

logMessage('Starting scheduled backup: ' . $cmd);
exec($cmd, $output, $rc);
logMessage('Scheduled backup finished with exit code ' . $rc);
exit($rc);
