<?php

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\View;
use App\Models\Conversation;
use App\Models\Notification;
use App\Models\UserPresence;

class RealtimeController
{
    private const POLL_INTERVAL = 2;
    private const MAX_RUNTIME = 55;

    /**
     * GET /realtime/events (SSE - Server-Sent Events)
     * Mantém conexão aberta e envia eventos em tempo real para o usuário.
     * Tipos de eventos:
     *   - connected   : handshake inicial
     *   - notification: nova notificação persistida
     *   - conversation_new: nova conversa chegou (atalho para UI atualizar lista)
     *   - message_incoming: nova mensagem em conversa visível ao usuário
     *   - unread_count: contagem atualizada
     *   - presence: status online/offline de outro usuário
     */
    public function events(Request $request): void
    {
        $userId = Auth::id();
        if (!$userId) {
            View::json(['error' => 'Não autenticado'], 401);
            return;
        }

        UserPresence::update($userId);

        session_write_close();

        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', '0');
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        @ob_implicit_flush(true);

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        header('Access-Control-Allow-Origin: *');

        set_time_limit(self::MAX_RUNTIME + 10);

        $maxRow = Database::getInstance()->fetch(
            "SELECT MAX(id) as max_id FROM notifications WHERE user_id = ?",
            [$userId]
        );
        $lastNotificationId = (int) ($maxRow['max_id'] ?? 0);

        $maxConvRow = Database::getInstance()->fetch(
            "SELECT COALESCE(MAX(id), 0) as max_id FROM conversations"
        );
        $lastConversationId = (int) ($maxConvRow['max_id'] ?? 0);

        $maxMsgRow = Database::getInstance()->fetch(
            "SELECT COALESCE(MAX(id), 0) as max_id FROM messages"
        );
        $lastMessageId = (int) ($maxMsgRow['max_id'] ?? 0);

        echo "event: connected\ndata: " . json_encode([
            'userId' => $userId,
            'lastNotificationId' => $lastNotificationId,
            'lastConversationId' => $lastConversationId,
            'lastMessageId' => $lastMessageId,
        ]) . "\n\n";
        @ob_flush();
        @flush();

        $lastCheck = time();
        $startTime = time();

        while (time() - $startTime < self::MAX_RUNTIME) {
            if (connection_aborted()) {
                UserPresence::offline($userId);
                break;
            }

            if (time() - $lastCheck >= self::POLL_INTERVAL) {
                $this->emitNotifications($userId, $lastNotificationId);
                $this->emitNewConversations($userId, $lastConversationId);
                $this->emitIncomingMessages($userId, $lastMessageId);
                $this->emitUnreadCount($userId);
                $this->emitTyping($userId);
                $this->emitHeartbeat();

                @ob_flush();
                @flush();
                $lastCheck = time();
            }

            sleep(1);
        }

        @ob_flush();
        @flush();
    }

    private function emitNotifications(int $userId, int &$lastId): void
    {
        try {
            $rows = Database::getInstance()->fetchAll(
                "SELECT n.id, n.notification_type, n.title, n.body, n.conversation_id,
                        n.metadata, n.created_at, n.is_read,
                        ct.name as contact_name, ch.type as channel_type
                 FROM notifications n
                 LEFT JOIN conversations c ON c.id = n.conversation_id
                 LEFT JOIN contacts ct ON ct.id = c.contact_id
                 LEFT JOIN channels ch ON ch.id = c.channel_id
                 WHERE n.user_id = ? AND n.id > ?
                 ORDER BY n.id ASC
                 LIMIT 50",
                [$userId, $lastId]
            );
            foreach ($rows as $row) {
                echo "event: notification\ndata: " . json_encode($row, JSON_UNESCAPED_UNICODE) . "\n\n";
                $lastId = max($lastId, (int) $row['id']);
            }
        } catch (\Throwable $e) {
            error_log('SSE notifications error: ' . $e->getMessage());
        }
    }

    private function emitNewConversations(int $userId, int &$lastConvId): void
    {
        try {
            $rows = Database::getInstance()->fetchAll(
                "SELECT c.id, c.contact_id, c.channel_id, c.inbox_id, c.status, c.created_at,
                        c.last_message_at, ct.name as contact_name, ct.avatar as contact_avatar,
                        ch.type as channel_type, ch.name as channel_name
                 FROM conversations c
                 JOIN contacts ct ON ct.id = c.contact_id
                 LEFT JOIN channels ch ON ch.id = c.channel_id
                 WHERE c.id > ?
                   AND c.status NOT IN ('closed','resolved','spam')
                 ORDER BY c.id ASC
                 LIMIT 20",
                [$lastConvId]
            );
            foreach ($rows as $row) {
                if (!self::userSeesConversation($userId, $row)) {
                    continue;
                }
                echo "event: conversation_new\ndata: " . json_encode($row, JSON_UNESCAPED_UNICODE) . "\n\n";
                $lastConvId = max($lastConvId, (int) $row['id']);
            }
        } catch (\Throwable $e) {
            error_log('SSE conversations error: ' . $e->getMessage());
        }
    }

