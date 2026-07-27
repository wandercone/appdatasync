<?php

declare(strict_types=1);

namespace UnraidAppdataSync;

final class Config
{
    private const CONFIG_DIR  = '/boot/config/plugins/appdatasync';
    private const CONFIG_FILE = '/config.yaml';
    private const EXAMPLE     = '/usr/local/emhttp/plugins/appdatasync/config.yaml.example';

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    /** @return array<string, mixed> */
    public static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $path = self::configPath();
        if ( ! is_file($path)) {
            self::ensureConfig();
        }

        $raw = self::readYaml($path);
        if ( ! is_array($raw)) {
            $raw = self::readYaml(self::EXAMPLE);
        }
        $data = is_array($raw) ? self::toStringKeys(self::migrateLegacyConfig($raw)) : null;
        if ( ! is_array($data)) {
            if (is_file($path) && ! is_readable($path)) {
                throw new \RuntimeException('Configuration file is not readable: ' . $path);
            }
            throw new \RuntimeException('Failed to parse configuration file: ' . $path);
        }

        self::$cache = $data;
        return self::$cache;
    }

    /** @param array<string, mixed> $config */
    public static function save(array $config): void
    {
        self::ensureDir();
        $path = self::configPath();
        $tmp  = $path . '.tmp';

        if ( ! self::writeYaml($tmp, $config)) {
            throw new \RuntimeException('Failed to write configuration.');
        }

        rename($tmp, $path);
        chmod($path, 0o644);
        self::$cache = $config;
    }

    /**
     * @param array<string, mixed> $config
     * @return array{success: bool, message: string}
     */
    public static function validate(array $config): array
    {
        $backupDestination = self::string($config, 'backup_destination');
        if ($backupDestination === '') {
            return ['success' => false, 'message' => 'backup_destination is required.'];
        }

        $config = self::migrateLegacyConfig($config);

        $hosts = [];
        if (is_array($config['hosts'] ?? null)) {
            foreach ($config['hosts'] as $index => $host) {
                if (!is_array($host)) {
                    return ['success' => false, 'message' => 'hosts must be a list of host definitions.'];
                }
                $hostName = self::string($host, 'name');
                if ($hostName === '') {
                    return ['success' => false, 'message' => 'Host at index ' . $index . ' is missing a name.'];
                }
                if (isset($hosts[$hostName])) {
                    return ['success' => false, 'message' => "Duplicate host name '{$hostName}'."];
                }
                $hosts[$hostName] = $host;
                if ($hostName !== 'local') {
                    $port = self::int($host, 'ssh_port', 22);
                    if ($port < 1 || $port > 65535) {
                        return ['success' => false, 'message' => "Host '{$hostName}' has an invalid ssh_port."];
                    }
                }
            }
        }
        if (!isset($hosts['local'])) {
            return ['success' => false, 'message' => 'A host named "local" is required.'];
        }

        if (!is_array($config['groups'] ?? null)) {
            return ['success' => false, 'message' => 'groups must be a mapping.'];
        }

        $groups = $config['groups'];
        foreach ($groups as $groupName => $containers) {
            if ($groupName === '') {
                return ['success' => false, 'message' => 'Group names must be non-empty strings.'];
            }
            if (!is_array($containers)) {
                return ['success' => false, 'message' => "Group '{$groupName}' must contain a list of containers."];
            }

            foreach ($containers as $container) {
                if (!is_array($container)) {
                    return ['success' => false, 'message' => "Group '{$groupName}' contains an invalid container entry."];
                }
                $name = self::string($container, 'name');
                if ($name === '') {
                    return ['success' => false, 'message' => "Group '{$groupName}' has a container without a name."];
                }
                $hostName = self::string($container, 'host', 'local');
                if ($hostName === '') {
                    return ['success' => false, 'message' => "Container '{$name}' has an empty host."];
                }
                if (!isset($hosts[$hostName])) {
                    return ['success' => false, 'message' => "Container '{$name}' references unknown host '{$hostName}'."];
                }
                $hostDef = $hosts[$hostName];
                $override = self::bool($container, 'ssh_override', false);
                if ($hostName !== 'local') {
                    $hasHostSsh = self::string($hostDef, 'ssh_user') !== '';
                    $hasOverrideSsh = $override && self::string($container, 'ssh_user') !== '';
                    if (!$hasHostSsh && !$hasOverrideSsh) {
                        return ['success' => false, 'message' => "Container '{$name}' on remote host '{$hostName}' requires ssh_user on the host or an override."];
                    }
                }
                if ($override) {
                    $port = self::int($container, 'ssh_port', 22);
                    if ($port < 1 || $port > 65535) {
                        return ['success' => false, 'message' => "Container '{$name}' has an invalid ssh_port override."];
                    }
                }
                $delay = self::int($container, 'start_delay', 0);
                if ($delay < 0) {
                    return ['success' => false, 'message' => "Container '{$name}' has a negative start_delay."];
                }
            }
        }

        return ['success' => true, 'message' => 'Configuration is valid.'];
    }
    public static function configDir(): string
    {
        $env = getenv('APPDATA_BACKUP_CONFIG_DIR');
        if ($env !== false && $env !== '') {
            return rtrim($env, '/');
        }
        return self::CONFIG_DIR;
    }

    public static function configPath(): string
    {
        return self::configDir() . self::CONFIG_FILE;
    }

    public static function ensureConfig(): void
    {
        self::ensureDir();
        $path = self::configPath();
        if ( ! is_file($path) && is_file(self::EXAMPLE)) {
            copy(self::EXAMPLE, $path);
        }
    }

    public static function ensureDir(): void
    {
        $dir = self::configDir();
        if ( ! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
    }

    /**
     * @param array<mixed, mixed> $array
     */
    public static function string(array $array, string $key, string $default = ''): string
    {
        $value = $array[$key] ?? $default;
        return is_string($value) ? $value : (is_int($value) || is_float($value) ? (string)$value : $default);
    }

    /**
     * @param array<mixed, mixed> $array
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

    public static function bool(array $array, string $key, bool $default): bool
    {
        $value = $array[$key] ?? $default;
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['yes', 'true', '1'], true);
        }
        if (is_int($value) || is_float($value)) {
            return (bool)$value;
        }
        return $default;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function readYaml(string $path): ?array
    {
        if ( ! is_file($path)) {
            return null;
        }

        if (extension_loaded('yaml')) {
            $data = @yaml_parse_file($path);
            return is_array($data) ? self::toStringKeys($data) : null;
        }

        $cmd = sprintf(
            'python3 -c %s %s 2>/dev/null',
            escapeshellarg('import yaml,json,sys; print(json.dumps(yaml.safe_load(open(sys.argv[1], "r"))))'),
            escapeshellarg($path)
        );
        $output = shell_exec($cmd);
        if ( ! is_string($output) || $output === '') {
            return null;
        }

        $decoded = json_decode(trim($output), true);
        return is_array($decoded) ? self::toStringKeys($decoded) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function writeYaml(string $path, array $data): bool
    {
        if (extension_loaded('yaml')) {
            return @yaml_emit_file($path, $data, YAML_UTF8_ENCODING, YAML_ANY_BREAK) !== false;
        }

        $cmd = sprintf(
            'python3 -c %s > %s',
            escapeshellarg('import yaml,json,sys; data=json.loads(sys.stdin.read()); yaml.safe_dump(data, sys.stdout, default_flow_style=False, sort_keys=False)'),
            escapeshellarg($path)
        );
        $process = proc_open(
            $cmd,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        if ( ! is_resource($process)) {
            return false;
        }

        $json = json_encode($data);
        if ($json === false) {
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            return false;
        }

        fwrite($pipes[0], $json);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($process);

        return $rc === 0;
    }

    /**
     * @param array<mixed> $data
     * @return array<string, mixed>
     */
    public static function toStringKeys(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $result[(string)$key] = is_array($value) ? self::toStringKeys($value) : $value;
        }
        return $result;
    }

    /**
     * Convert legacy configs that store SSH credentials inline on each container
     * into the new hosts + optional override format.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function migrateLegacyConfig(array $config): array
    {
        if (!is_array($config['groups'] ?? null)) {
            return $config;
        }

        // If hosts already exist, only ensure local is present.
        $hosts = [];
        if (is_array($config['hosts'] ?? null)) {
            foreach ($config['hosts'] as $host) {
                if (is_array($host) && self::string($host, 'name') !== '') {
                    $hosts[self::string($host, 'name')] = $host;
                }
            }
        }
        if (!isset($hosts['local'])) {
            $hosts['local'] = ['name' => 'local'];
        }

        $needsMigration = false;
        $groups = $config['groups'];
        foreach ($groups as $groupName => &$containers) {
            if (!is_array($containers)) {
                continue;
            }
            foreach ($containers as &$container) {
                if (!is_array($container)) {
                    continue;
                }
                $hostName = self::string($container, 'host', 'local');
                if ($hostName === 'local' || $hostName === '') {
                    continue;
                }
                // Legacy: host value is the actual address and ssh_* may be inline.
                if (isset($hosts[$hostName])) {
                    // Already migrated to a named host; keep inline SSH as override if present.
                    if (self::string($container, 'ssh_user') !== '' || self::string($container, 'ssh_key') !== '') {
                        $container['ssh_override'] = true;
                    }
                    continue;
                }
                $needsMigration = true;
                $newHostName = $hostName;
                // Avoid collisions with an existing host of the same address.
                if (isset($hosts[$newHostName])) {
                    $i = 1;
                    while (isset($hosts[$newHostName . '-' . $i])) {
                        $i++;
                    }
                    $newHostName = $newHostName . '-' . $i;
                }
                $hosts[$newHostName] = [
                    'name'     => $newHostName,
                    'ssh_user' => self::string($container, 'ssh_user'),
                    'ssh_key'  => self::string($container, 'ssh_key'),
                    'ssh_port' => self::int($container, 'ssh_port', 22),
                ];
                $container['host'] = $newHostName;
                // Remove inherited SSH fields from container; keep nothing unless override existed.
                if (self::string($container, 'ssh_user') !== '' || self::string($container, 'ssh_key') !== '') {
                    $container['ssh_override'] = true;
                } else {
                    unset($container['ssh_user'], $container['ssh_key'], $container['ssh_port']);
                }
            }
            unset($container);
        }
        unset($containers);

        if ($needsMigration || !isset($config['hosts'])) {
            $config['hosts'] = array_values($hosts);
        }

        return $config;
    }
}
