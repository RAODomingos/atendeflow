<?php

namespace App\Models;

use App\Core\Database;

/**
 * Widgets de ChatWeb (um por canal webchat).
 */
class WebchatWidget
{
    public static function all(): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT id, title, widget_key, color_primary, position, is_active, avatar_url,
                    ask_name, require_name, ask_email, require_email,
                    ask_phone, require_phone, ask_cnpj, require_cnpj
             FROM webchat_widgets ORDER BY is_active DESC, id ASC"
        );
    }

    public static function find(int $id): ?array
    {
        return Database::getInstance()->fetch("SELECT * FROM webchat_widgets WHERE id = ?", [$id]);
    }

    public static function findByKey(string $key): ?array
    {
        return Database::getInstance()->fetch("SELECT * FROM webchat_widgets WHERE widget_key = ?", [$key]);
    }

    public static function findByChannel(int $channelId): ?array
    {
        return Database::getInstance()->fetch("SELECT * FROM webchat_widgets WHERE channel_id = ?", [$channelId]);
    }

    public static function update(int $id, array $data): int
    {
        return Database::getInstance()->update('webchat_widgets', $data, 'id = ?', [$id]);
    }

    public static function regenerateKey(int $id): string
    {
        $key = bin2hex(random_bytes(16));
        self::update($id, ['widget_key' => $key]);
        return $key;
    }
}
