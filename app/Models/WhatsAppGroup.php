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
     *
     * @param int|null    $connectionId Filtra por conexão.
     * @param string|null $search       Filtra por nome ou JID.
     * @param bool        $onlyMonitored Só grupos monitorados (is_active).
     */
    public static function allWithDetails(?int $connectionId = null, ?string $search = null, bool $onlyMonitored = false): array
    {
        $sql = "SELECT g.*,
                    ch.name as channel_name, ch.id as channel_id,
                    wc.phone_number as connection_phone,
                    i.name as inbox_name,
                    (SELECT COUNT(*) FROM whatsapp_group_mentions m WHERE m.group_id = g.id AND m.is_read = 0) as unread_mentions,
                    (SELECT COUNT(*) FROM whatsapp_group_mentions m WHERE m.group_id = g.id) as total_mentions,
                    (SELECT c.id FROM conversations c WHERE c.group_id = g.id AND c.status NOT IN ('closed','resolved','spam') ORDER BY COALESCE(c.last_message_at, c.created_at) DESC LIMIT 1) as conversation_id
                FROM whatsapp_groups g
                JOIN whatsapp_connections wc ON wc.id = g.connection_id
                JOIN channels ch ON ch.id = wc.channel_id
                LEFT JOIN inboxes i ON i.id = g.inbox_id";
        $where = [];
        $params = [];
        if ($connectionId) {
            $where[] = "g.connection_id = ?";
            $params[] = $connectionId;
        }
        if ($onlyMonitored) {
            $where[] = "g.is_active = 1";
        }
        $search = trim((string) $search);
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $where[] = "(g.name LIKE ? OR g.group_jid LIKE ?)";
            $params[] = $like;
            $params[] = $like;
        }
        if ($where) {
            $sql .= " WHERE " . implode(' AND ', $where);
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

    /**
     * Histórico de menções do grupo.
     *
     * Traz a conversa e a mensagem espelhadas (quando existem) para o
     * "ver na conversa" levar direto ao balão.
     */
    public static function mentions(int $groupId, int $limit = 50): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT g.*,
                    (SELECT c.id FROM messages ms
                       JOIN conversations c ON c.id = ms.conversation_id
                      WHERE ms.id = g.message_id LIMIT 1) as conversation_id
             FROM whatsapp_group_mentions g
             WHERE g.group_id = ? ORDER BY g.id DESC LIMIT " . (int) $limit,
            [$groupId]
        );
    }

    /**
     * Participantes em cache (última consulta ao provedor).
     *
     * O autocomplete de @menção usa este cache para não chamar o provedor a
     * cada troca de conversa; a tela do grupo tem um botão para renovar.
     *
     * @return array<int, array{phone:string, lid:string, name:string, is_admin:bool}>
     */
    public static function members(int $groupId): array
    {
        $row = Database::getInstance()->fetch(
            "SELECT members_json FROM whatsapp_groups WHERE id = ?",
            [$groupId]
        );
        if (!$row || empty($row['members_json'])) {
            return [];
        }
        $decoded = json_decode((string) $row['members_json'], true);
        return is_array($decoded) ? $decoded : [];
    }

    /** Grava o cache de participantes com o instante da consulta. */
    public static function saveMembers(int $groupId, array $members): int
    {
        return self::update($groupId, [
            'members_json' => json_encode(array_values($members), JSON_UNESCAPED_UNICODE),
            'members_synced_at' => date('Y-m-d H:i:s'),
            'participant_count' => count($members) ?: null,
        ]);
    }

    /** Instante da última consulta de participantes (null = nunca). */
    public static function membersSyncedAt(int $groupId): ?string
    {
        $row = Database::getInstance()->fetch(
            "SELECT members_synced_at FROM whatsapp_groups WHERE id = ?",
            [$groupId]
        );
        return !empty($row['members_synced_at']) ? (string) $row['members_synced_at'] : null;
    }

    /**
     * Monitora/arquiva o grupo.
     *
     * Arquivado = o grupo não espelha mais mensagem na caixa e não dispara
     * alerta (o histórico já espelhado continua lá para consulta).
     */
    public static function setMonitored(int $id, bool $monitored): int
    {
        return self::update($id, ['is_active' => $monitored ? 1 : 0]);
    }

    public static function isMonitored(array $group): bool
    {
        return (int) ($group['is_active'] ?? 1) === 1;
    }

    /**
     * Janela de silêncio (horas) que abre uma nova conversa para o grupo.
     * 0 = uma conversa só por grupo (comportamento antigo).
     */
    public static function setTopicWindow(int $id, int $hours): int
    {
        return self::update($id, ['topic_window_hours' => max(0, $hours)]);
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
            'message_id' => !empty($data['message_id']) ? (int) $data['message_id'] : null,
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
