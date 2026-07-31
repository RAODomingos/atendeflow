<?php

namespace App\Services;

use App\Core\Database;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\Notification;
use App\Models\User;

class NotificationService
{
    public const TYPE_NEW_MESSAGE = 'new_message';
    public const TYPE_NEW_CONVERSATION = 'new_conversation';
    public const TYPE_MENTION = 'mention';
    public const TYPE_ASSIGNMENT = 'assignment';
    public const TYPE_TRANSFER = 'transfer';
    public const TYPE_STATUS_CHANGE = 'status_change';
    public const TYPE_SYSTEM = 'system';

    public static function notifyNewMessage(int $conversationId, int $messageId, ?string $preview = null, ?int $excludeUserId = null): array
    {
        $conv = Conversation::find($conversationId);
        if (!$conv) {
            return [];
        }

        $flowState = \App\Models\Flow::getActiveFlowState($conversationId);
        $alreadyIntroduced = self::wasIntroduced($conversationId);
        $isInitial = !$alreadyIntroduced
            && ($conv['status'] === 'new' || empty($conv['assigned_user_id']))
            && empty($flowState);
        $type = $isInitial ? self::TYPE_NEW_CONVERSATION : self::TYPE_NEW_MESSAGE;

        $contact = Database::getInstance()->fetch(
            "SELECT name FROM contacts WHERE id = ?",
            [$conv['contact_id']]
        );
        $contactName = $contact['name'] ?? 'Cliente';

        $title = $type === self::TYPE_NEW_CONVERSATION
            ? "Nova conversa: {$contactName}"
            : "{$contactName} enviou mensagem";

        $body = $preview !== null && $preview !== ''
            ? mb_substr($preview, 0, 200)
            : '';

        $recipients = self::resolveRecipients($conv, $excludeUserId);

        $created = [];
        foreach ($recipients as $uid) {
            $notifId = self::createUnique([
                'user_id' => (int) $uid,
                'notification_type' => $type,
                'title' => $title,
                'body' => $body,
                'conversation_id' => $conversationId,
                'metadata' => json_encode([
                    'message_id' => $messageId,
                    'contact_id' => (int) $conv['contact_id'],
                    'conversation_id' => $conversationId,
                ], JSON_UNESCAPED_UNICODE),
            ]);
            if ($notifId) {
                $created[] = $notifId;
            }
        }

        return $created;
    }

    private static function wasIntroduced(int $conversationId): bool
    {
        $row = Database::getInstance()->fetch(
            "SELECT id FROM notifications
             WHERE conversation_id = ? AND notification_type IN (?, ?)
             LIMIT 1",
            [$conversationId, self::TYPE_NEW_CONVERSATION, self::TYPE_NEW_MESSAGE]
        );
        return (bool) $row;
    }

    public static function notifyAssigned(int $toUserId, int $conversationId, int $assignedByUserId): ?int
    {
        if ($toUserId === $assignedByUserId) {
            return null;
        }
        $conv = Conversation::find($conversationId);
        $by = User::find($assignedByUserId);
        $body = ($by['name'] ?? 'Sistema') . ' lhe atribuiu uma conversa';
        return Notification::create([
            'user_id' => $toUserId,
            'notification_type' => self::TYPE_ASSIGNMENT,
            'title' => 'Conversa atribuída a você',
            'body' => $body,
            'conversation_id' => $conversationId,
            'metadata' => json_encode(['assigned_by' => $assignedByUserId]),
        ]);
    }

    public static function notifyMention(int $fromUserId, int $toUserId, int $conversationId, string $message): ?int
    {
        if ($toUserId === $fromUserId) {
            return null;
        }
        $from = User::find($fromUserId);
        $title = ($from['name'] ?? 'Alguém') . ' mencionou você';
        return Notification::create([
            'user_id' => $toUserId,
            'notification_type' => self::TYPE_MENTION,
            'title' => $title,
            'body' => mb_substr($message, 0, 200),
            'conversation_id' => $conversationId,
            'metadata' => json_encode(['from_user_id' => $fromUserId]),
        ]);
    }

