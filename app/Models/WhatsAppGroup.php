<?php

namespace App\Models;

use App\Core\Database;

/**
 * Caixa de Grupos WhatsApp: grupos aprendidos via webhook + log de menções.
 */
class WhatsAppGroup
{
    public static function find(int $id): ?array
    {
        return Database::getInstance()->fetch(
            "SELECT * FROM whatsapp_groups WHERE id = ?",
            [$id]
        );
    }

    public static function findByConnectionJid(int $connectionId, string $groupJid): ?array
    {
        return Database::getInstance()->fetch(
            "SELECT * FROM whatsapp_groups WHERE connection_id = ? AND group_jid = ? LIMIT 1",
            [$connectionId, $groupJid]
        );
    }

    /**
     * Cria ou atualiza o registro do grupo a partir de uma mensagem recebida.
     */
    public static function upsertFromWebhook(int $connectionId, string $groupJid, array $data = []): array
    {
        $db = Database::getInstance();
        $existing = self::findByConnectionJid($connectionId, $groupJid);
        $now = date('Y-m-d H:i:s');
        $fields = ['last_message_at' => $now];
        foreach (['name', 'avatar_url', 'participant_count'] as $f) {
            if (array_key_exists($f, $data) && $data[$f] !== null && $data[$f] !== '') {
                $fields[$f] = $data[$f];
            }
        }
        if (!$existing) {
            $id = $db->insert('whatsapp_groups', array_merge([
                'connection_id' => $connectionId,
                'group_jid' => $groupJid,
            ], $fields));
            return self::find((int) $id);
        }
        $db->update('whatsapp_groups', $fields, 'id = ?', [$existing['id']]);
        return array_merge($existing, $fields);
    }

    /**
     * Lista grupos com contadores de menções não lidas + dados da conexão
     * + conversa aberta na caixa (para link "abrir na caixa").
     */
    public static function allWithDetails(?int $connectionId = null): array
    {
        $sql = "SELECT g.*,
                    ch.name as channel_name, ch.id as channel_id,
                    wc.phone_number as connection_phone,
                    i.name as inbox_name,
                    (SELECT COUNT(*) FROM whatsapp_group_mentions m WHERE m.group_id = g.id AND m.is_read = 0) as unread_mentions,
                    (SELECT c.id FROM conversations c WHERE c.group_id = g.id AND c.status NOT IN ('closed','resolved','spam') ORDER BY COALESCE(c.last_message_at, c.created_at) DESC LIMIT 1) as conversation_id
                FROM whatsapp_groups g
                JOIN whatsapp_connections wc ON wc.id = g.connection_id
                JOIN channels ch ON ch.id = wc.channel_id
                LEFT JOIN inboxes i ON i.id = g.inbox_id";
        $params = [];
        if ($connectionId) {
            $sql .= " WHERE g.connection_id = ?";
            $params[] = $connectionId;
        }
        $sql .= " ORDER BY unread_mentions DESC, g.last_message_at DESC";
        return Database::getInstance()->fetchAll($sql, $params);
    }

    public static function update(int $id, array $data): int
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        return Database::getInstance()->update('whatsapp_groups', $data, 'id = ?', [$id]);
    }

    /**
     * Troca a caixa do grupo e move as conversas abertas junto,
     * para aparecerem imediatamente na nova caixa.
     */
    public static function setInbox(int $id, ?int $inboxId): int
    {
        $ret = self::update($id, ['inbox_id' => $inboxId]);
        try {
            Database::getInstance()->update(
                'conversations',
                ['inbox_id' => $inboxId],
                'group_id = ? AND status NOT IN (\'closed\', \'resolved\', \'spam\')',
                [$id]
            );
        } catch (\Throwable $e) {
            // Coluna group_id pode não existir em bases antigas: ignora.
        }
        return $ret;
    }

    public static function openConversationId(int $groupId): ?int
    {
        try {
            $row = Database::getInstance()->fetch(
                "SELECT id FROM conversations WHERE group_id = ? AND status NOT IN ('closed','resolved','spam') ORDER BY COALESCE(last_message_at, created_at) DESC LIMIT 1",
                [$groupId]
            );
            return $row ? (int) $row['id'] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function mentions(int $groupId, int $limit = 50): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT * FROM whatsapp_group_mentions WHERE group_id = ? ORDER BY id DESC LIMIT " . (int) $limit,
            [$groupId]
        );
    }

    public static function logMention(int $groupId, array $data): ?int
    {
        $db = Database::getInstance();
        if (!empty($data['provider_message_id'])) {
            $dup = $db->fetch(
                "SELECT id FROM whatsapp_group_mentions WHERE group_id = ? AND provider_message_id = ? LIMIT 1",
                [$groupId, $data['provider_message_id']]
            );
            if ($dup) {
                return null;
            }
        }
        return (int) $db->insert('whatsapp_group_mentions', [
            'group_id' => $groupId,
            'provider_message_id' => $data['provider_message_id'] ?? null,
            'sender_name' => $data['sender_name'] ?? null,
            'sender_phone' => $data['sender_phone'] ?? null,
            'content' => isset($data['content']) ? mb_substr((string) $data['content'], 0, 2000) : null,
            'mentioned_digits' => $data['mentioned_digits'] ?? null,
        ]);
    }

    public static function markMentionsRead(int $groupId, ?int $mentionId = null): int
    {
        $db = Database::getInstance();
        if ($mentionId) {
            return $db->update('whatsapp_group_mentions', ['is_read' => 1], 'id = ? AND group_id = ?', [$mentionId, $groupId]);
        }
        return $db->update('whatsapp_group_mentions', ['is_read' => 1], 'group_id = ? AND is_read = 0', [$groupId]);
    }

    public static function unreadMentionsTotal(): int
    {
        $row = Database::getInstance()->fetch(
            "SELECT COUNT(*) as c FROM whatsapp_group_mentions WHERE is_read = 0"
        );
        return (int) ($row['c'] ?? 0);
    }
}
