<?php

namespace App\Models;

use App\Core\Database;

class Tag
{
    public static function all(): array
    {
        return Database::getInstance()->fetchAll("SELECT * FROM tags ORDER BY name");
    }

    public static function find(int $id): ?array
    {
        return Database::getInstance()->fetch("SELECT * FROM tags WHERE id = ?", [$id]);
    }

    public static function create(array $data): int
    {
        return Database::getInstance()->insert('tags', $data);
    }

    public static function update(int $id, array $data): int
    {
        return Database::getInstance()->update('tags', $data, 'id = ?', [$id]);
    }

    public static function delete(int $id): int
    {
        return Database::getInstance()->delete('tags', 'id = ?', [$id]);
    }
}
