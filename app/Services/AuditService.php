<?php

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;

class AuditService
{
    public static function log(string $action, string $entityType, ?int $entityId = null, ?array $oldValues = null, ?array $newValues = null): int
    {
        return Database::getInstance()->insert('audit_logs', [
            'user_id' => Auth::id(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $oldValues ? json_encode($oldValues) : null,
            'new_values' => $newValues ? json_encode($newValues) : null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }

    public static function getRecent(int $limit = 50): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT al.*, u.name as user_name
             FROM audit_logs al
             LEFT JOIN users u ON u.id = al.user_id
             ORDER BY al.created_at DESC
             LIMIT ?",
            [$limit]
        );
    }
}
