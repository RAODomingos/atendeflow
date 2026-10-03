<?php

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\View;
use App\Models\Inbox;

class UnreadConversationsController
{
    public function index(Request $request): void
    {
        $userId = Auth::id();
        if (!$userId) {
            View::json(['error' => 'Não autenticado'], 401);
            return;
        }

        // Determine which inbox(es) to show
        $inboxIds = [];
        $inboxParam = $request->input('inbox');
        if ($inboxParam) {
            $inboxIds = [(int) $inboxParam];
        } else {
            $inboxes = Inbox::getUserInboxes($userId);
            $inboxIds = array_column($inboxes, 'id');
        }

        if (empty($inboxIds)) {
            View::json(['conversations' => [], 'total_unread' => 0]);
            return;
        }

        // Build inbox membership fragment for each inbox
        $inboxConditions = [];
        $params = [$userId];
        foreach ($inboxIds as $iid) {
            $inbox = Inbox::find($iid);
            if ($inbox && $inbox['type'] === 'personal') {
                $owners = Inbox::getUsers($iid);
                $ownerIds = array_column($owners, 'id');
                if ($ownerIds) {
                    $phs = rtrim(str_repeat('?,', count($ownerIds)), ',');
                    $inboxConditions[] = "(c.assigned_user_id IN ({$phs}) OR c.channel_id IN (SELECT channel_id FROM inbox_channels WHERE inbox_id = ?))";
                    foreach ($ownerIds as $oid) { $params[] = $oid; }
                    $params[] = $iid;
                } else {
                    $inboxConditions[] = "c.channel_id IN (SELECT channel_id FROM inbox_channels WHERE inbox_id = ?)";
                    $params[] = $iid;
                }
            } else {
                $inboxConditions[] = "(c.inbox_id = ? OR (c.inbox_id IS NULL AND c.channel_id IN (SELECT channel_id FROM inbox_channels WHERE inbox_id = ?)))";
                $params[] = $iid;
                $params[] = $iid;
            }
        }

        $inboxWhere = '(' . implode(' OR ', $inboxConditions) . ')';

        $conversations = Database::getInstance()->fetchAll(
            "SELECT c.id, ct.name as contact_name, ct.avatar as contact_avatar,
                    ch.type as channel_type, ch.name as channel_name,
                    c.last_message_at,
                    (SELECT content FROM messages WHERE conversation_id = c.id ORDER BY id DESC LIMIT 1) as last_message,
                    c.subject,
                    (SELECT COUNT(*) FROM messages m
                     WHERE m.conversation_id = c.id AND m.direction = 'inbound'
                     AND m.is_read = 0 AND (m.user_id != ? OR m.user_id IS NULL)) as unread_count
             FROM conversations c
             JOIN contacts ct ON ct.id = c.contact_id
             LEFT JOIN channels ch ON ch.id = c.channel_id
             WHERE {$inboxWhere}
             AND c.id IN (
                 SELECT DISTINCT m.conversation_id FROM messages m
                 WHERE m.direction = 'inbound' AND m.is_read = 0
             )
             ORDER BY c.last_message_at DESC
             LIMIT 20",
            $params
        );

        $total = 0;
        foreach ($conversations as &$c) {
            $total += (int) ($c['unread_count'] ?? 0);
            unset($c);
        }

        View::json([
            'conversations' => $conversations,
            'total_unread' => $total,
        ]);
    }
}
