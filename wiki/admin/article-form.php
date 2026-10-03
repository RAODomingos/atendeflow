<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
requireLogin();

$db      = getDB();
$article = null;
$errors  = [];
$cats    = $db->query('SELECT id, slug, title FROM categories ORDER BY sort_order ASC')->fetchAll();

// Carregar artigo existente
if (!empty($_GET['slug'])) {
    $stmt = $db->prepare('SELECT * FROM articles WHERE slug = ?');
    $stmt->execute([trim($_GET['slug'])]);
    $article = $stmt->fetch();
    if (!$article) { header('Location: /admin/articles.php'); exit; }
}

// Salvar
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title    = trim($_POST['title']       ?? '');
    $slug     = trim($_POST['slug']        ?? '');
    $desc     = trim($_POST['description'] ?? '');
    $content  = $_POST['content']          ?? '';   // HTML do Quill (não escapar)
    $catId    = (int)($_POST['category_id']?? 0);
    $featured = (int)(!empty($_POST['featured']));
    $order    = (int)($_POST['sort_order'] ?? 0);
    $pubDate  = trim($_POST['published_at']?? date('Y-m-d'));
    $cover    = trim($_POST['cover_image'] ?? '');

    if (!$title) $errors[] = 'Título obrigatório.';
    if (!$slug)  $slug = slugify($title);

    // Garante slug único
    $check = $db->prepare('SELECT id FROM articles WHERE slug = ? AND id != ?');
    $check->execute([$slug, $article['id'] ?? 0]);
    if ($check->fetch()) $slug .= '-' . time();

    if (empty($errors)) {
        if ($article) {
            $db->prepare('UPDATE articles SET title=?,slug=?,description=?,content=?,category_id=?,
                          featured=?,sort_order=?,published_at=?,cover_image=? WHERE id=?')
               ->execute([$title,$slug,$desc,$content,$catId,$featured,$order,$pubDate,$cover,$article['id']]);
        } else {
            $db->prepare('INSERT INTO articles
                          (title,slug,description,content,category_id,featured,sort_order,published_at,cover_image)
                          VALUES (?,?,?,?,?,?,?,?,?)')
               ->execute([$title,$slug,$desc,$content,$catId,$featured,$order,$pubDate,$cover]);
        }
        header('Location: /admin/articles.php?saved=1'); exit;
    }
}

$pageTitle = $article ? 'Editar artigo' : 'Novo artigo';

// Quill CSS + JS no <head>
$extraHead = '
<!-- Quill 2 Snow -->
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet"/>
<!-- quill-table-better -->
<link href="https://cdn.jsdelivr.net/npm/quill-table-better@1/dist/quill-table-better.css" rel="stylesheet"/>
<style>
  /* ── Editor wrapper ─────────────────────────────────────────── */
  .editor-wrapper {
    border: 1px solid #d4e0d4;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
  }

  /* ── Toolbar principal (Quill Snow) ─────────────────────────── */
  .ql-toolbar.ql-snow {
    background: #f4f8f4;
    border: none !important;
    border-bottom: 1px solid #d4e0d4 !important;
    padding: 6px 8px;
    display: flex;
    flex-wrap: wrap;
    gap: 2px;
    align-items: center;
  }
  .ql-toolbar.ql-snow .ql-formats {
    margin-right: 6px;
    display: flex;
    align-items: center;
    gap: 1px;
  }
  .ql-toolbar.ql-snow button,
  .ql-toolbar.ql-snow .ql-picker-label {
    border-radius: 5px;
    transition: background .15s;
  }
  .ql-toolbar.ql-snow button:hover,
  .ql-toolbar.ql-snow .ql-picker-label:hover,
  .ql-toolbar.ql-snow button.ql-active,
  .ql-toolbar.ql-snow .ql-picker-label.ql-active {
    background: #ddefdd !important;
    color: #1a6b1a !important;
  }
  .ql-toolbar.ql-snow .ql-picker { color: #3d4a3f; }
  .ql-toolbar.ql-snow .ql-picker-options { border-radius: 6px; box-shadow: 0 4px 16px rgba(0,0,0,.12); }

  /* ── Container do editor ─────────────────────────────────────── */
  .ql-container.ql-snow {
    border: none !important;
    font-family: inherit;
    font-size: 15px;
  }
  .ql-editor {
    min-height: 360px;
    padding: 20px 24px;
    line-height: 1.7;
    color: #2a2a2a;
  }
  .ql-editor:focus { outline: none; }

  /* Placeholder */
  .ql-editor.ql-blank::before {
    color: #aab8aa;
    font-style: normal;
    left: 24px;
  }

  /* ── Toolbar secundária (vídeo + contagem) ───────────────────── */
  .editor-footer {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    background: #f4f8f4;
    border-top: 1px solid #d4e0d4;
    flex-wrap: wrap;
  }
  .editor-footer .video-embed-bar {
    display: flex;
    align-items: center;
    gap: 6px;
    flex: 1;
    min-width: 260px;
  }
  .editor-footer .video-embed-bar input {
    flex: 1;
    font-size: 13px;
    padding: 4px 8px;
    border: 1px solid #c8d8c8;
    border-radius: 6px;
    background: #fff;
    color: #3d4a3f;
  }
  .editor-footer .video-embed-bar input:focus { outline: 2px solid #5aaa5a; outline-offset: 1px; }
  .word-count {
    font-size: 12px;
    color: #7a8a7a;
    white-space: nowrap;
    margin-left: auto;
  }

  /* ── Estilos dentro do editor ─────────────────────────────────── */
  .ql-editor table {
    border-collapse: collapse;
    width: 100%;
    margin: 12px 0;
  }
  .ql-editor table td,
  .ql-editor table th {
    border: 1px solid #c8d8c8;
    padding: 8px 12px;
    min-width: 60px;
  }
  .ql-editor table th {
    background: #eef5ee;
    font-weight: 600;
  }
  .ql-editor blockquote {
    border-left: 4px solid #5aaa5a;
    padding: 10px 16px;
    margin: 16px 0;
    background: #f4faf4;
    border-radius: 0 6px 6px 0;
    color: #3a5a3a;
  }
  .ql-editor pre.ql-syntax {
    background: #1e1e2e;
    color: #cdd6f4;
    border-radius: 8px;
    padding: 16px;
    font-size: 13px;
    overflow-x: auto;
  }
  .ql-editor img {
    max-width: 100%;
    border-radius: 6px;
    cursor: pointer;
  }
  .ql-editor .ql-video {
    display: block;
    width: 100%;
    aspect-ratio: 16/9;
    border-radius: 8px;
    margin: 12px 0;
  }

  /* ── Preview da capa ─────────────────────────────────────────── */
  #coverPreview { max-width:100%; max-height:160px; border-radius:8px; margin-top:8px; display:none; }

  /* ── Picker de tamanho de fonte custom ───────────────────────── */
  .ql-snow .ql-picker.ql-size .ql-picker-label::before,
  .ql-snow .ql-picker.ql-size .ql-picker-item::before { content: attr(data-value) !important; }
  .ql-snow .ql-picker.ql-size .ql-picker-label[data-value=""]::before,
  .ql-snow .ql-picker.ql-size .ql-picker-item[data-value=""]::before { content: "Normal" !important; }

  /* ── Botão "Inserir tabela" custom ───────────────────────────── */
  .ql-table-insert { font-size: 12px; font-weight: 600; padding: 0 6px; height: 24px; }
  .ql-table-insert:hover { color: #1a6b1a !important; }
</style>';

require '_header.php';
?>

<div class="panel" style="max-width:960px">
  <div class="panel-header">
    <h2 class="panel-title"><?= $pageTitle ?></h2>
    <a href="/admin/articles.php" class="btn btn-sm btn-outline">← Voltar</a>
  </div>

  <?php foreach ($errors as $e): ?>
    <div class="alert alert-error"><?= htmlspecialchars($e) ?></div>
  <?php endforeach; ?>

  <form method="POST" id="articleForm" class="form-stack">
    <!-- Campo oculto onde o Quill vai salvar o HTML -->
    <input type="hidden" name="content" id="contentField"
           value="<?= htmlspecialchars($article['content'] ?? '') ?>"/>

    <!-- ── Linha 1: Título + Slug ── -->
    <div class="form-row">
      <div class="form-group" style="flex:2">
        <label>Título <span class="required">*</span></label>
        <input type="text" name="title" id="titleInput"
               value="<?= htmlspecialchars($article['title'] ?? $_POST['title'] ?? '') ?>"
               placeholder="Título do artigo" required oninput="autoSlug(this.value)"/>
      </div>
      <div class="form-group">
        <label>Slug (URL)</label>
        <input type="text" name="slug" id="slugField"
               value="<?= htmlspecialchars($article['slug'] ?? $_POST['slug'] ?? '') ?>"
               placeholder="titulo-do-artigo"/>
      </div>
    </div>

    <!-- ── Linha 2: Categoria + Destaque + Ordem + Data ── -->
    <div class="form-row">
      <div class="form-group">
        <label>Categoria</label>
        <select name="category_id">
          <option value="">— Sem categoria —</option>
          <?php foreach ($cats as $c): ?>
            <option value="<?= $c['id'] ?>"
              <?= ($article['category_id'] ?? $_POST['category_id'] ?? '') == $c['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($c['title']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="max-width:130px">
        <label>Ordem</label>
        <input type="number" name="sort_order" min="0"
               value="<?= htmlspecialchars($article['sort_order'] ?? $_POST['sort_order'] ?? '0') ?>"/>
      </div>
      <div class="form-group" style="max-width:160px">
        <label>Data de publicação</label>
        <input type="date" name="published_at"
               value="<?= htmlspecialchars($article['published_at'] ?? date('Y-m-d')) ?>"/>
      </div>
      <div class="form-group" style="max-width:120px;align-self:flex-end">
        <label class="checkbox-label">
          <input type="checkbox" name="featured" value="1"
                 <?= ($article['featured'] ?? 0) ? 'checked' : '' ?>/>
          Destaque
        </label>
      </div>
    </div>

    <!-- ── Descrição curta ── -->
    <div class="form-group">
      <label>Descrição curta</label>
      <textarea name="description" rows="2"
                placeholder="Resumo exibido nas listagens e buscas"><?= htmlspecialchars($article['description'] ?? $_POST['description'] ?? '') ?></textarea>
    </div>

    <!-- ── Imagem de capa ── -->
    <div class="form-group">
      <label>Imagem de capa</label>
      <div class="upload-zone" id="coverZone">
        <input type="file" id="coverFile" accept="image/*" style="display:none"/>
        <input type="hidden" name="cover_image" id="coverImageField"
               value="<?= htmlspecialchars($article['cover_image'] ?? '') ?>"/>
        <div class="upload-zone-inner" onclick="document.getElementById('coverFile').click()">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
          <span>Clique para enviar uma imagem</span>
          <small>JPG, PNG, WEBP — máx. 5 MB</small>
        </div>
        <img id="coverPreview" src="<?= htmlspecialchars($article['cover_image'] ?? '') ?>"
             alt="Preview" <?= empty($article['cover_image']) ? '' : 'style="display:block"' ?>/>
        <?php if (!empty($article['cover_image'])): ?>
          <button type="button" class="btn btn-xs btn-danger" onclick="clearCover()" style="margin-top:6px">Remover imagem</button>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Editor de conteúdo ── -->
    <div class="form-group">
      <label>Conteúdo do artigo</label>

      <div class="editor-wrapper">
        <!-- Toolbar gerada pelo Quill fica aqui automaticamente -->
        <div id="quillEditor"></div>

        <!-- Rodapé do editor: embed de vídeo + contagem de palavras -->
        <div class="editor-footer">
          <div class="video-embed-bar">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>
            <input type="text" id="videoUrl" placeholder="Link do YouTube ou Vimeo para inserir vídeo"/>
            <button type="button" class="btn btn-sm btn-outline" onclick="insertVideo()">Inserir vídeo</button>
          </div>
          <span class="word-count" id="wordCount">0 palavras</span>
        </div>
      </div>
    </div>

    <!-- ── Ações ── -->
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">
        <?= $article ? 'Salvar alterações' : 'Criar artigo' ?>
      </button>
      <?php if ($article): ?>
        <a href="/#article/<?= urlencode($article['slug']) ?>" target="_blank" class="btn btn-outline">Ver no site ↗</a>
      <?php endif; ?>
      <a href="/admin/articles.php" class="btn btn-outline">Cancelar</a>
    </div>
  </form>
</div>

<?php
$extraScripts = <<<'HTML'
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
<script src="https://cdn.jsdelivr.net/npm/quill-table-better@1/dist/quill-table-better.js"></script>
<script>
// ── Registrar tamanhos de fonte ───────────────────────────────────
const SizeStyle = Quill.import('attributors/style/size');
SizeStyle.whitelist = ['10px','12px','14px','16px','18px','20px','24px','28px','32px','36px','48px'];
Quill.register(SizeStyle, true);

// ── Registrar alinhamento ─────────────────────────────────────────
const AlignStyle = Quill.import('attributors/style/align');
Quill.register(AlignStyle, true);

// ── Registrar módulo de tabela ────────────────────────────────────
Quill.register({ 'modules/table-better': QuillTableBetter }, true);

// ── Inicializar Quill ─────────────────────────────────────────────
const quill = new Quill('#quillEditor', {
  theme: 'snow',
  modules: {
    toolbar: {
      container: [
        // Linha 1 — Estrutura de texto
        [{ header: [1, 2, 3, 4, false] }],
        [{ font: [] }],
        [{ size: ['10px','12px','14px','','18px','20px','24px','28px','32px','36px','48px'] }],

        // Linha 2 — Formatação inline
        ['bold', 'italic', 'underline', 'strike'],
        [{ script: 'sub' }, { script: 'super' }],
        [{ color: [] }, { background: [] }],

        // Linha 3 — Parágrafo
        [{ align: [] }],
        [{ indent: '-1' }, { indent: '+1' }],
        [{ list: 'ordered' }, { list: 'bullet' }, { list: 'check' }],

        // Linha 4 — Blocos especiais
        ['blockquote', 'code-block'],
        ['link', 'image', 'formula'],

        // Linha 5 — Tabela + limpeza
        [{ 'table-better': [] }],
        ['clean']
      ],
      handlers: {
        // Handler de imagem com upload
        image: function() {
          const input = document.createElement('input');
          input.type = 'file';
          input.accept = 'image/*';
          input.click();
          input.onchange = async () => {
            const file = input.files[0];
            if (!file) return;
            const form = new FormData();
            form.append('file', file);
            try {
              const res  = await fetch('/api/upload.php', { method: 'POST', body: form });
              const data = await res.json();
              if (data.url) {
                const range = quill.getSelection(true);
                quill.insertEmbed(range.index, 'image', data.url);
              } else {
                alert(data.error || 'Erro ao fazer upload.');
              }
            } catch(e) {
              alert('Falha na conexão ao fazer upload.');
            }
          };
        },

        // Handler de tabela — abre modal de inserção
        'table-better': function() {
          showTableModal();
        }
      }
    },

    'table-better': {
      toolbarTable: true
    },

    // Histórico ampliado
    history: { delay: 500, maxStack: 200, userOnly: true },

    // Keyboard: Tab para indent dentro de listas + table-better
    keyboard: {
      bindings: Object.assign({}, QuillTableBetter.keyboardBindings, {
        tab: {
          key: 9,
          handler: function(range) {
            if (quill.getFormat(range).list) {
              quill.format('indent', '+1');
            } else {
              quill.insertText(range.index, '    ');
              quill.setSelection(range.index + 4);
            }
            return false;
          }
        }
      })
    }
  },
  placeholder: 'Escreva o conteúdo do artigo aqui…'
});

// ── Traduzir dicas da toolbar ─────────────────────────────────────
const tooltips = {
  'ql-bold':        'Negrito (Ctrl+B)',
  'ql-italic':      'Itálico (Ctrl+I)',
  'ql-underline':   'Sublinhado (Ctrl+U)',
  'ql-strike':      'Tachado',
  'ql-blockquote':  'Citação',
  'ql-code-block':  'Bloco de código',
  'ql-link':        'Inserir link',
  'ql-image':       'Inserir imagem',
  'ql-formula':     'Fórmula matemática',
  'ql-clean':       'Limpar formatação',
};
document.querySelectorAll('.ql-toolbar button').forEach(btn => {
  const cls = [...btn.classList].find(c => c.startsWith('ql-') && c !== 'ql-picker');
  if (cls && tooltips[cls]) btn.title = tooltips[cls];
});

// ── Carregar conteúdo existente ───────────────────────────────────
const existing = document.getElementById('contentField').value;
if (existing) quill.root.innerHTML = existing;

// ── Sincronizar antes de salvar ───────────────────────────────────
document.getElementById('articleForm').addEventListener('submit', function() {
  document.getElementById('contentField').value = quill.root.innerHTML;
});

// ── Contagem de palavras ──────────────────────────────────────────
function updateWordCount() {
  const text = quill.getText().trim();
  const words = text ? text.split(/\s+/).filter(w => w.length > 0).length : 0;
  const chars = quill.getLength() - 1;
  document.getElementById('wordCount').textContent =
    words + ' palavra' + (words !== 1 ? 's' : '') + ' · ' + chars + ' caracteres';
}
quill.on('text-change', updateWordCount);
updateWordCount();

// ── Inserir vídeo YouTube / Vimeo ────────────────────────────────
function insertVideo() {
  const raw = document.getElementById('videoUrl').value.trim();
  if (!raw) return;
  let embedUrl = '';
  const ytMatch = raw.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/);
  if (ytMatch) embedUrl = 'https://www.youtube.com/embed/' + ytMatch[1];
  const vmMatch = raw.match(/vimeo\.com\/(\d+)/);
  if (vmMatch) embedUrl = 'https://player.vimeo.com/video/' + vmMatch[1];
  if (!embedUrl) { alert('Link inválido. Use YouTube ou Vimeo.'); return; }
  const range = quill.getSelection(true);
  quill.insertEmbed(range.index, 'video', embedUrl);
  quill.setSelection(range.index + 1);
  document.getElementById('videoUrl').value = '';
}

// ── Modal de inserção de tabela ───────────────────────────────────
function showTableModal() {
  // Cria overlay
  const overlay = document.createElement('div');
  overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;display:flex;align-items:center;justify-content:center';

  overlay.innerHTML = `
    <div style="background:#fff;border-radius:12px;padding:28px 32px;min-width:280px;box-shadow:0 8px 32px rgba(0,0,0,.2)">
      <h3 style="margin:0 0 18px;font-size:16px;color:#1e2e1e">Inserir tabela</h3>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px">
        <label style="font-size:13px;color:#4a5a4a">
          Linhas
          <input id="tblRows" type="number" min="1" max="30" value="3"
                 style="display:block;width:100%;margin-top:4px;padding:6px 10px;border:1px solid #c8d8c8;border-radius:6px;font-size:14px"/>
        </label>
        <label style="font-size:13px;color:#4a5a4a">
          Colunas
          <input id="tblCols" type="number" min="1" max="15" value="3"
                 style="display:block;width:100%;margin-top:4px;padding:6px 10px;border:1px solid #c8d8c8;border-radius:6px;font-size:14px"/>
        </label>
      </div>
      <label style="font-size:13px;color:#4a5a4a;display:flex;align-items:center;gap:6px;margin-bottom:20px;cursor:pointer">
        <input type="checkbox" id="tblHeader" checked style="width:14px;height:14px"/>
        Incluir linha de cabeçalho
      </label>
      <div style="display:flex;gap:10px;justify-content:flex-end">
        <button id="tblCancel" style="padding:8px 18px;border:1px solid #c8d8c8;border-radius:6px;background:#fff;cursor:pointer;font-size:14px">Cancelar</button>
        <button id="tblInsert" style="padding:8px 18px;border:none;border-radius:6px;background:#3a8a3a;color:#fff;cursor:pointer;font-size:14px;font-weight:600">Inserir</button>
      </div>
    </div>`;

  document.body.appendChild(overlay);
  document.getElementById('tblRows').focus();

  document.getElementById('tblCancel').onclick = () => overlay.remove();
  overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });

  document.getElementById('tblInsert').onclick = () => {
    const rows    = Math.max(1, parseInt(document.getElementById('tblRows').value) || 3);
    const cols    = Math.max(1, parseInt(document.getElementById('tblCols').value) || 3);
    const header  = document.getElementById('tblHeader').checked;
    overlay.remove();
    insertTable(rows, cols, header);
  };
}

function insertTable(rows, cols, withHeader) {
  const range = quill.getSelection(true) || { index: quill.getLength() };

  // Monta HTML da tabela
  let html = '<table><tbody>';
  const totalRows = withHeader ? rows + 1 : rows;
  for (let r = 0; r < totalRows; r++) {
    html += '<tr>';
    for (let c = 0; c < cols; c++) {
      const tag = (r === 0 && withHeader) ? 'th' : 'td';
      html += `<${tag}> </${tag}>`;
    }
    html += '</tr>';
  }
  html += '</tbody></table><p><br></p>';

  // Insere via clipboard (Quill aceita HTML via dangerouslyPasteHTML)
  quill.clipboard.dangerouslyPasteHTML(range.index, html);
  quill.setSelection(range.index + 1);
}

// ── Slug automático ───────────────────────────────────────────────
function autoSlug(val) {
  const field = document.getElementById('slugField');
  if (field.dataset.edited) return;
  field.value = val.toLowerCase()
    .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9\s-]/g, '').trim()
    .replace(/[\s-]+/g, '-');
}
document.getElementById('slugField').addEventListener('input', () => {
  document.getElementById('slugField').dataset.edited = '1';
});

// ── Upload de capa ────────────────────────────────────────────────
document.getElementById('coverFile').addEventListener('change', async function() {
  const file = this.files[0];
  if (!file) return;
  const form = new FormData();
  form.append('file', file);
  try {
    const res  = await fetch('/api/upload.php', { method: 'POST', body: form });
    const data = await res.json();
    if (data.url) {
      document.getElementById('coverImageField').value = data.url;
      const preview = document.getElementById('coverPreview');
      preview.src = data.url;
      preview.style.display = 'block';
    } else {
      alert(data.error || 'Erro ao fazer upload.');
    }
  } catch(e) {
    alert('Falha na conexão ao fazer upload.');
  }
});

function clearCover() {
  document.getElementById('coverImageField').value = '';
  const preview = document.getElementById('coverPreview');
  preview.src = ''; preview.style.display = 'none';
}
</script>
HTML;
require '_footer.php';
?>