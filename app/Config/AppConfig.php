<?php

namespace App\Config;

use App\Config\Settings;

class AppConfig
{
    public static function get(string $key, $default = null)
    {
        Settings::load();
        return Settings::get($key, $default);
    }

    public static function getAll(): array
    {
        Settings::load();
        return Settings::getAll();
    }

    public static function set(string $key, $value): void
    {
        Settings::set($key, $value);
    }
}