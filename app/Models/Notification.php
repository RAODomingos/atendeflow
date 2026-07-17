<?php

namespace App\Models;

use App\Core\Database;

class Notification
{
    public static function create(array $data): int
    {
        return Database::getInstance()->insert('notifications', $data);
    }

    public static function find(int $id): ?array
    {
        return Database::getInstance()->fetch("SELECT * FROM notifications WHERE id = ?", [$id]);
    }

    public static function getByUser(int $userId, bool $unreadOnly = false, int $limit = 50): array
    {
        $sql = "SELECT n.*, 
                       COALESCE(ct.name, c.subject, CONCAT('Conversa #', c.id)) as conversation_title,
                       c.status as conv_status,
                       u.name as from_user_name
                FROM notifications n
                LEFT JOIN conversations c ON c.id = n.conversation_id
                LEFT JOIN contacts ct ON ct.id = c.contact_id
                LEFT JOIN users u ON u.id = JSON_UNQUOTE(JSON_EXTRACT(n.metadata, '$.from_user_id'))
                WHERE n.user_id = ?";
        $params = [$userId];

        if ($unreadOnly) {
            $sql .= " AND n.is_read = 0";
        }

        $sql .= " ORDER BY n.created_at DESC LIMIT " . (int) $limit;
        return Database::getInstance()->fetchAll($sql, $params);
    }

    public static function markAsRead(int $id, int $userId): bool
    {
        return (bool) Database::getInstance()->update(
            'notifications',
            ['is_read' => 1],
            'id = ? AND user_id = ?',
            [$id, $userId]
        );
    }

    public static function markAllAsRead(int $userId): bool
    {
        return (bool) Database::getInstance()->update(
            'notifications',
            ['is_read' => 1],
            'user_id = ? AND is_read = 0',
            [$userId]
        );
    }

    public static function getUnreadCount(int $userId): int
    {
        $row = Database::getInstance()->fetch(
            "SELECT COUNT(*) as total FROM notifications WHERE user_id = ? AND is_read = 0",
            [$userId]
        );
        return (int) ($row['total'] ?? 0);
    }

    /**
     * Cria notificação de @menção para um usuário
     */
    public static function mention(int $fromUserId, int $toUserId, int $conversationId, string $message): int
    {
        $from = \App\Models\User::find($fromUserId);
        return self::create([
            'user_id' => $toUserId,
            'notification_type' => 'mention',
            'title' => ($from['name'] ?? 'Alguém') . ' mencionou você',
            'body' => mb_substr($message, 0, 200),
            'conversation_id' => $conversationId,
            'metadata' => json_encode(['from_user_id' => $fromUserId]),
        ]);
    }

    /**
     * Cria notificação de atribuição de conversa
     */
    public static function assigned(int $toUserId, int $conversationId, int $assignedByUserId): int
    {
        $conv = \App\Models\Conversation::find($conversationId);
        $by = \App\Models\User::find($assignedByUserId);
        return self::create([
            'user_id' => $toUserId,
            'notification_type' => 'assignment',
            'title' => 'Conversa atribuída a você',
            'body' => ($by['name'] ?? 'Sistema') . ' lhe atribuiu: ' . ($conv['contact_name'] ?? "Conversa #{$conversationId}"),
            'conversation_id' => $conversationId,
            'metadata' => json_encode(['assigned_by' => $assignedByUserId]),
        ]);
    }
}
