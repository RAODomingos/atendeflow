<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
requireLogin();

$db = getDB();
$totalCats     = $db->query('SELECT COUNT(*) FROM categories')->fetchColumn();
$totalArticles = $db->query('SELECT COUNT(*) FROM articles')->fetchColumn();
$totalFeatured = $db->query('SELECT COUNT(*) FROM articles WHERE featured = 1')->fetchColumn();

$recentArticles = $db->query(
    'SELECT a.title, a.slug, a.created_at, c.title AS cat_title
     FROM articles a LEFT JOIN categories c ON c.id = a.category_id
     ORDER BY a.created_at DESC LIMIT 6'
)->fetchAll();

$pageTitle = 'Dashboard';
require '_header.php';
?>

<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
      </svg>
    </div>
    <div class="stat-info">
      <div class="stat-number"><?= $totalCats ?></div>
      <div class="stat-label">Categorias</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
        <polyline points="14 2 14 8 20 8"/>
      </svg>
    </div>
    <div class="stat-info">
      <div class="stat-number"><?= $totalArticles ?></div>
      <div class="stat-label">Artigos</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon stat-icon-accent">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
      </svg>
    </div>
    <div class="stat-info">
      <div class="stat-number"><?= $totalFeatured ?></div>
      <div class="stat-label">Em destaque</div>
    </div>
  </div>
</div>

<div class="panel">
  <div class="panel-header">
    <h2 class="panel-title">Artigos recentes</h2>
    <a href="/admin/articles.php" class="btn btn-sm btn-outline">Ver todos</a>
  </div>
  <table class="table">
    <thead>
      <tr>
        <th>Título</th>
        <th>Categoria</th>
        <th>Criado em</th>
        <th>Ações</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($recentArticles as $a): ?>
      <tr>
        <td><?= htmlspecialchars($a['title']) ?></td>
        <td><span class="badge"><?= htmlspecialchars($a['cat_title'] ?? '—') ?></span></td>
        <td><?= date('d/m/Y', strtotime($a['created_at'])) ?></td>
        <td>
          <a href="/admin/article-form.php?slug=<?= urlencode($a['slug']) ?>" class="btn btn-xs btn-outline">Editar</a>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($recentArticles)): ?>
      <tr><td colspan="4" class="text-muted text-center">Nenhum artigo cadastrado ainda.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<div class="quick-actions">
  <a href="/admin/category-form.php" class="quick-btn">
    Nova categoria
  </a>
  <a href="/admin/article-form.php" class="quick-btn quick-btn-primary">
    Novo artigo
  </a>
</div>

<?php require '_footer.php'; ?>