    public static function markConversationNotificationsRead(int $conversationId, int $userId): int
    {
        return Database::getInstance()->update(
            'notifications',
            ['is_read' => 1],
            'conversation_id = ? AND user_id = ? AND is_read = 0',
            [$conversationId, $userId]
        );
    }

    /**
     * Determina os usuários que devem ser notificados sobre uma nova mensagem
     * em uma conversa. Ordem de preferência:
     *  1) Usuário atribuído à conversa (se houver)
     *  2) Membros da caixa (inbox) vinculada
     *  3) Administradores do sistema
     */
    private static function resolveRecipients(array $conv, ?int $excludeUserId = null): array
    {
        $exclude = $excludeUserId ? (int) $excludeUserId : 0;
        $ids = [];

        if (!empty($conv['assigned_user_id']) && (int) $conv['assigned_user_id'] !== $exclude) {
            $ids[] = (int) $conv['assigned_user_id'];
            return $ids;
        }

        if (!empty($conv['inbox_id'])) {
            $inboxUsers = Inbox::getUsers((int) $conv['inbox_id']);
            foreach ($inboxUsers as $u) {
                if ((int) $u['id'] !== $exclude) {
                    $ids[] = (int) $u['id'];
                }
            }
        }

        if (empty($ids) && !empty($conv['department_id'])) {
            $deptUsers = Database::getInstance()->fetchAll(
                "SELECT user_id FROM department_users WHERE department_id = ?",
                [$conv['department_id']]
            );
            foreach ($deptUsers as $du) {
                if ((int) $du['user_id'] !== $exclude) {
                    $ids[] = (int) $du['user_id'];
                }
            }
        }

        if (empty($ids)) {
            $admins = Database::getInstance()->fetchAll(
                "SELECT id FROM users WHERE role = 'admin' AND id != ?",
                [$exclude]
            );
            foreach ($admins as $a) {
                $ids[] = (int) $a['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Cria uma notificação evitando duplicatas recentes.
     *  - Mesma `message_id` para o mesmo usuário/conversa: dedup absoluto
     *    (defesa contra o mesmo webhook chegando por dois canais ou
     *    contra a nova chamada após uma `new_conversation` ter sido
     *    gerada com a mesma mensagem).
     *  - `new_conversation` só dispara uma vez por conversa.
     *  - Demais tipos deduplicam por user + conversation + type em 5s.
     */
    private static function createUnique(array $data): ?int
    {
        if (empty($data['user_id']) || empty($data['conversation_id']) || empty($data['notification_type'])) {
            return null;
        }

        $type = $data['notification_type'];
        $msgId = 0;
        if (!empty($data['metadata'])) {
            $meta = is_array($data['metadata']) ? $data['metadata'] : json_decode((string) $data['metadata'], true);
            if (is_array($meta) && !empty($meta['message_id'])) {
                $msgId = (int) $meta['message_id'];
            }
        }

        if ($msgId > 0) {
            $existing = Database::getInstance()->fetch(
                "SELECT id FROM notifications
                 WHERE user_id = ? AND conversation_id = ?
                   AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.message_id')) = ?
                 LIMIT 1",
                [$data['user_id'], $data['conversation_id'], (string) $msgId]
            );
            if ($existing) {
                return null;
            }
        }

        if ($type === self::TYPE_NEW_CONVERSATION) {
            $existing = Database::getInstance()->fetch(
                "SELECT id FROM notifications
                 WHERE conversation_id = ? AND notification_type = ?
                 LIMIT 1",
                [$data['conversation_id'], $type]
            );
            if ($existing) {
                return null;
            }
        } elseif ($type !== self::TYPE_NEW_MESSAGE) {
            $existing = Database::getInstance()->fetch(
                "SELECT id FROM notifications
                 WHERE user_id = ? AND conversation_id = ? AND notification_type = ?
                   AND created_at >= (NOW() - INTERVAL 5 SECOND)
                 LIMIT 1",
                [$data['user_id'], $data['conversation_id'], $type]
            );
            if ($existing) {
                return null;
            }
        }

        return Notification::create($data);
    }
}
