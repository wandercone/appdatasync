<?php

declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/LogManager.php';

use UnraidAppdataSync\Config;
use UnraidAppdataSync\LogManager;
use UnraidAppdataSync\Settings;

header('Content-Type: application/json');

$varIni = parse_ini_file('/var/local/emhttp/var.ini') ?: [];
$csrf   = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';

if (empty($varIni['csrf_token']) || $csrf !== $varIni['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

const PID_FILE    = '/tmp/appdatasync.pid';
const SCRIPT_PATH = '/usr/local/emhttp/plugins/appdatasync/backup.py';

function postStr(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? null;
    return is_string($v) ? $v : $default;
}

function jsonResponse(bool $success, string $message): never
{
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

function isProcessRunning(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }

    exec('kill -0 ' . $pid . ' 2>/dev/null', $out, $rc);
    if ($rc !== 0) {
        return false;
    }

    $cmdline = @file_get_contents('/proc/' . $pid . '/cmdline');
    return $cmdline !== false && str_contains($cmdline, 'backup.py');
}

/**
 * @param array<string, mixed> $state
 */
function saveJobState(array $state): void
{
    $full = Settings::loadState();
    foreach ($state as $k => $v) {
        $full[$k] = $v;
    }
    Settings::saveState($full);
}

/**
 * @param list<string>         $groups
 * @param array<string, string> $extraEnv
 */
function startBackupJob(string $args, string $operation, array $groups, bool $dryRun, array $extraEnv = []): void
{
    $python = is_executable('/usr/bin/python3') ? '/usr/bin/python3' : 'python3';

    foreach (array_merge(['APPDATA_BACKUP_CONFIG' => Config::configPath()], $extraEnv) as $k => $v) {
        putenv((string)$k . '=' . (string)$v);
    }

    $logFile = LogManager::generatePath($operation);
    file_put_contents($logFile, '');
    @unlink(PID_FILE);

    $cmd = sprintf(
        'nohup %s %s %s > %s 2>&1 & echo $!',
        escapeshellarg($python),
        escapeshellarg(SCRIPT_PATH),
        $args,
        escapeshellarg($logFile)
    );

    $output = [];
    $rc     = 0;
    exec($cmd, $output, $rc);
    $pid = isset($output[0]) ? (int)$output[0] : 0;

    if ($pid <= 0) {
        jsonResponse(false, 'Failed to start backup job.');
    }

    file_put_contents(PID_FILE, (string)$pid);
    LogManager::setCurrentLog($logFile);
    saveJobState([
        'running'     => true,
        'pid'         => $pid,
        'started_at'  => date('c'),
        'operation'   => $operation,
        'groups'      => $groups,
        'dry_run'     => $dryRun,
        'last_result' => null,
    ]);

    echo json_encode(['success' => true, 'message' => 'Job started.', 'pid' => $pid]);
    exit;
}

$action = postStr('action');

switch ($action) {
    case 'get_config':
        try {
            $config = Config::load();
            echo json_encode(['success' => true, 'config' => $config]);
        } catch (\Throwable $e) {
            jsonResponse(false, $e->getMessage());
        }
        exit;

    case 'get_containers':
        try {
            $host   = postStr('host', 'local');
            $format = escapeshellarg('{{.Names}}');

            if ($host === '' || $host === 'local') {
                $output = shell_exec("docker ps --format {$format} 2>/dev/null");
            } else {
                $sshUser = postStr('ssh_user');
                $sshKey  = postStr('ssh_key');
                $sshPort = max(1, min(65535, (int)postStr('ssh_port', '22')));

                $cmd = 'ssh -o BatchMode=yes -o ConnectTimeout=5 -p ' . $sshPort;
                if ($sshKey !== '') {
                    $cmd .= ' -i ' . escapeshellarg($sshKey);
                }
                $target = $host;
                if ($sshUser !== '') {
                    $target = $sshUser . '@' . $host;
                }
                $cmd .= ' ' . escapeshellarg($target) . ' docker ps --format ' . $format . ' 2>/dev/null';
                $output = shell_exec($cmd);
            }

            $containers = [];
            if (is_string($output)) {
                foreach (explode("\n", $output) as $line) {
                    $name = trim($line);
                    if ($name !== '') {
                        $containers[] = $name;
                    }
                }
            }
            echo json_encode(['success' => true, 'containers' => $containers]);
        } catch (\Throwable $e) {
            jsonResponse(false, $e->getMessage());
        }
        exit;

    case 'save_config':
        try {
            $raw = json_decode(postStr('config'), true);
            if ( ! is_array($raw)) {
                jsonResponse(false, 'Invalid configuration payload.');
            }

            $config     = Config::toStringKeys($raw);
            $validation = Config::validate($config);
            if ( ! $validation['success']) {
                jsonResponse(false, $validation['message']);
            }

            Config::save($config);
            echo json_encode(['success' => true, 'message' => 'Configuration saved.']);
        } catch (\Throwable $e) {
            jsonResponse(false, $e->getMessage());
        }
        exit;

    case 'run_backup':
        if (isProcessRunning((int)@file_get_contents(PID_FILE))) {
            jsonResponse(false, 'A backup job is already running.');
        }

        $args   = '';
        $group  = postStr('group');
        $groups = $group !== '' ? [$group] : [];
        if ($group !== '') {
            $args .= ' --group ' . escapeshellarg($group);
        }
        $dryRun = postStr('dry_run') === 'true';
        if ($dryRun) {
            $args .= ' --dry-run';
        }
        if (postStr('debug') === 'true') {
            $args .= ' --debug';
        }

        startBackupJob($args, 'Backup', $groups, $dryRun);
        // never reached

        // no break
    case 'run_restore':
        if (isProcessRunning((int)@file_get_contents(PID_FILE))) {
            jsonResponse(false, 'A backup job is already running.');
        }

        $group = postStr('group');
        if ($group === '') {
            jsonResponse(false, 'Group is required for restore.');
        }

        $args   = ' --restore --restore-group ' . escapeshellarg($group);
        $dryRun = postStr('dry_run') === 'true';
        if ($dryRun) {
            $args .= ' --dry-run';
        }
        if (postStr('debug') === 'true') {
            $args .= ' --debug';
        }

        startBackupJob($args, 'Restore', [$group], $dryRun);
        // never reached

        // no break
    case 'run_restore_container':
        if (isProcessRunning((int)@file_get_contents(PID_FILE))) {
            jsonResponse(false, 'A backup job is already running.');
        }

        $group     = postStr('group');
        $container = postStr('container');
        if ($group === '' || $container === '') {
            jsonResponse(false, 'Group and container are required.');
        }

        $args = ' --restore --restore-group ' . escapeshellarg($group)
              . ' --restore-container ' . escapeshellarg($container);
        $dryRun = postStr('dry_run') === 'true';
        if ($dryRun) {
            $args .= ' --dry-run';
        }
        if (postStr('debug') === 'true') {
            $args .= ' --debug';
        }

        startBackupJob($args, 'Restore', [$group], $dryRun);
        // never reached

        // no break
    case 'poll_log':
        try {
            $offset  = max(0, (int)postStr('offset'));
            $logFile = LogManager::currentLog();
            if ($logFile === null || ! is_file($logFile)) {
                $logFile = null;
            }
            $full    = ($logFile !== null) ? (string)file_get_contents($logFile) : '';
            $pid     = (int)@file_get_contents(PID_FILE);
            $running = $pid > 0 && isProcessRunning($pid);

            $done   = ! $running && $pid > 0;
            $failed = $done      && LogManager::detectFailure($full);

            if ($done && $logFile !== null) {
                $state     = Settings::loadState();
                $operation = is_string($state['operation'] ?? null) ? (string)$state['operation'] : 'Backup';
                $groups    = isset($state['groups']) && is_array($state['groups'])
                    ? array_values(array_filter($state['groups'], 'is_string'))
                    : [];
                $dryRun  = (bool)($state['dry_run'] ?? false);
                $started = is_string($state['started_at'] ?? null) ? (string)$state['started_at'] : date('c');
                LogManager::finalizeRun($logFile, $operation, $groups, $dryRun, $started);
                @unlink(PID_FILE);
            }

            echo json_encode([
                'success' => true,
                'content' => substr($full, $offset),
                'offset'  => strlen($full),
                'running' => $running,
                'done'    => $done,
                'failed'  => $failed,
            ]);
        } catch (\Throwable $e) {
            jsonResponse(false, $e->getMessage());
        }
        exit;

    case 'job_status':
        try {
            $state   = Settings::loadState();
            $pid     = (int)@file_get_contents(PID_FILE);
            $running = $pid > 0 && isProcessRunning($pid);

            echo json_encode([
                'success'     => true,
                'running'     => $running,
                'pid'         => $running ? $pid : null,
                'last_run'    => $state['last_run']    ?? null,
                'last_result' => $state['last_result'] ?? null,
            ]);
        } catch (\Throwable $e) {
            jsonResponse(false, $e->getMessage());
        }
        exit;

    case 'get_history':
        try {
            echo json_encode(['success' => true, 'history' => LogManager::history()]);
        } catch (\Throwable $e) {
            jsonResponse(false, $e->getMessage());
        }
        exit;

    case 'view_log':
        try {
            $filename = postStr('filename');
            if ($filename === '') {
                jsonResponse(false, 'Filename is required.');
            }
            $content = LogManager::readLog($filename);
            echo json_encode(['success' => true, 'content' => $content]);
        } catch (\Throwable $e) {
            jsonResponse(false, $e->getMessage());
        }
        exit;

    case 'get_settings':
        try {
            $settings = Settings::load();
            echo json_encode(['success' => true, 'settings' => $settings]);
        } catch (\Throwable $e) {
            jsonResponse(false, $e->getMessage());
        }
        exit;

    case 'save_settings':
        try {
            $raw = json_decode(postStr('settings'), true);
            if ( ! is_array($raw)) {
                jsonResponse(false, 'Invalid settings payload.');
            }

            $validated = validateScheduleSettings($raw);
            Settings::save($validated);
            echo json_encode(['success' => true, 'message' => 'Schedule saved.']);
        } catch (\Throwable $e) {
            jsonResponse(false, $e->getMessage());
        }
        exit;

    default:
        jsonResponse(false, 'Unknown action.');
}

/**
 * @param array<mixed> $input
 * @return array<string, mixed>
 */
function validateScheduleSettings(array $input): array
{
    $frequencies = ['daily', 'weekly', 'monthly', 'custom'];
    $freq        = is_string($input['schedule_frequency'] ?? null)
        && in_array($input['schedule_frequency'], $frequencies, true)
        ? $input['schedule_frequency']
        : 'daily';

    $time = is_string($input['schedule_time'] ?? null)
        && preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $input['schedule_time'])
        ? $input['schedule_time']
        : '02:00';

    $day = is_numeric($input['schedule_day'] ?? null)
        ? max(0, min(6, (int)$input['schedule_day']))
        : 0;

    $cron = is_string($input['schedule_cron'] ?? null)
        && validateCron($input['schedule_cron'])
        ? $input['schedule_cron']
        : '0 2 * * *';

    $groups = [];
    if (is_array($input['schedule_groups'] ?? null)) {
        foreach ($input['schedule_groups'] as $g) {
            if (is_string($g) && $g !== '') {
                $groups[] = $g;
            }
        }
    } elseif (is_string($input['schedule_groups'] ?? null)) {
        foreach (array_filter(explode(',', $input['schedule_groups'])) as $g) {
            $g = trim($g);
            if ($g !== '') {
                $groups[] = $g;
            }
        }
    }

    return [
        'schedule_enabled'   => filter_var($input['schedule_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'schedule_frequency' => $freq,
        'schedule_time'      => $time,
        'schedule_day'       => $day,
        'schedule_cron'      => $cron,
        'schedule_groups'    => implode(',', $groups),
        'schedule_dry_run'   => filter_var($input['schedule_dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN),
    ];
}

function validateCron(string $expression): bool
{
    $fields = preg_split('/\s+/', trim($expression));
    if ( ! is_array($fields) || count($fields) !== 5) {
        return false;
    }

    $patterns = [
        '/^(\*|[\*\/\-,0-9]+)$/',
        '/^(\*|[\*\/\-,0-9]+)$/',
        '/^(\*|[\*\/\-,?LW0-9]+)$/i',
        '/^(\*|[\*\/\-,A-Z0-9]+)$/i',
        '/^(\*|[\*\/\-,A-Z0-9]+)$/i',
    ];

    foreach ($fields as $i => $field) {
        if ( ! preg_match($patterns[$i], (string)$field)) {
            return false;
        }
    }

    return true;
}
