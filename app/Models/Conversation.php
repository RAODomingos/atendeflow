<?php

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;

class Conversation
{
    public static function find(int $id): ?array
    {
        $conv = Database::getInstance()->fetch(
            "SELECT c.*, ch.type as channel_type, ch.name as channel_name,
                    d.name as department_name, d.color as department_color,
                     u.name as assigned_user_name, ct.name as contact_name,
                     ct.email as contact_email, ct.phone as contact_phone,
                     ct.avatar as contact_avatar, COALESCE((SELECT network_name FROM contact_stores WHERE contact_id = ct.id ORDER BY network_name LIMIT 1), ct.company) as contact_company
             FROM conversations c
             JOIN contacts ct ON ct.id = c.contact_id
             LEFT JOIN departments d ON d.id = c.department_id
             LEFT JOIN users u ON u.id = c.assigned_user_id
             LEFT JOIN channels ch ON ch.id = c.channel_id
             WHERE c.id = ?",
            [$id]
        );

        if ($conv) {
            $conv['tags'] = Database::getInstance()->fetchAll(
                "SELECT t.* FROM tags t
                 JOIN conversation_tags ct ON ct.tag_id = t.id
                 WHERE ct.conversation_id = ?",
                [$id]
            );
        }

        return $conv;
    }

    public static function findByPublicId(string $publicId): ?array
    {
        return Database::getInstance()->fetch(
            "SELECT * FROM conversations WHERE public_id = ?",
            [$publicId]
        );
    }

