<?php

declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/LogManager.php';

use UnraidAppdataSync\Config;
use UnraidAppdataSync\LogManager;
use UnraidAppdataSync\Settings;

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
    LogManager::ensureDir();
    $logFile = LogManager::generatePath('backup');
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Scheduled backups are disabled; exiting.\n");
    LogManager::finalizeRun($logFile, 'Backup', Settings::scheduleGroups($settings), false, date('c'));
    exit(0);
}

$python = 'python3';
if (is_executable('/usr/bin/python3')) {
    $python = '/usr/bin/python3';
}

$args           = '';
$scheduleGroups = Settings::scheduleGroups($settings);
if ($groups !== null && $groups !== '') {
    $args .= ' --group ' . escapeshellarg($groups);
} elseif ($scheduleGroups !== []) {
    $args .= ' --group ' . escapeshellarg(implode(',', $scheduleGroups));
}
if ($dryRun) {
    $args .= ' --dry-run';
}

$logFile = LogManager::generatePath('backup');
LogManager::setCurrentLog($logFile);
$startedAt = date('c');

$env = 'APPDATA_BACKUP_CONFIG=' . escapeshellarg(Config::configPath()) . ' ';
$cmd = sprintf(
    '%s%s %s %s >> %s 2>&1',
    $env,
    escapeshellarg($python),
    escapeshellarg('/usr/local/emhttp/plugins/appdatasync/backup.py'),
    $args,
    escapeshellarg($logFile)
);

function logToFile(string $path, string $message): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . trim($message) . "\n";
    file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

logToFile($logFile, 'Starting scheduled backup: ' . $cmd);
exec($cmd, $output, $rc);
logToFile($logFile, 'Scheduled backup finished with exit code ' . $rc);

$operationGroups = $groups !== null && $groups !== '' ? explode(',', $groups) : $scheduleGroups;
$result          = LogManager::finalizeRun($logFile, 'Backup', $operationGroups, $dryRun, $startedAt);
exit($result === 'failed' ? 1 : $rc);
