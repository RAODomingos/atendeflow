<?php

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\View;

/**
 * Busca global da barra do topo (Ctrl K / "Pesquisar").
 *
 * Procura clientes e atendimentos. O critério de acesso é o mesmo da caixa de
 * entrada: admin vê tudo; os demais veem o que são responsáveis, o que está
 * numa caixa que acessam ou o que é do seu departamento.
 */
class GlobalSearchController
{
    private const MAX_CONTACTS = 6;
    private const MAX_CONVERSATIONS = 8;
    private const MAX_GROUPS = 5;
    private const MIN_CHARS = 2;

    public function index(Request $request): void
    {
        $userId = Auth::id();
        if (!$userId) {
            View::json(['success' => false, 'error' => 'Não autenticado'], 401);
            return;
        }

        $term = trim((string) $request->get('q', ''));
        if (mb_strlen($term) < self::MIN_CHARS) {
            View::json([
                'success' => true,
                'query' => $term,
                'contacts' => [],
                'conversations' => [],
            ]);
            return;
        }

        View::json([
            'success' => true,
            'query' => $term,
            'contacts' => self::searchContacts($term),
            'conversations' => self::searchConversations($term, $userId),
            'groups' => self::searchGroups($term),
        ]);
    }

    /**
     * Grupos de WhatsApp por nome ou JID.
     */
    private static function searchGroups(string $term): array
    {
        $like = self::like($term);
        $rows = Database::getInstance()->fetchAll(
            "SELECT g.id, g.name, g.group_jid, g.avatar_url, g.participant_count,
                    g.inbox_id, i.name as inbox_name,
                    (SELECT COUNT(*) FROM conversations c WHERE c.group_id = g.id) as conversation_count
             FROM whatsapp_groups g
             LEFT JOIN inboxes i ON i.id = g.inbox_id
             WHERE g.name LIKE ? OR g.group_jid LIKE ?
             ORDER BY (g.name LIKE ?) DESC, g.name ASC
             LIMIT " . self::MAX_GROUPS,
            [$like, $like, $term . '%']
        );

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'name' => $r['name'] ?: '(grupo sem nome)',
                'group_jid' => $r['group_jid'] ?? '',
                'avatar' => $r['avatar_url'] ?? '',
                'participant_count' => (int) ($r['participant_count'] ?? 0),
                'conversation_count' => (int) ($r['conversation_count'] ?? 0),
                'inbox_name' => $r['inbox_name'] ?? '',
            ];
        }
        return $out;
    }

    /**
     * Clientes por nome, e-mail, telefone, documento, empresa ou loja.
     */
    private static function searchContacts(string $term): array
    {
        $like = self::like($term);
        $rows = Database::getInstance()->fetchAll(
            "SELECT c.id, c.name, c.email, c.phone, c.document, c.company, c.avatar,
                    (SELECT COUNT(*) FROM conversations cv WHERE cv.contact_id = c.id) AS conversation_count,
                    (SELECT GROUP_CONCAT(s.network_name ORDER BY s.network_name SEPARATOR ', ')
                       FROM contact_stores s WHERE s.contact_id = c.id) AS stores
             FROM contacts c
             WHERE c.is_group = 0
               AND (c.name LIKE ?
                OR c.email LIKE ?
                OR c.phone LIKE ?
                OR c.document LIKE ?
                OR c.company LIKE ?
                OR EXISTS (SELECT 1 FROM contact_phones p
                           WHERE p.contact_id = c.id AND p.phone LIKE ?)
                OR EXISTS (SELECT 1 FROM contact_stores s
                           WHERE s.contact_id = c.id AND (s.network_name LIKE ? OR s.customer_id LIKE ?)))
             ORDER BY (c.name LIKE ?) DESC, c.name ASC
             LIMIT " . self::MAX_CONTACTS,
            [$like, $like, $like, $like, $like, $like, $like, $like, $term . '%']
        );

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'name' => $r['name'] ?? '',
                'email' => $r['email'] ?? '',
                'phone' => $r['phone'] ?? '',
                'document' => $r['document'] ?? '',
                'company' => $r['company'] ?? '',
                'stores' => $r['stores'] ?? '',
                'avatar' => $r['avatar'] ?? '',
                'conversation_count' => (int) ($r['conversation_count'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Atendimentos por protocolo, assunto, cliente ou texto das mensagens.
     */
    private static function searchConversations(string $term, int $userId): array
    {
        $like = self::like($term);

        $conditions = [
            'c.protocol LIKE ?',
            'c.subject LIKE ?',
            'ct.name LIKE ?',
            'ct.email LIKE ?',
            'EXISTS (SELECT 1 FROM messages m WHERE m.conversation_id = c.id AND m.content LIKE ?)',
        ];
        $params = [$like, $like, $like, $like, $like];

        // Protocolo sem máscara: "2026-000145" acha "2026000145".
        $digits = preg_replace('/\D/', '', $term);
        if ($digits !== '') {
            $conditions[] = 'c.protocol LIKE ?';
            $params[] = '%' . $digits . '%';
        }

        $sql = "SELECT c.id, c.protocol, c.subject, c.status, c.unit, c.priority,
                       c.created_at, c.last_message_at,
                       ct.id AS contact_id, ct.name AS contact_name, ct.email AS contact_email,
                       ct.avatar AS contact_avatar,
                       d.name AS department_name,
                       u.name AS assigned_user_name,
                       ch.type AS channel_type, ch.name AS channel_name,
                       (SELECT m.content FROM messages m
                         WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_message
                FROM conversations c
                JOIN contacts ct ON ct.id = c.contact_id
                LEFT JOIN departments d ON d.id = c.department_id
                LEFT JOIN users u ON u.id = c.assigned_user_id
                LEFT JOIN channels ch ON ch.id = c.channel_id
                WHERE (" . implode(' OR ', $conditions) . ")";

        if (!Auth::isAdmin()) {
            // Mesmo critério de Conversation::userCanSee().
            $scopeParams = [];
            $sql .= " AND " . \App\Models\Conversation::visibilitySql($userId, 'c.', $scopeParams);
            $params = array_merge($params, $scopeParams);
        }

        $sql .= " ORDER BY (c.protocol LIKE ? OR c.subject LIKE ?) DESC, COALESCE(c.last_message_at, c.created_at) DESC LIMIT " . self::MAX_CONVERSATIONS;

        $rows = Database::getInstance()->fetchAll($sql, array_merge($params, [$term . '%', $term . '%']));

        $out = [];
        foreach ($rows as $r) {
            $status = (string) ($r['status'] ?? '');
            $when = $r['last_message_at'] ?: $r['created_at'];
            $out[] = [
                'id' => (int) $r['id'],
                'protocol' => format_protocol($r['protocol'] ?? ''),
                'subject' => $r['subject'] ?: 'Sem assunto',
                'status' => $status,
                'status_label' => self::statusLabel($status),
                'unit' => $r['unit'] ?? '',
                'priority' => $r['priority'] ?? 'normal',
                'contact_id' => (int) $r['contact_id'],
                'contact_name' => $r['contact_name'] ?? '',
                'contact_email' => $r['contact_email'] ?? '',
                'contact_avatar' => $r['contact_avatar'] ?? '',
                'department_name' => $r['department_name'] ?? '',
                'assigned_user_name' => $r['assigned_user_name'] ?? '',
                'channel_type' => $r['channel_type'] ?? 'webchat',
                'channel_icon' => channel_icon((string) ($r['channel_type'] ?? 'webchat')),
                'preview' => self::preview((string) ($r['last_message'] ?? '')),
                'when' => $when,
                'when_label' => $when ? format_datetime((string) $when) : '',
            ];
        }
        return $out;
    }

    /**
     * Escapa os curingas do LIKE para que "%" e "_" do usuário sejam literais.
     */
    private static function like(string $term): string
    {
        return '%' . addcslashes($term, '%_\\') . '%';
    }

    private static function statusLabel(string $status): string
    {
        $labels = [
            'new' => 'Novo',
            'open' => 'Aberto',
            'waiting_customer' => 'Aguardando cliente',
            'waiting_internal' => 'Aguardando interno',
            'resolved' => 'Resolvido',
            'closed' => 'Fechado',
            'spam' => 'Spam',
        ];
        return $labels[$status] ?? ucfirst($status);
    }

    /**
     * Prévia da última mensagem: texto puro, sem JSON e sem caixinha.
     */
    private static function preview(string $content): string
    {
        $content = trim(strip_tags($content));
        if ($content === '') {
            return '';
        }
        // Conteúdo JSON (fluxo, mídia) não serve de prévia.
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            foreach (['text', 'message', 'caption', 'body'] as $key) {
                if (!empty($decoded[$key]) && is_string($decoded[$key])) {
                    $content = $decoded[$key];
                    break;
                }
            }
        }
        $content = preg_replace('/\s+/u', ' ', $content) ?? $content;
        return mb_strimwidth($content, 0, 120, '…');
    }
}
