<?php

namespace App\Models;

use App\Core\Database;

class Department
{
    public static function find(int $id): ?array
    {
        return Database::getInstance()->fetch(
            "SELECT * FROM departments WHERE id = ?",
            [$id]
        );
    }

    public static function all(): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT d.*,
                    (SELECT COUNT(*) FROM department_users WHERE department_id = d.id) as user_count,
                    (SELECT COUNT(*) FROM conversations WHERE department_id = d.id) as conversation_count,
                    (SELECT COUNT(*) FROM conversations WHERE department_id = d.id AND status NOT IN ('resolved', 'closed', 'spam')) as open_conversations,
                    (SELECT COUNT(DISTINCT contact_id) FROM conversations WHERE department_id = d.id) as contact_count
             FROM departments d
             ORDER BY d.name"
        );
    }

    public static function create(array $data): int
    {
        return Database::getInstance()->insert('departments', $data);
    }

    public static function update(int $id, array $data): int
    {
        return Database::getInstance()->update('departments', $data, 'id = ?', [$id]);
    }

    public static function delete(int $id): int
    {
        return Database::getInstance()->delete('departments', 'id = ?', [$id]);
    }

    public static function getUsers(int $departmentId): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT u.*, du.is_manager,
                    (SELECT COUNT(*) FROM conversations WHERE assigned_user_id = u.id) as conversation_count
             FROM users u
             JOIN department_users du ON du.user_id = u.id
             WHERE du.department_id = ?
             ORDER BY du.is_manager DESC, u.name",
            [$departmentId]
        );
    }

    public static function addUser(int $departmentId, int $userId, bool $isManager = false): int
    {
        return Database::getInstance()->insert('department_users', [
            'department_id' => $departmentId,
            'user_id' => $userId,
            'is_manager' => $isManager ? 1 : 0,
        ]);
    }

    public static function removeUser(int $departmentId, int $userId): int
    {
        return Database::getInstance()->delete(
            'department_users',
            'department_id = ? AND user_id = ?',
            [$departmentId, $userId]
        );
    }

    public static function getUserDepartments(int $userId): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT d.*, du.is_manager
             FROM departments d
             JOIN department_users du ON du.department_id = d.id
             WHERE du.user_id = ?",
            [$userId]
        );
    }

    /**
     * Conversas do departamento com filtros opcionais.
     * Filtros: year, month, status, contact_id, assigned_user_id.
     */
    public static function getConversations(int $departmentId, array $filters = []): array
    {
        $sql = "SELECT c.*, ch.type as channel_type, ch.name as channel_name,
                       ct.name as contact_name, ct.email as contact_email, ct.phone as contact_phone,
                       u.name as assigned_user_name,
                       (SELECT content FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message,
                       (SELECT COUNT(*) FROM messages WHERE conversation_id = c.id) as message_count
                FROM conversations c
                LEFT JOIN channels ch ON ch.id = c.channel_id
                LEFT JOIN contacts ct ON ct.id = c.contact_id
                LEFT JOIN users u ON u.id = c.assigned_user_id
                WHERE c.department_id = ?";
        $params = [$departmentId];
        self::applyConversationFilters($sql, $params, $filters);
        $sql .= " ORDER BY c.created_at DESC";
        return Database::getInstance()->fetchAll($sql, $params);
    }

    public static function countConversationsByStatus(int $departmentId, array $filters = []): array
    {
        $sql = "SELECT c.status, COUNT(*) as total
                FROM conversations c
                WHERE c.department_id = ?";
        $params = [$departmentId];
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

    public static function getAvailableMonths(int $departmentId): array
    {
        $rows = Database::getInstance()->fetchAll(
            "SELECT YEAR(created_at) as year, MONTH(created_at) as month, COUNT(*) as total
             FROM conversations
             WHERE department_id = ? AND created_at IS NOT NULL
             GROUP BY YEAR(created_at), MONTH(created_at)
             ORDER BY year DESC, month DESC",
            [$departmentId]
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
     * Usuários do sistema que NÃO estão no departamento (para o select de "adicionar").
     */
    public static function getUsersNotInDepartment(int $departmentId): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT u.id, u.name, u.email
             FROM users u
             WHERE u.is_active = 1
               AND u.id NOT IN (
                   SELECT user_id FROM department_users WHERE department_id = ?
               )
             ORDER BY u.name",
            [$departmentId]
        );
    }

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
        if (!empty($filters['status']) && is_string($filters['status'])) {
            $sql .= " AND c.status = ?";
            $params[] = (string) $filters['status'];
        }
        if (!empty($filters['contact_id']) && (int) $filters['contact_id'] > 0) {
            $sql .= " AND c.contact_id = ?";
            $params[] = (int) $filters['contact_id'];
        }
        if (!empty($filters['assigned_user_id']) && (int) $filters['assigned_user_id'] > 0) {
            $sql .= " AND c.assigned_user_id = ?";
            $params[] = (int) $filters['assigned_user_id'];
        }
    }
}
