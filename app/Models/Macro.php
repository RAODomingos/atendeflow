<?php

namespace App\Models;

use App\Core\Database;

class Macro
{
    public static function all(): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT * FROM macros ORDER BY title ASC"
        );
    }

    public static function getForContext(?int $departmentId, ?int $userId): array
    {
        $sql = "SELECT * FROM macros WHERE 1=1";
        $params = [];
        if ($departmentId) {
            $sql .= " AND (department_id IS NULL OR department_id = ?)";
            $params[] = $departmentId;
        }
        if ($userId) {
            $sql .= " AND (user_id IS NULL OR user_id = ?)";
            $params[] = $userId;
        }
        $sql .= " ORDER BY title ASC";
        return Database::getInstance()->fetchAll($sql, $params);
    }

    public static function find(int $id): ?array
    {
        return Database::getInstance()->fetch("SELECT * FROM macros WHERE id = ?", [$id]);
    }

    public static function create(array $data): int
    {
        return Database::getInstance()->insert('macros', $data);
    }

    public static function update(int $id, array $data): int
    {
        return Database::getInstance()->update('macros', $data, 'id = ?', [$id]);
    }

    public static function delete(int $id): int
    {
        return Database::getInstance()->delete('macros', 'id = ?', [$id]);
    }
}
