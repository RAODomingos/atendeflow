<?php
/** @var array $tags */
/** @var array $canned */
/** @var array $departments */
/** @var array|null $editTag */
/** @var array|null $editCanned */
$isEditCanned = !empty($editCanned);
?>
<style>
.library-page{margin:0;padding:2px 26px 32px 30px}
.lib-grid-tags{display:flex;flex-direction:column;gap:6px}
@media(min-width:1100px){.lib-grid-tags{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}}
.lib-cmdbar{display:flex;align-items:flex-end;gap:16px;flex-wrap:wrap;margin-bottom:12px}
.lib-search-box{position:relative;width:340px;max-width:100%}
.lib-search-box i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:13px}
.lib-search-box input{width:100%;height:38px;border:1px solid var(--border-soft);border-radius:10px;background:var(--bg-panel);padding:0 12px 0 36px;font-size:13px;color:var(--text-primary);outline:none}
.lib-search-box input:focus{border-color:var(--brand);box-shadow:0 0 0 3px var(--brand-soft)}
.lib-cmdbar .spacer{flex:1}
.var-chip{display:inline-flex;align-items:center;font-family:Consolas,monospace;font-size:11.5px;background:var(--bg-panel-alt);border:1px solid var(--border-soft);color:var(--brand-dark);border-radius:8px;padding:5px 10px;cursor:pointer;transition:.15s}
.var-chip:hover{background:var(--brand-soft);border-color:var(--brand)}
.icon-btn-sm{width:30px;height:30px;border-radius:8px;border:1px solid var(--border-soft);background:var(--bg-panel);color:var(--text-secondary);display:inline-flex;align-items:center;justify-content:center;cursor:pointer;font-size:12px;transition:.15s;text-decoration:none}
.icon-btn-sm:hover{border-color:var(--brand);color:var(--brand)}
.icon-btn-sm.danger:hover{border-color:var(--danger);color:var(--danger);background:var(--danger-soft)}
/* Drawer lateral (mesmo padrão das macros) */
.drawer-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;opacity:0;pointer-events:none;transition:opacity .2s}
.drawer-overlay.open{opacity:1;pointer-events:auto}
.lib-drawer{position:fixed;top:0;right:0;bottom:0;width:620px;max-width:94vw;background:var(--bg-panel);z-index:1001;transform:translateX(102%);transition:transform .25s ease;display:flex;flex-direction:column;box-shadow:var(--shadow-lg);border-left:1px solid var(--border-soft)}
.lib-drawer.open{transform:none}
.lib-drawer-head{padding:16px 20px;border-bottom:1px solid var(--border-soft);display:flex;align-items:center;gap:12px;background:var(--bg-panel-alt);flex-shrink:0}
.lib-drawer-head h3{margin:0;font-size:16px;font-weight:700;color:var(--text-primary)}
.lib-drawer-head p{margin:2px 0 0;font-size:12.5px;color:var(--text-secondary)}
.lib-drawer-close{margin-left:auto;width:32px;height:32px;border-radius:9px;border:1px solid var(--border-soft);background:var(--bg-panel);color:var(--text-secondary);cursor:pointer;font-size:15px;flex-shrink:0}
.lib-drawer-close:hover{color:var(--danger);border-color:var(--danger)}
.lib-drawer-body{flex:1;overflow-y:auto;padding:18px 20px}
.lib-drawer-foot{padding:14px 20px;border-top:1px solid var(--border-soft);background:var(--bg-panel-alt);display:flex;gap:8px;justify-content:flex-end;flex-shrink:0}
.lib-sec-title{display:flex;align-items:center;gap:8px;font-size:12px;font-weight:700;color:var(--text-primary);margin:18px 0 10px;text-transform:uppercase;letter-spacing:.04em}
.lib-sec-title:first-child{margin-top:0}
@media(max-width:640px){.lib-drawer{width:100%}}
</style>

