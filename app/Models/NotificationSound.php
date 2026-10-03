<?php

namespace App\Models;

use App\Core\Database;

class NotificationSound
{
    public const MAX_PER_USER = 10;
    public const MAX_BYTES = 1024 * 1024; // 1 MB (toque curto de notificação)

    public static function forUser(int $userId): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT * FROM notification_sounds WHERE user_id = ? ORDER BY id ASC",
            [$userId]
        );
    }

    public static function find(int $id): ?array
    {
        return Database::getInstance()->fetch(
            "SELECT * FROM notification_sounds WHERE id = ?",
            [$id]
        );
    }

    public static function countForUser(int $userId): int
    {
        $row = Database::getInstance()->fetch(
            "SELECT COUNT(*) as c FROM notification_sounds WHERE user_id = ?",
            [$userId]
        );
        return (int) ($row['c'] ?? 0);
    }

    public static function create(int $userId, string $name, string $path, ?string $mime, int $size): int
    {
        return Database::getInstance()->insert('notification_sounds', [
            'user_id' => $userId,
            'name' => mb_substr($name, 0, 120),
            'path' => $path,
            'mime' => $mime,
            'size_bytes' => $size,
        ]);
    }

    /**
     * Exclui o registro e o arquivo físico. Retorna false se não for do usuário.
     */
    public static function deleteOwned(int $id, int $userId): bool
    {
        $row = self::find($id);
        if (!$row || (int) $row['user_id'] !== $userId) {
            return false;
        }
        Database::getInstance()->delete('notification_sounds', 'id = ?', [$id]);
        if (!empty($row['path'])) {
            $abs = upload_dir() . '/' . ltrim($row['path'], '/');
            $base = realpath(upload_dir());
            $real = realpath($abs);
            // Trava anti path-traversal: só apaga dentro de public/uploads.
            if ($real && $base && str_starts_with($real, $base) && is_file($real)) {
                @unlink($real);
            }
        }
        return true;
    }

    public static function toApi(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'url' => upload_url($row['path']),
            'size' => (int) ($row['size_bytes'] ?? 0),
            'mime' => $row['mime'] ?? null,
        ];
    }
}
