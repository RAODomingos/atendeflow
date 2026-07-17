<?php

namespace App\Models;

use App\Core\Database;

class UserPresence
{
    public static function online(int $userId): void
    {
        Database::getInstance()->insert('user_presence', [
            'user_id' => $userId,
            'is_online' => 1,
            'last_seen_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function update(int $userId): void
    {
        $exists = Database::getInstance()->fetch(
            "SELECT id FROM user_presence WHERE user_id = ?",
            [$userId]
        );

        if ($exists) {
            Database::getInstance()->update(
                'user_presence',
                ['is_online' => 1, 'last_seen_at' => date('Y-m-d H:i:s')],
                'user_id = ?',
                [$userId]
            );
        } else {
            self::online($userId);
        }
    }

    public static function offline(int $userId): void
    {
        Database::getInstance()->update(
            'user_presence',
            ['is_online' => 0, 'last_seen_at' => date('Y-m-d H:i:s')],
            'user_id = ?',
            [$userId]
        );
    }

    public static function isOnline(int $userId): bool
    {
        $row = Database::getInstance()->fetch(
            "SELECT is_online FROM user_presence WHERE user_id = ?",
            [$userId]
        );
        return $row && $row['is_online'];
    }

    public static function getOnlineUsers(): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT u.id, u.name, u.email, u.avatar, up.last_seen_at
             FROM user_presence up
             JOIN users u ON u.id = up.user_id
             WHERE up.is_online = 1
             ORDER BY u.name"
        );
    }

    public static function cleanup(): void
    {
        Database::getInstance()->update(
            'user_presence',
            ['is_online' => 0],
            'last_seen_at < NOW() - INTERVAL 5 MINUTE',
            []
        );
    }
}
