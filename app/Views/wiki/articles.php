<?php
/** @var array $articles */
/** @var array $categories */
/** @var array $filters */
/** @var string $portalUrl */
?>
<div class="library-page">
    <div class="page-actions">
        <a href="<?= url('wiki') ?>" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Wiki</a>
        <a href="<?= url('wiki/articles/create') ?>" class="btn btn-primary"><i class="fas fa-plus"></i> Novo artigo</a>
    </div>

    <form method="GET" action="<?= url('wiki/articles') ?>" class="card" style="margin-bottom:12px">
        <div class="card-body" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <input name="q" class="form-control" style="max-width:280px" placeholder="Buscar por título…" value="<?= e($filters['q'] ?? '') ?>">
            <select name="cat" class="form-control" style="max-width:220px">
                <option value="">Todas as categorias</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= e($c['slug']) ?>" <?= ($filters['category_slug'] ?? '') === $c['slug'] ? 'selected' : '' ?>><?= e($c['title']) ?></option>
                <?php endforeach; ?>
            </select>
            <label style="font-size:13px;display:flex;gap:6px;align-items:center"><input type="checkbox" name="featured" value="1" <?= !empty($filters['featured']) ? 'checked' : '' ?>> Só destaques</label>
            <button class="btn btn-outline"><i class="fas fa-filter"></i> Filtrar</button>
            <?php if (!empty($filters['q']) || !empty($filters['category_slug']) || !empty($filters['featured'])): ?>
                <a href="<?= url('wiki/articles') ?>" class="btn btn-outline">✕ Limpar</a>
            <?php endif; ?>
        </div>
    </form>

    <div class="card"><div class="card-body" style="padding:0">
        <?php if (empty($articles)): ?>
            <div style="text-align:center;padding:40px;color:var(--text-muted)">Nenhum artigo. <a href="<?= url('wiki/articles/create') ?>">Criar o primeiro</a>.</div>
        <?php else: ?>
            <table class="table" style="margin:0">
                <thead><tr><th width="36"></th><th>Artigo</th><th>Categoria</th><th>Destaque</th><th>Link do cliente</th><th width="150">Ações</th></tr></thead>
                <tbody id="wikiArtBody">
                    <?php foreach ($articles as $a): ?>
                        <?php $clientUrl = rtrim($portalUrl, '/') . '#article/' . $a['slug']; ?>
                        <tr data-id="<?= $a['id'] ?>">
                            <td class="drag-handle" style="cursor:grab">⠿</td>
                            <td><strong><?= e($a['title']) ?></strong><br><small style="color:var(--text-muted)"><?= e(mb_substr($a['description'] ?? '', 0, 80)) ?></small></td>
                            <td><?= $a['category_title'] ? '<span style="background:rgba(99,102,241,.12);color:var(--primary);padding:2px 10px;border-radius:10px;font-size:11px;font-weight:700">' . e($a['category_title']) . '</span>' : '—' ?></td>
                            <td><?= !empty($a['featured']) ? '★ Sim' : 'Não' ?></td>
                            <td>
                                <div style="display:flex;gap:4px;align-items:center">
                                    <a href="<?= e($clientUrl) ?>" target="_blank" class="btn btn-sm btn-outline" title="Abrir link do cliente"><i class="fas fa-external-link-alt"></i></a>
                                    <button class="btn btn-sm btn-outline" onclick="navigator.clipboard.writeText('<?= e($clientUrl) ?>');this.innerHTML='<i class=&quot;fas fa-check&quot;></i>'" title="Copiar link do cliente"><i class="fas fa-copy"></i></button>
                                </div>
                            </td>
                            <td>
                                <a href="<?= url('wiki/articles/' . $a['id'] . '/edit') ?>" class="btn btn-sm btn-outline"><i class="fas fa-edit"></i> Editar</a>
                                <form method="POST" action="<?= url('wiki/articles/' . $a['id'] . '/delete') ?>" style="display:inline" data-confirm="Excluir &quot;<?= e(addslashes($a['title'])) ?>&quot;?">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-sm btn-outline" style="color:var(--danger)"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div></div>
</div>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
(function(){
  var tbody = document.getElementById('wikiArtBody');
  if (!tbody) return;
  Sortable.create(tbody, { handle: '.drag-handle', animation: 150, onEnd: function(){
    var order = [...tbody.querySelectorAll('tr[data-id]')].map(r => r.dataset.id);
    var fd = new FormData();
    fd.append('_csrf_token', document.querySelector('meta[name="csrf-token"]').content);
    fd.append('order', JSON.stringify(order));
    fetch('<?= url('wiki/articles/reorder') ?>', { method: 'POST', body: fd });
  }});
})();
</script>
