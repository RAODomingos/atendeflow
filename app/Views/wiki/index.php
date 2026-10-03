<?php
/** @var array $categories */
/** @var array $articles */
/** @var int $totalArticles */
/** @var string $apiBaseUrl */
/** @var string $apiKey */
/** @var string $portalUrl */
?>
<div class="library-page">
    <div class="page-actions">
        <a href="<?= url('wiki/articles/create') ?>" class="btn btn-primary"><i class="fas fa-plus"></i> Novo artigo</a>
        <a href="<?= url('wiki/categories/create') ?>" class="btn btn-outline"><i class="fas fa-folder-plus"></i> Nova categoria</a>
        <a href="<?= url('wiki/settings') ?>" class="btn btn-outline"><i class="fas fa-plug"></i> Integração</a>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px">
        <div class="card"><div class="card-body" style="text-align:center">
            <div style="font-size:28px;font-weight:800"><?= count($categories) ?></div>
            <div style="font-size:12px;color:var(--text-muted)">categorias</div>
        </div></div>
        <div class="card"><div class="card-body" style="text-align:center">
            <div style="font-size:28px;font-weight:800"><?= $totalArticles ?></div>
            <div style="font-size:12px;color:var(--text-muted)">artigos</div>
        </div></div>
        <div class="card"><div class="card-body" style="text-align:center">
            <div style="font-size:28px;font-weight:800"><?= count(array_filter($articles, fn($a) => !empty($a['featured']))) ?></div>
            <div style="font-size:12px;color:var(--text-muted)">em destaque</div>
        </div></div>
    </div>

    <div class="card" style="margin-bottom:16px">
        <div class="card-header"><h3><i class="fas fa-globe" style="color:var(--primary)"></i> Portal do cliente — onde a Wiki está hospedada</h3>
            <a href="<?= url('wiki/settings') ?>" class="btn btn-sm btn-outline">Alterar</a></div>
        <div class="card-body">
            <div style="display:flex;gap:8px">
                <input class="form-control" readonly value="<?= e($portalUrl) ?>" onclick="this.select()" style="font-family:monospace;font-size:12px">
                <button class="btn btn-outline" onclick="navigator.clipboard.writeText('<?= e($portalUrl) ?>');this.innerHTML='<i class=&quot;fas fa-check&quot;></i>'" title="Copiar link do portal"><i class="fas fa-copy"></i></button>
                <a class="btn btn-outline" href="<?= e($portalUrl) ?>" target="_blank" title="Abrir portal"><i class="fas fa-external-link-alt"></i></a>
            </div>
            <p style="margin:8px 0 0;font-size:12px;color:var(--text-muted)">É este endereço que vai nos links enviados pelo chat. O link de cada artigo é <code><?= e($portalUrl) ?>#article/{slug}</code>.</p>
        </div>
    </div>

    <div class="card" style="margin-bottom:16px">
        <div class="card-header"><h3><i class="fas fa-plug" style="color:var(--primary)"></i> Front-end externo — configuração atual</h3></div>
        <div class="card-body" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div>
                <label style="font-size:12px;font-weight:600;color:var(--secondary)">URL base da API (WIKI_API_URL)</label>
                <div style="display:flex;gap:8px;margin-top:4px">
                    <input class="form-control" readonly value="<?= e($apiBaseUrl) ?>" onclick="this.select()" style="font-family:monospace;font-size:12px">
                    <button class="btn btn-outline" onclick="navigator.clipboard.writeText('<?= e($apiBaseUrl) ?>');this.innerHTML='<i class=&quot;fas fa-check&quot;></i>'"><i class="fas fa-copy"></i></button>
                </div>
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:var(--secondary)">Chave (WIKI_API_KEY)</label>
                <div style="display:flex;gap:8px;margin-top:4px">
                    <input class="form-control" readonly type="password" id="wikiKeyPreview" value="<?= e($apiKey) ?>" onclick="this.select()" style="font-family:monospace;font-size:12px">
                    <button class="btn btn-outline" onclick="var i=document.getElementById('wikiKeyPreview');i.type=i.type==='password'?'text':'password'"><i class="fas fa-eye"></i></button>
                    <a href="<?= url('wiki/settings') ?>" class="btn btn-outline"><i class="fas fa-cog"></i></a>
                </div>
            </div>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-folder" style="color:var(--warning)"></i> Categorias</h3>
                <a href="<?= url('wiki/categories') ?>" class="btn btn-sm btn-outline">Ver todas</a></div>
            <div class="card-body" style="display:flex;flex-direction:column;gap:6px;max-height:320px;overflow-y:auto">
                <?php if (empty($categories)): ?>
                    <p style="color:var(--text-muted);font-size:13px">Nenhuma categoria. <a href="<?= url('wiki/categories/create') ?>">Criar a primeira</a>.</p>
                <?php else: ?>
                    <?php foreach (array_slice($categories, 0, 10) as $c): ?>
                        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 12px;border:1px solid var(--border-color);border-radius:8px">
                            <div><strong style="font-size:13px"><?= e($c['title']) ?></strong>
                                <span style="font-size:11px;color:var(--text-muted)"> · <?= (int)($c['article_count'] ?? 0) ?> artigos</span></div>
                            <code style="font-size:11px"><?= e($c['slug']) ?></code>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-file-alt" style="color:var(--primary)"></i> Últimos artigos</h3>
                <a href="<?= url('wiki/articles') ?>" class="btn btn-sm btn-outline">Ver todos</a></div>
            <div class="card-body" style="display:flex;flex-direction:column;gap:6px;max-height:320px;overflow-y:auto">
                <?php if (empty($articles)): ?>
                    <p style="color:var(--text-muted);font-size:13px">Nenhum artigo. <a href="<?= url('wiki/articles/create') ?>">Criar o primeiro</a>.</p>
                <?php else: ?>
                    <?php foreach (array_slice($articles, 0, 10) as $a): ?>
                        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 12px;border:1px solid var(--border-color);border-radius:8px;gap:8px">
                            <div style="min-width:0"><div style="font-weight:600;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($a['title']) ?></div>
                                <div style="font-size:11px;color:var(--text-muted)"><?= e($a['category_title'] ?? 'Sem categoria') ?><?= !empty($a['featured']) ? ' · ★ destaque' : '' ?></div></div>
                            <a href="<?= url('wiki/articles/' . $a['id'] . '/edit') ?>" class="btn btn-sm btn-outline"><i class="fas fa-edit"></i></a>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
