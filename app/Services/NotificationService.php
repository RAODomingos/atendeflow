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
    public const TYPE_GROUP_MENTION = 'group_mention';
    public const TYPE_ASSIGNMENT = 'assignment';
    public const TYPE_TRANSFER = 'transfer';
    public const TYPE_STATUS_CHANGE = 'status_change';
    public const TYPE_SYSTEM = 'system';

    /**
     * Alerta de menção ao número da conexão em grupo de WhatsApp.
     * Destinatários: membros da caixa vinculada ao grupo → caixa do canal →
     * departamento do canal → admins. Dedup por mensagem do provedor.
     * A conversa do grupo (na caixa selecionada) vai em conversation_id para
     * o sino abrir direto no inbox + limpar ao ler.
     *
     * @param array $group      Linha de whatsapp_groups
     * @param array $connection Linha de whatsapp_connections
     * @param array $mention    ['sender_name'=>?, 'sender_phone'=>?, 'content'=>?, 'provider_message_id'=>?, 'conversation_id'=>?]
     */
    public static function notifyGroupMention(array $group, array $connection, array $mention): array
    {
        $recipients = self::resolveGroupRecipients($group, $connection);
        if (empty($recipients)) {
            return [];
        }
        $groupName = $group['name'] ?: 'Grupo WhatsApp';
        $sender = $mention['sender_name'] ?: ($mention['sender_phone'] ?: 'Alguém');
        $title = ($mention['mention_kind'] ?? '') === 'everyone'
            ? "@{$sender} mencionou todos em {$groupName}"
            : "@{$sender} marcou o número em {$groupName}";
        $body = mb_substr((string) ($mention['content'] ?? ''), 0, 200);
        $conversationId = !empty($mention['conversation_id']) ? (int) $mention['conversation_id'] : null;
        if (!$conversationId && !empty($group['id'])) {
            try {
                $row = Database::getInstance()->fetch(
                    "SELECT id FROM conversations WHERE group_id = ? AND status NOT IN ('closed','resolved','spam') ORDER BY COALESCE(last_message_at, created_at) DESC LIMIT 1",
                    [(int) $group['id']]
                );
                $conversationId = $row ? (int) $row['id'] : null;
            } catch (\Throwable $e) {
                $conversationId = null;
            }
        }
        $metadata = json_encode([
            'group_id' => (int) $group['id'],
            'group_jid' => $group['group_jid'],
            'connection_id' => (int) $connection['id'],
            'channel_id' => (int) $connection['channel_id'],
            'conversation_id' => $conversationId,
            'provider_message_id' => $mention['provider_message_id'] ?? null,
            'sender_phone' => $mention['sender_phone'] ?? null,
        ], JSON_UNESCAPED_UNICODE);

        $created = [];
        foreach ($recipients as $uid) {
            $dup = Database::getInstance()->fetch(
                "SELECT id FROM notifications
                  WHERE user_id = ? AND notification_type = ?
                    AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.provider_message_id')) = ?
                    AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.group_id')) = ?
                  LIMIT 1",
                [$uid, self::TYPE_GROUP_MENTION, (string) ($mention['provider_message_id'] ?? ''), (string) $group['id']]
            );
            if ($dup) {
                continue;
            }
            $id = Notification::create([
                'user_id' => $uid,
                'notification_type' => self::TYPE_GROUP_MENTION,
                'title' => $title,
                'body' => $body,
                'conversation_id' => $conversationId,
                'metadata' => $metadata,
            ]);
            if ($id) {
                $created[] = $id;
            }
        }
        return $created;
    }

    private static function resolveGroupRecipients(array $group, array $connection): array
    {
        $ids = [];
        if (!empty($group['inbox_id'])) {
            foreach (Inbox::getUsers((int) $group['inbox_id']) as $u) {
                $ids[] = (int) $u['id'];
            }
        }
        if (empty($ids)) {
            $inboxId = Inbox::resolveInboxForChannel((int) $connection['channel_id']);
            if ($inboxId) {
                foreach (Inbox::getUsers($inboxId) as $u) {
                    $ids[] = (int) $u['id'];
                }
            }
        }
        if (empty($ids)) {
            $channel = Database::getInstance()->fetch(
                "SELECT department_id FROM channels WHERE id = ?",
                [$connection['channel_id']]
            );
            if (!empty($channel['department_id'])) {
                $deptUsers = Database::getInstance()->fetchAll(
                    "SELECT du.user_id FROM department_users du
                      JOIN users u ON u.id = du.user_id AND u.is_active = 1
                      WHERE du.department_id = ?",
                    [$channel['department_id']]
                );
                foreach ($deptUsers as $du) {
                    $ids[] = (int) $du['user_id'];
                }
            }
        }
        if (empty($ids)) {
            $admins = Database::getInstance()->fetchAll(
                "SELECT id FROM users WHERE role = 'admin' AND is_active = 1"
            );
            foreach ($admins as $a) {
                $ids[] = (int) $a['id'];
            }
        }
        if (!empty($ids)) {
            $ids = array_values(array_unique($ids));
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $active = Database::getInstance()->fetchAll(
                "SELECT id FROM users WHERE id IN ($ph) AND is_active = 1",
                $ids
            );
            $ids = array_map(fn($r) => (int) $r['id'], $active);
        }
        return array_values(array_unique($ids));
    }

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
        // Evita spam de re-atribuição: dedup 30s por user+conversa+tipo
        $recent = Database::getInstance()->fetch(
            "SELECT id FROM notifications
             WHERE user_id = ? AND conversation_id = ? AND notification_type = ?
               AND created_at >= (NOW() - INTERVAL 30 SECOND)
             LIMIT 1",
            [$toUserId, $conversationId, self::TYPE_ASSIGNMENT]
        );
        if ($recent) {
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
        // Dedup menção repetida da mesma mensagem em 60s
        $recent = Database::getInstance()->fetch(
            "SELECT id FROM notifications
             WHERE user_id = ? AND conversation_id = ? AND notification_type = ?
               AND created_at >= (NOW() - INTERVAL 60 SECOND)
             LIMIT 1",
            [$toUserId, $conversationId, self::TYPE_MENTION]
        );
        if ($recent) {
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
     * Marca como lidas as notificações de menção em grupo abertas na caixa.
     */
    public static function markGroupNotificationsRead(int $groupId, int $userId): int
    {
        return Database::getInstance()->update(
            'notifications',
            ['is_read' => 1],
            "user_id = ? AND is_read = 0 AND notification_type = 'group_mention'
              AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.group_id')) = ?",
            [$userId, (string) $groupId]
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
                "SELECT du.user_id FROM department_users du
                 JOIN users u ON u.id = du.user_id AND u.is_active = 1
                 WHERE du.department_id = ?",
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
                "SELECT id FROM users WHERE role = 'admin' AND is_active = 1 AND id != ?",
                [$exclude]
            );
            foreach ($admins as $a) {
                $ids[] = (int) $a['id'];
            }
        }

        // Filtra inativos por segurança (caso Inbox::getUsers traga alguém desativado)
        if (!empty($ids)) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $active = Database::getInstance()->fetchAll(
                "SELECT id FROM users WHERE id IN ($ph) AND is_active = 1",
                $ids
            );
            $ids = array_map(fn($r) => (int) $r['id'], $active);
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
                 WHERE user_id = ? AND conversation_id = ? AND notification_type = ?
                 LIMIT 1",
                [$data['user_id'], $data['conversation_id'], $type]
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
