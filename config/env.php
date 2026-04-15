<?php
/**
 * Minimal .env loader for Home Finances.
 *
 * - Loads repo-root /.env if present
 * - Does not override already-set process environment variables
 * - Exposes env_value() helper for PHP runtime use
 */

if (!function_exists('load_finance_env')) {
    function load_finance_env(?string $path = null): void {
        static $loaded = [];

        $path = $path ?? dirname(__DIR__) . '/.env';
        if (isset($loaded[$path])) {
            return;
        }
        $loaded[$path] = true;

        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $value = trim($parts[1]);

            if ($key === '') {
                continue;
            }

            $hasEnv = array_key_exists($key, $_ENV) || array_key_exists($key, $_SERVER) || getenv($key) !== false;
            if ($hasEnv) {
                continue;
            }

            $len = strlen($value);
            if ($len >= 2) {
                $first = $value[0];
                $last  = $value[$len - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

if (!function_exists('env_value')) {
    function env_value(string $key, $default = null) {
        if (array_key_exists($key, $_ENV)) {
            return $_ENV[$key];
        }
        if (array_key_exists($key, $_SERVER)) {
            return $_SERVER[$key];
        }
        $value = getenv($key);
        return $value === false ? $default : $value;
    }
}

load_finance_env();
