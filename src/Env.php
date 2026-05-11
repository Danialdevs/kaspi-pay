<?php
declare(strict_types=1);

namespace Kaspi;

final class Env
{
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (self::$loaded) return;
        self::$loaded = true;
        if (!is_file($path)) return;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
            $k = trim($k);
            $v = trim($v, " \t\"'");
            if ($k === '') continue;
            $_ENV[$k] = $v;
            putenv("$k=$v");
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $v = $_ENV[$key] ?? getenv($key);
        if ($v === false || $v === null || $v === '') return $default;
        return (string)$v;
    }
}
