<?php

namespace App\Models;

use App\Core\Database;

class WikiArticle
{
    public static function all(array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['category_slug'])) {
            $where[] = 'c.slug = ?';
            $params[] = $filters['category_slug'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(a.title LIKE ? OR a.description LIKE ?)';
            $params[] = '%' . $filters['q'] . '%';
            $params[] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['featured'])) {
            $where[] = 'a.featured = 1';
        }

        return Database::getInstance()->fetchAll(
            "SELECT a.*, c.title AS category_title, c.slug AS category_slug
             FROM wiki_articles a
             LEFT JOIN wiki_categories c ON c.id = a.category_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY c.sort_order ASC, a.sort_order ASC, a.created_at DESC
             LIMIT 200",
            $params
        );
    }

    public static function find(int $id): ?array
    {
        return Database::getInstance()->fetch(
            "SELECT a.*, c.title AS category_title, c.slug AS category_slug
             FROM wiki_articles a
             LEFT JOIN wiki_categories c ON c.id = a.category_id
             WHERE a.id = ?",
            [$id]
        );
    }

    public static function findBySlug(string $slug): ?array
    {
        return Database::getInstance()->fetch(
            "SELECT a.*, c.title AS category_title, c.slug AS category_slug
             FROM wiki_articles a
             LEFT JOIN wiki_categories c ON c.id = a.category_id
             WHERE a.slug = ?",
            [$slug]
        );
    }

    public static function create(array $data): int
    {
        return Database::getInstance()->insert('wiki_articles', $data);
    }

    public static function update(int $id, array $data): int
    {
        return Database::getInstance()->update('wiki_articles', $data, 'id = ?', [$id]);
    }

    public static function delete(int $id): int
    {
        return Database::getInstance()->delete('wiki_articles', 'id = ?', [$id]);
    }

    public static function reorder(array $orderedIds): void
    {
        $db = Database::getInstance();
        foreach (array_values($orderedIds) as $pos => $id) {
            $db->update('wiki_articles', ['sort_order' => $pos + 1], 'id = ?', [(int) $id]);
        }
    }

    public static function slugExists(string $slug, int $ignoreId = 0): bool
    {
        return (bool) Database::getInstance()->fetch(
            "SELECT id FROM wiki_articles WHERE slug = ? AND id != ?",
            [$slug, $ignoreId]
        );
    }

    public static function search(string $q, int $limit = 30): array
    {
        $like = '%' . $q . '%';
        return Database::getInstance()->fetchAll(
            "SELECT a.*, c.slug AS category_slug, c.title AS category_title
             FROM wiki_articles a
             LEFT JOIN wiki_categories c ON c.id = a.category_id
             WHERE a.title LIKE ? OR a.description LIKE ? OR a.content LIKE ?
             ORDER BY a.sort_order ASC LIMIT " . ((int) $limit),
            [$like, $like, $like]
        );
    }

    public static function featured(int $limit = 6): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT a.*, c.slug AS category_slug, c.title AS category_title
             FROM wiki_articles a
             LEFT JOIN wiki_categories c ON c.id = a.category_id
             WHERE a.featured = 1
             ORDER BY a.sort_order ASC LIMIT " . ((int) $limit)
        );
    }

    public static function countAll(): int
    {
        $row = Database::getInstance()->fetch("SELECT COUNT(*) AS total FROM wiki_articles");
        return (int) ($row['total'] ?? 0);
    }

    public static function registerView(int $articleId, ?string $ip, ?string $ua, ?string $referrer): void
    {
        $db = Database::getInstance();
        $db->insert('wiki_article_views', [
            'article_id' => $articleId,
            'ip_address' => $ip ? substr($ip, 0, 45) : null,
            'user_agent' => $ua ? substr($ua, 0, 1000) : null,
            'referrer' => $referrer ? substr($referrer, 0, 500) : null,
        ]);
        $db->execute("UPDATE wiki_articles SET view_count = view_count + 1 WHERE id = ?", [$articleId]);
    }
}
