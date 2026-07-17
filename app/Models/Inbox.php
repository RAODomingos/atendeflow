<?php

namespace App\Models;

use App\Core\Database;
use App\Core\Auth;

class Inbox
{
    public static function all(): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT * FROM inboxes WHERE is_active = 1 ORDER BY type, name"
        );
    }

    public static function find(int $id): ?array
    {
        return Database::getInstance()->fetch("SELECT * FROM inboxes WHERE id = ?", [$id]);
    }

    public static function create(array $data): int
    {
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        return Database::getInstance()->insert('inboxes', $data);
    }

    public static function update(int $id, array $data): int
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        return Database::getInstance()->update('inboxes', $data, 'id = ?', [$id]);
    }

    public static function delete(int $id): int
    {
        return Database::getInstance()->delete('inboxes', 'id = ?', [$id]);
    }

    public static function getChannels(int $inboxId): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT c.* FROM channels c
             JOIN inbox_channels ic ON ic.channel_id = c.id
             WHERE ic.inbox_id = ? ORDER BY c.name",
            [$inboxId]
        );
    }

    public static function setChannels(int $inboxId, array $channelIds): void
    {
        $db = Database::getInstance();
        $db->delete('inbox_channels', 'inbox_id = ?', [$inboxId]);
        foreach (array_unique(array_filter($channelIds)) as $cid) {
            $db->insert('inbox_channels', ['inbox_id' => $inboxId, 'channel_id' => (int) $cid]);
        }
    }

    public static function getDepartments(int $inboxId): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT d.* FROM departments d
             JOIN inbox_departments idp ON idp.department_id = d.id
             WHERE idp.inbox_id = ? ORDER BY d.name",
            [$inboxId]
        );
    }

    public static function setDepartments(int $inboxId, array $deptIds): void
    {
        $db = Database::getInstance();
        $db->delete('inbox_departments', 'inbox_id = ?', [$inboxId]);
        foreach (array_unique(array_filter($deptIds)) as $did) {
            $db->insert('inbox_departments', ['inbox_id' => $inboxId, 'department_id' => (int) $did]);
        }
    }

    public static function getUsers(int $inboxId): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT u.* FROM users u
             JOIN inbox_users iu ON iu.user_id = u.id
             WHERE iu.inbox_id = ? ORDER BY u.name",
            [$inboxId]
        );
    }

    public static function setUsers(int $inboxId, array $userIds): void
    {
        $db = Database::getInstance();
        $db->delete('inbox_users', 'inbox_id = ?', [$inboxId]);
        foreach (array_unique(array_filter($userIds)) as $uid) {
            $db->insert('inbox_users', ['inbox_id' => $inboxId, 'user_id' => (int) $uid]);
        }
    }

    /**
     * Resolve a inbox para uma conversa que chega por um canal.
     * Prefere caixas pessoais em vez de departamentais.
     */
    public static function resolveInboxForChannel(int $channelId): ?int
    {
        $row = Database::getInstance()->fetch(
            "SELECT ic.inbox_id FROM inbox_channels ic
             JOIN inboxes i ON i.id = ic.inbox_id
             WHERE ic.channel_id = ?
             ORDER BY (i.type = 'personal') DESC, i.id ASC
             LIMIT 1",
            [$channelId]
        );
        return $row ? (int) $row['inbox_id'] : null;
    }

    /**
     * Caixas que o usuário pode ver.
     * Admin vê todas; demais veem departamento (membro) + pessoal (dono).
     */
    public static function getUserInboxes(int $userId): array
    {
        if (Auth::isAdmin()) {
            return self::all();
        }
        return Database::getInstance()->fetchAll(
            "SELECT DISTINCT i.* FROM inboxes i
             LEFT JOIN inbox_departments idp ON idp.inbox_id = i.id
             LEFT JOIN department_users du ON du.department_id = idp.department_id AND du.user_id = ?
             LEFT JOIN inbox_users iu ON iu.inbox_id = i.id AND iu.user_id = ?
             WHERE i.is_active = 1
               AND (du.id IS NOT NULL OR iu.id IS NOT NULL)
             ORDER BY i.type, i.name",
            [$userId, $userId]
        );
    }

    public static function canAccess(int $inboxId, int $userId): bool
    {
        if (Auth::isAdmin()) {
            return true;
        }
        $row = Database::getInstance()->fetch(
            "SELECT 1 FROM inboxes i
             LEFT JOIN inbox_departments idp ON idp.inbox_id = i.id
             LEFT JOIN department_users du ON du.department_id = idp.department_id AND du.user_id = ?
             LEFT JOIN inbox_users iu ON iu.inbox_id = i.id AND iu.user_id = ?
             WHERE i.id = ? AND (du.id IS NOT NULL OR iu.id IS NOT NULL)
             LIMIT 1",
            [$userId, $userId, $inboxId]
        );
        return (bool) $row;
    }

    /**
     * Caixas pessoais criadas pelo usuário (onde ele é o dono),
     * independente de ser admin. Usado em "Minha Caixa".
     */
    public static function getOwnedPersonalInboxes(int $userId): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT i.* FROM inboxes i
             JOIN inbox_users iu ON iu.inbox_id = i.id AND iu.user_id = ?
             WHERE i.type = 'personal' AND i.is_active = 1
             ORDER BY i.name",
            [$userId]
        );
    }
}
