<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
requireLogin();

$db       = getDB();
$cat      = null;
$errors   = [];

// Carregar categoria existente
if (!empty($_GET['id'])) {
    $stmt = $db->prepare('SELECT * FROM categories WHERE id = ?');
    $stmt->execute([(int)$_GET['id']]);
    $cat = $stmt->fetch();
    if (!$cat) { header('Location: /admin/categories.php'); exit; }
}

// Salvar
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title  = trim($_POST['title']   ?? '');
    $slug   = trim($_POST['slug']    ?? '');
    $desc   = trim($_POST['desc']    ?? '');
    $icon   = trim($_POST['icon_svg']?? '');
    $order  = (int)($_POST['sort_order'] ?? 0);

    if (!$title) $errors[] = 'Título obrigatório.';
    if (!$slug)  $slug = slugify($title);

    // Garante slug único
    $check = $db->prepare('SELECT id FROM categories WHERE slug = ? AND id != ?');
    $check->execute([$slug, $cat['id'] ?? 0]);
    if ($check->fetch()) $slug .= '-' . time();

    if (empty($errors)) {
        if ($cat) {
            $db->prepare('UPDATE categories SET title=?, slug=?, description=?, icon_svg=?, sort_order=? WHERE id=?')
               ->execute([$title, $slug, $desc, $icon, $order, $cat['id']]);
        } else {
            $db->prepare('INSERT INTO categories (title, slug, description, icon_svg, sort_order) VALUES (?,?,?,?,?)')
               ->execute([$title, $slug, $desc, $icon, $order]);
        }
        header('Location: /admin/categories.php?saved=1'); exit;
    }
}

$pageTitle = $cat ? 'Editar categoria' : 'Nova categoria';
require '_header.php';
?>

<div class="panel" style="max-width:760px">
  <div class="panel-header">
    <h2 class="panel-title"><?= $pageTitle ?></h2>
    <a href="/admin/categories.php" class="btn btn-sm btn-outline">← Voltar</a>
  </div>

  <?php foreach ($errors as $e): ?>
    <div class="alert alert-error"><?= htmlspecialchars($e) ?></div>
  <?php endforeach; ?>

  <form method="POST" class="form-stack">
    <div class="form-row">
      <div class="form-group">
        <label>Título <span class="required">*</span></label>
        <input type="text" name="title" value="<?= htmlspecialchars($cat['title'] ?? $_POST['title'] ?? '') ?>"
               placeholder="Ex: Comece aqui" required oninput="autoSlug(this.value)"/>
      </div>
      <div class="form-group">
        <label>Slug (URL)</label>
        <input type="text" name="slug" id="slugField"
               value="<?= htmlspecialchars($cat['slug'] ?? $_POST['slug'] ?? '') ?>"
               placeholder="comece-aqui"/>
        <small class="form-hint">Gerado automaticamente. Usado na URL do site.</small>
      </div>
    </div>

    <div class="form-group">
      <label>Descrição</label>
      <textarea name="desc" rows="2" placeholder="Breve descrição da categoria"><?= htmlspecialchars($cat['description'] ?? $_POST['desc'] ?? '') ?></textarea>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Ícone SVG</label>
        <textarea name="icon_svg" id="iconSvg" rows="4"
                  placeholder='<svg viewBox="0 0 24 24" ...>...</svg>'><?= htmlspecialchars($cat['icon_svg'] ?? $_POST['icon_svg'] ?? '') ?></textarea>
        <small class="form-hint">Cole o SVG do ícone (ex: do <a href="https://lucide.dev" target="_blank">lucide.dev</a>).
          Troque <code>stroke="currentColor"</code> por <code>stroke="white"</code>.</small>
      </div>
      <div class="form-group" style="max-width:200px">
        <label>Pré-visualização</label>
        <div class="icon-preview" id="iconPreview">
          <?= $cat['icon_svg'] ?? '' ?>
        </div>
        <label style="margin-top:16px">Ordem de exibição</label>
        <input type="number" name="sort_order" min="0"
               value="<?= htmlspecialchars($cat['sort_order'] ?? $_POST['sort_order'] ?? '0') ?>"/>
      </div>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary">
        <?= $cat ? 'Salvar alterações' : 'Criar categoria' ?>
      </button>
      <a href="/admin/categories.php" class="btn btn-outline">Cancelar</a>
    </div>
  </form>
</div>

<?php
$extraScripts = <<<'HTML'
<script>
function autoSlug(val) {
  const field = document.getElementById('slugField');
  if (field.dataset.edited) return; // não sobrescreve se o usuário editou manualmente
  field.value = val.toLowerCase()
    .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9\s-]/g, '')
    .trim().replace(/[\s-]+/g, '-');
}
document.getElementById('slugField')?.addEventListener('input', () => {
  document.getElementById('slugField').dataset.edited = '1';
});
document.getElementById('iconSvg')?.addEventListener('input', function() {
  document.getElementById('iconPreview').innerHTML = this.value;
});
</script>
HTML;
require '_footer.php';
?>
