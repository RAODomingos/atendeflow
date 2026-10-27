<?php

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use App\Services\SlaService;

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
                        (SELECT g.avatar_url FROM whatsapp_groups g WHERE g.id = c.group_id LIMIT 1) as group_avatar,
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

        // Teto de linhas no proprio SQL: quem so precisa de uma amostra
        // (dashboard) nao deve carregar a lista inteira para cortar depois.
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int) $filters['limit'];
        }

        return Database::getInstance()->fetchAll($sql, $params);
    }

    /**
     * Conversas de uma caixa específica: setor coberto, canal vinculado ou
     * grupo de WhatsApp ligado à caixa.
     */
    public static function getConversationsForInbox(int $inboxId, ?int $userId = null, array $filters = []): array
    {
        $sets = \App\Models\Inbox::membershipSets([$inboxId]);
        return self::getConversationsForSets($sets, $userId, $filters);
    }

    /**
     * Derived table da âncora do relógio do SLA.
     *
     * Última mensagem do cliente e última resposta HUMANA nossa.
     *
     * 'Humana' = outbound com user_id preenchido (a mensagem de bot/fluxo
     * ou de automação tem user_id nulo e NÃO conta como resposta): é a
     * mesma regra do motor de medição (SlaMeasurement::isHumanReply).
     *
     * Fragmento público porque a inbox (listBaseSql) e o dashboard
     * (openConversationsAwaitingReply) precisam da MESMA âncora: se
     * divergirem, o número do dashboard e a cor da fila contam coisas
     * diferentes.
     */
    public static function slaAnchorsSql(): string
    {
        return "(
                SELECT conversation_id,
                       MAX(CASE WHEN direction = 'inbound' THEN created_at END) as last_inbound_at,
                       MAX(CASE WHEN direction = 'inbound' THEN id END) as last_inbound_id,
                       MAX(CASE WHEN direction = 'outbound' AND user_id IS NOT NULL
                             AND type NOT IN ('internal_note','system') THEN id END) as last_human_id
                FROM messages
                GROUP BY conversation_id
            )";
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
                        -- Foto do grupo (a conversa de grupo tem contato sintético,
                        -- sem avatar: quem mostra a foto do grupo é ele).
                        (SELECT g.avatar_url FROM whatsapp_groups g WHERE g.id = c.group_id LIMIT 1) as group_avatar,
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
                        -- Âncoras do relógio do SLA: última mensagem do cliente
                        -- e última resposta HUMANA nossa. SlaService::applyClock()
                        -- reescreve `waiting_seconds` em segundos úteis.
                        --
                        -- 'Humana' = outbound com user_id preenchido (a mensagem
                        -- de bot/fluxo ou de automação tem user_id nulo e NÃO
                        -- conta como resposta): é a mesma regra do motor de
                        -- medição (SlaMeasurement::isHumanReply).
                        last_in.last_inbound_at as sla_last_inbound_at,
                        last_in.last_inbound_id as sla_last_inbound_id,
                        last_in.last_human_id as sla_last_human_id,
                        -- Fallback em tempo de relógio: as listagens que alimentam a
                        -- tela de conversas sobrescrevem este valor com
                        -- SlaService::applyClock(), que conta apenas segundos úteis
                        -- (dentro do horário de atendimento do departamento).
                        CASE
                            WHEN c.status IN ('new','open','waiting_customer','waiting_internal')
                            THEN CASE
                                -- Sem mensagem do cliente ainda: conta desde a criação
                                WHEN last_in.last_inbound_id IS NULL
                                THEN TIMESTAMPDIFF(SECOND, c.created_at, NOW())
                                -- Atendente já respondeu depois da última msg do cliente: zerado
                                WHEN last_in.last_human_id > last_in.last_inbound_id THEN 0
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
                LEFT JOIN " . self::slaAnchorsSql() . " last_in ON last_in.conversation_id = c.id
                LEFT JOIN (
                    SELECT conversation_id,
                           COUNT(*) as message_count,
                           SUM(CASE WHEN {$unreadCondition} THEN 1 ELSE 0 END) as unread_count
                    FROM messages
                    GROUP BY conversation_id
                ) msg_stats ON msg_stats.conversation_id = c.id";
    }

    /**
     * Conversas abertas cuja bola está com a gente, com as âncoras do
     * relógio do SLA.
     *
     * Só o que o relógio precisa: status, caixa, departamento e as
     * âncoras (última inbound / última resposta humana). Sem os JOINs
     * pesados da listagem — o dashboard quer o relógio, não a lista.
     *
     * Filtros no SQL (o cálculo em PHP fica barato):
     *  - status ativo, mas sem `waiting_internal`: esse status congela o
     *    relógio (a bola está com outro setor);
     *  - `last_human_id <= last_inbound_id`: o atendente ainda não
     *    respondeu depois do cliente. Conversa sem inbound usa a criação
     *    como âncora (mesma regra de SlaService::clockState).
     *
     * @return array<int, array>
     */
    public static function openConversationsAwaitingReply(): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT c.id, c.status, c.inbox_id, c.department_id, c.created_at,
                    last_in.last_inbound_at as sla_last_inbound_at,
                    last_in.last_inbound_id as sla_last_inbound_id,
                    last_in.last_human_id as sla_last_human_id
             FROM conversations c
             LEFT JOIN " . self::slaAnchorsSql() . " last_in ON last_in.conversation_id = c.id
             WHERE c.status IN ('new','open','waiting_customer')
               AND (
                    last_in.last_inbound_id IS NULL
                    OR last_in.last_human_id IS NULL
                    OR last_in.last_human_id <= last_in.last_inbound_id
               )"
        );
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
        // Responsável: id do atendente, "me" ou "unassigned".
        if (!empty($filters['assignee'])) {
            $assignee = (string) $filters['assignee'];
            if ($assignee === 'unassigned') {
                $sql .= " AND {$c}assigned_user_id IS NULL";
            } elseif ($assignee === 'me') {
                if ($userId) {
                    $sql .= " AND {$c}assigned_user_id = ?";
                    $params[] = $userId;
                }
            } elseif (ctype_digit($assignee) && (int) $assignee > 0) {
                $sql .= " AND {$c}assigned_user_id = ?";
                $params[] = (int) $assignee;
            }
        }
        if (!empty($filters['channel_id'])) {
            $sql .= " AND {$c}channel_id = ?";
            $params[] = (int) $filters['channel_id'];
        }
        if (!empty($filters['channel'])) {
            $sql .= " AND ch.type = ?";
            $params[] = (string) $filters['channel'];
        }
        if (!empty($filters['priority'])) {
            $sql .= " AND {$c}priority = ?";
            $params[] = (string) $filters['priority'];
        }
        // Só não lidas: existe mensagem do cliente ainda não vista por mim.
        if (!empty($filters['unread'])) {
            $unread = "SELECT 1 FROM messages m WHERE m.conversation_id = {$c}id AND m.direction = 'inbound' AND m.is_read = 0";
            if ($userId) {
                $unread .= " AND (m.user_id != ? OR m.user_id IS NULL)";
                $params[] = $userId;
            }
            $sql .= " AND EXISTS ({$unread})";
        }
        if (!empty($filters['status'])) {
            if ($filters['status'] === 'active') {
                // "Em atendimento": a bola está com a equipe — conversa
                // aberta, aguardando o cliente ou aguardando outro setor.
                $sql .= " AND {$c}status IN ('open', 'waiting_customer', 'waiting_internal')";
            } elseif ($filters['status'] === 'waiting') {
                // "Aguardando": fila de chegada do setor — conversas novas,
                // as que saíram do bot e as transferidas de setor.
                $sql .= " AND {$c}status IN ('new')";
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
     * Predicado SQL de pertinência.
     *
     * A conversa pertence a um conjunto de caixas quando casa com um dos
     * eixos: departamento coberto, canal vinculado ou grupo de WhatsApp
     * ligado à caixa. Devolve só a expressão (sem "AND") para poder ser
     * reaproveitada dentro de CASE WHEN, nas contagens por caixa.
     *
     * A natureza da conversa é o filtro final: cada caixa declara quais
     * conversas quer (inboxes.conversation_scope) e a conversa é de grupo
     * (group_id preenchido) ou mensagem geral. Os três baldes — qualquer
     * natureza, grupo e mensagem geral — continuam num predicado só.
     */
    private static function membershipPredicate(array $sets, string $c, array &$params): string
    {
        if (!empty($sets['all'])) {
            return '1=1';
        }
        $clauses = [];
        $any = self::axesClause($sets['any'] ?? [], $c, $params, null);
        if ($any !== '') {
            $clauses[] = $any;
        }
        $groups = self::axesClause($sets['groups'] ?? [], $c, $params, 'groups');
        if ($groups !== '') {
            $clauses[] = $groups;
        }
        $nonGroups = self::axesClause($sets['nonGroups'] ?? [], $c, $params, 'nonGroups');
        if ($nonGroups !== '') {
            $clauses[] = $nonGroups;
        }
        if (empty($clauses)) {
            return '1=0';
        }
        return '(' . implode(' OR ', $clauses) . ')';
    }

    /**
     * Eixos de pertinência de um balde (departamento / canal / grupo).
     *
     * $natureza restringe o balde: 'groups' só conversa de grupo,
     * 'nonGroups' só mensagem geral, null = qualquer natureza.
     */
    private static function axesClause(array $bucket, string $c, array &$params, ?string $natureza): string
    {
        $axes = [];
        if (!empty($bucket['departments'])) {
            $ph = rtrim(str_repeat('?,', count($bucket['departments'])), ',');
            $axes[] = "{$c}department_id IN ({$ph})";
            foreach ($bucket['departments'] as $id) {
                $params[] = $id;
            }
        }
        if (!empty($bucket['channels'])) {
            $ph = rtrim(str_repeat('?,', count($bucket['channels'])), ',');
            $axes[] = "{$c}channel_id IN ({$ph})";
            foreach ($bucket['channels'] as $id) {
                $params[] = $id;
            }
        }
        if (!empty($bucket['groups'])) {
            $ph = rtrim(str_repeat('?,', count($bucket['groups'])), ',');
            $axes[] = "{$c}group_id IN ({$ph})";
            foreach ($bucket['groups'] as $id) {
                $params[] = $id;
            }
        }
        if (empty($axes)) {
            return '';
        }
        $clause = '(' . implode(' OR ', $axes) . ')';
        if ($natureza === 'groups') {
            $clause .= " AND {$c}group_id IS NOT NULL";
        } elseif ($natureza === 'nonGroups') {
            $clause .= " AND {$c}group_id IS NULL";
        }
        return $clause;
    }

    /**
     * Escopo de conversas de um usuário: a visão unificada da caixa de
     * entrada. Junta as caixas às quais ele tem acesso, os próprios
     * departamentos e as conversas atribuídas a ele.
     *
     * @return array{all:bool, any:array, groups:array, nonGroups:array}
     */
    public static function scopeSetsForUser(int $userId): array
    {
        $row = Database::getInstance()->fetch("SELECT role FROM users WHERE id = ?", [$userId]);
        if ($row && ($row['role'] ?? '') === 'admin') {
            // Admin enxerga tudo: não adianta restringi-lo por caixa.
            $sets = \App\Models\Inbox::emptySets();
            $sets['all'] = true;
            return $sets;
        }
        $inboxes = \App\Models\Inbox::getUserInboxes($userId);
        $sets = \App\Models\Inbox::membershipSets(array_column($inboxes, 'id'));
        // Os próprios departamentos entram no escopo mesmo sem caixa
        // vinculada: o atendente precisa ver a fila do seu time — tanto as
        // mensagens gerais quanto as conversas de grupo.
        $sets['any']['departments'] = array_values(array_unique(array_merge(
            $sets['any']['departments'],
            array_map('intval', \App\Models\User::getDepartmentIds($userId))
        )));
        $sets['all'] = false;
        return $sets;
    }

    /**
     * Quantidade por caixa para o badge vermelho do menu lateral.
     *
     * - Caixas de mensagens gerais: contam as conversas em aberto (novo,
     *   aberto, aguardando).
     * - Caixas de grupo (conversation_scope = 'groups'): contam SÓ as
     *   conversas em que o número foi mencionado no grupo e a menção ainda
     *   não foi lida. Mensagem comum de grupo é histórico do grupo, não é
     *   atendimento: a bolinha acende quando alguém chama o bot.
     *
     * Uma única query: cada caixa vira um CASE sobre o mesmo conjunto de
     * conversas. Antes eram N branches de UNION somados no PHP, o que
     * contava duas vezes a conversa que estava em duas caixas.
     *
     * Retorna um mapa [inbox_id => total].
     */
    public static function openCountsByInbox(array $inboxIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $inboxIds))));
        $result = array_fill_keys($ids, 0);
        if (empty($ids)) {
            return $result;
        }

        $map = \App\Models\Inbox::loadMembershipMap($ids);
        $params = [];
        $selects = [];
        foreach ($ids as $i => $id) {
            $entry = $map[(int) $id] ?? null;
            $sets = \App\Models\Inbox::setsFromMap($map, [$id]);
            $pred = self::membershipPredicate($sets, 'c.', $params);
            // Caixa de grupo: só conta conversa com menção não lida.
            $isGroupBox = $entry
                && \App\Models\Inbox::normalizeScope($entry['scope'] ?? 'all') === 'groups';
            $gate = $isGroupBox
                ? " AND EXISTS (SELECT 1 FROM messages m WHERE m.conversation_id = c.id"
                    . " AND m.is_mention = 1 AND m.is_read = 0)"
                : '';
            $selects[] = "COUNT(DISTINCT CASE WHEN {$pred}{$gate} THEN c.id END) AS c{$i}";
        }

        $sql = "SELECT " . implode(', ', $selects) . "
                FROM conversations c
                WHERE c.status IN ('new', 'open', 'waiting_customer', 'waiting_internal')";

        $rows = Database::getInstance()->fetchAll($sql, $params);
        $row = $rows[0] ?? [];
        foreach ($ids as $i => $id) {
            $result[$id] = (int) ($row['c' . $i] ?? 0);
        }
        return $result;
    }

    /**
     * Total de conversas em aberto de um escopo (visão unificada).
     *
     * O total NÃO é a soma dos badges por caixa: as caixas se sobrepõem
     * (uma conversa pode estar em duas), então somar contaria duas vezes.
     * Aqui a contagem é feita direto sobre o escopo.
     *
     * Conta o mesmo que a aba "Em atendimento" da caixa de entrada — o
     * badge do menu precisa bater com a lista que o usuário abre. (A regra
     * de "menção não vista" fica só nos badges por caixa, onde o grupo do
     * WhatsApp é que não pode ficar aceso para sempre.)
     */
    public static function openCountForSets(array $sets): int
    {
        $params = [];
        $pred = self::membershipPredicate($sets, 'c.', $params);

        $row = Database::getInstance()->fetch(
            "SELECT COUNT(DISTINCT c.id) as total
             FROM conversations c
             WHERE c.status IN ('new', 'open', 'waiting_customer', 'waiting_internal')
               AND ({$pred})",
            $params
        );
        return (int) ($row['total'] ?? 0);
    }

    /**
     * Contagem por aba da caixa (badges "Em atendimento" / "Aguardando").
     *
     * Mesmo critério de pertinência e os mesmos filtros da listagem, mas
     * numa query leve (sem as junções de mensagens/tags). O filtro de
     * status NÃO entra: contamos todos os status e distribuímos nas abas:
     *   - active  = open + waiting_customer + waiting_internal
     *   - waiting = new
     *
     * @return array{active:int, waiting:int}
     */
    public static function countsByTabForInboxes(array $inboxIds, ?int $userId = null, array $filters = []): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $inboxIds))));
        return self::countsByTabForSets(\App\Models\Inbox::membershipSets($ids), $userId, $filters);
    }

    /**
     * Contagem por aba sobre um escopo de conversas.
     *
     * @return array{active:int, waiting:int}
     */
    public static function countsByTabForSets(array $sets, ?int $userId = null, array $filters = []): array
    {
        $counts = ['active' => 0, 'waiting' => 0];
        unset($filters['status']);

        $params = [];
        $pred = self::membershipPredicate($sets, 'c.', $params);

        $sql = "SELECT c.status, COUNT(DISTINCT c.id) as total
                FROM conversations c
                JOIN contacts ct ON ct.id = c.contact_id
                LEFT JOIN channels ch ON ch.id = c.channel_id
                WHERE ({$pred})";
        $sql .= self::listFilterSql($filters, $params, $userId);
        $sql .= " GROUP BY c.status";

        $rows = Database::getInstance()->fetchAll($sql, $params);
        $byStatus = [];
        foreach ($rows as $r) {
            $byStatus[(string) $r['status']] = (int) $r['total'];
        }
        $counts['active'] = ($byStatus['open'] ?? 0)
            + ($byStatus['waiting_customer'] ?? 0)
            + ($byStatus['waiting_internal'] ?? 0);
        $counts['waiting'] = $byStatus['new'] ?? 0;

        return $counts;
    }

    /**
     * Conversas combinadas de várias caixas (união, sem duplicar),
     * ordenadas pela mais recente. Usada na visão "Caixa de Entrada".
     */
    /**
     * Conversas combinadas de várias caixas numa única query.
     *
     * As caixas são achatadas em conjuntos (departamento / canal / dono) e
     * aplicadas como um predicado só. Antes: uma UNION ALL por caixa, com
     * a base pesada (subqueries de mensagens) repetida N vezes, e dedup em
     * PHP no final.
     */
    public static function getConversationsForInboxes(array $inboxIds, ?int $userId = null, array $filters = []): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $inboxIds))));
        if (empty($ids)) {
            return [];
        }
        return self::getConversationsForSets(\App\Models\Inbox::membershipSets($ids), $userId, $filters);
    }

    /**
     * Conversas de um escopo (conjuntos de pertinência já resolvidos).
     */
    public static function getConversationsForSets(array $sets, ?int $userId = null, array $filters = []): array
    {
        $params = [];
        $pred = self::membershipPredicate($sets, 'c.', $params);

        $sql = self::listBaseSql($userId) . " WHERE ({$pred})";
        $sql .= self::listFilterSql($filters, $params, $userId);
        $sql .= " ORDER BY COALESCE(c.last_message_at, c.created_at) DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int) $filters['limit'];
        }

        $rows = Database::getInstance()->fetchAll($sql, $params);
        return SlaService::applyClock(self::attachTagsJson($rows));
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

    /**
     * Conversas sem responsável.
     *
     * $sets limita ao escopo do usuário (a fila sem dono que ele enxerga).
     */
    public static function getUnassigned(int $departmentId = null, ?array $sets = null): array
    {
        $sql = "SELECT c.*, ch.type as channel_type, ct.name as contact_name, ct.phone as contact_phone,
                       ct.avatar as contact_avatar, d.name as department_name, d.color as department_color,
                       u.name as assigned_user_name,
                       last_in.last_inbound_at as sla_last_inbound_at,
                       last_in.last_inbound_id as sla_last_inbound_id,
                       last_in.last_human_id as sla_last_human_id
                FROM conversations c
                JOIN contacts ct ON ct.id = c.contact_id
                LEFT JOIN channels ch ON ch.id = c.channel_id
                LEFT JOIN departments d ON d.id = c.department_id
                LEFT JOIN users u ON u.id = c.assigned_user_id
                LEFT JOIN " . self::slaAnchorsSql() . " last_in ON last_in.conversation_id = c.id
                WHERE c.assigned_user_id IS NULL AND c.status IN ('new', 'open')
                  AND (c.snoozed_until IS NULL OR c.snoozed_until <= NOW())";
        $params = [];

        if ($departmentId) {
            $sql .= " AND c.department_id = ?";
            $params[] = $departmentId;
        }

        if ($sets !== null) {
            $scopeParams = [];
            $pred = self::membershipPredicate($sets, 'c.', $scopeParams);
            $sql .= " AND ({$pred})";
            $params = array_merge($params, $scopeParams);
        }

        $sql .= " ORDER BY c.created_at ASC";

        return SlaService::applyClock(Database::getInstance()->fetchAll($sql, $params));
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

    /**
     * Mapa id => status de todas as conversas das caixas informadas.
     *
     * O LiveFeed (atualização incremental da lista) precisa saber o status
     * real de uma conversa que sumiu da lista filtrada: com as três abas,
     * sair de "Em atendimento" pode ser o atendimento indo para
     * "Aguardando" — não é a mesma coisa que ser finalizada. Sem esse mapa
     * o cliente announces "Conversa finalizada" para um simples
     * "aguardando cliente".
     *
     * @param array<int> $inboxIds
     * @return array<int, string> [conversation_id => status]
     */
    public static function statusMapForInboxes(array $inboxIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $inboxIds))));
        if (!$ids) {
            return [];
        }
        return self::statusMapForSets(\App\Models\Inbox::membershipSets($ids));
    }

    /**
     * Mapa id => status das conversas de um escopo.
     *
     * @return array<int, string> [conversation_id => status]
     */
    public static function statusMapForSets(array $sets): array
    {
        $params = [];
        $pred = self::membershipPredicate($sets, 'c.', $params);
        $rows = Database::getInstance()->fetchAll(
            "SELECT c.id, c.status FROM conversations c WHERE ({$pred})",
            $params
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['id']] = (string) $r['status'];
        }
        return $map;
    }

    private static ?bool $groupColCache = null;

    /**
     * Indica se a base já tem a coluna conversations.group_id
     * (conversas de grupos WhatsApp). Bases antigas não têm.
     */
    public static function hasGroupColumn(): bool
    {
        if (self::$groupColCache !== null) {
            return self::$groupColCache;
        }
        try {
            $row = Database::getInstance()->fetch("SHOW COLUMNS FROM conversations LIKE 'group_id'");
            self::$groupColCache = !empty($row);
        } catch (\Throwable $e) {
            self::$groupColCache = false;
        }
        return self::$groupColCache;
    }

    /**
     * Contagem por status separada em atendimento normal (group_id NULL)
     * x grupos WhatsApp (group_id NOT NULL). Sem a coluna, tudo é normal.
     *
     * @return array{normal: array<string,int>, groups: array<string,int>}
     */
    public static function countByStatusSplit(?int $userId = null): array
    {
        $keys = ['new', 'open', 'waiting_customer', 'waiting_internal', 'resolved', 'closed', 'spam'];
        $empty = array_fill_keys($keys, 0);
        $out = ['normal' => $empty, 'groups' => $empty];
        if (!self::hasGroupColumn()) {
            $out['normal'] = self::countByStatus($userId);
            return $out;
        }
        $where = '1=1';
        $params = [];
        if ($userId) {
            $where .= ' AND assigned_user_id = ?';
            $params[] = $userId;
        }
        try {
            $rows = Database::getInstance()->fetchAll(
                "SELECT status, (group_id IS NOT NULL) as is_group, COUNT(*) as total
                 FROM conversations WHERE {$where} GROUP BY status, is_group",
                $params
            );
            foreach ($rows as $row) {
                $slot = !empty($row['is_group']) ? 'groups' : 'normal';
                if (isset($out[$slot][$row['status']])) {
                    $out[$slot][$row['status']] = (int) $row['total'];
                }
            }
        } catch (\Throwable $e) {
            $out['normal'] = self::countByStatus($userId);
        }
        return $out;
    }

    /**
     * Mensagens de uma conversa.
     *
     * Opções:
     *  - `before` (int): só mensagens com id menor que o informado.
     *  - `limit` (int): teto de linhas (ORDER BY crescente).
     *  - `tail` (int): devolve as N mensagens MAIS RECENTES em ordem crescente.
     *  - `types` (array): whitelist de `messages.type`. Use sempre que o
     *    consumidor for um cliente final — `internal_note` e `system` são
     *    internos e não podem sair do servidor.
     */
    public static function getMessages(int $conversationId, array $opts = []): array
    {
        $where = "m.conversation_id = ?";
        $params = [$conversationId];

        if (!empty($opts['before'])) {
            $where .= " AND m.id < ?";
            $params[] = (int) $opts['before'];
        }

        if (!empty($opts['types']) && is_array($opts['types'])) {
            $types = array_values(array_filter(array_map('strval', $opts['types'])));
            if ($types) {
                $ph = implode(',', array_fill(0, count($types), '?'));
                $where .= " AND m.type IN ({$ph})";
                foreach ($types as $t) {
                    $params[] = $t;
                }
            }
        }

        $select = "SELECT m.*, u.name as user_name, u.avatar as user_avatar, ct.avatar as contact_avatar
                FROM messages m
                LEFT JOIN users u ON u.id = m.user_id
                JOIN conversations c ON c.id = m.conversation_id
                LEFT JOIN contacts ct ON ct.id = c.contact_id
                WHERE {$where}";

        // Cauda: as N mais recentes, devolvidas em ordem crescente.
        if (!empty($opts['tail'])) {
            $sql = $select . " ORDER BY m.created_at DESC, m.id DESC LIMIT " . (int) $opts['tail'];
            $msgs = array_reverse(Database::getInstance()->fetchAll($sql, $params));
            return self::enrichMessages($msgs);
        }

        $sql = $select . " ORDER BY m.created_at ASC, m.id ASC";
        if (!empty($opts['limit'])) {
            $sql .= " LIMIT " . (int) $opts['limit'];
        }
        $msgs = Database::getInstance()->fetchAll($sql, $params);
        return self::enrichMessages($msgs);
    }

    /**
     * Quantidade de mensagens da conversa (para paginação/hasOlder).
     *
     * @param array $types whitelist de `messages.type` (vazio = todos)
     */
    public static function countMessages(int $conversationId, array $types = []): int
    {
        $sql = "SELECT COUNT(*) AS t FROM messages WHERE conversation_id = ?";
        $params = [$conversationId];
        if ($types) {
            $types = array_values(array_filter(array_map('strval', $types)));
            if ($types) {
                $ph = implode(',', array_fill(0, count($types), '?'));
                $sql .= " AND type IN ({$ph})";
                foreach ($types as $t) {
                    $params[] = $t;
                }
            }
        }
        $row = Database::getInstance()->fetch($sql, $params);
        return (int) ($row['t'] ?? 0);
    }

    /**
     * Mensagens criadas ou alteradas depois de um instante.
     *
     * O `since` chega em UTC (ISO 8601 do navegador) e é convertido para
     * a hora local da aplicação — o banco guarda hora local.
     *
     * @param string $since ISO 8601 (UTC) do último poll
     */
    public static function getMessagesSince(int $conversationId, string $since, int $limit = 200): array
    {
        $ts = strtotime($since);
        if ($ts === false) {
            return [];
        }
        // Overlap de 2s: uma mensagem gravada no instante do poll não pode
        // ficar de fora por causa do relógio do cliente.
        $local = date('Y-m-d H:i:s', $ts - 2);

        $sql = "SELECT m.*, u.name as user_name, u.avatar as user_avatar, ct.avatar as contact_avatar
                FROM messages m
                LEFT JOIN users u ON u.id = m.user_id
                JOIN conversations c ON c.id = m.conversation_id
                LEFT JOIN contacts ct ON ct.id = c.contact_id
                WHERE m.conversation_id = ?
                  AND (m.created_at > ? OR m.updated_at > ?)
                ORDER BY m.created_at ASC, m.id ASC
                LIMIT " . (int) $limit;

        $msgs = Database::getInstance()->fetchAll($sql, [$conversationId, $local, $local]);
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

    /**
     * Fragmento SQL das conversas visíveis a um usuário (não-admin).
     *
     * Espelha userCanSee(): responsável pela conversa ou membro do
     * departamento.
     */
    public static function visibilitySql(int $userId, string $c = 'c.', array &$params = []): string
    {
        $params[] = $userId;
        $params[] = $userId;
        return "(
                    {$c}assigned_user_id = ?
                    OR {$c}department_id IN (SELECT department_id FROM department_users WHERE user_id = ?)
                )";
    }

    /**
     * O usuário tem acesso a esta conversa?
     *
     * Regras (uma só, usada pela listagem e pela checagem de acesso):
     * - admin: enxerga tudo
     * - responsável pela conversa
     * - membro do departamento da conversa
     */
    public static function userCanSee(int $conversationId, int $userId): bool
    {
        $role = Database::getInstance()->fetch("SELECT role FROM users WHERE id = ?", [$userId]);
        if ($role && ($role['role'] ?? '') === 'admin') {
            return Database::getInstance()->fetch(
                "SELECT 1 FROM conversations WHERE id = ? LIMIT 1",
                [$conversationId]
            ) !== null;
        }
        $params = [];
        $row = Database::getInstance()->fetch(
            "SELECT 1 FROM conversations c
             WHERE c.id = ? AND " . self::visibilitySql($userId, 'c.', $params),
            array_merge([$conversationId], $params)
        );
        return (bool) $row;
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
        $sets = self::scopeSetsForUser($userId);
        $params = [];
        $pred = self::membershipPredicate($sets, 'c.', $params);

        $row = Database::getInstance()->fetch(
            "SELECT COUNT(DISTINCT c.id) as total
             FROM conversations c
             JOIN messages m ON m.conversation_id = c.id
             WHERE m.direction = 'inbound' AND m.is_read = 0
               AND (m.user_id != ? OR m.user_id IS NULL)
               AND ({$pred})",
            array_merge([$userId], $params)
        );
        return (int) ($row['total'] ?? 0);
    }

    public static function markAllMessagesAsRead(int $userId): int
    {
        $sets = self::scopeSetsForUser($userId);
        $params = [];
        $pred = self::membershipPredicate($sets, 'c.', $params);

        $sql = "UPDATE messages m JOIN conversations c ON c.id = m.conversation_id "
             . "SET m.is_read = 1 "
             . "WHERE m.direction = 'inbound' AND m.is_read = 0 "
             . "AND (m.user_id != ? OR m.user_id IS NULL) AND ({$pred})";

        $stmt = Database::getInstance()->query($sql, array_merge([$userId], $params));
        return $stmt->rowCount();
    }
}
