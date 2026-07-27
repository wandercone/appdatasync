<?php

declare(strict_types=1);

namespace UnraidAppdataSync;

final class Settings
{
    private const CFG_DIR    = '/boot/config/plugins/appdatasync';
    private const CFG_FILE   = '/appdatasync.cfg';
    private const STATE_FILE = '/state.json';
    private const CRON_FILE  = '/appdatasync.cron';

    /** @var array<string, mixed> */
    private const DEFAULTS = [
        'schedule_enabled'   => false,
        'schedule_frequency' => 'daily',
        'schedule_time'      => '02:00',
        'schedule_day'       => 0,
        'schedule_cron'      => '0 2 * * *',
        'schedule_groups'    => '',
        'schedule_dry_run'   => false,
    ];

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    /** @return array<string, mixed> */
    public static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        self::$cache = self::loadSettings();
        return self::$cache;
    }

    /** @param array<string, mixed> $settings */
    public static function save(array $settings): void
    {
        self::ensureDir();

        $merged      = array_merge(self::load(), $settings);
        self::$cache = $merged;

        $lines = [];
        foreach ($merged as $key => $value) {
            if (is_bool($value)) {
                $lineValue = $value ? 'true' : 'false';
            } elseif (is_array($value)) {
                $lineValue = implode(',', self::toStringList($value));
            } elseif (is_string($value) || is_int($value) || is_float($value)) {
                $lineValue = (string)$value;
            } else {
                $lineValue = '';
            }
            $lines[] = self::iniLine((string)$key, $lineValue);
        }

        $cfgFile = self::cfgPath();
        $tmp     = $cfgFile . '.tmp';
        if (file_put_contents($tmp, implode("\n", $lines) . "\n") === false) {
            throw new \RuntimeException('Failed to write settings.');
        }
        rename($tmp, $cfgFile);
        chmod($cfgFile, 0o644);

        self::writeCronFile($merged);
    }

    /** @return array<string, mixed> */
    public static function loadState(): array
    {
        $path = self::statePath();
        if ( ! is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if ($contents === false || $contents === '') {
            return [];
        }

        $decoded = json_decode($contents, true);
        return is_array($decoded) ? self::toStringKeys($decoded) : [];
    }

    /** @param array<string, mixed> $state */
    public static function saveState(array $state): void
    {
        self::ensureDir();
        $path    = self::statePath();
        $tmp     = $path . '.tmp';
        $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode state.');
        }
        if (file_put_contents($tmp, $encoded) === false) {
            throw new \RuntimeException('Failed to write state.');
        }
        rename($tmp, $path);
    }

    public static function installCron(): void
    {
        self::writeCronFile(self::load());
    }

    public static function removeCron(): void
    {
        $cronFile = self::cronPath();
        if (is_file($cronFile)) {
            @unlink($cronFile);
        }
        @exec('/usr/local/sbin/update_cron >/dev/null 2>&1');
    }

    public static function phpBinary(): string
    {
        return '/usr/bin/php';
    }

    public static function cronPath(): string
    {
        return self::CFG_DIR . self::CRON_FILE;
    }

    public static function cfgPath(): string
    {
        return self::CFG_DIR . self::CFG_FILE;
    }

    public static function statePath(): string
    {
        return self::CFG_DIR . self::STATE_FILE;
    }

    public static function configDir(): string
    {
        $env = getenv('APPDATA_BACKUP_CONFIG_DIR');
        if (is_string($env) && $env !== '') {
            return rtrim($env, '/');
        }
        return self::CFG_DIR;
    }

    /** @param array<string, mixed> $settings */
    public static function buildCronExpression(array $settings): string
    {
        $parts = self::parseTime(self::string($settings, 'schedule_time', '02:00'));
        $freq  = self::string($settings, 'schedule_frequency', 'daily');
        $day   = max(0, min(6, self::int($settings, 'schedule_day', 0)));

        return match ($freq) {
            'daily'   => "{$parts['m']} {$parts['h']} * * *",
            'weekly'  => "{$parts['m']} {$parts['h']} * * {$day}",
            'monthly' => "{$parts['m']} {$parts['h']} 1 * *",
            default   => self::string($settings, 'schedule_cron', '0 2 * * *'),
        };
    }

    /**
     * @param array<string, mixed> $settings
     * @return list<string>
     */
    public static function scheduleGroups(array $settings): array
    {
        $raw    = self::string($settings, 'schedule_groups', '');
        $groups = [];
        foreach (array_filter(explode(',', $raw)) as $g) {
            $g = trim($g);
            if ($g !== '') {
                $groups[] = $g;
            }
        }
        return $groups;
    }

    /**
     * @param array<string, mixed> $array
     */
    public static function string(array $array, string $key, string $default = ''): string
    {
        $value = $array[$key] ?? $default;
        return is_string($value) ? $value : (is_int($value) || is_float($value) ? (string)$value : $default);
    }

    /**
     * @param array<string, mixed> $array
     */
    public static function int(array $array, string $key, int $default): int
    {
        $value = $array[$key] ?? $default;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int)$value;
        }
        return $default;
    }

    public static function ensureDir(): void
    {
        $dir = self::configDir();
        if ( ! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
    }

    /** @return array<string, mixed> */
    private static function loadSettings(): array
    {
        $path  = self::cfgPath();
        $saved = [];
        if (is_file($path)) {
            $parsed = parse_ini_file($path);
            if (is_array($parsed)) {
                $saved = self::toStringKeys($parsed);
            }
        }

        $merged                     = array_merge(self::DEFAULTS, $saved);
        $merged['schedule_enabled'] = filter_var($merged['schedule_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $merged['schedule_dry_run'] = filter_var($merged['schedule_dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        return $merged;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private static function writeCronFile(array $settings): void
    {
        self::ensureDir();

        $enabled = (bool)($settings['schedule_enabled'] ?? false);
        $cron    = self::buildCronExpression($settings);
        $dryRun  = (bool)($settings['schedule_dry_run'] ?? false);
        $groups  = self::scheduleGroups($settings);

        $php    = self::phpBinary();
        $script = '/usr/local/emhttp/plugins/appdatasync/include/cron.php';
        $args   = $dryRun ? 'backup --dry-run' : 'backup';
        if ($groups !== []) {
            $args .= ' --groups ' . escapeshellarg(implode(',', $groups));
        }

        $lines = ['# Generated appdatasync plugin scheduled tasks'];
        if ($enabled) {
            $lines[] = "{$cron} {$php} {$script} {$args} >/tmp/appdatasync-cron.log 2>&1";
        }

        $cronFile = self::cronPath();
        $tmp      = $cronFile . '.tmp';
        file_put_contents($tmp, implode("\n", $lines) . "\n\n");
        rename($tmp, $cronFile);
        chmod($cronFile, 0o644);

        @exec('/usr/local/sbin/update_cron >/dev/null 2>&1');
    }

    private static function iniLine(string $key, string $value): string
    {
        return $key . '="' . str_replace(['"', '\\'], ['\\"', '\\\\'], $value) . '"';
    }

    /**
     * @return array{h: string, m: string}
     */
    private static function parseTime(string $time): array
    {
        $t = trim($time);
        if ( ! preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $t, $m)) {
            return ['h' => '2', 'm' => '00'];
        }

        $h   = (int)$m[1];
        $min = (int)$m[2];
        if ($h < 0 || $h > 23 || $min < 0 || $min > 59) {
            return ['h' => '2', 'm' => '00'];
        }

        return ['h' => (string)$h, 'm' => sprintf('%02d', $min)];
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

    /**
     * @param array<mixed> $data
     * @return list<string>
     */
    private static function toStringList(array $data): array
    {
        $result = [];
        foreach ($data as $value) {
            if (is_string($value) || is_int($value) || is_float($value)) {
                $result[] = (string)$value;
            }
        }
        return $result;
    }
}
