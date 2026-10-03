<?php
// ============================================================
//  admin/categories.php — Listar, reordenar e excluir categorias
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
requireLogin();

$db = getDB();

// ── EXCLUIR ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'delete') {
    $db->prepare('DELETE FROM categories WHERE id = ?')->execute([(int)$_POST['id']]);
    header('Location: /admin/categories.php?msg=deleted'); exit;
}

// ── REORDENAR (drag-and-drop via AJAX) ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'reorder') {
    $order = json_decode($_POST['order'] ?? '[]', true);
    if (is_array($order)) {
        foreach ($order as $pos => $catId) {
            $db->prepare('UPDATE categories SET sort_order = ? WHERE id = ?')
               ->execute([$pos + 1, (int)$catId]);
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]); exit;
}

// ── BUSCAR CATEGORIAS ────────────────────────────────────────
$categories = $db->query(
    'SELECT c.*, COUNT(a.id) AS article_count
     FROM categories c
     LEFT JOIN articles a ON a.category_id = c.id
     GROUP BY c.id
     ORDER BY c.sort_order ASC, c.created_at ASC'
)->fetchAll();

// ── LAYOUT ──────────────────────────────────────────────────
$pageTitle     = 'Categorias';
$topbarActions = '<a href="/admin/category-form.php" class="btn btn-primary">
  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
       stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
    <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
  </svg>
  Nova categoria
</a>';
require __DIR__ . '/_header.php';
?>

<?php if (($_GET['msg'] ?? '') === 'deleted'): ?>
  <div class="alert alert-success">✅ Categoria excluída com sucesso.</div>
<?php elseif (($_GET['msg'] ?? '') === 'saved'): ?>
  <div class="alert alert-success">✅ Categoria salva com sucesso.</div>
<?php endif; ?>

<div class="panel">
  <div class="panel-header">
    <h2 class="panel-title">
      Todas as categorias
      <span class="badge" style="margin-left:8px;font-size:.8rem"><?= count($categories) ?></span>
    </h2>
    <span class="text-muted text-sm">Arraste as linhas para reordenar</span>
  </div>

  <?php if (empty($categories)): ?>
    <div class="empty-state">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
           stroke-linecap="round" stroke-linejoin="round">
        <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
      </svg>
      <p>Nenhuma categoria cadastrada ainda.</p>
      <a href="/admin/category-form.php" class="btn btn-primary">Criar primeira categoria</a>
    </div>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th width="36"></th>
          <th>Categoria</th>
          <th>Slug</th>
          <th>Artigos</th>
          <th>Ordem</th>
          <th width="140">Ações</th>
        </tr>
      </thead>
      <tbody id="sortableBody">
        <?php foreach ($categories as $cat): ?>
        <tr data-id="<?= $cat['id'] ?>">
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
              <div class="cat-thumb">
                <?= $cat['icon_svg'] ?: '<svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/></svg>' ?>
              </div>
              <div>
                <div style="font-weight:600"><?= htmlspecialchars($cat['title']) ?></div>
                <div class="text-muted text-sm" style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                  <?= htmlspecialchars($cat['description'] ?? '') ?>
                </div>
              </div>
            </div>
          </td>
          <td><code><?= htmlspecialchars($cat['slug']) ?></code></td>
          <td>
            <span class="badge"><?= $cat['article_count'] ?> artigo<?= $cat['article_count'] != 1 ? 's' : '' ?></span>
          </td>
          <td><?= $cat['sort_order'] ?></td>
          <td>
            <div class="action-btns">
              <a href="/admin/category-form.php?id=<?= $cat['id'] ?>"
                 class="btn btn-xs btn-outline">Editar</a>
              <form method="POST" style="display:inline"
                    onsubmit="return confirm('Excluir \"<?= htmlspecialchars(addslashes($cat['title'])) ?>\"?\nOs artigos vinculados perderão a categoria.')">
                <input type="hidden" name="_action" value="delete"/>
                <input type="hidden" name="id" value="<?= $cat['id'] ?>"/>
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
      fetch("/admin/categories.php", {
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
