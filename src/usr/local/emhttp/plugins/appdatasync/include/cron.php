<?php

declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/LogManager.php';
require_once __DIR__ . '/JobState.php';

use UnraidAppdataSync\Config;
use UnraidAppdataSync\JobState;
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

$python = JobState::pythonBinary();

$args           = [];
$scheduleGroups = Settings::scheduleGroups($settings);
if ($groups !== null && $groups !== '') {
    $args[] = '--group';
    $args[] = $groups;
} elseif ($scheduleGroups !== []) {
    $args[] = '--group';
    $args[] = implode(',', $scheduleGroups);
}
if ($dryRun) {
    $args[] = '--dry-run';
}

$logFile = LogManager::generatePath('backup');
file_put_contents($logFile, '');
LogManager::setCurrentLog($logFile);
$startedAt = date('c');

$operationGroups = $groups !== null && $groups !== '' ? explode(',', $groups) : ($scheduleGroups === [] ? ['all'] : $scheduleGroups);

JobState::saveJobState([
    'running'     => true,
    'pid'         => null,
    'started_at'  => $startedAt,
    'operation'   => 'Backup',
    'groups'      => $operationGroups,
    'dry_run'     => $dryRun,
    'last_result' => null,
]);

$cmd = array_merge([$python, JobState::SCRIPT_PATH], $args);
putenv('APPDATA_BACKUP_CONFIG=' . Config::configPath());

function logToFile(string $path, string $message): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . trim($message) . "\n";
    file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

logToFile($logFile, 'Starting scheduled backup: ' . implode(' ', array_map('escapeshellarg', $cmd)));
@unlink(JobState::PID_FILE);

$pipes   = [];
$process = proc_open(
    $cmd,
    [
        0 => ['pipe', 'r'],
        1 => ['file', $logFile, 'a'],
        2 => ['file', $logFile, 'a'],
    ],
    $pipes
);

if ( ! is_resource($process)) {
    logToFile($logFile, 'Failed to start scheduled backup process.');
    LogManager::finalizeRun($logFile, 'Backup', $operationGroups, $dryRun, $startedAt);
    exit(1);
}

$status = proc_get_status($process);
$pid    = $status['pid'];

if ($pid > 0) {
    file_put_contents(JobState::PID_FILE, (string)$pid);
    JobState::saveJobState([
        'running' => true,
        'pid'     => $pid,
    ]);
}

fclose($pipes[0]);
$rc = proc_close($process);

logToFile($logFile, 'Scheduled backup finished with exit code ' . $rc);
@unlink(JobState::PID_FILE);

$result = LogManager::finalizeRun($logFile, 'Backup', $operationGroups, $dryRun, $startedAt);
exit($result === 'failed' ? 1 : $rc);
