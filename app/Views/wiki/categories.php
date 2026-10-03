<?php
/** @var array $categories */
/** @var string $apiBaseUrl */
/** @var string $apiKey */
?>
<div class="library-page">
    <div class="page-actions">
        <a href="<?= url('wiki') ?>" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Wiki</a>
        <a href="<?= url('wiki/categories/create') ?>" class="btn btn-primary"><i class="fas fa-plus"></i> Nova categoria</a>
    </div>

    <div class="card">
        <div class="card-body" style="padding:0">
            <?php if (empty($categories)): ?>
                <div style="text-align:center;padding:40px;color:var(--text-muted)">
                    <i class="fas fa-folder-open fa-2x" style="opacity:.3;display:block;margin-bottom:8px"></i>
                    Nenhuma categoria cadastrada. <a href="<?= url('wiki/categories/create') ?>">Criar a primeira</a>.
                </div>
            <?php else: ?>
                <table class="table" style="margin:0">
                    <thead><tr><th width="36"></th><th>Categoria</th><th>Slug</th><th>Artigos</th><th>Ordem</th><th width="150">Ações</th></tr></thead>
                    <tbody id="wikiCatBody">
                        <?php foreach ($categories as $c): ?>
                            <tr data-id="<?= $c['id'] ?>">
                                <td class="drag-handle" style="cursor:grab" title="Arrastar">⠿</td>
                                <td><strong><?= e($c['title']) ?></strong><br><small style="color:var(--text-muted)"><?= e($c['description'] ?? '') ?></small></td>
                                <td><code><?= e($c['slug']) ?></code></td>
                                <td><span class="tag-pill" style="background:rgba(99,102,241,.12);color:var(--primary);padding:2px 10px;border-radius:10px;font-size:11px;font-weight:700"><?= (int)($c['article_count'] ?? 0) ?></span></td>
                                <td><?= (int)$c['sort_order'] ?></td>
                                <td>
                                    <a href="<?= url('wiki/categories/' . $c['id'] . '/edit') ?>" class="btn btn-sm btn-outline"><i class="fas fa-edit"></i></a>
                                    <form method="POST" action="<?= url('wiki/categories/' . $c['id'] . '/delete') ?>" style="display:inline" data-confirm="Excluir &quot;<?= e(addslashes($c['title'])) ?>&quot;? Os artigos ficarão sem categoria.">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-outline" style="color:var(--danger)"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
(function(){
  var tbody = document.getElementById('wikiCatBody');
  if (!tbody) return;
  Sortable.create(tbody, { handle: '.drag-handle', animation: 150, onEnd: function(){
    var order = [...tbody.querySelectorAll('tr[data-id]')].map(r => r.dataset.id);
    var fd = new FormData();
    fd.append('_csrf_token', document.querySelector('meta[name="csrf-token"]').content);
    fd.append('order', JSON.stringify(order));
    fetch('<?= url('wiki/categories/reorder') ?>', { method: 'POST', body: fd });
  }});
})();
</script>
