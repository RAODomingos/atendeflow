<?php

namespace App\Models;

use App\Core\Database;

class User
{
    public static function find(int $id): ?array
    {
        return Database::getInstance()->fetch(
            "SELECT id, name, email, role, avatar, signature, is_active, last_login_at, created_at, updated_at FROM users WHERE id = ?",
            [$id]
        );
    }

    public static function findByEmail(string $email): ?array
    {
        return Database::getInstance()->fetch(
            "SELECT * FROM users WHERE email = ?",
            [$email]
        );
    }

    public static function all(): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT u.*, GROUP_CONCAT(d.name SEPARATOR ', ') as department_names,
                    (SELECT COUNT(*) FROM conversations WHERE assigned_user_id = u.id) as conversation_count
             FROM users u
             LEFT JOIN department_users du ON du.user_id = u.id
             LEFT JOIN departments d ON d.id = du.department_id
             GROUP BY u.id
             ORDER BY u.name"
        );
    }

    public static function create(array $data): int
    {
        $data['password'] = password_hash($data['password'], PASSWORD_ARGON2ID);
        return Database::getInstance()->insert('users', $data);
    }

    public static function update(int $id, array $data): int
    {
        if (isset($data['password'])) {
            $data['password'] = password_hash($data['password'], PASSWORD_ARGON2ID);
        }
        return Database::getInstance()->update('users', $data, 'id = ?', [$id]);
    }

    public static function delete(int $id): int
    {
        return Database::getInstance()->delete('users', 'id = ?', [$id]);
    }

    public static function getDepartmentIds(int $userId): array
    {
        $rows = Database::getInstance()->fetchAll(
            "SELECT department_id FROM department_users WHERE user_id = ?",
            [$userId]
        );
        return array_column($rows, 'department_id');
    }

    public static function getOnlineCount(): int
    {
        $row = Database::getInstance()->fetch(
            "SELECT COUNT(*) as total FROM users WHERE is_active = 1 AND last_login_at >= NOW() - INTERVAL 15 MINUTE"
        );
        return (int) ($row['total'] ?? 0);
    }
}
