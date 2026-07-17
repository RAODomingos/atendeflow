<?php

namespace App\Models;

use App\Core\Database;

class CannedResponse
{
    public static function all(): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT cr.*, d.name as department_name, u.name as user_name
             FROM canned_responses cr
             LEFT JOIN departments d ON d.id = cr.department_id
             LEFT JOIN users u ON u.id = cr.user_id
             ORDER BY cr.title"
        );
    }

    public static function getForContext(?int $departmentId, ?int $userId): array
    {
        $sql = "SELECT * FROM canned_responses WHERE 1=1";
        $params = [];
        if ($departmentId) {
            $sql .= " AND (department_id IS NULL OR department_id = ?)";
            $params[] = $departmentId;
        }
        if ($userId) {
            $sql .= " AND (user_id IS NULL OR user_id = ?)";
            $params[] = $userId;
        }
        $sql .= " ORDER BY title";
        return Database::getInstance()->fetchAll($sql, $params);
    }

    public static function find(int $id): ?array
    {
        return Database::getInstance()->fetch("SELECT * FROM canned_responses WHERE id = ?", [$id]);
    }

    public static function create(array $data): int
    {
        return Database::getInstance()->insert('canned_responses', $data);
    }

    public static function update(int $id, array $data): int
    {
        return Database::getInstance()->update('canned_responses', $data, 'id = ?', [$id]);
    }

    public static function delete(int $id): int
    {
        return Database::getInstance()->delete('canned_responses', 'id = ?', [$id]);
    }
}
