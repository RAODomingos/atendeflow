<?php
// ============================================================
//  api/articles.php — REST API de artigos
//  GET /api/articles.php              → todos os artigos
//  GET /api/articles.php?slug=xxx     → artigo por slug
//  GET /api/articles.php?category=xx  → artigos da categoria (slug)
//  GET /api/articles.php?featured=1   → artigos em destaque
//  GET /api/articles.php?search=xxx   → busca full-text
// ============================================================

require_once __DIR__ . '/../config/api_auth.php';

// Authenticate API request
APIAuth::authenticate();

// Set CORS headers for authenticated requests
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: X-API-Key, Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$db = getDB();

$base = '
    SELECT a.*, c.slug AS category_slug, c.title AS category_title
    FROM articles a
    LEFT JOIN categories c ON c.id = a.category_id
';

// Por slug do artigo
if (!empty($_GET['slug'])) {
    $stmt = $db->prepare($base . ' WHERE a.slug = ?');
    $stmt->execute([trim($_GET['slug'])]);
    $row = $stmt->fetch();
    jsonResponse($row ?: ['error' => 'Artigo não encontrado'], $row ? 200 : 404);
}

// Por categoria (slug)
if (!empty($_GET['category'])) {
    $stmt = $db->prepare($base . ' WHERE c.slug = ? ORDER BY a.sort_order ASC, a.created_at ASC');
    $stmt->execute([trim($_GET['category'])]);
    jsonResponse($stmt->fetchAll());
}

// Em destaque
if (!empty($_GET['featured'])) {
    $stmt = $db->query($base . ' WHERE a.featured = 1 ORDER BY a.sort_order ASC LIMIT 6');
    jsonResponse($stmt->fetchAll());
}

// Busca
if (!empty($_GET['search'])) {
    $q = '%' . trim($_GET['search']) . '%';
    $stmt = $db->prepare($base . ' WHERE a.title LIKE ? OR a.description LIKE ? OR a.content LIKE ?
                                    ORDER BY a.sort_order ASC');
    $stmt->execute([$q, $q, $q]);
    jsonResponse($stmt->fetchAll());
}

// Todos
$all = $db->query($base . ' ORDER BY c.sort_order ASC, a.sort_order ASC')->fetchAll();
jsonResponse($all);