    public static function getInboxConversations(?int $userId = null, ?int $departmentId = null, string $status = null, array $filters = []): array
    {
        $sql = "SELECT c.*, ch.type as channel_type, ch.name as channel_name,
                       d.name as department_name, d.color as department_color,
                       u.name as assigned_user_name,
                        ct.name as contact_name, ct.email as contact_email, ct.phone as contact_phone, ct.avatar as contact_avatar, COALESCE((SELECT network_name FROM contact_stores WHERE contact_id = ct.id ORDER BY network_name LIMIT 1), ct.company) as contact_company,
                        (SELECT content FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message,
                        (SELECT type FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message_type,
                        (SELECT created_at FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message_at,
                        (SELECT COUNT(*) FROM messages WHERE conversation_id = c.id) as message_count,
                         (SELECT COUNT(*) FROM messages WHERE conversation_id = c.id AND direction = 'inbound' AND is_read = 0" . ($userId ? " AND (user_id != ? OR user_id IS NULL)" : "") . ") as unread_count
                 FROM conversations c
                 JOIN contacts ct ON ct.id = c.contact_id
                 LEFT JOIN departments d ON d.id = c.department_id
                 LEFT JOIN users u ON u.id = c.assigned_user_id
                 LEFT JOIN channels ch ON ch.id = c.channel_id
                 WHERE 1=1";

        $params = [];

        if ($userId) {
            $sql .= " AND c.assigned_user_id = ?";
            $params[] = $userId;
            array_unshift($params, $userId); // prepend for the unread_count subquery
        }

        if ($departmentId) {
            $sql .= " AND c.department_id = ?";
            $params[] = $departmentId;
        }

        if ($status) {
            if ($status === 'open') {
                $sql .= " AND c.status IN ('open', 'waiting_customer', 'waiting_internal')";
            } elseif ($status === 'resolved_closed') {
                $sql .= " AND c.status IN ('resolved', 'closed')";
            } else {
                $sql .= " AND c.status = ?";
                $params[] = $status;
            }
        } else {
            $sql .= " AND c.status NOT IN ('closed', 'spam')";
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (ct.name LIKE ? OR ct.email LIKE ? OR c.subject LIKE ?";
            $search = "%{$filters['search']}%";
            $params[] = $search; $params[] = $search; $params[] = $search;
            // Protocolo: ignora máscara (ex.: "2026-000145" acha "2026000145").
            $protoDigits = preg_replace('/\D/', '', (string) $filters['search']);
            if ($protoDigits !== '') {
                $sql .= " OR c.protocol LIKE ?";
                $params[] = "%{$protoDigits}%";
            }
            $sql .= ")";
        }

        if (!empty($filters['priority'])) {
            $sql .= " AND c.priority = ?";
            $params[] = $filters['priority'];
        }

        if (!empty($filters['channel'])) {
            $sql .= " AND ch.type = ?";
            $params[] = $filters['channel'];
        }

        if (empty($filters['include_snoozed'])) {
            $sql .= " AND (c.snoozed_until IS NULL OR c.snoozed_until <= NOW())";
        }

        $sql .= " ORDER BY
                    CASE c.status
                        WHEN 'new' THEN 0
                        WHEN 'open' THEN 1
                        WHEN 'waiting_customer' THEN 2
                        WHEN 'waiting_internal' THEN 3
                        ELSE 4
                    END,
                    c.last_message_at DESC";

        return Database::getInstance()->fetchAll($sql, $params);
    }

    /**
     * Conversas de uma caixa específica, respeitando o tipo da caixa:
     * - department: conversas cujo inbox_id = caixa OU canal vinculado à caixa
     * - personal: conversas atribuídas aos donos OU canal vinculado à caixa
     */
    public static function getConversationsForInbox(int $inboxId, ?int $userId = null, array $filters = []): array
    {
        $sql = self::listBaseSql($userId) . " WHERE 1=1";
        $params = [];

        $sql .= self::inboxMembershipFragment($inboxId, $params);
        $sql .= self::listFilterSql($filters, $params, $userId);

        $sql .= " ORDER BY COALESCE(c.last_message_at, c.created_at) DESC";
        $rows = Database::getInstance()->fetchAll($sql, $params);
        return self::attachTagsJson($rows);
    }

    /**
     * SELECT + JOINs da listagem de conversas (sem WHERE).
     * Reusado pela query de 1 caixa e pela UNION ALL de N caixas.
     */
    private static function listBaseSql(?int $userId): string
    {
        $unreadCondition = "direction = 'inbound' AND is_read = 0";
        if ($userId) {
            $unreadCondition .= " AND (user_id != " . (int)$userId . " OR user_id IS NULL)";
        }

        return "SELECT c.*, ch.type as channel_type, ch.name as channel_name,
                        d.name as department_name, d.color as department_color,
                        u.name as assigned_user_name,
                        ct.name as contact_name, ct.email as contact_email, ct.phone as contact_phone, ct.avatar as contact_avatar, COALESCE((SELECT network_name FROM contact_stores WHERE contact_id = ct.id ORDER BY network_name LIMIT 1), ct.company) as contact_company,
                        latest_msg.content as last_message,
                        latest_msg.type as last_message_type,
                        latest_msg.created_at as last_msg_at,
                        latest_msg.direction as last_message_direction,
                        COALESCE(msg_stats.message_count, 0) as message_count,
                        COALESCE(msg_stats.unread_count, 0) as unread_count,
                        CASE WHEN EXISTS (
                            SELECT 1 FROM conversation_flow_states fs
                            WHERE fs.conversation_id = c.id AND fs.is_active = 1
                        ) THEN 1 ELSE 0 END as has_active_flow,
                        CASE
                            WHEN c.status IN ('new','open','waiting_customer','waiting_internal')
                            THEN CASE
                                -- Sem mensagem do cliente ainda: conta desde a criação
                                WHEN last_in.last_inbound_id IS NULL
                                THEN TIMESTAMPDIFF(SECOND, c.created_at, NOW())
                                -- Atendente já respondeu depois da última msg do cliente: zerado
                                WHEN last_in.last_public_id > last_in.last_inbound_id THEN 0
                                -- Cliente aguardando resposta: conta desde a última msg dele
                                ELSE TIMESTAMPDIFF(SECOND, last_in.last_inbound_at, NOW())
                            END
                            ELSE 0
                        END as waiting_seconds
                FROM conversations c
                JOIN contacts ct ON ct.id = c.contact_id
                LEFT JOIN departments d ON d.id = c.department_id
                LEFT JOIN users u ON u.id = c.assigned_user_id
                LEFT JOIN channels ch ON ch.id = c.channel_id
                LEFT JOIN (
                    SELECT m1.conversation_id, m1.content, m1.created_at, m1.type, m1.direction
                    FROM messages m1
                    WHERE m1.id = (
                        SELECT MAX(m2.id) FROM messages m2 WHERE m2.conversation_id = m1.conversation_id
                    )
                ) latest_msg ON latest_msg.conversation_id = c.id
                LEFT JOIN (
                    SELECT conversation_id,
                           MAX(CASE WHEN direction = 'inbound' THEN created_at END) as last_inbound_at,
                           MAX(CASE WHEN direction = 'inbound' THEN id END) as last_inbound_id,
                           MAX(CASE WHEN type <> 'internal_note' THEN id END) as last_public_id
                    FROM messages
                    GROUP BY conversation_id
                ) last_in ON last_in.conversation_id = c.id
                LEFT JOIN (
                    SELECT conversation_id,
                           COUNT(*) as message_count,
                           SUM(CASE WHEN {$unreadCondition} THEN 1 ELSE 0 END) as unread_count
                    FROM messages
                    GROUP BY conversation_id
                ) msg_stats ON msg_stats.conversation_id = c.id";
    }

    /**
     * Fragmento WHERE dos filtros da listagem (status/busca/adiadas).
     * $c é o prefixo da conversa; $contactName/$contactEmail apontam as
     * colunas de contato (na UNION externa são aliases u.*).
     */
    private static function listFilterSql(array $filters, array &$params, ?int $userId, string $c = 'c.', string $contactName = 'ct.name', string $contactEmail = 'ct.email'): string
    {
        $sql = "";
        if (!empty($filters['unassigned'])) {
            $sql .= " AND {$c}assigned_user_id IS NULL AND {$c}status IN ('new', 'open')";
        }
        if (!empty($filters['mine']) && $userId) {
            $sql .= " AND {$c}assigned_user_id = ?";
            $params[] = $userId;
        }
        if (!empty($filters['status'])) {
            if ($filters['status'] === 'active') {
                // Conversas com fluxo ativo também entram em "Em atendimento".
                $sql .= " AND {$c}status IN ('new', 'open', 'waiting_customer', 'waiting_internal')";
            } elseif ($filters['status'] === 'open') {
                $sql .= " AND {$c}status IN ('open', 'waiting_customer', 'waiting_internal')";
            } elseif ($filters['status'] === 'resolved_closed') {
                $sql .= " AND {$c}status IN ('resolved', 'closed')";
            } else {
                $sql .= " AND {$c}status = ?";
                $params[] = $filters['status'];
            }
        }
        if (!empty($filters['search'])) {
            $sql .= " AND ({$contactName} LIKE ? OR {$contactEmail} LIKE ? OR {$c}subject LIKE ?";
            $search = "%{$filters['search']}%";
            $params[] = $search; $params[] = $search; $params[] = $search;
            // Protocolo: ignora máscara (ex.: "2026-000145" acha "2026000145").
            $protoDigits = preg_replace('/\D/', '', (string) $filters['search']);
            if ($protoDigits !== '') {
                $sql .= " OR {$c}protocol LIKE ?";
                $params[] = "%{$protoDigits}%";
            }
            $sql .= ")";
        }

        if (empty($filters['include_snoozed'])) {
            $sql .= " AND ({$c}snoozed_until IS NULL OR {$c}snoozed_until <= NOW())";
        }
        return $sql;
    }

    /**
     * Anexa tags_json a cada linha da listagem.
     * Monta o JSON em PHP (em vez de JSON_ARRAYAGG, que só existe no MySQL 8 —
     * o MariaDB, usado pelo XAMPP, lança "FUNCTION JSON_ARRAYAGG does not exist").
     */
    private static function attachTagsJson(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }
        foreach ($rows as &$row) {
            // O alias last_msg_at (msg mais recente) sobrescreve a coluna da
            // tabela, como antes fazia o alias duplicado last_message_at.
            if (array_key_exists('last_msg_at', $row)) {
                $row['last_message_at'] = $row['last_msg_at'] ?? $row['last_message_at'] ?? null;
                unset($row['last_msg_at']);
            }
        }
        unset($row);
        $ids = array_column($rows, 'id');
        $ph = rtrim(str_repeat('?,', count($ids)), ',');
        $tagRows = Database::getInstance()->fetchAll(
            "SELECT ct.conversation_id, t.id, t.name, t.color
             FROM conversation_tags ct
             JOIN tags t ON t.id = ct.tag_id
             WHERE ct.conversation_id IN ({$ph})
             ORDER BY t.id",
            $ids
        );
        $tagsByConv = [];
        foreach ($tagRows as $tr) {
            $tagsByConv[(int) $tr['conversation_id']][] = [
                'id'    => (int) $tr['id'],
                'name'  => (string) $tr['name'],
                'color' => $tr['color'] ?? '#6c757d',
            ];
        }
        foreach ($rows as &$row) {
            $row['tags_json'] = json_encode($tagsByConv[(int) $row['id']] ?? [], JSON_UNESCAPED_UNICODE);
        }
        unset($row);
        return $rows;
    }

    /**
     * Fragmento SQL de pertinência de uma conversa à caixa informada
     * (mesmo critério usado em getConversationsForInbox).
     */
    private static function inboxMembershipFragment(int $inboxId, array &$params): string
    {
        $inbox = \App\Models\Inbox::find($inboxId);
        if ($inbox && $inbox['type'] === 'personal') {
            $owners = \App\Models\Inbox::getUsers($inboxId);
            $ownerIds = array_column($owners, 'id');
            if ($ownerIds) {
                $ph = rtrim(str_repeat('?,', count($ownerIds)), ',');
                $fragment = " AND (c.assigned_user_id IN ({$ph}) OR c.channel_id IN (SELECT channel_id FROM inbox_channels WHERE inbox_id = ?))";
                foreach ($ownerIds as $oid) {
                    $params[] = $oid;
                }
                $params[] = $inboxId;
                return $fragment;
            }
            $params[] = $inboxId;
            return " AND c.channel_id IN (SELECT channel_id FROM inbox_channels WHERE inbox_id = ?)";
        }

        $params[] = $inboxId;
        $params[] = $inboxId;
        // inbox_id é autoritativo: o fallback por canal só vale para
        // conversas legadas sem caixa definida. Assim, ao transferir de
        // setor (que move a inbox junto), a conversa sai da caixa de origem
        // em vez de aparecer nas duas.
        return " AND (c.inbox_id = ? OR (c.inbox_id IS NULL AND c.channel_id IN (SELECT channel_id FROM inbox_channels WHERE inbox_id = ?)))";
    }

    /**
     * Quantidade por caixa para o badge vermelho do menu lateral.
     * - Conversas normais: conta quando em aberto (novo/aberto/aguardando).
     * - Conversas de grupo WhatsApp (sempre abertas, sem encerramento):
     *   contam SOMENTE quando há menção ao número ainda não vista
     *   (mensagem inbound não-lida). Mensagem comum de grupo é histórico
     *   lido e não acende o badge.
     * Retorna um mapa [inbox_id => total].
     */
    public static function openCountsByInbox(array $inboxIds): array
    {
        $result = array_fill_keys($inboxIds, 0);
        $parts = [];
        $allParams = [];
        foreach (array_filter($inboxIds) as $inboxId) {
            $params = [];
            $where = self::inboxMembershipFragment((int) $inboxId, $params);
            $parts[] = "SELECT ? AS inbox_id, COUNT(*) AS c FROM conversations c WHERE 1=1 {$where} AND c.status IN ('new', 'open', 'waiting_customer', 'waiting_internal')"
                . " AND (c.group_id IS NULL OR EXISTS (SELECT 1 FROM messages m WHERE m.conversation_id = c.id AND m.direction = 'inbound' AND m.is_read = 0))";
            array_unshift($params, (int) $inboxId);
            $allParams = array_merge($allParams, $params);
        }
        if ($parts) {
            $rows = Database::getInstance()->fetchAll(implode(' UNION ALL ', $parts), $allParams);
            foreach ($rows as $row) {
                $result[(int) $row['inbox_id']] = (int) $row['c'];
            }
        }
        return $result;
    }

    /**
     * Conversas combinadas de várias caixas (união, sem duplicar),
     * ordenadas pela mais recente. Usada na visão "Caixa de Entrada".
     */
    /**
     * Conversas combinadas de várias caixas em UMA query (UNION ALL por
     * caixa + filtros aplicados uma vez na camada externa). Antes: N
     * queries em série (uma por caixa) + sort em PHP.
     */
    public static function getConversationsForInboxes(array $inboxIds, ?int $userId = null, array $filters = []): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $inboxIds))));
        if (empty($ids)) {
            return [];
        }
        if (count($ids) === 1) {
            return self::getConversationsForInbox($ids[0], $userId, $filters);
        }

        $base = self::listBaseSql($userId);
        $branches = [];
        $params = [];
        foreach ($ids as $id) {
            $bp = [];
            $branches[] = $base . " WHERE 1=1" . self::inboxMembershipFragment($id, $bp);
            $params = array_merge($params, $bp);
        }

        // Filtros uma vez só, sobre os aliases da UNION (u.*).
        // contact_name/contact_email/subject/protocol/status existem no SELECT.
        $sql = "SELECT u.* FROM (" . implode(' UNION ALL ', $branches) . ") u WHERE 1=1";
        $sql .= self::listFilterSql($filters, $params, $userId, 'u.', 'u.contact_name', 'u.contact_email');
        $sql .= " ORDER BY COALESCE(u.last_msg_at, u.created_at) DESC";

        $rows = Database::getInstance()->fetchAll($sql, $params);

        // Deduplica (conversa pode estar em 2 caixas): mantém a mais recente.
        $map = [];
        foreach ($rows as $c) {
            if (!isset($map[$c['id']])) {
                $map[$c['id']] = $c;
            }
        }
        return self::attachTagsJson(array_values($map));
    }


    /**
     * Outros tickets do mesmo contato (exceto o informado), usado no painel do atendimento.
     */
    public static function getByContact(int $contactId, ?int $excludeId = null): array
    {
        $sql = "SELECT c.*, ch.type as channel_type, ch.name as channel_name,
                       ct.name as contact_name,
                       d.name as department_name, d.color as department_color,
                       u.name as assigned_user_name,
                       (SELECT content FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message,
                        (SELECT type FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message_type,
                        (SELECT created_at FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message_at
                FROM conversations c
                JOIN contacts ct ON ct.id = c.contact_id
                LEFT JOIN departments d ON d.id = c.department_id
                LEFT JOIN users u ON u.id = c.assigned_user_id
                LEFT JOIN channels ch ON ch.id = c.channel_id
                WHERE c.contact_id = ?";
        $params = [$contactId];
        if ($excludeId) {
            $sql .= " AND c.id != ?";
            $params[] = $excludeId;
        }
        $sql .= " ORDER BY COALESCE(c.last_message_at, c.created_at) DESC";
        return Database::getInstance()->fetchAll($sql, $params);
    }

    public static function getUnassigned(int $departmentId = null): array
    {
        $sql = "SELECT c.*, ch.type as channel_type, ct.name as contact_name, ct.phone as contact_phone,
                       ct.avatar as contact_avatar, d.name as department_name, d.color as department_color,
                       u.name as assigned_user_name
                FROM conversations c
                JOIN contacts ct ON ct.id = c.contact_id
                LEFT JOIN channels ch ON ch.id = c.channel_id
                LEFT JOIN departments d ON d.id = c.department_id
                LEFT JOIN users u ON u.id = c.assigned_user_id
                WHERE c.assigned_user_id IS NULL AND c.status IN ('new', 'open')
                  AND (c.snoozed_until IS NULL OR c.snoozed_until <= NOW())";
        $params = [];

        if ($departmentId) {
            $sql .= " AND c.department_id = ?";
            $params[] = $departmentId;
        }

        $sql .= " ORDER BY c.created_at ASC";

        return Database::getInstance()->fetchAll($sql, $params);
    }

    public static function create(array $data): int
    {
        $data['public_id'] ??= bin2hex(random_bytes(16));
        $id = Database::getInstance()->insert('conversations', $data);
        if (empty($data['protocol'])) {
            // Protocolo único: AAMMDD + id, sem traço (ex.: 260914145).
            $protocol = date('ymd') . $id;
            Database::getInstance()->update('conversations', ['protocol' => $protocol], 'id = ?', [$id]);
        }
        return $id;
    }

    public static function update(int $id, array $data): int
    {
        return Database::getInstance()->update('conversations', $data, 'id = ?', [$id]);
    }

    public static function countByStatus(?int $userId = null, ?int $departmentId = null): array
    {
        $where = "1=1";
        $params = [];

        if ($userId) {
            $where .= " AND assigned_user_id = ?";
            $params[] = $userId;
        }
        if ($departmentId) {
            $where .= " AND department_id = ?";
            $params[] = $departmentId;
        }

        $rows = Database::getInstance()->fetchAll(
            "SELECT status, COUNT(*) as total FROM conversations WHERE {$where} GROUP BY status",
            $params
        );

        $counts = array_fill_keys(['new', 'open', 'waiting_customer', 'waiting_internal', 'resolved', 'closed', 'spam'], 0);
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    public static function getMessages(int $conversationId, array $opts = []): array
    {
        $sql = "SELECT m.*, u.name as user_name, u.avatar as user_avatar, ct.avatar as contact_avatar
                FROM messages m
                LEFT JOIN users u ON u.id = m.user_id
                JOIN conversations c ON c.id = m.conversation_id
                LEFT JOIN contacts ct ON ct.id = c.contact_id
                WHERE m.conversation_id = ?";
        $params = [$conversationId];
        if (!empty($opts['before'])) {
            $sql .= " AND m.id < ?";
            $params[] = (int) $opts['before'];
        }
        $sql .= " ORDER BY m.created_at ASC";
        if (!empty($opts['limit'])) {
            $sql .= " LIMIT " . (int) $opts['limit'];
        }
        $msgs = Database::getInstance()->fetchAll($sql, $params);
        return self::enrichMessages($msgs);
    }

    private static function enrichMessages(array $msgs): array
    {
        $replyIds = array_values(array_filter(array_column($msgs, 'reply_to')));
        $replyMap = [];
        if ($replyIds) {
            $ph = implode(',', array_fill(0, count($replyIds), '?'));
            $replies = Database::getInstance()->fetchAll(
                "SELECT id, content, type, direction, user_id FROM messages WHERE id IN ({$ph})",
                $replyIds
            );
            foreach ($replies as $r) {
                $replyMap[$r['id']] = $r;
            }
        }
        foreach ($msgs as &$m) {
            if (!empty($m['reply_to']) && isset($replyMap[$m['reply_to']])) {
                $m['reply_to_data'] = $replyMap[$m['reply_to']];
            }
            $m['avatar_url'] = $m['direction'] === 'inbound'
                ? ($m['contact_avatar'] ?? null)
                : ($m['user_avatar'] ?? null);
        }
        return $msgs;
    }

    public static function getMessage(int $id): ?array
    {
        $msg = Database::getInstance()->fetch(
            "SELECT m.*, u.name as user_name FROM messages m
             LEFT JOIN users u ON u.id = m.user_id WHERE m.id = ?",
            [$id]
        );
        if ($msg && !empty($msg['reply_to'])) {
            $reply = Database::getInstance()->fetch(
                "SELECT id, content, type, direction, user_id FROM messages WHERE id = ?",
                [$msg['reply_to']]
            );
            if ($reply) {
                $msg['reply_to_data'] = $reply;
            }
        }
        return $msg;
    }

    public static function updateMessage(int $id, int $userId, string $content): bool
    {
        $msg = self::getMessage($id);
        if (!$msg || $msg['deleted_at'] || (int) $msg['user_id'] !== $userId) {
            return false;
        }
        if (!in_array($msg['type'], ['text', 'internal_note'], true)) {
            return false;
        }
        return (bool) Database::getInstance()->update(
            'messages',
            ['content' => $content, 'updated_at' => date('Y-m-d H:i:s')],
            'id = ?',
            [$id]
        );
    }

    public static function deleteMessage(int $id, int $userId): bool
    {
        $msg = self::getMessage($id);
        if (!$msg || $msg['deleted_at'] || (int) $msg['user_id'] !== $userId) {
            return false;
        }
        return (bool) Database::getInstance()->update(
            'messages',
            ['deleted_at' => date('Y-m-d H:i:s')],
            'id = ?',
            [$id]
        );
    }

    /**
     * Edição pelo CLIENTE (webchat): só mensagens próprias (inbound),
     * de texto, não apagadas, da conversa informada e ainda aberta.
     */
    public static function clientUpdateMessage(int $conversationId, int $id, string $content): bool
    {
        $msg = self::getMessage($id);
        if (!$msg || (int) $msg['conversation_id'] !== $conversationId) {
            return false;
        }
        if (!empty($msg['deleted_at']) || ($msg['direction'] ?? '') !== 'inbound') {
            return false;
        }
        if (($msg['type'] ?? '') !== 'text') {
            return false;
        }
        return (bool) Database::getInstance()->update(
            'messages',
            ['content' => $content, 'updated_at' => date('Y-m-d H:i:s')],
            'id = ?',
            [$id]
        );
    }

    /**
     * Exclusão pelo CLIENTE (webchat): só mensagens próprias (inbound),
     * não apagadas, da conversa informada e ainda aberta.
     */
    public static function clientDeleteMessage(int $conversationId, int $id): bool
    {
        $msg = self::getMessage($id);
        if (!$msg || (int) $msg['conversation_id'] !== $conversationId) {
            return false;
        }
        if (!empty($msg['deleted_at']) || ($msg['direction'] ?? '') !== 'inbound') {
            return false;
        }
        return (bool) Database::getInstance()->update(
            'messages',
            ['deleted_at' => date('Y-m-d H:i:s')],
            'id = ?',
            [$id]
        );
    }

    /**
     * Reação do CLIENTE (webchat): alterna (toggle) o emoji na mensagem.
     * Marca origem 'contact' para distinguir das reações do atendente.
     */
    public static function clientToggleReaction(int $conversationId, int $id, string $emoji, int $contactId): bool
    {
        $msg = Database::getInstance()->fetch("SELECT * FROM messages WHERE id = ?", [$id]);
        if (!$msg || (int) $msg['conversation_id'] !== $conversationId) {
            return false;
        }
        if (!empty($msg['deleted_at']) || in_array($msg['type'] ?? '', ['system', 'internal_note'], true)) {
            return false;
        }
        $reactions = $msg['reactions'] ? (array) json_decode($msg['reactions'], true) : [];
        $found = false;
        $reactions = array_values(array_filter($reactions, function ($r) use ($emoji, $contactId, &$found) {
            $mine = ($r['emoji'] ?? '') === $emoji
                && ($r['from'] ?? '') === 'contact'
                && (int) ($r['contact_id'] ?? 0) === $contactId;
            if ($mine) {
                $found = true;
                return false;
            }
            return true;
        }));
        if (!$found) {
            $reactions[] = [
                'emoji' => $emoji,
                'from' => 'contact',
                'contact_id' => $contactId,
                'timestamp' => time(),
            ];
        }
        $reactions = array_slice($reactions, -20); // keep last 20
        return (bool) Database::getInstance()->update(
            'messages',
            [
                'reactions' => json_encode($reactions),
                // updated_at move o relógio para os polls (painel/widget)
                // enxergarem a reação sem reload.
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            'id = ?',
            [$id]
        );
    }

    public static function updateMessageReaction(int $id, string $emoji): bool
    {
        $msg = Database::getInstance()->fetch("SELECT reactions FROM messages WHERE id = ?", [$id]);
        if (!$msg) return false;
        $reactions = $msg['reactions'] ? (array) json_decode($msg['reactions'], true) : [];
        $reactions[] = ['emoji' => $emoji, 'user_id' => Auth::id(), 'created_at' => date('Y-m-d H:i:s')];
        $reactions = array_slice($reactions, -20); // keep last 20
        return (bool) Database::getInstance()->update(
            'messages',
            [
                'reactions' => json_encode($reactions),
                // updated_at move o relógio para os polls (painel/widget)
                // enxergarem a reação sem reload.
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            'id = ?',
            [$id]
        );
    }

    public static function snooze(int $id, ?string $until): void
    {
        self::update($id, ['snoozed_until' => $until]);
        self::addEvent($id, 'snoozed', $until ? 'Aten. agendada para ' . $until : 'Agendamento cancelado', Auth::id());
    }

    public static function getCsat(int $id): ?array
    {
        return Database::getInstance()->fetch(
            "SELECT * FROM conversation_csats WHERE conversation_id = ? ORDER BY id DESC LIMIT 1",
            [$id]
        );
    }

    public static function addCsat(int $id, int $rating, ?string $comment): void
    {
        $existing = self::getCsat($id);
        if ($existing) {
            Database::getInstance()->update(
                'conversation_csats',
                ['rating' => $rating, 'comment' => $comment],
                'id = ?',
                [$existing['id']]
            );
        } else {
            Database::getInstance()->insert('conversation_csats', [
                'conversation_id' => $id,
                'rating' => $rating,
                'comment' => $comment,
            ]);
        }
        self::addEvent($id, 'csat', "Avaliação de satisfação: {$rating}/5", Auth::id());
    }

    /**
     * Agregados do relatório de CSAT (total + média no período).
     *
     * @return array{total: int, average: ?float}
     */
    public static function csatSummary(?int $days): array
    {
        [$where, $params] = self::csatPeriodFilter($days);
        $db = Database::getInstance();
        $total = (int) ($db->fetch("SELECT COUNT(*) AS c FROM conversation_csats cc {$where}", $params)['c'] ?? 0);
        $avg = $db->fetch("SELECT AVG(rating) AS a FROM conversation_csats cc {$where}", $params)['a'] ?? null;
        return ['total' => $total, 'average' => $avg !== null ? round((float) $avg, 2) : null];
    }

    /**
     * Distribuição das notas 1–5 no período (chaves sempre presentes).
     *
     * @return array<int, int>
     */
    public static function csatDistribution(?int $days): array
    {
        [$where, $params] = self::csatPeriodFilter($days);
        $rows = Database::getInstance()->fetchAll(
            "SELECT rating, COUNT(*) AS c FROM conversation_csats cc {$where} GROUP BY rating ORDER BY rating DESC",
            $params
        );
        $distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        foreach ($rows as $row) {
            $distribution[(int) $row['rating']] = (int) $row['c'];
        }
        return $distribution;
    }

    /**
     * Avaliações recentes com dados da conversa para o relatório.
     */
    public static function csatRecent(?int $days, int $limit = 25): array
    {
        [$where, $params] = self::csatPeriodFilter($days);
        return Database::getInstance()->fetchAll(
            "SELECT cc.*, conv.id AS conversation_id, conv.public_id, c.name AS contact_name,
                    ch.type AS channel_type, d.name AS department_name
             FROM conversation_csats cc
             JOIN conversations conv ON conv.id = cc.conversation_id
             LEFT JOIN contacts c ON c.id = conv.contact_id
             LEFT JOIN channels ch ON ch.id = conv.channel_id
             LEFT JOIN departments d ON d.id = conv.department_id
             {$where}
             ORDER BY cc.created_at DESC
             LIMIT " . max(1, $limit),
            $params
        );
    }

    /**
     * @return array{0: string, 1: array}
     */
    private static function csatPeriodFilter(?int $days): array
    {
        if ($days === null) {
            return ['', []];
        }
        return ['WHERE cc.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)', [$days]];
    }

    public static function merge(int $sourceId, int $targetId): void
    {
        Database::getInstance()->update('messages', ['conversation_id' => $targetId], 'conversation_id = ?', [$sourceId]);
        Database::getInstance()->update('conversation_tags', ['conversation_id' => $targetId], 'conversation_id = ?', [$sourceId]);
        self::update($sourceId, ['status' => 'closed']);
        self::addEvent($sourceId, 'merged', "Conversa mesclada na #{$targetId}", Auth::id());
        self::addEvent($targetId, 'merged', "Conversa #{$sourceId} mesclada aqui", Auth::id());
    }

    public static function addMessage(int $conversationId, array $data): int
    {
        $data['conversation_id'] = $conversationId;
        $messageId = Database::getInstance()->insert('messages', $data);

        self::update($conversationId, ['last_message_at' => date('Y-m-d H:i:s')]);

        if (($data['direction'] ?? '') === 'inbound') {
            $conv = self::find($conversationId);
            if ($conv) {
                \App\Models\Contact::touchActivity($conv['contact_id']);
            }
        }

        return $messageId;
    }

    public static function addEvent(int $conversationId, string $eventType, ?string $description = null, ?int $userId = null, ?array $metadata = null): int
    {
        return Database::getInstance()->insert('conversation_events', [
            'conversation_id' => $conversationId,
            'event_type' => $eventType,
            'description' => $description,
            'user_id' => $userId,
            'metadata' => $metadata ? json_encode($metadata) : null,
        ]);
    }

    public static function getEvents(int $conversationId): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT e.*, u.name as user_name
             FROM conversation_events e
             LEFT JOIN users u ON u.id = e.user_id
             WHERE e.conversation_id = ?
             ORDER BY e.created_at DESC",
            [$conversationId]
        );
    }

    public static function markMessagesAsRead(int $conversationId, int $userId): int
    {
        $now = date('Y-m-d H:i:s');
        return Database::getInstance()->update(
            'messages',
            [
                'is_read' => 1,
                'read_at' => $now,
            ],
            'conversation_id = ? AND direction = ? AND (user_id != ? OR user_id IS NULL) AND is_read = 0',
            [$conversationId, 'inbound', $userId]
        );
    }

    /**
     * Marca mensagens outbound como "entregues" (delivered_at) para o cliente.
     * Chamado pelo webhook de WhatsApp ao confirmar a entrega.
     */
    public static function markMessagesDelivered(array $messageIds, ?string $when = null): int
    {
        if (empty($messageIds)) return 0;
        $when ??= date('Y-m-d H:i:s');
        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        return Database::getInstance()->update(
            'messages',
            ['delivered_at' => $when],
            "id IN ({$placeholders}) AND direction = 'outbound' AND delivered_at IS NULL",
            $messageIds
        );
    }

    public static function addTag(int $conversationId, int $tagId): void
    {
        $exists = Database::getInstance()->fetch(
            "SELECT 1 FROM conversation_tags WHERE conversation_id = ? AND tag_id = ? LIMIT 1",
            [$conversationId, $tagId]
        );
        if (!$exists) {
            Database::getInstance()->insert('conversation_tags', [
                'conversation_id' => $conversationId,
                'tag_id' => $tagId,
            ]);
            self::addEvent($conversationId, 'tag_added', 'Etiqueta aplicada', Auth::id());
        }
    }

    public static function removeTag(int $conversationId, int $tagId): void
    {
        Database::getInstance()->delete(
            'conversation_tags',
            'conversation_id = ? AND tag_id = ?',
            [$conversationId, $tagId]
        );
        self::addEvent($conversationId, 'tag_removed', 'Etiqueta removida', Auth::id());
    }

    public static function getUnreadCount(int $userId): int
    {
        $row = Database::getInstance()->fetch(
            "SELECT COUNT(*) as total FROM messages m
             JOIN conversations c ON c.id = m.conversation_id
             WHERE m.direction = 'inbound' AND m.is_read = 0
             AND (c.assigned_user_id = ?
                  OR c.department_id IN (
                      SELECT department_id FROM department_users WHERE user_id = ?
                  ))",
            [$userId, $userId]
        );
        return (int) ($row['total'] ?? 0);
    }

    public static function getUnreadConversationsCount(int $userId): int
    {
        $inboxes = \App\Models\Inbox::getUserInboxes($userId);
        $inboxIds = array_column($inboxes, 'id');
        if (empty($inboxIds)) return 0;

        $inboxConditions = [];
        $params = [$userId];
        foreach ($inboxIds as $iid) {
            $inbox = \App\Models\Inbox::find($iid);
            if ($inbox && ($inbox['type'] ?? '') === 'personal') {
                $owners = \App\Models\Inbox::getUsers($iid);
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

        $row = Database::getInstance()->fetch(
            "SELECT COUNT(DISTINCT c.id) as total
             FROM conversations c
             JOIN messages m ON m.conversation_id = c.id
             WHERE m.direction = 'inbound' AND m.is_read = 0
               AND (m.user_id != ? OR m.user_id IS NULL)
               AND {$inboxWhere}",
            $params
        );
        return (int) ($row['total'] ?? 0);
    }

    public static function markAllMessagesAsRead(int $userId): int
    {
        $inboxes = \App\Models\Inbox::getUserInboxes($userId);
        $inboxIds = array_column($inboxes, 'id');
        if (empty($inboxIds)) return 0;

        $inboxConditions = [];
        $params = [$userId];
        foreach ($inboxIds as $iid) {
            $inbox = \App\Models\Inbox::find($iid);
            if ($inbox && ($inbox['type'] ?? '') === 'personal') {
                $owners = \App\Models\Inbox::getUsers($iid);
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

        $sql = "UPDATE messages m JOIN conversations c ON c.id = m.conversation_id "
             . "SET m.is_read = 1 "
             . "WHERE m.direction = 'inbound' AND m.is_read = 0 "
             . "AND (m.user_id != ? OR m.user_id IS NULL) AND {$inboxWhere}";

        $stmt = Database::getInstance()->query($sql, $params);
        return $stmt->rowCount();
    }
}
