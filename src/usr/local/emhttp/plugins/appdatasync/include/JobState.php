<?php

declare(strict_types=1);

namespace UnraidAppdataSync;

final class JobState
{
    public const PID_FILE    = '/tmp/appdatasync.pid';
    public const SCRIPT_PATH = '/usr/local/emhttp/plugins/appdatasync/backup.py';

    /**
     * @phpstan-impure
     */
    public static function isProcessRunning(int $pid): bool
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
    public static function saveJobState(array $state): void
    {
        $full = Settings::loadState();
        foreach ($state as $k => $v) {
            $full[$k] = $v;
        }
        Settings::saveState($full);
    }

    public static function pidFile(): string
    {
        return self::PID_FILE;
    }

    public static function scriptPath(): string
    {
        return self::SCRIPT_PATH;
    }

    public static function pythonBinary(): string
    {
        return is_executable('/usr/bin/python3') ? '/usr/bin/python3' : 'python3';
    }
}
