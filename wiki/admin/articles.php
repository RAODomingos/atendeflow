<?php
// ============================================================
//  admin/articles.php — Listar, filtrar, reordenar e excluir artigos
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
requireLogin();

$db = getDB();

// ── EXCLUIR ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'delete') {
    $db->prepare('DELETE FROM articles WHERE id = ?')->execute([(int)$_POST['id']]);
    header('Location: /admin/articles.php?msg=deleted'); exit;
}

// ── REORDENAR ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'reorder') {
    $order = json_decode($_POST['order'] ?? '[]', true);
    if (is_array($order)) {
        foreach ($order as $pos => $artId) {
            $db->prepare('UPDATE articles SET sort_order = ? WHERE id = ?')
               ->execute([$pos + 1, (int)$artId]);
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]); exit;
}

// ── FILTROS ─────────────────────────────────────────────────
$filterCat  = trim($_GET['cat']  ?? '');
$search     = trim($_GET['q']    ?? '');
$filterFeat = $_GET['featured']  ?? '';

$where  = ['1=1'];
$params = [];

if ($filterCat)  { $where[] = 'c.slug = ?';                               $params[] = $filterCat; }
if ($search)     { $where[] = '(a.title LIKE ? OR a.description LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($filterFeat) { $where[] = 'a.featured = 1'; }

$sql = 'SELECT a.id, a.slug, a.title, a.description, a.cover_image,
               a.featured, a.sort_order, a.created_at, a.view_count,
               c.title AS cat_title, c.slug AS cat_slug
        FROM articles a
        LEFT JOIN categories c ON c.id = a.category_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY c.sort_order ASC, a.sort_order ASC, a.created_at DESC
        LIMIT 50';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$articles = $stmt->fetchAll();

// Categorias para o filtro
$cats = $db->query('SELECT id, slug, title FROM categories ORDER BY sort_order ASC')->fetchAll();

// ── LAYOUT ──────────────────────────────────────────────────
$pageTitle     = 'Artigos';
$topbarActions = '<a href="/admin/article-form.php" class="btn btn-primary">
  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
       stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
    <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
  </svg>
  Novo artigo
</a>';
require __DIR__ . '/_header.php';
?>

<?php if (($_GET['msg'] ?? '') === 'deleted'): ?>
  <div class="alert alert-success">✅ Artigo excluído com sucesso.</div>
<?php elseif (($_GET['msg'] ?? '') === 'saved'): ?>
  <div class="alert alert-success">✅ Artigo salvo com sucesso.</div>
<?php endif; ?>

<!-- Filtros -->
<form method="GET" class="filter-bar">
  <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
         placeholder="Buscar por título ou descrição…" class="filter-input"/>
  <select name="cat" class="filter-select">
    <option value="">Todas as categorias</option>
    <?php foreach ($cats as $c): ?>
      <option value="<?= $c['slug'] ?>" <?= $filterCat === $c['slug'] ? 'selected' : '' ?>>
        <?= htmlspecialchars($c['title']) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <label class="checkbox-label" style="white-space:nowrap">
    <input type="checkbox" name="featured" value="1" <?= $filterFeat ? 'checked' : '' ?>/> Só destaques
  </label>
  <button type="submit" class="btn btn-outline">Filtrar</button>
  <?php if ($search || $filterCat || $filterFeat): ?>
    <a href="/admin/articles.php" class="btn btn-outline">✕ Limpar</a>
  <?php endif; ?>
</form>

<div class="panel">
  <div class="panel-header">
    <h2 class="panel-title">
      Artigos
      <span class="badge" style="margin-left:8px;font-size:.8rem"><?= count($articles) ?></span>
    </h2>
    <span class="text-muted text-sm">Arraste para reordenar dentro da categoria</span>
  </div>

  <?php if (empty($articles)): ?>
    <div class="empty-state">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
           stroke-linecap="round" stroke-linejoin="round">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
        <polyline points="14 2 14 8 20 8"/>
      </svg>
      <p>Nenhum artigo encontrado<?= $search || $filterCat ? ' para este filtro' : ' ainda' ?>.</p>
      <?php if (!$search && !$filterCat): ?>
        <a href="/admin/article-form.php" class="btn btn-primary">Criar primeiro artigo</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th width="36"></th>
          <th>Artigo</th>
          <th>Categoria</th>
          <th width="80">Destaque</th>
          <th width="90">Criado em</th>
          <th width="140">Ações</th>
        </tr>
      </thead>
      <tbody id="sortableBody">
        <?php foreach ($articles as $a): ?>
        <tr data-id="<?= $a['id'] ?>">
          <td class="drag-handle" title="Arrastar para reordenar">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="9" cy="5" r="1" fill="currentColor"/>
              <circle cx="9" cy="12" r="1" fill="currentColor"/>
              <circle cx="9" cy="19" r="1" fill="currentColor"/>
              <circle cx="15" cy="5" r="1" fill="currentColor"/>
              <circle cx="15" cy="12" r="1" fill="currentColor"/>
              <circle cx="15" cy="19" r="1" fill="currentColor"/>
            </svg>
          </td>
          <td>
            <div style="display:flex;align-items:center;gap:10px;">
              <?php if ($a['cover_image']): ?>
                <img src="<?= htmlspecialchars($a['cover_image']) ?>"
                     class="article-thumb" alt=""/>
              <?php else: ?>
                <div class="article-thumb-placeholder">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                       stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="3" width="18" height="18" rx="2"/>
                    <circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>
                  </svg>
                </div>
              <?php endif; ?>
              <div>
                <div style="font-weight:600"><?= htmlspecialchars($a['title']) ?></div>
                <div class="text-muted text-sm" style="max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                  <?= htmlspecialchars($a['description'] ?? '') ?>
                </div>
              </div>
            </div>
          </td>
          <td>
            <?php if ($a['cat_title']): ?>
              <span class="badge"><?= htmlspecialchars($a['cat_title']) ?></span>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?= $a['featured']
              ? '<span class="badge badge-green">★ Sim</span>'
              : '<span class="text-muted">Não</span>' ?>
          </td>
          <td class="text-muted text-sm"><?= date('d/m/Y', strtotime($a['created_at'])) ?></td>
          <td>
            <div class="action-btns">
              <a href="/admin/article-form.php?slug=<?= urlencode($a['slug']) ?>"
                 class="btn btn-xs btn-outline">Editar</a>
              <form method="POST" style="display:inline"
                    onsubmit="return confirm('Excluir \"<?= htmlspecialchars(addslashes($a['title'])) ?>\"?')">
                <input type="hidden" name="_action" value="delete"/>
                <input type="hidden" name="id" value="<?= $a['id'] ?>"/>
                <button type="submit" class="btn btn-xs btn-danger">Excluir</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php
$extraScripts = '<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
const tbody = document.getElementById("sortableBody");
if (tbody) {
  Sortable.create(tbody, {
    handle: ".drag-handle",
    animation: 150,
    onEnd: function() {
      const order = [...tbody.querySelectorAll("tr[data-id]")].map(r => r.dataset.id);
      fetch("/admin/articles.php", {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: "_action=reorder&order=" + encodeURIComponent(JSON.stringify(order))
      });
    }
  });
}
</script>';
require __DIR__ . '/_footer.php';