<div class="library-page">
    <div class="card" style="border-radius:12px;overflow:hidden;margin-bottom:16px">
        <div class="card-header" style="background:var(--bg-card)">
            <h4 style="margin:0;font-size:14px;font-weight:700"><i class="fas fa-tags" style="color:var(--primary)"></i> Etiquetas (Tags)</h4>
        </div>
        <div class="card-body">
            <form method="POST" action="<?= url('library/tags') ?>" class="mb-3" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
                <?= csrf_field() ?>
                <div style="flex:1;min-width:140px">
                    <label style="display:block;font-size:12px;font-weight:500;color:var(--secondary);margin-bottom:4px">Nome</label>
                    <input type="text" name="name" class="form-control" required
                           value="<?= $editTag ? e($editTag['name']) : '' ?>"
                           placeholder="Ex: VIP, Urgente">
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:500;color:var(--secondary);margin-bottom:4px">Cor</label>
                    <input type="color" name="color" class="form-control form-control-color"
                           value="<?= e($editTag['color'] ?? '#6c757d') ?>" style="width:46px;height:38px;padding:2px;cursor:pointer">
                </div>
                <div>
                    <?php if ($editTag): ?>
                        <button type="submit" formaction="<?= base_url('library/tags/' . $editTag['id']) ?>" class="btn btn-primary">
                            <i class="fas fa-check"></i> Atualizar
                        </button>
                        <a href="<?= url('library') ?>" class="btn btn-outline">
                            <i class="fas fa-times"></i> Cancelar
                        </a>
                    <?php else: ?>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Adicionar
                        </button>
                    <?php endif; ?>
                </div>
            </form>

            <?php if (empty($tags)): ?>
                <div style="text-align:center;padding:24px;color:var(--text-muted)">
                    <i class="fas fa-tags fa-2x" style="opacity:0.3;margin-bottom:8px;display:block"></i>
                    <p>Nenhuma etiqueta criada.</p>
                </div>
            <?php else: ?>
                <div class="lib-grid-tags">
                    <?php foreach ($tags as $t): ?>
                        <div class="tag-row" style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 12px;border:1px solid var(--border-color);border-radius:8px;transition:all 0.2s">
                            <span class="tag-pill" style="display:inline-flex;align-items:center;padding:4px 14px;border-radius:12px;font-size:12px;font-weight:600;background:<?= e($t['color']) ?>;color:#fff;">
                                <?= e($t['name']) ?>
                            </span>
                            <div style="display:flex;gap:4px">
                                <a href="<?= url('library/tags/') ?><?= $t['id'] ?>/edit" class="btn btn-sm btn-outline" title="Editar">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form method="POST" action="<?= url('library/tags/') ?><?= $t['id'] ?>/delete" style="display:inline" data-confirm="Remover a etiqueta &quot;<?= e(addslashes($t['name'])) ?>&quot;?">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm btn-outline" title="Remover" style="color:var(--danger)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card" style="border-radius:12px;overflow:hidden">
        <div class="card-header" style="background:var(--bg-card)">
            <h4 style="margin:0;font-size:14px;font-weight:700"><i class="fas fa-comment-dots" style="color:var(--warning)"></i> Mensagens Prontas</h4>
        </div>
        <div class="card-body">
            <div class="lib-cmdbar">
                <div class="lib-search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="cannedTableFilter" placeholder="Pesquisar mensagens..." oninput="filterCannedRows(this.value)">
                </div>
                <span class="spacer"></span>
                <button type="button" class="btn btn-primary btn-sm" onclick="openCannedDrawer()">
                    <i class="fas fa-plus"></i> Nova mensagem
                </button>
            </div>

            <?php if (empty($canned)): ?>
                <div style="text-align:center;padding:32px 24px;color:var(--text-muted)">
                    <i class="fas fa-comment-dots fa-2x" style="opacity:0.3;margin-bottom:8px;display:block"></i>
                    <p>Nenhuma mensagem pronta criada.</p>
                    <button type="button" class="btn btn-primary mt-2" onclick="openCannedDrawer()">
                        <i class="fas fa-plus"></i> Criar mensagem
                    </button>
                </div>
            <?php else: ?>
                <table class="table table-hover">
                    <thead>
                        <tr><th style="width:24%">Título</th><th>Conteúdo</th><th style="width:18%">Departamento</th><th style="width:110px">Gerenciar</th></tr>
                    </thead>
                    <tbody id="cannedTableBody">
                        <?php foreach ($canned as $c): ?>
                            <tr data-title="<?= e(mb_strtolower($c['title'] . ' ' . $c['content'])) ?>">
                                <td><strong><?= e($c['title']) ?></strong></td>
                                <td style="font-size:12.5px;color:var(--text-secondary)"><?= e(truncate($c['content'], 110)) ?></td>
                                <td>
                                    <?php if (!empty($c['department_name'])): ?>
                                        <span class="badge badge-info"><?= e($c['department_name']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted" style="font-size:11.5px">Todos</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?= url('library/canned/') ?><?= $c['id'] ?>/edit" class="icon-btn-sm" title="Editar"><i class="fas fa-edit"></i></a>
                                    <form method="POST" action="<?= url('library/canned/') ?><?= $c['id'] ?>/delete" style="display:inline" data-confirm="Remover esta mensagem pronta?">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="icon-btn-sm danger" title="Remover"><i class="fas fa-trash"></i></button>
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

<div class="drawer-overlay" id="cannedDrawerOverlay" onclick="closeCannedDrawer()"></div>
<aside class="lib-drawer" id="cannedDrawer" aria-label="<?= $isEditCanned ? 'Editar mensagem' : 'Nova mensagem' ?>">
    <div class="lib-drawer-head">
        <div>
            <h3><i class="fas fa-<?= $isEditCanned ? 'edit' : 'plus' ?>" style="color:var(--primary)"></i> <?= $isEditCanned ? 'Editar: ' . e($editCanned['title']) : 'Nova mensagem pronta' ?></h3>
            <p>Texto com variáveis resolvidas na hora do envio</p>
        </div>
        <button type="button" class="lib-drawer-close" onclick="closeCannedDrawer()" title="Fechar">&times;</button>
    </div>
    <form method="POST" action="<?= $isEditCanned ? base_url('library/canned/' . $editCanned['id']) : url('library/canned') ?>" style="display:flex;flex-direction:column;flex:1;min-height:0">
        <?= csrf_field() ?>
        <div class="lib-drawer-body">
            <div class="lib-sec-title">Informações</div>
            <div class="form-group">
                <label>Título</label>
                <input type="text" name="title" class="form-control" required
                       value="<?= $isEditCanned ? e($editCanned['title']) : '' ?>"
                       placeholder="Ex: Saudação inicial">
            </div>
            <div class="form-group">
                <label>Conteúdo <span class="text-muted" style="font-weight:400">(suporta variáveis)</span></label>
                <textarea name="content" id="cannedContent" class="form-control" rows="5" required
                          placeholder="Ex.: Olá {{ contact.name }}, sou {{ agent.name }}!"><?= $isEditCanned ? e($editCanned['content']) : '' ?></textarea>
            </div>
            <div class="lib-sec-title">Variáveis <span class="text-muted" style="font-weight:400;text-transform:none">(toque para inserir)</span></div>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
                <?php foreach (\App\Services\TemplateService::availableVariables() as $v): ?>
                    <button type="button" class="var-chip" data-token="<?= e($v['token']) ?>" title="<?= e($v['label'] . ' — ex.: ' . $v['example']) ?>" onclick="var t=this.dataset.token,ta=document.getElementById('cannedContent');ta.focus();var s=ta.selectionStart||ta.value.length,e=ta.selectionEnd||ta.value.length;ta.value=ta.value.slice(0,s)+t+ta.value.slice(e);">
                        <?= e($v['token']) ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <div class="lib-sec-title">Departamento</div>
            <div class="form-group">
                <select name="department_id" class="form-control">
                    <option value="">Todos os departamentos</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= $isEditCanned && ($editCanned['department_id'] ?? null) == $d['id'] ? 'selected' : '' ?>>
                            <?= e($d['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="lib-drawer-foot">
            <button type="button" class="btn btn-outline" onclick="closeCannedDrawer()">Cancelar</button>
            <button type="submit" class="btn btn-primary"><i class="fas fa-<?= $isEditCanned ? 'check' : 'plus' ?>"></i> <?= $isEditCanned ? 'Salvar alterações' : 'Criar mensagem' ?></button>
        </div>
    </form>
</aside>

<script>
var CANNED_EDIT_MODE = <?= $isEditCanned ? 'true' : 'false' ?>;
var LIBRARY_URL = '<?= url('library') ?>';
function openCannedDrawer() {
    if (CANNED_EDIT_MODE) { window.location.href = LIBRARY_URL; return; }
    document.getElementById('cannedDrawer').classList.add('open');
    document.getElementById('cannedDrawerOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeCannedDrawer() {
    if (CANNED_EDIT_MODE) { window.location.href = LIBRARY_URL; return; }
    document.getElementById('cannedDrawer').classList.remove('open');
    document.getElementById('cannedDrawerOverlay').classList.remove('open');
    document.body.style.overflow = '';
}
function filterCannedRows(q) {
    q = (q || '').toLowerCase();
    document.querySelectorAll('#cannedTableBody tr').forEach(function(tr) {
        tr.style.display = (!q || (tr.dataset.title || '').indexOf(q) !== -1) ? '' : 'none';
    });
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeCannedDrawer();
});
if (CANNED_EDIT_MODE) {
    document.getElementById('cannedDrawer').classList.add('open');
    document.getElementById('cannedDrawerOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
}
</script>
