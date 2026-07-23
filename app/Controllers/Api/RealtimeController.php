<?php

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\View;
use App\Models\Notification;
use App\Models\UserPresence;

class RealtimeController
{
    /**
     * GET /api/v2/realtime/events (SSE - Server-Sent Events)
     * Mantém conexão aberta e envia eventos em tempo real para o usuário.
     * Headers necessários no frontend: EventSource('/api/v2/realtime/events')
     */
    public function events(Request $request): void
    {
        $userId = Auth::id();
        if (!$userId) {
            View::json(['error' => 'Não autenticado'], 401);
            return;
        }

        UserPresence::update($userId);

        // Libera session lock para não bloquear outras requisições AJAX
        session_write_close();

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        $lastCheck = time();
        $lastNotificationId = 0;

        // Send initial connection event
        echo "event: connected\ndata: " . json_encode(['userId' => $userId]) . "\n\n";
        ob_flush();
        flush();

        while (true) {
            if (connection_aborted()) {
                UserPresence::offline($userId);
                break;
            }

            // Check for new notifications (polling every 3 seconds)
            if (time() - $lastCheck >= 3) {
                $notifications = Database::getInstance()->fetchAll(
                    "SELECT n.*, ct.name as contact_name, u.name as from_user_name
                     FROM notifications n
                     LEFT JOIN conversations c ON c.id = n.conversation_id
                     LEFT JOIN contacts ct ON ct.id = c.contact_id
                     LEFT JOIN users u ON u.id = JSON_UNQUOTE(JSON_EXTRACT(n.metadata, '$.from_user_id'))
                     WHERE n.user_id = ? AND n.id > ? AND n.is_read = 0
                     ORDER BY n.id ASC",
                    [$userId, $lastNotificationId]
                );

                foreach ($notifications as $n) {
                    echo "event: notification\ndata: " . json_encode($n) . "\n\n";
                    $lastNotificationId = (int) $n['id'];
                }

                // Unread count
                $unread = Notification::getUnreadCount($userId);
                echo "event: unread_count\ndata: {$unread}\n\n";

                // Heartbeat
                echo ": heartbeat\n\n";

                ob_flush();
                flush();
                $lastCheck = time();
            }

            // Sleep 1 second between checks
            sleep(1);
        }
    }

}
