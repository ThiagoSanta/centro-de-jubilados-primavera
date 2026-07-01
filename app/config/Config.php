<?php

namespace CJP\Config;

class Config
{
    private static ?array $config = null;

    /**
     * Get a configuration value by key, or return default.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null): mixed
    {
        if (self::$config === null) {
            self::load();
        }

        return self::$config[$key] ?? $default;
    }

    /**
     * Load and parse .env file line by line.
     *
     * @return void
     */
    private static function load(): void
    {
        self::$config = [];
        $envPath = dirname(__DIR__, 2) . '/.env';

        if (!file_exists($envPath)) {
            return;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // Ignore comments and empty lines
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Parse KEY=VALUE
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // Strip quotes if present
                if (
                    (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))
                ) {
                    $value = substr($value, 1, -1);
                } else {
                    // Cast common string representations to PHP types
                    $lowerVal = strtolower($value);
                    if ($lowerVal === 'true') {
                        $value = true;
                    } elseif ($lowerVal === 'false') {
                        $value = false;
                    } elseif ($lowerVal === 'null') {
                        $value = null;
                    }
                }

                self::$config[$key] = $value;
            }
        }
    }
}
