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

    public static function findByName(string $name): ?array
    {
        return Database::getInstance()->fetch("SELECT * FROM tags WHERE name = ? LIMIT 1", [$name]);
    }

    /**
     * Retorna o ID da etiqueta com o nome informado, criando-a (com a cor)
     * caso ainda não exista. Usado para etiquetas de sistema ("Fluxo", "Aberto").
     */
    public static function ensureByName(string $name, string $color = '#6c757d'): int
    {
        $existing = self::findByName($name);
        if ($existing) {
            return (int) $existing['id'];
        }
        return self::create(['name' => $name, 'color' => $color]);
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
