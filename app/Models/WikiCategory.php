<?php

namespace App\Models;

use App\Core\Database;

class WikiCategory
{
    public static function all(bool $withCounts = true): array
    {
        if ($withCounts) {
            return Database::getInstance()->fetchAll(
                "SELECT c.*, COUNT(a.id) AS article_count
                 FROM wiki_categories c
                 LEFT JOIN wiki_articles a ON a.category_id = c.id
                 GROUP BY c.id
                 ORDER BY c.sort_order ASC, c.title ASC"
            );
        }
        return Database::getInstance()->fetchAll(
            "SELECT * FROM wiki_categories ORDER BY sort_order ASC, title ASC"
        );
    }

    public static function find(int $id): ?array
    {
        return Database::getInstance()->fetch("SELECT * FROM wiki_categories WHERE id = ?", [$id]);
    }

    public static function findBySlug(string $slug): ?array
    {
        return Database::getInstance()->fetch("SELECT * FROM wiki_categories WHERE slug = ?", [$slug]);
    }

    public static function create(array $data): int
    {
        return Database::getInstance()->insert('wiki_categories', $data);
    }

    public static function update(int $id, array $data): int
    {
        return Database::getInstance()->update('wiki_categories', $data, 'id = ?', [$id]);
    }

    public static function delete(int $id): int
    {
        return Database::getInstance()->delete('wiki_categories', 'id = ?', [$id]);
    }

    public static function reorder(array $orderedIds): void
    {
        $db = Database::getInstance();
        foreach (array_values($orderedIds) as $pos => $id) {
            $db->update('wiki_categories', ['sort_order' => $pos + 1], 'id = ?', [(int) $id]);
        }
    }

    public static function slugExists(string $slug, int $ignoreId = 0): bool
    {
        return (bool) Database::getInstance()->fetch(
            "SELECT id FROM wiki_categories WHERE slug = ? AND id != ?",
            [$slug, $ignoreId]
        );
    }
}
