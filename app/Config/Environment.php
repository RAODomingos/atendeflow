<?php

namespace App\Config;

use Exception;

class Environment
{
    public static function load(string $path = ''): array
    {
        if (empty($path)) {
            $path = __DIR__ . '/../../env/.env';
        }

        if (!file_exists($path)) {
            throw new Exception("Arquivo .env não encontrado em: $path");
        }

        $env = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || $line[0] === '#') {
                continue;
            }

            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // Remove surrounding quotes if present
                if ((str_starts_with($value, '"') && str_ends_with($value, '"'))
                    || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                    $value = substr($value, 1, -1);
                }

                // Putenv / $_ENV / $_SERVER for compatibility with env() helper
                putenv("$key=$value");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;

                if (str_starts_with($key, 'ATENDeflow_')) {
                    $envKey = 'ATENDeflow_' . strtolower(str_replace('ATENDeflow_', '', $key));
                    $env[$envKey] = $value;
                }
            }
        }

        return $env;
    }
}
