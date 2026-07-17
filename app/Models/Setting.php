<?php

namespace App\Models;

use App\Core\Database;

/**
 * Armazena configurações chave-valor do sistema (horário de funcionamento,
 * mensagens de ausência, CSAT, etc.).
 */
class Setting
{
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            $rows = Database::getInstance()->fetchAll("SELECT `key`, value FROM settings");
            $map = [];
            foreach ($rows as $r) {
                $map[$r['key']] = $r['value'];
            }
            self::$cache = $map;
        }
        return self::$cache;
    }

    public static function get(string $key, $default = null)
    {
        $all = self::all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function set(string $key, $value): void
    {
        $db = Database::getInstance();
        $affected = $db->update('settings', ['value' => $value], '`key` = ?', [$key]);
        if ($affected === 0) {
            $db->insert('settings', ['key' => $key, 'value' => $value]);
        }
        self::$cache = null;
    }
}
