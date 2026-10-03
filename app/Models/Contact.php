<?php

namespace App\Models;

use App\Core\Database;

class Contact
{
    public static function find(int $id): ?array
    {
        $contact = Database::getInstance()->fetch(
            "SELECT * FROM contacts WHERE id = ?",
            [$id]
        );
        if ($contact) {
            $contact['phones'] = Database::getInstance()->fetchAll(
                "SELECT * FROM contact_phones WHERE contact_id = ?",
                [$id]
            );
            $contact['emails'] = Database::getInstance()->fetchAll(
                "SELECT * FROM contact_emails WHERE contact_id = ?",
                [$id]
            );
            $contact['tags'] = Database::getInstance()->fetchAll(
                "SELECT t.* FROM tags t
                 JOIN contact_tags ct ON ct.tag_id = t.id
                 WHERE ct.contact_id = ?",
                [$id]
            );
            $contact['stores'] = self::getStores($id);
        }
        return $contact;
    }

    /**
     * Lojas/unidades Guild vinculadas (loja = network_name, unidade = store).
     *
     * @return array<int, array{customer_id:string, network_name:string, store_id:int, store_name:string}>
     */
    public static function getStores(int $id): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT customer_id, network_name, store_id, store_name
             FROM contact_stores WHERE contact_id = ?
             ORDER BY network_name, store_name",
            [$id]
        );
    }

    /**
     * Soma vínculos (não substitui): insere os ausentes e remove, da(s)
     * network(s) exibida(s), os desmarcados.
     *
     * @param array<int, array{customer_id:string, network_name:string, store_id:int, store_name:string}> $stores
     * @param array<int, string> $displayedNetworks networks exibidas na tela (escopo da remoção)
     */
    public static function syncStores(int $contactId, array $stores, array $displayedNetworks = []): void
    {
        $db = Database::getInstance();
        $wanted = [];
        foreach ($stores as $s) {
            $sid = (int) ($s['store_id'] ?? 0);
            if ($sid <= 0) continue;
            $wanted[$sid] = [
                'customer_id' => trim((string) ($s['customer_id'] ?? '')),
                'network_name' => trim((string) ($s['network_name'] ?? '')),
                'store_id' => $sid,
                'store_name' => trim((string) ($s['store_name'] ?? '')),
            ];
        }
        $db->beginTransaction();
        try {
            foreach ($wanted as $sid => $s) {
                if ($s['network_name'] === '' || $s['store_name'] === '') continue;
                $exists = $db->fetch(
                    "SELECT 1 FROM contact_stores WHERE contact_id = ? AND store_id = ? LIMIT 1",
                    [$contactId, $sid]
                );
                if (!$exists) {
                    $db->insert('contact_stores', [
                        'contact_id' => $contactId,
                        'customer_id' => $s['customer_id'],
                        'network_name' => $s['network_name'],
                        'store_id' => $sid,
                        'store_name' => $s['store_name'],
                    ]);
                }
            }
            if ($displayedNetworks !== []) {
                $current = $db->fetchAll(
                    "SELECT store_id, network_name FROM contact_stores WHERE contact_id = ?",
                    [$contactId]
                );
                foreach ($current as $c) {
                    if (in_array($c['network_name'], $displayedNetworks, true)
                        && !isset($wanted[(int) $c['store_id']])) {
                        $db->delete(
                            'contact_stores',
                            'contact_id = ? AND store_id = ?',
                            [$contactId, (int) $c['store_id']]
                        );
                    }
                }
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function removeStore(int $contactId, int $storeId): int
    {
        return Database::getInstance()->delete(
            'contact_stores',
            'contact_id = ? AND store_id = ?',
            [$contactId, $storeId]
        );
    }

    public static function touchActivity(int $id): void
    {
        Database::getInstance()->update(
            'contacts',
            ['last_activity_at' => date('Y-m-d H:i:s')],
            'id = ?',
            [$id]
        );
    }

    public static function findByPhone(string $phone): ?array
    {
        $candidates = self::phoneCandidates($phone);
        if (empty($candidates)) {
            return null;
        }
        $ph = implode(',', array_fill(0, count($candidates), '?'));
        $params = array_merge($candidates, $candidates);
        return Database::getInstance()->fetch(
            "SELECT c.* FROM contacts c
             LEFT JOIN contact_phones cp ON cp.contact_id = c.id
             WHERE c.phone IN ($ph) OR cp.phone IN ($ph)
             LIMIT 1",
            $params
        );
    }

    /**
     * Gera as variações de um número para comparação. No Brasil, o DDI (55) é
     * opcional no cadastro mas o WhatsApp sempre envia com 55, então tratamos
     * "22992036639" e "5522992036639" como o mesmo número.
     */
    private static function phoneCandidates(string $phone): array
    {
        $norm = self::normalizePhone($phone);
        if (!$norm) {
            return [];
        }
        $candidates = [$norm];
        if (str_starts_with($norm, '55') && strlen($norm) >= 12) {
            $candidates[] = substr($norm, 2);
        } elseif (strlen($norm) === 10 || strlen($norm) === 11) {
            $candidates[] = '55' . $norm;
        }
        return array_values(array_unique($candidates));
    }

    public static function findByEmail(string $email): ?array
    {
        if (!$email) return null;
        $email = trim($email);
        return Database::getInstance()->fetch(
            "SELECT c.* FROM contacts c
             LEFT JOIN contact_emails ce ON ce.contact_id = c.id
             WHERE c.email = ? OR ce.email = ?",
            [$email, $email]
        );
    }

    private static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) return null;
        $digits = preg_replace('/\D/', '', $phone);
        return $digits === '' ? null : $digits;
    }

    public static function all(array $filters = []): array
    {
        $sql = "SELECT c.*,
                       (SELECT COUNT(*) FROM conversations WHERE contact_id = c.id) as conversation_count,
                       (SELECT network_name FROM contact_stores WHERE contact_id = c.id ORDER BY network_name LIMIT 1) as store_network,
                       (SELECT COUNT(DISTINCT network_name) FROM contact_stores WHERE contact_id = c.id) as store_networks,
                       (SELECT MAX(m.created_at) FROM messages m
                        JOIN conversations cv ON cv.id = m.conversation_id
                        WHERE cv.contact_id = c.id) as last_message_at
                FROM contacts c
                WHERE 1=1";

        $params = [];

        if (!empty($filters['search'])) {
            $sql .= " AND (c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)";
            $search = "%{$filters['search']}%";
            $params = array_merge($params, [$search, $search, $search]);
        }

        $sql .= " ORDER BY c.updated_at DESC";

        // Paginação opcional (index usa; selects internos trazem tudo).
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . max(1, (int) $filters['limit']);
            if (!empty($filters['offset'])) {
                $sql .= " OFFSET " . max(0, (int) $filters['offset']);
            }
        }

        return Database::getInstance()->fetchAll($sql, $params);
    }

    public static function count(array $filters = []): int
    {
        $sql = "SELECT COUNT(*) as t FROM contacts c WHERE 1=1";
        $params = [];

        if (!empty($filters['search'])) {
            $sql .= " AND (c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)";
            $search = "%{$filters['search']}%";
            $params = array_merge($params, [$search, $search, $search]);
        }

        return (int) (Database::getInstance()->fetch($sql, $params)['t'] ?? 0);
    }

    public static function create(array $data): int
    {
        return Database::getInstance()->insert('contacts', $data);
    }

    public static function update(int $id, array $data): int
    {
        return Database::getInstance()->update('contacts', $data, 'id = ?', [$id]);
    }

    public static function delete(int $id): int
    {
        return Database::getInstance()->delete('contacts', 'id = ?', [$id]);
    }

    /**
     * Mescla o contato $sourceId no contato $targetId, consolidando o histórico:
     * - todas as conversas passam para o contato de destino;
     * - telefones, e-mails e etiquetas são unificados (sem duplicar);
     * - campos ausentes no destino são preenchidos a partir da origem;
     * - o contato de origem é removido.
     */
    public static function merge(int $sourceId, int $targetId): void
    {
        $source = self::find($sourceId);
        $target = self::find($targetId);
        if (!$source || !$target || $sourceId === $targetId) {
            return;
        }

        // Realoca conversas para o contato de destino
        Database::getInstance()->update('conversations', ['contact_id' => $targetId], 'contact_id = ?', [$sourceId]);

        // Telefones
        $srcPhones = Database::getInstance()->fetchAll("SELECT * FROM contact_phones WHERE contact_id = ?", [$sourceId]);
        foreach ($srcPhones as $p) {
            if (!self::hasPhone($targetId, $p['phone'])) {
                self::addPhone($targetId, $p['phone'], $p['label'], false);
            }
        }

        // E-mails
        $srcEmails = Database::getInstance()->fetchAll("SELECT * FROM contact_emails WHERE contact_id = ?", [$sourceId]);
        foreach ($srcEmails as $em) {
            if (!self::hasEmail($targetId, $em['email'])) {
                self::addEmail($targetId, $em['email'], $em['label'], false);
            }
        }

        // Etiquetas
        $srcTags = Database::getInstance()->fetchAll("SELECT tag_id FROM contact_tags WHERE contact_id = ?", [$sourceId]);
        foreach ($srcTags as $t) {
            $exists = Database::getInstance()->fetch(
                "SELECT 1 FROM contact_tags WHERE contact_id = ? AND tag_id = ?",
                [$targetId, $t['tag_id']]
            );
            if (!$exists) {
                Database::getInstance()->insert('contact_tags', ['contact_id' => $targetId, 'tag_id' => (int) $t['tag_id']]);
            }
        }

        // Lojas/unidades Guild (sem duplicar)
        foreach (self::getStores($sourceId) as $s) {
            $exists = Database::getInstance()->fetch(
                "SELECT 1 FROM contact_stores WHERE contact_id = ? AND store_id = ?",
                [$targetId, (int) $s['store_id']]
            );
            if (!$exists) {
                Database::getInstance()->insert('contact_stores', [
                    'contact_id' => $targetId,
                    'customer_id' => $s['customer_id'],
                    'network_name' => $s['network_name'],
                    'store_id' => (int) $s['store_id'],
                    'store_name' => $s['store_name'],
                ]);
            }
        }

        // Preenche campos ausentes no destino
        $upd = [];
        foreach (['name', 'email', 'phone', 'company', 'document', 'avatar', 'notes'] as $f) {
            if (empty($target[$f]) && !empty($source[$f])) {
                $upd[$f] = $source[$f];
            }
        }
        if ($upd) {
            self::update($targetId, $upd);
        }

        // Remove a origem (cascade apaga phones/emails/tags da origem)
        self::delete($sourceId);
    }

    public static function addPhone(int $contactId, string $phone, ?string $label = null, bool $primary = false): int
    {
        return Database::getInstance()->insert('contact_phones', [
            'contact_id' => $contactId,
            'phone' => $phone,
            'label' => $label,
            'is_primary' => $primary ? 1 : 0,
        ]);
    }

    public static function addEmail(int $contactId, string $email, ?string $label = null, bool $primary = false): int
    {
        return Database::getInstance()->insert('contact_emails', [
            'contact_id' => $contactId,
            'email' => $email,
            'label' => $label,
            'is_primary' => $primary ? 1 : 0,
        ]);
    }

    public static function search(string $term): array
    {
        $search = "%{$term}%";
        return Database::getInstance()->fetchAll(
            "SELECT DISTINCT c.* FROM contacts c
             LEFT JOIN contact_phones cp ON cp.contact_id = c.id
             WHERE c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR cp.phone LIKE ?
             ORDER BY c.name LIMIT 20",
            [$search, $search, $search, $search]
        );
    }

    /**
     * Retorna as conversas do contato com filtros opcionais.
     *
     * Filtros aceitos em $filters:
     *  - year:    int (4 dígitos) — filtra por YEAR(c.created_at)
     *  - month:   int (1-12)     — filtra por MONTH(c.created_at)
     *  - department: int         — filtra por c.department_id
     *  - status:  string        — filtra por c.status
     */
    public static function getConversations(int $contactId, array $filters = []): array
    {
        $sql = "SELECT c.*, ch.type as channel_type, ch.name as channel_name,
                       d.name as department_name, d.color as department_color,
                       u.name as assigned_user_name,
                       (SELECT content FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message,
                       (SELECT created_at FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message_date,
                       (SELECT COUNT(*) FROM messages WHERE conversation_id = c.id) as message_count
                FROM conversations c
                LEFT JOIN channels ch ON ch.id = c.channel_id
                LEFT JOIN departments d ON d.id = c.department_id
                LEFT JOIN users u ON u.id = c.assigned_user_id
                WHERE c.contact_id = ?";
        $params = [$contactId];

        self::applyConversationFilters($sql, $params, $filters);

        $sql .= " ORDER BY c.created_at DESC";

        return Database::getInstance()->fetchAll($sql, $params);
    }

    /**
     * Conta conversas do contato por status, respeitando os mesmos filtros
     * de getConversations() (exceto 'status' em si).
     */
    public static function countConversationsByStatus(int $contactId, array $filters = []): array
    {
        $sql = "SELECT c.status, COUNT(*) as total
                FROM conversations c
                WHERE c.contact_id = ?";
        $params = [$contactId];
        $f = $filters;
        unset($f['status']);
        self::applyConversationFilters($sql, $params, $f);

        $sql .= " GROUP BY c.status";

        $rows = Database::getInstance()->fetchAll($sql, $params);
        $map = ['new' => 0, 'open' => 0, 'waiting_customer' => 0, 'waiting_internal' => 0,
                'resolved' => 0, 'closed' => 0, 'spam' => 0];
        $total = 0;
        foreach ($rows as $r) {
            $map[$r['status']] = (int) $r['total'];
            $total += (int) $r['total'];
        }
        $map['_total'] = $total;
        return $map;
    }

    /**
     * Meses/anos em que o contato teve conversas (para popular o filtro).
     * Retorna [ ['year' => 2026, 'month' => 7, 'label' => 'Julho 2026', 'count' => 3], ... ]
     */
    public static function getAvailableMonths(int $contactId): array
    {
        $rows = Database::getInstance()->fetchAll(
            "SELECT YEAR(created_at) as year, MONTH(created_at) as month, COUNT(*) as total
             FROM conversations
             WHERE contact_id = ? AND created_at IS NOT NULL
             GROUP BY YEAR(created_at), MONTH(created_at)
             ORDER BY year DESC, month DESC",
            [$contactId]
        );
        $monthNames = [1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
                       'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
        $out = [];
        foreach ($rows as $r) {
            $m = (int) $r['month'];
            $out[] = [
                'year' => (int) $r['year'],
                'month' => $m,
                'label' => ($monthNames[$m] ?? $m) . ' / ' . $r['year'],
                'count' => (int) $r['total'],
            ];
        }
        return $out;
    }

    /**
     * Departamentos em que o contato teve conversas (para popular o filtro).
     * Retorna [['id' => 2, 'name' => 'Comercial', 'color' => '#abc', 'count' => 5], ...]
     */
    public static function getAvailableDepartments(int $contactId): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT d.id, d.name, d.color, COUNT(c.id) as total
             FROM conversations c
             INNER JOIN departments d ON d.id = c.department_id
             WHERE c.contact_id = ? AND c.department_id IS NOT NULL
             GROUP BY d.id, d.name, d.color
             ORDER BY d.name",
            [$contactId]
        );
    }

    /**
     * Aplica filtros comuns a getConversations/countConversationsByStatus.
     * Modifica $sql e $params por referência.
     */
    private static function applyConversationFilters(string &$sql, array &$params, array $filters): void
    {
        if (!empty($filters['year']) && (int) $filters['year'] > 0) {
            $sql .= " AND YEAR(c.created_at) = ?";
            $params[] = (int) $filters['year'];
        }
        if (!empty($filters['month']) && (int) $filters['month'] > 0) {
            $sql .= " AND MONTH(c.created_at) = ?";
            $params[] = (int) $filters['month'];
        }
        if (!empty($filters['department']) && (int) $filters['department'] > 0) {
            $sql .= " AND c.department_id = ?";
            $params[] = (int) $filters['department'];
        }
        if (!empty($filters['status']) && is_string($filters['status'])) {
            $sql .= " AND c.status = ?";
            $params[] = (string) $filters['status'];
        }
    }

    public static function findOrCreate(string $name, ?string $email = null, ?string $phone = null, array $extra = []): array
    {
        $email = $email ? trim($email) : null;
        $phone = self::normalizePhone($phone);

        $contact = null;
        if ($email) {
            $contact = self::findByEmail($email);
        }
        if (!$contact && $phone) {
            $contact = self::findByPhone($phone);
        }

        if ($contact) {
            // Unifica números brasileiros que diferem só pelo DDI (ex.: 22992036639 == 5522992036639).
            // Mantém a forma internacional (com 55) como principal, garantindo que o envio
            // via WhatsApp funcione.
            $incoming = self::normalizePhone($phone);
            $stored = self::normalizePhone($contact['phone']);
            if ($incoming && $stored && $incoming !== $stored) {
                $strip = static fn(string $n) => str_starts_with($n, '55') ? substr($n, 2) : $n;
                if ($strip($incoming) === $strip($stored)) {
                    $canonical = str_starts_with($incoming, '55') ? $incoming : '55' . $incoming;
                    if ($stored !== $canonical) {
                        self::update($contact['id'], ['phone' => $canonical]);
                        $contact['phone'] = $canonical;
                    }
                    if (!self::hasPhone($contact['id'], $stored)) {
                        self::addPhone($contact['id'], $stored, 'alternativo', false);
                    }
                }
            }

            // Mescla dados novos sem duplicar o contato
            $upd = [];
            if ($phone && empty($contact['phone'])) {
                $upd['phone'] = $phone;
            }
            if ($email && empty($contact['email'])) {
                $upd['email'] = $email;
            }
            if (!empty($extra['cnpj']) && empty($contact['document'])) {
                $upd['document'] = $extra['cnpj'];
            }
            if ($upd) {
                self::update($contact['id'], $upd);
            }
            if ($phone && !self::hasPhone($contact['id'], $phone)) {
                self::addPhone($contact['id'], $phone, 'principal', empty($contact['phone']));
            }
            if ($email && !self::hasEmail($contact['id'], $email)) {
                self::addEmail($contact['id'], $email, 'principal', empty($contact['email']));
            }
            return self::find($contact['id']);
        }

        $data = [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
        ];
        if (!empty($extra['cnpj'])) {
            $data['document'] = $extra['cnpj'];
        }

        $id = self::create($data);

        if ($phone) {
            self::addPhone($id, $phone, 'principal', true);
        }
        if ($email) {
            self::addEmail($id, $email, 'principal', true);
        }

        return self::find($id);
    }

    private static function hasPhone(int $contactId, string $phone): bool
    {
        return (bool) Database::getInstance()->fetch(
            "SELECT 1 FROM contact_phones WHERE contact_id = ? AND phone = ? LIMIT 1",
            [$contactId, $phone]
        );
    }

    private static function hasEmail(int $contactId, string $email): bool
    {
        return (bool) Database::getInstance()->fetch(
            "SELECT 1 FROM contact_emails WHERE contact_id = ? AND email = ? LIMIT 1",
            [$contactId, $email]
        );
    }
}
