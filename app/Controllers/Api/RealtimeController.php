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
    private const POLL_INTERVAL = 5;
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
     *   - inbox_counts: abertas por caixa (badges do menu lateral)
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

        $maxEvtRow = Database::getInstance()->fetch(
            "SELECT COALESCE(MAX(id), 0) as max_id FROM conversation_events
             WHERE event_type IN ('flow_started','flow_completed','flow_timeout','tag_added','tag_removed')"
        );
        $lastEventId = (int) ($maxEvtRow['max_id'] ?? 0);

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
                $this->emitConversationUpdates($userId, $lastEventId);
                $this->emitUnreadCount($userId);
                $this->emitInboxCounts($userId);
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
            // Grupos WhatsApp nunca disparam "nova conversa": só menção
            // (via evento notification) deve tocar/avisar.
            $rows = Database::getInstance()->fetchAll(
                "SELECT c.id, c.contact_id, c.channel_id, c.inbox_id, c.status, c.created_at,
                        c.last_message_at, c.assigned_user_id, c.department_id,
                        ct.name as contact_name, ct.avatar as contact_avatar,
                        ch.type as channel_type, ch.name as channel_name
                 FROM conversations c
                 JOIN contacts ct ON ct.id = c.contact_id
                 LEFT JOIN channels ch ON ch.id = c.channel_id
                 WHERE c.id > ?
                   AND c.status NOT IN ('closed','resolved','spam')
                   AND c.group_id IS NULL
                 ORDER BY c.id ASC
                 LIMIT 20",
                [$lastConvId]
            );
            $maxSeen = $lastConvId;
            foreach ($rows as $row) {
                $maxSeen = max($maxSeen, (int) $row['id']);
                if (!self::userSeesConversation($userId, $row)) {
                    continue;
                }
                echo "event: conversation_new\ndata: " . json_encode($row, JSON_UNESCAPED_UNICODE) . "\n\n";
            }
            // Avança o cursor mesmo para conversas invisíveis: evita re-scan infinito.
            $lastConvId = $maxSeen;
        } catch (\Throwable $e) {
            error_log('SSE conversations error: ' . $e->getMessage());
        }
    }

    private function emitIncomingMessages(int $userId, int &$lastMsgId): void
    {
        try {
            // Traz inbox/assigned/department no JOIN: evita 1 SELECT extra por mensagem (N+1).
            // Grupos WhatsApp nunca emitem message_incoming (nem menção): o
            // aviso de menção chega UMA vez via evento notification.
            $rows = Database::getInstance()->fetchAll(
                "SELECT m.id, m.conversation_id, m.type, m.content, m.direction, m.created_at,
                        c.contact_id, c.inbox_id, c.assigned_user_id, c.department_id,
                        ct.name as contact_name, ch.type as channel_type
                 FROM messages m
                 JOIN conversations c ON c.id = m.conversation_id
                 JOIN contacts ct ON ct.id = c.contact_id
                 LEFT JOIN channels ch ON ch.id = c.channel_id
                 WHERE m.id > ? AND m.direction = 'inbound'
                   AND c.group_id IS NULL
                 ORDER BY m.id ASC
                 LIMIT 50",
                [$lastMsgId]
            );
            $maxSeen = $lastMsgId;
            foreach ($rows as $row) {
                $maxSeen = max($maxSeen, (int) $row['id']);
                if (!self::userSeesConversation($userId, $row)) {
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
            }
            // Avança mesmo para invisíveis: evita re-scan a cada 2s.
            $lastMsgId = $maxSeen;
        } catch (\Throwable $e) {
            error_log('SSE messages error: ' . $e->getMessage());
        }
    }

    /**
     * Emite o evento "conversation_updated" quando tags ou estado de fluxo
     * mudam (fluxo iniciado/finalizado/timeout, etiqueta aplicada/removida).
     * A UI usa esse sinal para re-buscar a lista e atualizar os chips em tempo real.
     */
    private function emitConversationUpdates(int $userId, int &$lastEventId): void
    {
        try {
            $rows = Database::getInstance()->fetchAll(
                "SELECT e.id, e.conversation_id, e.event_type,
                        c.inbox_id, c.assigned_user_id, c.department_id
                 FROM conversation_events e
                 JOIN conversations c ON c.id = e.conversation_id
                 WHERE e.id > ?
                   AND e.event_type IN ('flow_started','flow_completed','flow_timeout','tag_added','tag_removed')
                 ORDER BY e.id ASC
                 LIMIT 50",
                [$lastEventId]
            );
            foreach ($rows as $row) {
                if (!self::userSeesConversation($userId, $row)) {
                    $lastEventId = max($lastEventId, (int) $row['id']);
                    continue;
                }
                echo "event: conversation_updated\ndata: " . json_encode([
                    'conversation_id' => (int) $row['conversation_id'],
                    'event_type' => $row['event_type'],
                ], JSON_UNESCAPED_UNICODE) . "\n\n";
                $lastEventId = max($lastEventId, (int) $row['id']);
            }
        } catch (\Throwable $e) {
            error_log('SSE conversation_updated error: ' . $e->getMessage());
        }
    }

    private function emitUnreadCount(int $userId): void
    {
        static $last = [];
        try {
            $count = Notification::getUnreadCount($userId);
            // Só emite quando muda: antes ia a cada tick (6 queries/2s por usuário).
            if (($last[$userId] ?? null) !== $count) {
                $last[$userId] = $count;
                echo "event: unread_count\ndata: {$count}\n\n";
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }

    private function emitInboxCounts(int $userId): void
    {
        static $last = [];
        try {
            [$counts, $total] = InboxCountsController::countsForUser($userId);
            $key = json_encode(['c' => $counts, 't' => $total]);
            if (($last[$userId] ?? null) !== $key) {
                $last[$userId] = $key;
                echo "event: inbox_counts\ndata: " . json_encode([
                    'counts' => $counts,
                    'total' => $total,
                ], JSON_UNESCAPED_UNICODE) . "\n\n";
            }
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
     * atribuição ou papel admin). Com cache estático por tick para evitar
     * 2-3 queries por mensagem em rajadas.
     */
    private static function userSeesConversation(int $userId, array $conv): bool
    {
        static $roleCache = [];
        static $inboxCache = [];
        static $deptCache = [];

        if (!array_key_exists($userId, $roleCache)) {
            $row = Database::getInstance()->fetch(
                "SELECT role FROM users WHERE id = ?",
                [$userId]
            );
            $roleCache[$userId] = $row['role'] ?? null;
        }
        if (!$roleCache[$userId]) {
            return false;
        }
        if ($roleCache[$userId] === 'admin') {
            return true;
        }
        if (!empty($conv['assigned_user_id']) && (int) $conv['assigned_user_id'] === $userId) {
            return true;
        }
        if (!empty($conv['inbox_id'])) {
            $key = $userId . ':' . $conv['inbox_id'];
            if (!array_key_exists($key, $inboxCache)) {
                $own = Database::getInstance()->fetch(
                    "SELECT 1 FROM inboxes i
                     LEFT JOIN inbox_departments idp ON idp.inbox_id = i.id
                     LEFT JOIN department_users du ON du.department_id = idp.department_id AND du.user_id = ?
                     LEFT JOIN inbox_users iu ON iu.inbox_id = i.id AND iu.user_id = ?
                     WHERE i.id = ? AND (du.id IS NOT NULL OR iu.id IS NOT NULL)
                     LIMIT 1",
                    [$userId, $userId, $conv['inbox_id']]
                );
                $inboxCache[$key] = (bool) $own;
            }
            if ($inboxCache[$key]) {
                return true;
            }
        }
        if (!empty($conv['department_id'])) {
            $key = $userId . ':' . $conv['department_id'];
            if (!array_key_exists($key, $deptCache)) {
                $dept = Database::getInstance()->fetch(
                    "SELECT 1 FROM department_users WHERE department_id = ? AND user_id = ?",
                    [$conv['department_id'], $userId]
                );
                $deptCache[$key] = (bool) $dept;
            }
            if ($deptCache[$key]) {
                return true;
            }
        }
        return false;
    }
}
