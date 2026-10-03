<?php /** @var array|null $category */ ?>
<div class="library-page" style="max-width:760px">
    <div class="page-actions">
        <a href="<?= url('wiki/categories') ?>" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Voltar</a>
    </div>
    <div class="card"><div class="card-body">
        <form method="POST" action="<?= $category ? url('wiki/categories/' . $category['id'] . '/edit') : url('wiki/categories/create') ?>">
            <?= csrf_field() ?>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
                <div>
                    <label style="font-size:12px;font-weight:600">Título *</label>
                    <input name="title" class="form-control" required value="<?= e($category['title'] ?? '') ?>" placeholder="Ex: Comece aqui" oninput="document.getElementById('slugField').value=this.value.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-z0-9\s-]/g,'').trim().replace(/[\s-]+/g,'-')">
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600">Slug (URL)</label>
                    <input name="slug" id="slugField" class="form-control" value="<?= e($category['slug'] ?? '') ?>" placeholder="comece-aqui">
                </div>
            </div>
            <div style="margin-bottom:12px">
                <label style="font-size:12px;font-weight:600">Descrição</label>
                <textarea name="description" class="form-control" rows="2" placeholder="Breve descrição"><?= e($category['description'] ?? '') ?></textarea>
            </div>
            <div style="display:grid;grid-template-columns:1fr 160px;gap:12px;margin-bottom:12px">
                <div>
                    <label style="font-size:12px;font-weight:600">Ícone SVG</label>
                    <textarea name="icon_svg" id="iconSvg" class="form-control" rows="4" placeholder='<svg viewBox="0 0 24 24">…'></textarea>
                    <small style="color:var(--text-muted)">Cole o SVG (ex: lucide.dev). No portal, o fundo é colorido.</small>
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600">Preview / Ordem</label>
                    <div id="iconPreview" style="width:56px;height:56px;border-radius:12px;background:var(--primary);display:flex;align-items:center;justify-content:center;margin-bottom:8px;overflow:hidden"><?= $category['icon_svg'] ?? '' ?></div>
                    <input type="number" name="sort_order" class="form-control" min="0" value="<?= e($category['sort_order'] ?? '0') ?>">
                </div>
            </div>
            <div style="display:flex;gap:8px">
                <button class="btn btn-primary"><i class="fas fa-check"></i> <?= $category ? 'Salvar' : 'Criar categoria' ?></button>
                <a href="<?= url('wiki/categories') ?>" class="btn btn-outline">Cancelar</a>
            </div>
        </form>
    </div></div>
</div>
<script>
document.getElementById('iconSvg')?.addEventListener('input', function(){
  document.getElementById('iconPreview').innerHTML = this.value;
});
</script>
