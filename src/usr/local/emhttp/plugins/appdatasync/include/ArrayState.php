<?php

declare(strict_types=1);

namespace UnraidAppdataSync;

final class ArrayState
{
    private const VAR_INI = '/var/local/emhttp/var.ini';

    /**
     * @return array<string, string>
     */
    public static function varIni(): array
    {
        $parsed = @parse_ini_file(self::VAR_INI);
        return is_array($parsed) ? $parsed : [];
    }

    public static function state(): string
    {
        $state = self::varIni()['mdState'] ?? '';
        return is_string($state) ? strtoupper($state) : '';
    }

    public static function isStarted(): bool
    {
        return self::state() === 'STARTED';
    }
}
