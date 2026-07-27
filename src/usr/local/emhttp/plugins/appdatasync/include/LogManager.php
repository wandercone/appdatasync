<?php

declare(strict_types=1);

namespace UnraidAppdataSync;

final class LogManager
{
    private const LOGS_SUBDIR   = '/logs';
    private const HISTORY_KEEP  = 5;
    private const LOG_EXTENSION = '.log';

    public static function logDir(): string
    {
        return Settings::configDir() . self::LOGS_SUBDIR;
    }

    public static function ensureDir(): void
    {
        $dir = self::logDir();
        if ( ! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    public static function generatePath(string $operation = 'backup'): string
    {
        self::ensureDir();

        $base = sprintf(
            '%s_%s-%s%s',
            date('Y-m-d_H-i-s'),
            $operation,
            substr(str_replace('.', '', uniqid('', true)), -5),
            self::LOG_EXTENSION
        );

        return self::logDir() . '/' . $base;
    }

    /**
     * @param array<string, mixed> $entry
     */
    public static function addHistoryEntry(array $entry): void
    {
        $state   = Settings::loadState();
        $history = (isset($state['history']) && is_array($state['history'])) ? $state['history'] : [];

        $history[] = self::toStringKeys($entry);

        $state['history'] = self::pruneHistory($history);
        Settings::saveState($state);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function history(): array
    {
        $state = Settings::loadState();
        if ( ! isset($state['history']) || ! is_array($state['history'])) {
            return [];
        }

        $result = [];
        foreach ($state['history'] as $item) {
            if (is_array($item)) {
                $result[] = self::toStringKeys($item);
            }
        }

        return $result;
    }

    public static function cleanup(int $keep = self::HISTORY_KEEP): void
    {
        self::ensureDir();

        $files = [];
        $dir   = self::logDir();
        foreach ((array)glob($dir . '/*' . self::LOG_EXTENSION) as $file) {
            if (is_string($file) && is_file($file)) {
                $files[] = $file;
            }
        }

        usort($files, static function (string $a, string $b): int {
            return ((int)filemtime($b)) <=> ((int)filemtime($a));
        });

        foreach (array_slice($files, $keep) as $file) {
            @unlink($file);
        }

        $state   = Settings::loadState();
        $history = (isset($state['history']) && is_array($state['history'])) ? $state['history'] : [];
        $pruned  = self::pruneHistory($history, $keep);

        if ($pruned !== $history) {
            $state['history'] = $pruned;
            Settings::saveState($state);
        }
    }

    public static function readLog(string $filename): string
    {
        $basename = basename($filename);
        if ( ! preg_match('/^[A-Za-z0-9_\-]+\.log$/', $basename)) {
            throw new \RuntimeException('Invalid log filename.');
        }

        $path = self::logDir() . '/' . $basename;
        if ( ! is_file($path) || ! is_readable($path)) {
            throw new \RuntimeException('Log file not found or not readable.');
        }

        $contents = file_get_contents($path);
        return $contents !== false ? $contents : '';
    }

    public static function setCurrentLog(string $path): void
    {
        $state                = Settings::loadState();
        $state['current_log'] = $path;
        Settings::saveState($state);
    }

    public static function clearCurrentLog(): void
    {
        $state = Settings::loadState();
        unset($state['current_log']);
        Settings::saveState($state);
    }

    public static function currentLog(): ?string
    {
        $state = Settings::loadState();
        $log   = $state['current_log'] ?? null;

        return is_string($log) ? $log : null;
    }

    public static function detectFailure(string $log): bool
    {
        if (preg_match('/RESULT:\s*failed/i', $log)) {
            return true;
        }

        if (preg_match('/\b(\d+)\s+failed\b/', $log, $m)) {
            return (int)$m[1] > 0;
        }

        if (preg_match('/\b(CRITICAL|FAILED|rsync failed|Backup error|Restore error)\b/i', $log)) {
            return true;
        }

        return false;
    }

    /**
     * @param list<string> $groups
     */
    public static function finalizeRun(string $logFile, string $operation, array $groups, bool $dryRun, string $startedAt): string
    {
        $full   = is_file($logFile) ? (string)file_get_contents($logFile) : '';
        $failed = self::detectFailure($full);
        $result = $failed ? 'failed' : 'success';

        $state                = Settings::loadState();
        $state['running']     = false;
        $state['pid']         = null;
        $state['last_run']    = date('c');
        $state['last_result'] = $result;
        Settings::saveState($state);

        self::addHistoryEntry([
            'started_at'  => $startedAt,
            'finished_at' => date('c'),
            'operation'   => $operation,
            'groups'      => $groups,
            'dry_run'     => $dryRun,
            'result'      => $result,
            'log_file'    => $logFile,
        ]);

        self::cleanup();
        self::clearCurrentLog();

        return $result;
    }

    /**
     * @param array<mixed> $history
     * @return list<array<string, mixed>>
     */
    private static function pruneHistory(array $history, int $keep = self::HISTORY_KEEP): array
    {
        $filtered = [];
        foreach ($history as $item) {
            if ( ! is_array($item)) {
                continue;
            }

            $item = self::toStringKeys($item);
            $log  = $item['log_file'] ?? null;

            if (is_string($log) && is_file($log)) {
                $filtered[] = $item;
            } elseif ($log === null) {
                $filtered[] = $item;
            }
        }

        usort($filtered, static function (array $a, array $b): int {
            $ta = is_string($a['started_at'] ?? null) ? (string)$a['started_at'] : '';
            $tb = is_string($b['started_at'] ?? null) ? (string)$b['started_at'] : '';

            return $tb <=> $ta;
        });

        return array_slice($filtered, 0, $keep);
    }

    /**
     * @param array<mixed> $data
     * @return array<string, mixed>
     */
    private static function toStringKeys(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $result[(string)$key] = is_array($value) ? self::toStringKeys($value) : $value;
        }

        return $result;
    }
}
