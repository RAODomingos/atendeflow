<?php

namespace App\Models;

use App\Core\Database;

class UserPreference
{
    public static function get(int $userId, string $key, $default = null)
    {
        $row = Database::getInstance()->fetch(
            "SELECT preference_value FROM user_preferences WHERE user_id = ? AND preference_key = ?",
            [$userId, $key]
        );
        if (!$row) return $default;
        $val = $row['preference_value'];
        if ($val === 'true') return true;
        if ($val === 'false') return false;
        if (is_numeric($val)) return $val + 0;
        return $val;
    }

    public static function set(int $userId, string $key, $value): void
    {
        $db = Database::getInstance();
        $str = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        $affected = $db->update(
            'user_preferences',
            ['preference_value' => $str],
            'user_id = ? AND preference_key = ?',
            [$userId, $key]
        );
        if ($affected === 0) {
            $db->insert('user_preferences', [
                'user_id' => $userId,
                'preference_key' => $key,
                'preference_value' => $str,
            ]);
        }
    }

    public static function getAll(int $userId): array
    {
        $rows = Database::getInstance()->fetchAll(
            "SELECT preference_key, preference_value FROM user_preferences WHERE user_id = ?",
            [$userId]
        );
        $result = [];
        foreach ($rows as $r) {
            $val = $r['preference_value'];
            if ($val === 'true') $val = true;
            elseif ($val === 'false') $val = false;
            elseif (is_numeric($val)) $val = $val + 0;
            $result[$r['preference_key']] = $val;
        }
        return $result;
    }
}
