<?php
// ============================================================
//  api/categories.php — REST API de categorias
//  GET    /api/categories.php        → lista todas
//  GET    /api/categories.php?id=1   → uma categoria
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

$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

if ($id) {
    $stmt = $db->prepare('SELECT * FROM categories WHERE id = ?');
    $stmt->execute([$id]);
    $cat = $stmt->fetch();
    jsonResponse($cat ?: ['error' => 'Categoria não encontrada'], $cat ? 200 : 404);
}

// Lista todas ordenadas por sort_order
$cats = $db->query(
    'SELECT c.*, COUNT(a.id) AS article_count
     FROM categories c
     LEFT JOIN articles a ON a.category_id = c.id
     GROUP BY c.id
     ORDER BY c.sort_order ASC, c.title ASC'
)->fetchAll();

jsonResponse($cats);