    private function emitIncomingMessages(int $userId, int &$lastMsgId): void
    {
        try {
            $rows = Database::getInstance()->fetchAll(
                "SELECT m.id, m.conversation_id, m.type, m.content, m.direction, m.created_at,
                        c.contact_id, ct.name as contact_name, ch.type as channel_type
                 FROM messages m
                 JOIN conversations c ON c.id = m.conversation_id
                 JOIN contacts ct ON ct.id = c.contact_id
                 LEFT JOIN channels ch ON ch.id = c.channel_id
                 WHERE m.id > ? AND m.direction = 'inbound'
                 ORDER BY m.id ASC
                 LIMIT 50",
                [$lastMsgId]
            );
            foreach ($rows as $row) {
                $conv = [
                    'id' => $row['conversation_id'],
                    'inbox_id' => null,
                    'assigned_user_id' => null,
                    'department_id' => null,
                ];
                $full = Database::getInstance()->fetch(
                    "SELECT inbox_id, assigned_user_id, department_id FROM conversations WHERE id = ?",
                    [$row['conversation_id']]
                );
                if ($full) {
                    $conv = array_merge($conv, $full);
                }
                if (!self::userSeesConversation($userId, $conv)) {
                    continue;
                }
                $payload = [
                    'id' => (int) $row['id'],
                    'conversation_id' => (int) $row['conversation_id'],
                    'type' => $row['type'],
                    'content' => $row['content'],
                    'created_at' => $row['created_at'],
                    'contact_name' => $row['contact_name'],
                    'channel_type' => $row['channel_type'],
                ];
                echo "event: message_incoming\ndata: " . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
                $lastMsgId = max($lastMsgId, (int) $row['id']);
            }
        } catch (\Throwable $e) {
            error_log('SSE messages error: ' . $e->getMessage());
        }
    }

    private function emitUnreadCount(int $userId): void
    {
        try {
            $count = Notification::getUnreadCount($userId);
            echo "event: unread_count\ndata: {$count}\n\n";
        } catch (\Throwable $e) {
            // ignore
        }
    }

    private function emitTyping(int $userId): void
    {
        try {
            $rows = Database::getInstance()->fetchAll(
                "SELECT c.id as conversation_id, c.last_typing_at
                 FROM conversations c
                 WHERE c.last_typing_at IS NOT NULL
                   AND c.last_typing_at >= (NOW() - INTERVAL 6 SECOND)
                   AND (c.assigned_user_id = ? OR c.inbox_id IN (
                       SELECT inbox_id FROM inbox_users WHERE user_id = ?
                       UNION
                       SELECT inbox_id FROM inbox_departments idp
                       JOIN department_users du ON du.department_id = idp.department_id
                       WHERE du.user_id = ?
                   ))",
                [$userId, $userId, $userId]
            );
            foreach ($rows as $r) {
                $payload = [
                    'conversation_id' => (int) $r['conversation_id'],
                    'at' => $r['last_typing_at'],
                ];
                echo "event: typing\ndata: " . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }

    private function emitHeartbeat(): void
    {
        echo ": heartbeat " . time() . "\n\n";
    }

    /**
     * Verifica se o usuário tem acesso à conversa (caixa, departamento,
     * atribuição ou papel admin).
     */
    private static function userSeesConversation(int $userId, array $conv): bool
    {
        $row = Database::getInstance()->fetch(
            "SELECT role FROM users WHERE id = ?",
            [$userId]
        );
        if (!$row) {
            return false;
        }
        if ($row['role'] === 'admin') {
            return true;
        }
        if (!empty($conv['assigned_user_id']) && (int) $conv['assigned_user_id'] === $userId) {
            return true;
        }
        if (!empty($conv['inbox_id'])) {
            $own = Database::getInstance()->fetch(
                "SELECT 1 FROM inboxes i
                 LEFT JOIN inbox_departments idp ON idp.inbox_id = i.id
                 LEFT JOIN department_users du ON du.department_id = idp.department_id AND du.user_id = ?
                 LEFT JOIN inbox_users iu ON iu.inbox_id = i.id AND iu.user_id = ?
                 WHERE i.id = ? AND (du.id IS NOT NULL OR iu.id IS NOT NULL)
                 LIMIT 1",
                [$userId, $userId, $conv['inbox_id']]
            );
            if ($own) {
                return true;
            }
        }
        if (!empty($conv['department_id'])) {
            $dept = Database::getInstance()->fetch(
                "SELECT 1 FROM department_users WHERE department_id = ? AND user_id = ?",
                [$conv['department_id'], $userId]
            );
            if ($dept) {
                return true;
            }
        }
        return false;
    }
}
