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
        // INSERT ... ON DUPLICATE KEY UPDATE — evita o problema do
        // rowCount()=0 quando o valor já é igual ao existente, que levava
        // a um INSERT duplicado e erro 500 ("Duplicate entry ... for key 'key'").
        $sql = "INSERT INTO settings (`key`, value) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE value = VALUES(value)";
        $db->execute($sql, [$key, (string) $value]);
        self::$cache = null;
    }
}
