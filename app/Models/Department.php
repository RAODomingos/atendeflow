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
                    (SELECT COUNT(*) FROM conversations WHERE department_id = d.id AND status NOT IN ('resolved', 'closed', 'spam')) as open_conversations
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
            "SELECT u.*, du.is_manager
             FROM users u
             JOIN department_users du ON du.user_id = u.id
             WHERE du.department_id = ?
             ORDER BY u.name",
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
}
