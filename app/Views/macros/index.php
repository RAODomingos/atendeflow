<?php
/** @var array $macros */
/** @var array $departments */
/** @var array $tags */
/** @var array $inboxes */
/** @var array $variables */
/** @var array|null $editMacro */
$isEdit = !empty($editMacro);
$formAction = $isEdit ? url('macros/' . $editMacro['id']) : url('macros');
$editItems = $isEdit ? ($editMacro['items'] ?? []) : [];
if ($isEdit && empty($editItems) && !empty($editMacro['content'])) {
    $editItems = [['type' => 'text', 'content' => $editMacro['content']]];
}
$editActs = [];
if ($isEdit && !empty($editMacro['actions'])) {
    $editActs = json_decode($editMacro['actions'], true) ?: [];
}
$typeMeta = [
    'text'  => ['icon' => 'fa-comment-dots', 'label' => 'Texto',   'color' => '#0078d4'],
    'image' => ['icon' => 'fa-image',         'label' => 'Foto',    'color' => '#107c10'],
    'video' => ['icon' => 'fa-video',         'label' => 'Vídeo',   'color' => '#5c2d91'],
    'file'  => ['icon' => 'fa-paperclip',     'label' => 'Arquivo', 'color' => '#f7630c'],
    'audio' => ['icon' => 'fa-microphone',    'label' => 'Áudio',   'color' => '#008272'],
];
$totalMacros = count($macros);
$withMedia = 0;
foreach ($macros as $mm) {
    foreach (($mm['items'] ?? []) as $ii) {
        if (($ii['type'] ?? 'text') !== 'text') { $withMedia++; break; }
    }
}
$deptNameOf = function ($id) use ($departments) {
    foreach ($departments as $d) {
        if ((int) $d['id'] === (int) $id) return $d['name'];
    }
    return '';
};
?>
<style>
.macros-page{margin:0;padding:2px 26px 32px 30px}
.macro-cmdbar .spacer{flex:1}
.macros-page .page-subtitle{margin:4px 0 0;font-size:13.5px;color:var(--text-muted)}
.macros-page .toolbar-actions{display:flex;gap:8px;align-items:center}
.macros-page .form-row{flex-wrap:wrap}
.macros-page .form-row .col-12{flex:0 0 100%}
.macros-page .form-group{margin-bottom:12px}
@media(max-width:640px){.macros-page .form-row .col-6{flex:0 0 100%}}
.macro-cmdbar{display:flex;align-items:flex-end;gap:16px;flex-wrap:wrap;margin-bottom:14px}
.macro-search-box{position:relative;width:340px;max-width:100%}
.macro-pivots{display:flex;gap:2px;margin-left:auto}
.macro-pivot{border:none;background:none;font-family:inherit;font-size:13.5px;font-weight:600;color:var(--text-secondary);padding:9px 12px;cursor:pointer;position:relative;display:inline-flex;align-items:center}
.macro-pivot:hover{color:var(--text-primary)}
.macro-pivot.active{color:var(--brand-dark);font-weight:700}
.macro-pivot.active::after{content:'';position:absolute;left:10px;right:10px;bottom:-1px;height:2px;background:var(--brand);border-radius:2px}
.macro-pivot .cnt{font-size:11px;font-weight:700;background:var(--bg-panel-alt);border:1px solid var(--border-soft);color:var(--text-secondary);border-radius:10px;padding:0 7px;margin-left:7px}
.macro-pivot.active .cnt{background:var(--brand-soft);border-color:transparent;color:var(--brand-dark)}
.macros-page .contact-stat{cursor:pointer;transition:.15s;border:1px solid transparent}
.macros-page .contact-stat:hover{border-color:var(--border-strong)}
.macros-page .contact-stat.selected{border-color:var(--brand);background:var(--brand-soft)}
.macros-page .table thead th{white-space:nowrap}
.macros-page .table tbody tr{cursor:default}
.macro-search-box i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:13px}
.macro-search-box input{width:100%;height:38px;border:1px solid var(--border-soft);border-radius:10px;background:var(--bg-panel);padding:0 12px 0 36px;font-size:13px;color:var(--text-primary);outline:none}
.macro-search-box input:focus{border-color:var(--brand);box-shadow:0 0 0 3px var(--brand-soft)}
.macro-seq{display:flex;flex-direction:column;gap:5px;min-width:280px}
.macros-page .table td{padding:13px 18px}
.macro-seq-step{font-size:13px}
.macro-seq-step{display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--text-secondary);min-width:0}
.macro-seq-step i{width:15px;text-align:center;flex-shrink:0}
.macro-seq-step span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.macro-seq-more{font-size:11.5px;color:var(--text-muted);font-weight:600}
/* Drawer lateral */
.drawer-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;opacity:0;pointer-events:none;transition:opacity .2s}
.drawer-overlay.open{opacity:1;pointer-events:auto}
.macro-drawer{position:fixed;top:0;right:0;bottom:0;width:744px;max-width:94vw;background:var(--bg-panel);z-index:1001;transform:translateX(102%);transition:transform .25s ease;display:flex;flex-direction:column;box-shadow:var(--shadow-lg);border-left:1px solid var(--border-soft)}
.macro-drawer.open{transform:none}
.macro-drawer-head{padding:16px 20px;border-bottom:1px solid var(--border-soft);display:flex;align-items:center;gap:12px;background:var(--bg-panel-alt);flex-shrink:0}
.macro-drawer-head h3{margin:0;font-size:16px;font-weight:700;color:var(--text-primary)}
.macro-drawer-head p{margin:2px 0 0;font-size:12.5px;color:var(--text-secondary)}
.macro-drawer-close{margin-left:auto;width:32px;height:32px;border-radius:9px;border:1px solid var(--border-soft);background:var(--bg-panel);color:var(--text-secondary);cursor:pointer;font-size:15px;flex-shrink:0}
.macro-drawer-close:hover{color:var(--danger);border-color:var(--danger)}
.macro-drawer-body{flex:1;overflow-y:auto;padding:18px 20px}
.macro-drawer-foot{padding:14px 20px;border-top:1px solid var(--border-soft);background:var(--bg-panel-alt);display:flex;gap:8px;justify-content:flex-end;flex-shrink:0}
.macro-sec-title{display:flex;align-items:center;gap:8px;font-size:12px;font-weight:700;color:var(--text-primary);margin:18px 0 10px;text-transform:uppercase;letter-spacing:.04em}
.macro-sec-title:first-child{margin-top:0}
.macro-item{border:1px solid var(--border-soft);border-left:4px solid var(--brand);border-radius:10px;padding:11px 12px;background:var(--bg-panel-alt);margin-bottom:10px}
.macro-item[data-type="image"]{border-left-color:#107c10}
.macro-item[data-type="video"]{border-left-color:#5c2d91}
.macro-item[data-type="file"]{border-left-color:#f7630c}
.macro-item[data-type="audio"]{border-left-color:#008272}
.macro-item-head{display:flex;align-items:center;gap:7px;margin-bottom:9px}
.macro-type-pill{font-size:11px;font-weight:800;padding:3px 11px;border-radius:20px;color:#fff;background:var(--brand)}
.macro-item[data-type="image"] .macro-type-pill{background:#107c10}
.macro-item[data-type="video"] .macro-type-pill{background:#5c2d91}
.macro-item[data-type="file"] .macro-type-pill{background:#f7630c}
.macro-item[data-type="audio"] .macro-type-pill{background:#008272}
.macro-add-row{display:flex;gap:7px;flex-wrap:wrap}
.macro-add-btn{display:inline-flex;align-items:center;gap:7px;border:1px dashed var(--border-strong);background:transparent;color:var(--text-secondary);font-size:12.5px;font-weight:600;padding:8px 13px;border-radius:10px;cursor:pointer;transition:.15s}
.macro-add-btn:hover{border-color:var(--brand);color:var(--brand);background:var(--brand-soft)}
.var-chip{display:inline-flex;align-items:center;font-family:Consolas,monospace;font-size:11.5px;background:var(--bg-panel-alt);border:1px solid var(--border-soft);color:var(--brand-dark);border-radius:8px;padding:5px 10px;cursor:pointer;transition:.15s}
.var-chip:hover{background:var(--brand-soft);border-color:var(--brand)}
.macro-upload-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px}
.macro-file-status{font-size:12px;color:var(--text-secondary)}
.macro-file-status.ok{color:var(--success);font-weight:600}
.icon-btn-sm{width:30px;height:30px;border-radius:8px;border:1px solid var(--border-soft);background:var(--bg-panel);color:var(--text-secondary);display:inline-flex;align-items:center;justify-content:center;cursor:pointer;font-size:12px;transition:.15s;text-decoration:none}
.icon-btn-sm:hover{border-color:var(--brand);color:var(--brand)}
.icon-btn-sm.danger:hover{border-color:var(--danger);color:var(--danger);background:var(--danger-soft)}
@media(max-width:640px){.macro-drawer{width:100%}}
</style>

<div class="macros-page">
    <div class="contact-stats" style="margin-bottom:14px">
        <div class="contact-stat" data-statfilter="all" onclick="setMacroFilter('all')" title="Mostrar todas">
            <div class="contact-stat-icon" style="background:var(--brand-soft);color:var(--brand)">
                <i class="fas fa-bolt"></i>
            </div>
            <div class="contact-stat-body">
                <div class="contact-stat-value"><?= $totalMacros ?></div>
                <div class="contact-stat-label">Macros</div>
            </div>
        </div>
        <div class="contact-stat" data-statfilter="media" onclick="setMacroFilter('media')" title="Filtrar com mídia">
            <div class="contact-stat-icon" style="background:var(--success-soft);color:var(--success)">
                <i class="fas fa-photo-video"></i>
            </div>
            <div class="contact-stat-body">
                <div class="contact-stat-value"><?= $withMedia ?></div>
                <div class="contact-stat-label">Com mídia</div>
            </div>
        </div>
        <div class="contact-stat" data-statfilter="text" onclick="setMacroFilter('text')" title="Filtrar só texto">
            <div class="contact-stat-icon" style="background:var(--info-soft);color:var(--info)">
                <i class="fas fa-comment-dots"></i>
            </div>
            <div class="contact-stat-body">
                <div class="contact-stat-value"><?= $totalMacros - $withMedia ?></div>
                <div class="contact-stat-label">Só texto</div>
            </div>
        </div>
    </div>

    <div class="macro-cmdbar">
        <div class="macro-search-box">
            <i class="fas fa-search"></i>
            <input type="text" id="macroTableFilter" placeholder="Pesquisar macros..." oninput="applyMacroFilters()">
        </div>
        <div class="macro-pivots" role="tablist" aria-label="Filtrar macros">
            <button type="button" class="macro-pivot active" data-pivot="all" onclick="setMacroFilter('all')">Todas<span class="cnt"><?= $totalMacros ?></span></button>
            <button type="button" class="macro-pivot" data-pivot="media" onclick="setMacroFilter('media')">Com mídia<span class="cnt"><?= $withMedia ?></span></button>
            <button type="button" class="macro-pivot" data-pivot="text" onclick="setMacroFilter('text')">Só texto<span class="cnt"><?= $totalMacros - $withMedia ?></span></button>
        </div>
        <span class="spacer"></span>
        <?php if ($isEdit): ?>
            <a href="<?= url('macros') ?>" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
        <?php else: ?>
            <button type="button" class="btn btn-primary btn-sm" onclick="openMacroDrawer()">
                <i class="fas fa-plus"></i> Nova macro
            </button>
        <?php endif; ?>
    </div>

    <?php if (empty($macros)): ?>
        <div class="card" style="border-radius:12px;overflow:hidden">
            <div class="card-body" style="padding:0">
                <div class="empty-state-enhanced">
                    <div class="empty-icon"><i class="fas fa-bolt"></i></div>
                    <h3>Nenhuma macro criada</h3>
                    <p>Crie sequências de mensagens com foto, vídeo e arquivo para agilizar o atendimento.</p>
                    <button type="button" class="btn btn-primary mt-2" onclick="openMacroDrawer()">
                        <i class="fas fa-plus"></i> Criar macro
                    </button>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="card" style="border-radius:12px;overflow:hidden">
            <div class="card-body" style="padding:0">
                <table class="table table-hover">
                    <thead>
                        <tr><th style="width:23%">Macro</th><th>Sequência</th><th style="width:20%">Ações automáticas</th><th style="width:120px">Gerenciar</th></tr>
                    </thead>
                    <tbody id="macroTableBody">
                        <?php foreach ($macros as $m):
                            $acts = $m['actions'] ? json_decode($m['actions'], true) : [];
                            $actLabels = [];
                            if (!empty($acts['status'])) {
                                $st = ['open' => 'Aberto', 'waiting_customer' => 'Em atendimento', 'resolved' => 'Resolvido', 'closed' => 'Fechado'][$acts['status']] ?? $acts['status'];
                                $actLabels[] = $st;
                            }
                            if (!empty($acts['tag_id'])) $actLabels[] = 'Etiqueta';
                            if (!empty($acts['assign_me'])) $actLabels[] = 'Atribuir a mim';
                            if (!empty($acts['transfer_inbox_id'])) $actLabels[] = 'Transferir caixa';
                            $items = $m['items'] ?? [];
                            if (empty($items) && !empty($m['content'])) {
                                $items = [['type' => 'text', 'content' => $m['content']]];
                            }
                            $deptName = !empty($m['department_id']) ? $deptNameOf($m['department_id']) : '';
                            $hasMedia = false;
                            foreach ($items as $chk) {
                                if (($chk['type'] ?? 'text') !== 'text') { $hasMedia = true; break; }
                            }
                        ?>
                            <tr data-title="<?= e(mb_strtolower($m['title'])) ?>" data-media="<?= $hasMedia ? '1' : '0' ?>">
                                <td>
                                    <strong><?= e($m['title']) ?></strong>
                                    <?php if ($deptName): ?>
                                        <br><span class="badge badge-info" style="margin-top:4px"><?= e($deptName) ?></span>
                                    <?php else: ?>
                                        <br><span class="text-muted" style="font-size:11.5px">Todos os deptos.</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="macro-seq">
                                        <?php $n = 0; foreach (array_slice($items, 0, 3) as $it): $n++;
                                            $t = isset($it['type']) ? $it['type'] : 'text';
                                            $meta = isset($typeMeta[$t]) ? $typeMeta[$t] : $typeMeta['text'];
                                            if ($t === 'text') {
                                                $plain = (string) ($it['content'] ?? '');
                                            } else {
                                                $cap = trim((string) ($it['content'] ?? ''));
                                                $fname = (string) ($it['media_name'] ?? ($it['media_url'] ?? $t));
                                                $plain = $cap !== '' ? $cap . ' + ' . $fname : $fname;
                                            }
                                        ?>
                                            <div class="macro-seq-step">
                                                <i class="fas <?= $meta['icon'] ?>" style="color:<?= $meta['color'] ?>" title="<?= $meta['label'] ?>"></i>
                                                <span><?= e(truncate($plain, 110)) ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if (count($items) > 3): ?>
                                            <span class="macro-seq-more">+<?= count($items) - 3 ?> mensagens</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if (empty($actLabels)): ?>
                                        <span class="text-muted">—</span>
                                    <?php else: ?>
                                        <span class="badge badge-info"><?= e(implode(' • ', $actLabels)) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?= url('macros/' . $m['id'] . '/edit') ?>" class="icon-btn-sm" title="Editar"><i class="fas fa-edit"></i></a>
                                    <form method="POST" action="<?= url('macros/' . $m['id'] . '/delete') ?>" style="display:inline" data-confirm="Remover a macro &quot;<?= e(addslashes($m['title'])) ?>&quot;?">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="icon-btn-sm danger" title="Remover"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="drawer-overlay" id="macroDrawerOverlay" onclick="closeMacroDrawer()"></div>
<aside class="macro-drawer" id="macroDrawer" aria-label="<?= $isEdit ? 'Editar macro' : 'Nova macro' ?>">
    <div class="macro-drawer-head">
        <div>
            <h3><i class="fas fa-<?= $isEdit ? 'edit' : 'plus' ?>" style="color:var(--primary)"></i> <?= $isEdit ? 'Editar: ' . e($editMacro['title']) : 'Nova macro' ?></h3>
            <p>Sequência de mensagens, variáveis e ações automáticas</p>
        </div>
        <button type="button" class="macro-drawer-close" onclick="closeMacroDrawer()" title="Fechar">&times;</button>
    </div>
    <form method="POST" action="<?= $formAction ?>" id="macroForm" style="display:flex;flex-direction:column;flex:1;min-height:0">
        <?= csrf_field() ?>
        <div class="macro-drawer-body">
            <div class="macro-sec-title">Informações</div>
            <div class="form-row">
                <div class="form-group col-12">
                    <label>Título</label>
                    <input type="text" name="title" class="form-control" required placeholder="Ex: Saudação inicial" value="<?= $isEdit ? e($editMacro['title']) : '' ?>">
                </div>
                <div class="form-group col-12">
                    <label>Departamento</label>
                    <select name="department_id" class="form-control">
                        <option value="">Todos os departamentos</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?= $d['id'] ?>" <?= $isEdit && ($editMacro['department_id'] ?? null) == $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="macro-sec-title">Mensagens da sequência <span class="text-muted" style="font-weight:400;text-transform:none">(até 10, na ordem)</span></div>
            <div id="macroItems"></div>
            <div class="macro-add-row">
                <button type="button" class="macro-add-btn" onclick="addMacroItem('text')"><i class="fas fa-comment-dots"></i> Texto</button>
                <button type="button" class="macro-add-btn" onclick="addMacroItem('image')"><i class="fas fa-image"></i> Foto</button>
                <button type="button" class="macro-add-btn" onclick="addMacroItem('video')"><i class="fas fa-video"></i> Vídeo</button>
                <button type="button" class="macro-add-btn" onclick="addMacroItem('file')"><i class="fas fa-paperclip"></i> Arquivo</button>
                <button type="button" class="macro-add-btn" onclick="addMacroItem('audio')"><i class="fas fa-microphone"></i> Áudio</button>
            </div>
            <input type="hidden" name="items_json" id="macroItemsJson">

            <div class="macro-sec-title">Variáveis <span class="text-muted" style="font-weight:400;text-transform:none">(toque para inserir)</span></div>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
                <?php foreach (($variables ?? []) as $v): ?>
                    <button type="button" class="var-chip" data-token="<?= e($v['token']) ?>" title="<?= e($v['label'] . ' — ex.: ' . $v['example']) ?>" onclick="insertVarInMacro(this)"><?= e($v['token']) ?></button>
                <?php endforeach; ?>
            </div>

            <div class="macro-sec-title">Ações automáticas</div>
            <div class="form-group">
                <label>Status</label>
                <select name="action_status" class="form-control">
                    <option value="">Não alterar</option>
                    <option value="open" <?= ($editActs['status'] ?? '') === 'open' ? 'selected' : '' ?>>Aberto</option>
                    <option value="waiting_customer" <?= ($editActs['status'] ?? '') === 'waiting_customer' ? 'selected' : '' ?>>Em atendimento</option>
                    <option value="resolved" <?= ($editActs['status'] ?? '') === 'resolved' ? 'selected' : '' ?>>Resolvido</option>
                    <option value="closed" <?= ($editActs['status'] ?? '') === 'closed' ? 'selected' : '' ?>>Fechado</option>
                </select>
            </div>
            <div class="form-row">
                <div class="form-group col-6">
                    <label>Etiqueta</label>
                    <select name="action_tag_id" class="form-control">
                        <option value="">Nenhuma</option>
                        <?php foreach ($tags as $t): ?>
                            <option value="<?= $t['id'] ?>" <?= ($editActs['tag_id'] ?? null) == $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-6">
                    <label>Transferir p/ caixa</label>
                    <select name="action_transfer_inbox_id" class="form-control">
                        <option value="">Não transferir</option>
                        <?php foreach ($inboxes as $ib): ?>
                            <option value="<?= $ib['id'] ?>" <?= ($editActs['transfer_inbox_id'] ?? null) == $ib['id'] ? 'selected' : '' ?>><?= e($ib['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="action_assign_me" id="am" value="1" <?= !empty($editActs['assign_me']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="am">Atribuir a conversa a mim ao aplicar</label>
            </div>
        </div>
        <div class="macro-drawer-foot">
            <button type="button" class="btn btn-outline" onclick="closeMacroDrawer()">Cancelar</button>
            <button type="submit" class="btn btn-primary"><i class="fas fa-<?= $isEdit ? 'check' : 'plus' ?>"></i> <?= $isEdit ? 'Salvar alterações' : 'Criar macro' ?></button>
        </div>
    </form>
</aside>

<script>
var MACRO_EDIT_MODE = <?= $isEdit ? 'true' : 'false' ?>;
var MACRO_LIST_URL = '<?= url('macros') ?>';
function openMacroDrawer() {
    if (MACRO_EDIT_MODE) { window.location.href = MACRO_LIST_URL; return; }
    document.getElementById('macroDrawer').classList.add('open');
    document.getElementById('macroDrawerOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeMacroDrawer() {
    if (MACRO_EDIT_MODE) { window.location.href = MACRO_LIST_URL; return; }
    document.getElementById('macroDrawer').classList.remove('open');
    document.getElementById('macroDrawerOverlay').classList.remove('open');
    document.body.style.overflow = '';
}
var macroMediaFilter = 'all';
function setMacroFilter(f) {
    macroMediaFilter = f;
    document.querySelectorAll('.macro-pivot').forEach(function(p) {
        p.classList.toggle('active', p.dataset.pivot === f);
    });
    document.querySelectorAll('.contact-stat[data-statfilter]').forEach(function(s) {
        s.classList.toggle('selected', s.dataset.statfilter === f && f !== 'all');
    });
    applyMacroFilters();
}
function applyMacroFilters() {
    var q = (document.getElementById('macroTableFilter').value || '').toLowerCase();
    document.querySelectorAll('#macroTableBody tr').forEach(function(tr) {
        var okQ = !q || (tr.dataset.title || '').indexOf(q) !== -1;
        var okM = macroMediaFilter === 'all' ||
            (macroMediaFilter === 'media' && tr.dataset.media === '1') ||
            (macroMediaFilter === 'text' && tr.dataset.media === '0');
        tr.style.display = (okQ && okM) ? '' : 'none';
    });
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeMacroDrawer();
});
(function() {
    var container = document.getElementById('macroItems');
    var form = document.getElementById('macroForm');
    var jsonInput = document.getElementById('macroItemsJson');
    var lastFocus = null;
    var TYPE_LABEL = { text: 'Texto', image: 'Foto', video: 'Vídeo', file: 'Arquivo', audio: 'Áudio' };

    window.addMacroItem = function(type, preset) {
        preset = preset || {};
        var idx = container.children.length;
        if (idx >= 10) { alert('Limite de 10 mensagens por macro.'); return; }
        var div = document.createElement('div');
        div.className = 'macro-item';
        div.dataset.type = type;
        var mediaBlock = type === 'text' ? '' :
            '<div class="macro-upload-row">' +
            '<input type="file" class="form-control macro-file" style="flex:1;min-width:150px" accept="' + fileAccept(type) + '">' +
            '<button type="button" class="btn btn-sm btn-outline macro-upload-btn"><i class="fas fa-upload"></i> Enviar</button>' +
            '<span class="macro-file-status' + (preset.media_name ? ' ok' : '') + '">' + (preset.media_name ? '✓ ' + escapeHtml(preset.media_name) : 'Nenhum arquivo') + '</span>' +
            '</div>';
        div.innerHTML =
            '<div class="macro-item-head">' +
            '<span class="macro-type-pill">' + (idx + 1) + ' • ' + (TYPE_LABEL[type] || type) + '</span>' +
            '<span style="flex:1"></span>' +
            '<button type="button" class="icon-btn-sm" onclick="moveMacroItem(this,-1)" title="Subir"><i class="fas fa-arrow-up"></i></button>' +
            '<button type="button" class="icon-btn-sm" onclick="moveMacroItem(this,1)" title="Descer"><i class="fas fa-arrow-down"></i></button>' +
            '<button type="button" class="icon-btn-sm danger" onclick="removeMacroItem(this)" title="Remover"><i class="fas fa-times"></i></button>' +
            '</div>' +
            mediaBlock +
            '<textarea class="form-control macro-text" rows="' + (type === 'text' ? '3' : '2') + '" placeholder="' + (type === 'text' ? 'Texto da mensagem (ex.: Olá {{ contact.name }}, sou {{ agent.name }}!)' : 'Legenda da mídia (opcional)') + '"></textarea>' +
            '<input type="hidden" class="macro-url" value="' + escapeAttr(preset.media_url || '') + '">' +
            '<input type="hidden" class="macro-name" value="' + escapeAttr(preset.media_name || '') + '">' +
            '<input type="hidden" class="macro-mime" value="' + escapeAttr(preset.media_mime || '') + '">' +
            '<input type="hidden" class="macro-size" value="' + escapeAttr(preset.media_size || '') + '">' +
            '<input type="hidden" class="macro-path" value="' + escapeAttr(preset.media_path || '') + '">';
        div.querySelector('.macro-text').value = preset.content || '';
        div.querySelector('.macro-text').addEventListener('focus', function() { lastFocus = this; });
        var fileInput = div.querySelector('.macro-file');
        var uploadBtn = div.querySelector('.macro-upload-btn');
        if (fileInput && uploadBtn) {
            uploadBtn.addEventListener('click', function() { uploadMacroFile(div); });
            fileInput.addEventListener('change', function() { uploadMacroFile(div); });
        }
        container.appendChild(div);
        renumber();
    };

    window.removeMacroItem = function(btn) {
        btn.closest('.macro-item').remove();
        renumber();
    };

    window.moveMacroItem = function(btn, dir) {
        var item = btn.closest('.macro-item');
        if (dir < 0 && item.previousElementSibling) container.insertBefore(item, item.previousElementSibling);
        if (dir > 0 && item.nextElementSibling) container.insertBefore(item.nextElementSibling, item);
        renumber();
    };

    window.insertVarInMacro = function(btn) {
        var token = btn.dataset.token || '';
        var ta = lastFocus && document.contains(lastFocus) ? lastFocus : container.querySelector('.macro-text');
        if (!ta) return;
        ta.focus();
        var s = ta.selectionStart || ta.value.length, e = ta.selectionEnd || ta.value.length;
        ta.value = ta.value.slice(0, s) + token + ta.value.slice(e);
        lastFocus = ta;
    };

    function renumber() {
        Array.prototype.forEach.call(container.children, function(el, i) {
            var pill = el.querySelector('.macro-type-pill');
            if (pill) pill.textContent = (i + 1) + ' • ' + (TYPE_LABEL[el.dataset.type] || el.dataset.type);
        });
    }

    function fileAccept(type) {
        return { image: 'image/*', video: 'video/*', audio: 'audio/*', file: '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip,.rar,.csv' }[type] || '*/*';
    }

    function escapeHtml(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
    function escapeAttr(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }

    function uploadMacroFile(itemDiv) {
        var fileInput = itemDiv.querySelector('.macro-file');
        var status = itemDiv.querySelector('.macro-file-status');
        if (!fileInput || !fileInput.files || !fileInput.files[0]) return;
        var fd = new FormData();
        fd.append('file', fileInput.files[0]);
        if (status) { status.textContent = 'Enviando...'; status.classList.remove('ok'); }
        fetch('/api/macros/upload', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(j) {
                if (j.error || !j.url) { if (status) status.textContent = 'Falha: ' + (j.error || 'tente de novo'); return; }
                itemDiv.querySelector('.macro-url').value = j.url;
                itemDiv.querySelector('.macro-name').value = j.name || '';
                itemDiv.querySelector('.macro-mime').value = j.mime || '';
                itemDiv.querySelector('.macro-size').value = j.size || 0;
                itemDiv.querySelector('.macro-path').value = j.path || '';
                if (j.type && ['image', 'video', 'audio', 'file'].indexOf(j.type) !== -1) {
                    itemDiv.dataset.type = j.type;
                    renumber();
                }
                if (status) { status.textContent = '✓ ' + (j.name || j.url); status.classList.add('ok'); }
            })
            .catch(function() { if (status) status.textContent = 'Falha no envio.'; });
    }

    form.addEventListener('submit', function() {
        var items = [];
        Array.prototype.forEach.call(container.querySelectorAll('.macro-item'), function(el) {
            items.push({
                type: el.dataset.type || 'text',
                content: el.querySelector('.macro-text').value || '',
                media_url: el.querySelector('.macro-url').value || '',
                media_name: el.querySelector('.macro-name').value || '',
                media_mime: el.querySelector('.macro-mime').value || '',
                media_size: el.querySelector('.macro-size').value || 0,
                media_path: el.querySelector('.macro-path').value || ''
            });
        });
        jsonInput.value = JSON.stringify(items);
    });

    var initial = <?= json_encode(array_map(function($it) {
        return ['type' => $it['type'] ?? 'text', 'content' => $it['content'] ?? '', 'media_url' => $it['media_url'] ?? '', 'media_name' => $it['media_name'] ?? '', 'media_mime' => $it['media_mime'] ?? '', 'media_size' => $it['media_size'] ?? 0, 'media_path' => $it['media_path'] ?? ''];
    }, $editItems), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    if (!initial || !initial.length) initial = [{ type: 'text', content: '' }];
    initial.forEach(function(it) { addMacroItem(it.type || 'text', it); });

    if (MACRO_EDIT_MODE) {
        document.getElementById('macroDrawer').classList.add('open');
        document.getElementById('macroDrawerOverlay').classList.add('open');
        document.body.style.overflow = 'hidden';
    }
})();
</script>
