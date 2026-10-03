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
        // Sem JOIN em JSON (sem índice): busca o nome do autor em query separada se preciso.
        $sql = "SELECT n.*,
                       COALESCE(ct.name, c.subject, CONCAT('Conversa #', c.id)) as conversation_title,
                       c.status as conv_status
                FROM notifications n
                LEFT JOIN conversations c ON c.id = n.conversation_id
                LEFT JOIN contacts ct ON ct.id = c.contact_id
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
     * Mantido por compatibilidade: delega ao NotificationService.
     * Cria notificação de @menção para um usuário
     */
    public static function mention(int $fromUserId, int $toUserId, int $conversationId, string $message): int
    {
        $id = \App\Services\NotificationService::notifyMention($fromUserId, $toUserId, $conversationId, $message);
        return $id ?? 0;
    }

    /**
     * Mantido por compatibilidade: delega ao NotificationService.
     * Cria notificação de atribuição de conversa
     */
    public static function assigned(int $toUserId, int $conversationId, int $assignedByUserId): int
    {
        $id = \App\Services\NotificationService::notifyAssigned($toUserId, $conversationId, $assignedByUserId);
        return $id ?? 0;
    }

    /**
     * Lista as notificações estruturadas para o dropdown do sino, juntando
     * menções/atribuições com conversas não lidas do mesmo usuário.
     * Single-query (sem N+1): traz dados da conversa via JOIN.
     */
    public static function getForDropdown(int $userId, int $limit = 10): array
    {
        return \App\Core\Database::getInstance()->fetchAll(
            "SELECT n.id, n.notification_type, n.title, n.body, n.conversation_id,
                    n.is_read, n.created_at, n.metadata,
                    ct.name as contact_name, ch.type as channel_type, ch.name as channel_name,
                    c.status as conv_status, c.last_message_at
             FROM notifications n
             LEFT JOIN conversations c ON c.id = n.conversation_id
             LEFT JOIN contacts ct ON ct.id = c.contact_id
             LEFT JOIN channels ch ON ch.id = c.channel_id
             WHERE n.user_id = ? AND n.is_read = 0
             ORDER BY n.id DESC
             LIMIT " . (int) $limit,
            [$userId]
        );
    }

    /**
     * Marca como lidas todas as notificações das conversas em que o usuário
     * está envolvido. Chamado ao abrir a inbox / carregar uma conversa.
     */
    public static function markConversationNotificationsRead(int $conversationId, int $userId): int
    {
        return \App\Services\NotificationService::markConversationNotificationsRead($conversationId, $userId);
    }
}
