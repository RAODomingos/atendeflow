<?php

namespace App\Models;

use App\Core\Database;

class CloseReason
{
    public static function all(bool $onlyActive = true): array
    {
        $sql = "SELECT * FROM close_reasons";
        if ($onlyActive) $sql .= " WHERE is_active = 1";
        $sql .= " ORDER BY sort_order ASC, label ASC";
        return Database::getInstance()->fetchAll($sql);
    }

    public static function find(int $id): ?array
    {
        return Database::getInstance()->fetch("SELECT * FROM close_reasons WHERE id = ?", [$id]);
    }

    public static function findByCode(string $code): ?array
    {
        return Database::getInstance()->fetch("SELECT * FROM close_reasons WHERE code = ?", [$code]);
    }

    public static function create(array $data): int
    {
        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['is_active'] = $data['is_active'] ?? 1;
        $data['color'] = $data['color'] ?? '#6c757d';
        return Database::getInstance()->insert('close_reasons', $data);
    }

    public static function update(int $id, array $data): int
    {
        return Database::getInstance()->update('close_reasons', $data, 'id = ?', [$id]);
    }

    public static function delete(int $id): int
    {
        return Database::getInstance()->delete('close_reasons', 'id = ?', [$id]);
    }

    public static function toggle(int $id): bool
    {
        $row = self::find($id);
        if (!$row) return false;
        self::update($id, ['is_active' => $row['is_active'] ? 0 : 1]);
        return true;
    }

    /**
     * Resolve um motivo a partir do texto livre (close_reason) salvo na
     * conversa, casando primeiro pelo `code` exato e depois pelo `label`
     * (case-insensitive). Retorna null se nenhum motivo for reconhecido.
     */
    public static function resolve(?string $value): ?array
    {
        if (!$value) return null;
        $row = self::findByCode($value);
        if ($row) return $row;
        $needle = mb_strtolower(trim($value));
        foreach (self::all() as $r) {
            if (mb_strtolower($r['label']) === $needle) return $r;
        }
        return null;
    }
}
