<?php
/** @var array|null $article */
/** @var array $categories */
?>
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet"/>
<div class="library-page" style="max-width:960px">
    <div class="page-actions">
        <a href="<?= url('wiki/articles') ?>" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Voltar</a>
    </div>
    <div class="card"><div class="card-body">
        <form method="POST" id="wikiArticleForm" action="<?= $article ? url('wiki/articles/' . $article['id'] . '/edit') : url('wiki/articles/create') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="content" id="contentField" value="<?= e($article['content'] ?? '') ?>">
            <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px;margin-bottom:12px">
                <div>
                    <label style="font-size:12px;font-weight:600">Título *</label>
                    <input name="title" id="titleInput" class="form-control" required value="<?= e($article['title'] ?? '') ?>" placeholder="Título do artigo" oninput="document.getElementById('slugField').value=this.value.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-z0-9\s-]/g,'').trim().replace(/[\s-]+/g,'-')">
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600">Slug (URL)</label>
                    <input name="slug" id="slugField" class="form-control" value="<?= e($article['slug'] ?? '') ?>" placeholder="titulo-do-artigo">
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 100px 150px 120px;gap:12px;margin-bottom:12px">
                <div>
                    <label style="font-size:12px;font-weight:600">Categoria</label>
                    <select name="category_id" class="form-control">
                        <option value="">— Sem categoria —</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= ($article['category_id'] ?? '') == $c['id'] ? 'selected' : '' ?>><?= e($c['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600">Ordem</label>
                    <input type="number" name="sort_order" class="form-control" min="0" value="<?= e($article['sort_order'] ?? '0') ?>">
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600">Publicação</label>
                    <input type="date" name="published_at" class="form-control" value="<?= e($article['published_at'] ?? date('Y-m-d')) ?>">
                </div>
                <div style="align-self:end">
                    <label style="font-size:13px;display:flex;gap:6px;align-items:center"><input type="checkbox" name="featured" value="1" <?= !empty($article['featured']) ? 'checked' : '' ?>> Destaque</label>
                </div>
            </div>
            <div style="margin-bottom:12px">
                <label style="font-size:12px;font-weight:600">Descrição curta</label>
                <textarea name="description" class="form-control" rows="2" placeholder="Resumo exibido nas listagens"><?= e($article['description'] ?? '') ?></textarea>
            </div>
            <div style="margin-bottom:12px">
                <label style="font-size:12px;font-weight:600">Imagem de capa</label>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <input type="file" id="coverFile" accept="image/*" style="font-size:13px">
                    <input type="hidden" name="cover_image" id="coverImageField" value="<?= e($article['cover_image'] ?? '') ?>">
                    <small id="coverStatus" style="color:var(--text-muted)"><?= !empty($article['cover_image']) ? 'Capa atual: ' . e($article['cover_image']) : 'JPG/PNG/WEBP — máx. 10 MB' ?></small>
                </div>
                <?php if (!empty($article['cover_image'])): ?>
                    <img src="<?= e($article['cover_image']) ?>" style="max-width:100%;max-height:160px;border-radius:8px;margin-top:8px" alt="">
                <?php endif; ?>
                <img id="coverPreview" style="max-width:100%;max-height:160px;border-radius:8px;margin-top:8px;display:none" alt="">
            </div>
            <div style="margin-bottom:12px">
                <label style="font-size:12px;font-weight:600">Conteúdo do artigo</label>
                <div id="quillEditor" style="background:#fff;border:1px solid var(--border-color);border-radius:8px;min-height:360px"></div>
            </div>
            <div style="display:flex;gap:8px">
                <button class="btn btn-primary"><i class="fas fa-check"></i> <?= $article ? 'Salvar alterações' : 'Criar artigo' ?></button>
                <a href="<?= url('wiki/articles') ?>" class="btn btn-outline">Cancelar</a>
            </div>
        </form>
    </div></div>
</div>
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
<script>
(function(){
  var quill = new Quill('#quillEditor', {
    theme: 'snow',
    modules: { toolbar: [[{header:[1,2,3,false]}],[{size:[]} ],['bold','italic','underline','strike'],[{color:[]},{background:[]}],[{align:[]}],[{list:'ordered'},{list:'bullet'}],['blockquote','code-block'],['link','image'],['clean']] },
    placeholder: 'Escreva o conteúdo do artigo aqui…'
  });
  var existing = document.getElementById('contentField').value;
  if (existing) quill.root.innerHTML = existing;
  quill.getModule('toolbar').addHandler('image', function(){
    var input = document.createElement('input');
    input.type = 'file'; input.accept = 'image/*'; input.click();
    input.onchange = async () => {
      var file = input.files[0]; if (!file) return;
      var form = new FormData(); form.append('file', file);
      try {
        var res = await fetch('<?= url('api/wiki/upload') ?>', { method: 'POST', body: form, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        var data = await res.json();
        if (data.url) {
          var range = quill.getSelection(true);
          quill.insertEmbed(range.index, 'image', data.url);
        } else { alert(data.error || 'Erro ao fazer upload.'); }
      } catch(e) { alert('Falha na conexão ao fazer upload.'); }
    };
  });
  document.getElementById('wikiArticleForm').addEventListener('submit', function(){
    document.getElementById('contentField').value = quill.root.innerHTML;
  });
  document.getElementById('coverFile').addEventListener('change', async function(){
    var file = this.files[0]; if (!file) return;
    var form = new FormData(); form.append('file', file);
    var st = document.getElementById('coverStatus'); st.textContent = 'Enviando…';
    try {
      var res = await fetch('<?= url('api/wiki/upload') ?>', { method: 'POST', body: form, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      var data = await res.json();
      if (data.url) {
        document.getElementById('coverImageField').value = data.url;
        var pv = document.getElementById('coverPreview'); pv.src = data.url; pv.style.display = 'block';
        st.textContent = 'Enviada: ' + data.url;
      } else { st.textContent = data.error || 'Erro ao fazer upload.'; }
    } catch(e) { st.textContent = 'Falha na conexão ao fazer upload.'; }
  });
})();
</script>
