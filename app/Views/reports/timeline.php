<?php
$statusLabels = [
    'new' => 'Novos', 'open' => 'Abertos',
    'waiting_customer' => 'Aguardando cliente', 'waiting_internal' => 'Aguardando interno',
    'resolved' => 'Resolvidos', 'closed' => 'Fechados', 'spam' => 'Spam',
];
$statusIcons = [
    'new' => 'fa-bell', 'open' => 'fa-comments',
    'waiting_customer' => 'fa-headset', 'waiting_internal' => 'fa-pause',
    'resolved' => 'fa-check-circle', 'closed' => 'fa-archive', 'spam' => 'fa-ban',
];
$statusColors = [
    'new' => 'var(--info)', 'open' => 'var(--primary)',
    'waiting_customer' => 'var(--warning)', 'waiting_internal' => '#b45309',
    'resolved' => 'var(--success)', 'closed' => '#6c757d', 'spam' => 'var(--danger)',
];
$priorityLabels = ['low' => 'Baixa', 'normal' => 'Normal', 'high' => 'Alta', 'urgent' => 'Urgente'];
$weekdayNames = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
$deltas = $comparison ?: [];
$hasComparison = !empty($deltas);
$peakHour = $peakHour ?? null;
$heatmap = $heatmap ?? ['grid' => [], 'max' => 0];
$totalAll = $stats['total'] ?? 0;

// Helper de delta
function renderDeltaBadge($delta) {
    if ($delta === 'new') return '<span class="tl-delta tl-delta-new"><i class="fas fa-sparkles"></i>novo</span>';
    if (!is_numeric($delta)) return '';
    $cls = $delta > 0 ? 'tl-delta-up' : ($delta < 0 ? 'tl-delta-down' : 'tl-delta-flat');
    $icon = $delta > 0 ? 'fa-arrow-up' : ($delta < 0 ? 'fa-arrow-down' : 'fa-equals');
    return '<span class="tl-delta ' . $cls . '"><i class="fas ' . $icon . '"></i>' . ($delta > 0 ? '+' : '') . $delta . '%</span>';
}
?>
<div class="reports-page timeline-page">
    <div class="reports-header">
        <div class="reports-header-left">
            <a href="<?= url('reports') ?>" class="reports-back" title="Voltar">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
        </div>
        <a href="<?= url('reports/timeline/pdf') . '?' . http_build_query(array_diff_key($timeline, ['where' => null, 'params' => null])) ?>"
           class="btn btn-sm btn-outline"
           id="btnExportAllFiltered"
           title="Exporta o resultado completo do filtro atual">
            <i class="fas fa-file-pdf"></i> Exportar PDF
        </a>
        <a href="<?= url('reports/timeline/csv') . '?' . http_build_query(array_diff_key($timeline, ['where' => null, 'params' => null])) ?>"
           class="btn btn-sm btn-outline"
           id="btnExportAllCsv"
           title="Exporta o resultado do filtro em planilha (.csv)">
            <i class="fas fa-file-csv"></i> Exportar CSV
        </a>
    </div>

    <form method="GET" class="tl-filters" id="tlFilters">
        <div class="tl-granularity" role="tablist">
            <?php foreach (['day' => 'Dia', 'week' => 'Semana', 'month' => 'Mês', 'year' => 'Ano'] as $g => $label): ?>
                <button type="button" class="tl-gran-btn <?= $timeline['granularity'] === $g ? 'is-active' : '' ?>"
                        data-granularity="<?= $g ?>" data-role="granularity-btn">
                    <?= $label ?>
                </button>
            <?php endforeach; ?>
            <input type="hidden" name="granularity" id="granularityInput" value="<?= e($timeline['granularity']) ?>">
        </div>

        <div class="tl-row">
            <div class="tl-field">
                <label>De</label>
                <input type="date" name="from" class="form-control form-control-sm" value="<?= e($timeline['from']) ?>">
            </div>
            <div class="tl-field">
                <label>Até</label>
                <input type="date" name="to" class="form-control form-control-sm" value="<?= e($timeline['to']) ?>">
            </div>
            <div class="tl-field">
                <label>Status</label>
                <select name="status" class="form-control form-control-sm">
                    <option value="">Todos</option>
                    <?php foreach ($statusLabels as $sk => $sl): ?>
                        <option value="<?= e($sk) ?>" <?= $timeline['status'] === $sk ? 'selected' : '' ?>><?= e($sl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="tl-field">
                <label>Canal</label>
                <select name="channel_id" class="form-control form-control-sm">
                    <option value="0">Todos</option>
                    <?php foreach ($channels as $ch): ?>
                        <option value="<?= (int) $ch['id'] ?>" <?= (int) $timeline['channel_id'] === (int) $ch['id'] ? 'selected' : '' ?>>
                            <?= e($ch['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="tl-field">
                <label>Departamento</label>
                <select name="department_id" class="form-control form-control-sm">
                    <option value="0">Todos</option>
                    <?php foreach ($departments as $dp): ?>
                        <option value="<?= (int) $dp['id'] ?>" <?= (int) $timeline['department_id'] === (int) $dp['id'] ? 'selected' : '' ?>>
                            <?= e($dp['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="tl-field tl-field-actions">
                <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter"></i> Filtrar</button>
                <a href="<?= url('reports/timeline') ?>" class="btn btn-sm btn-outline"><i class="fas fa-xmark"></i></a>
            </div>
        </div>
    </form>

    <?php if ($hasComparison): ?>
    <div class="dashboard-grid tl-comparison">
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-calendar-week" style="color:var(--primary)"></i> Período atual</h3>
                <span class="text-muted" style="font-size:12px"><?= e($deltas['current_from']) ?> → <?= e($deltas['current_to']) ?></span>
            </div>
            <div class="card-body tl-cmp-body">
                <div class="tl-cmp-metric">
                    <span class="tl-cmp-val"><?= number_format($deltas['current']['total']) ?></span>
                    <span class="tl-cmp-label">Total</span>
                </div>
                <div class="tl-cmp-metric">
                    <span class="tl-cmp-val" style="color:var(--info)"><?= number_format($deltas['current']['open']) ?></span>
                    <span class="tl-cmp-label">Em aberto</span>
                </div>
                <div class="tl-cmp-metric">
                    <span class="tl-cmp-val" style="color:var(--success)"><?= number_format($deltas['current']['closed']) ?></span>
                    <span class="tl-cmp-label">Concluídas</span>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-clock-rotate-left" style="color:var(--text-muted)"></i> Período anterior</h3>
                <span class="text-muted" style="font-size:12px"><?= e($deltas['previous_from']) ?> → <?= e($deltas['previous_to']) ?></span>
            </div>
            <div class="card-body tl-cmp-body">
                <div class="tl-cmp-metric">
                    <span class="tl-cmp-val"><?= number_format($deltas['previous']['total']) ?></span>
                    <span class="tl-cmp-label">Total</span>
                    <?= renderDeltaBadge($deltas['delta_total'] ?? 0) ?>
                </div>
                <div class="tl-cmp-metric">
                    <span class="tl-cmp-val" style="color:var(--info)"><?= number_format($deltas['previous']['open']) ?></span>
                    <span class="tl-cmp-label">Em aberto</span>
                    <?= renderDeltaBadge($deltas['delta_open'] ?? 0) ?>
                </div>
                <div class="tl-cmp-metric">
                    <span class="tl-cmp-val" style="color:var(--success)"><?= number_format($deltas['previous']['closed']) ?></span>
                    <span class="tl-cmp-label">Concluídas</span>
                    <?= renderDeltaBadge($deltas['delta_closed'] ?? 0) ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon stat-icon-primary"><i class="fas fa-comments"></i></div>
            <div class="stat-info">
                <span class="stat-value"><?= number_format($stats['total']) ?></span>
                <span class="stat-label">Total</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon stat-icon-info"><i class="fas fa-spinner"></i></div>
            <div class="stat-info">
                <span class="stat-value"><?= number_format($stats['open']) ?></span>
                <span class="stat-label">Em aberto</span>
                <?php if ($stats['total'] > 0): ?>
                <span class="stat-sub"><?= round($stats['open'] / $stats['total'] * 100) ?>% do total</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon stat-icon-success"><i class="fas fa-check-circle"></i></div>
            <div class="stat-info">
                <span class="stat-value"><?= number_format($stats['closed']) ?></span>
                <span class="stat-label">Concluídas</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon stat-icon-amber"><i class="fas fa-comment-dots"></i></div>
            <div class="stat-info">
                <span class="stat-value"><?= number_format($stats['messages_total']) ?></span>
                <span class="stat-label">Mensagens</span>
                <span class="stat-sub">~<?= number_format($stats['avg_per_day'], 1) ?>/conversa</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon stat-icon-primary"><i class="fas fa-clock"></i></div>
            <div class="stat-info">
                <span class="stat-value">
                    <?php if ($peakHour): ?>
                        <?= str_pad($peakHour['hour'], 2, '0', STR_PAD_LEFT) ?>h
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </span>
                <span class="stat-label">Pico de horário</span>
                <?php if ($peakHour): ?>
                <span class="stat-sub"><?= $weekdayNames[$peakHour['dow']] ?? '' ?> · <?= (int) $peakHour['count'] ?> conversas</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!empty($heatmap['grid'])): ?>
    <div class="card tl-heatmap-card">
        <div class="card-header">
            <h3><i class="fas fa-fire" style="color:var(--warning)"></i> Mapa de calor — dia da semana × hora</h3>
            <span class="text-muted" style="font-size:12px">Onde as conversas chegam</span>
        </div>
        <div class="card-body">
            <div class="tl-heatmap">
                <div class="tl-heatmap-corner"></div>
                <?php for ($h = 0; $h < 24; $h++): ?>
                    <div class="tl-heatmap-hour"><?= str_pad($h, 2, '0', STR_PAD_LEFT) ?></div>
                <?php endfor; ?>
                <?php for ($d = 0; $d < 7; $d++): ?>
                    <div class="tl-heatmap-day"><?= $weekdayNames[$d] ?></div>
                    <?php for ($h = 0; $h < 24; $h++):
                        $v = $heatmap['grid'][$d][$h] ?? 0;
                        $intensity = $heatmap['max'] > 0 ? $v / $heatmap['max'] : 0;
                        $bg = 'rgba(0, 120, 212, ' . round($intensity * 0.85, 2) . ')';
                    ?>
                        <div class="tl-heatmap-cell" style="background:<?= $bg ?>" title="<?= $weekdayNames[$d] ?> <?= str_pad($h, 2, '0', STR_PAD_LEFT) ?>h — <?= $v ?> conversa(s)">
                            <?= $v > 0 ? $v : '' ?>
                        </div>
                    <?php endfor; ?>
                <?php endfor; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="tl-export-bar" id="tlExportBar">
        <div class="tl-export-info">
            <span class="tl-export-count"><i class="fas fa-comments"></i> <span id="tlVisibleCount"><?= $totalAll ?></span> visíveis</span>
            <span class="tl-export-selected" id="tlSelectedBadge" style="display:none">
                <i class="fas fa-check-square"></i> <span id="tlSelectedCount">0</span> selecionada(s)
            </span>
        </div>
        <div class="tl-export-actions">
            <button type="button" class="btn btn-sm btn-outline" data-action="tl-toggle-select" id="btnTlToggleSelect">
                <i class="fas fa-list-check"></i> <span id="btnTlToggleSelectLabel">Selecionar</span>
            </button>
            <button type="button" class="btn btn-sm btn-outline" data-action="tl-select-all" id="btnTlSelectAll" style="display:none">
                <i class="fas fa-check-double"></i> Selecionar todas
            </button>
            <button type="button" class="btn btn-sm btn-outline" data-action="tl-clear-selection" id="btnTlClearSelection" style="display:none">
                <i class="fas fa-xmark"></i> Limpar
            </button>
            <button type="button" class="btn btn-sm btn-primary" data-action="tl-export-selected" id="btnTlExportSelected" disabled>
                <i class="fas fa-file-pdf"></i> Exportar (<span id="tlExportSelectedCount">0</span>)
            </button>
            <button type="button" class="btn btn-sm btn-outline" data-action="tl-export-selected-csv" id="btnTlExportSelectedCsv" disabled>
                <i class="fas fa-file-csv"></i> CSV
            </button>
        </div>
    </div>

    <?php if (empty($groups)): ?>
        <div class="card"><div class="card-body" style="padding:0">
            <div class="empty-state-enhanced" style="padding:60px 24px">
                <div class="empty-icon"><i class="fas fa-stream"></i></div>
                <h3>Nenhuma conversa no período</h3>
                <p>Ajuste o intervalo de datas ou os filtros para ver resultados.</p>
            </div>
        </div></div>
    <?php else: ?>
    <div class="tl-groups" id="tlGroups" data-mode="view">
        <?php foreach ($groups as $gi => $g): ?>
            <div class="tl-group" data-group-index="<?= $gi ?>">
                <div class="tl-group-header" data-role="tl-group-toggle">
                    <div class="tl-group-head-left">
                        <i class="fas fa-chevron-right tl-group-chevron"></i>
                        <strong class="tl-group-label"><?= e($g['label']) ?></strong>
                        <span class="tl-group-range"><?= date('d/m', strtotime($g['from'])) ?><?= $g['from'] !== $g['to'] ? ' – ' . date('d/m', strtotime($g['to'])) : '' ?></span>
                    </div>
                    <div class="tl-group-head-stats">
                        <span class="badge badge-primary" title="Total"><?= $g['total'] ?></span>
                        <?php if ($g['open'] > 0): ?>
                            <span class="badge badge-info" title="Em aberto"><?= $g['open'] ?> aberto</span>
                        <?php endif; ?>
                        <?php if ($g['closed'] > 0): ?>
                            <span class="badge badge-success" title="Concluídas"><?= $g['closed'] ?> fechado</span>
                        <?php endif; ?>
                        <?php if ($g['csat_avg'] !== null): ?>
                            <span class="badge badge-warning" title="CSAT médio"><i class="fas fa-star" style="font-size:9px"></i> <?= number_format($g['csat_avg'], 1) ?></span>
                        <?php endif; ?>
                        <?php if ($g['avg_response_min'] !== null): ?>
                            <span class="badge badge-secondary" title="Tempo médio de resposta">~<?= $g['avg_response_min'] ?> min</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="tl-group-body">
                    <div class="tl-group-summary">
                        <span><i class="fas fa-user" style="color:var(--text-muted)"></i> Top: <strong><?= e($g['top_agent']) ?></strong></span>
                        <span><i class="fas fa-share-alt" style="color:var(--text-muted)"></i> Canal: <strong><?= e($g['top_channel']) ?></strong></span>
                    </div>
                    <div class="tl-conv-list">
                        <?php foreach ($g['conversations'] as $conv): ?>
                            <div class="tl-conv-row timeline-item-card" data-conv-id="<?= (int) $conv['id'] ?>">
                                <label class="conv-checkbox" data-role="tl-conv-checkbox" onclick="event.stopPropagation()" style="display:none">
                                    <input type="checkbox" data-role="tl-conv-check" value="<?= (int) $conv['id'] ?>" onchange="window.__tlExport.onChange()">
                                </label>
                                <div class="tl-conv-main">
                                    <div class="tl-conv-top">
                                        <i class="<?= channel_icon($conv['channel_type'] ?? 'webchat') ?>" style="color:<?= ['whatsapp'=>'#25D366','webchat'=>'var(--brand)','email'=>'#f59e0b','telegram'=>'#0088cc','facebook'=>'#1877f2','instagram'=>'#e1306c','phone'=>'#6c757d'][$conv['channel_type'] ?? ''] ?? 'var(--text-muted)' ?>"></i>
                                        <strong class="tl-conv-subject">
                                            <?= e($conv['subject'] ?: truncate($conv['id'] . ' — sem assunto', 60)) ?>
                                        </strong>
                                        <span class="tl-conv-status" style="background:<?= $statusColors[$conv['status']] ?? '#6c757d' ?>1a;color:<?= $statusColors[$conv['status']] ?? '#6c757d' ?>">
                                            <i class="fas <?= $statusIcons[$conv['status']] ?? 'fa-circle' ?>"></i>
                                            <?= $statusLabels[$conv['status']] ?? $conv['status'] ?>
                                        </span>
                                    </div>
                                    <div class="tl-conv-meta">
                                        <?php if (!empty($conv['contact_name'])): ?>
                                            <span><i class="fas fa-user" style="font-size:9px"></i> <?= e($conv['contact_name']) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($conv['department_name'])): ?>
                                            <span class="tl-conv-dept" style="color:<?= e($conv['department_color'] ?? 'var(--text-muted)') ?>">
                                                <i class="fas fa-layer-group" style="font-size:9px"></i> <?= e($conv['department_name']) ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($conv['assigned_user_name'])): ?>
                                            <span><i class="fas fa-headset" style="font-size:9px"></i> <?= e($conv['assigned_user_name']) ?></span>
                                        <?php endif; ?>
                                        <span><i class="fas fa-comment-dots" style="font-size:9px"></i> <?= (int) ($conv['message_count_cache'] ?? 0) ?> mensagens</span>
                                    </div>
                                </div>
                                <div class="tl-conv-aside">
                                    <span class="tl-conv-time"><?= time_elapsed($conv['created_at']) ?></span>
                                    <div class="tl-conv-actions" onclick="event.stopPropagation()">
                                        <a href="<?= url('inbox/' . (int) $conv['id'] . '/pdf') ?>" target="_blank"
                                           class="btn btn-sm btn-outline btn-icon" title="PDF desta conversa">
                                            <i class="fas fa-file-pdf"></i>
                                        </a>
                                        <a href="<?= url('inbox') ?>?conv=<?= (int) $conv['id'] ?>"
                                           class="btn btn-sm btn-outline btn-icon" title="Abrir no inbox">
                                            <i class="fas fa-arrow-up-right-from-square"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php // (helpers de renderização definidos no topo do arquivo) ?>

<script>
(function() {
    var pdfBase = <?= json_encode(url('reports/timeline/pdf'), JSON_UNESCAPED_SLASHES) ?>;
    var csvBase = <?= json_encode(url('reports/timeline/csv'), JSON_UNESCAPED_SLASHES) ?>;
    var groups = document.getElementById('tlGroups');
    var btnToggle = document.getElementById('btnTlToggleSelect');
    var btnLabel = document.getElementById('btnTlToggleSelectLabel');
    var btnSelectAll = document.getElementById('btnTlSelectAll');
    var btnClear = document.getElementById('btnTlClearSelection');
    var btnExportSelected = document.getElementById('btnTlExportSelected');
    var badge = document.getElementById('tlSelectedBadge');
    var selCountEl = document.getElementById('tlSelectedCount');
    var expCountEl = document.getElementById('tlExportSelectedCount');
    var btnExportCsv = document.getElementById('btnTlExportSelectedCsv');
    var selAll = false;
    var mode = 'view';

    // Granularity buttons
    document.querySelectorAll('[data-role="granularity-btn"]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var g = this.dataset.granularity;
            var input = document.getElementById('granularityInput');
            if (input) input.value = g;
            // Atualiza o range default por granularidade
            var defaults = { day: 14, week: 84, month: 365, year: 1825 };
            var today = new Date();
            var from = new Date(today.getTime() - (defaults[g] || 14) * 86400000);
            var fmt = function(d) { return d.toISOString().slice(0, 10); };
            var fromInput = document.querySelector('input[name="from"]');
            var toInput = document.querySelector('input[name="to"]');
            if (fromInput) fromInput.value = fmt(from);
            if (toInput) toInput.value = fmt(today);
            document.getElementById('tlFilters').submit();
        });
    });

    // Toggle de grupos (collapse)
    document.querySelectorAll('[data-role="tl-group-toggle"]').forEach(function(h) {
        h.addEventListener('click', function() {
            var group = h.closest('.tl-group');
            if (!group) return;
            group.classList.toggle('is-collapsed');
        });
    });

    // Modo seleção
    function setMode(next) {
        mode = next;
        if (groups) groups.dataset.mode = mode;
        var checkboxes = document.querySelectorAll('[data-role="tl-conv-checkbox"]');
        checkboxes.forEach(function(cb) { cb.style.display = (mode === 'select') ? 'flex' : 'none'; });
        document.querySelectorAll('.timeline-item-card').forEach(function(item) {
            var cb = item.querySelector('[data-role="tl-conv-check"]');
            if (cb) {
                item.classList.toggle('is-selecting', mode === 'select');
                item.classList.toggle('is-selected', !!cb.checked);
            }
        });
        if (btnLabel) btnLabel.textContent = (mode === 'select') ? 'Cancelar seleção' : 'Selecionar';
        if (btnSelectAll) btnSelectAll.style.display = (mode === 'select') ? '' : 'none';
        if (btnClear) btnClear.style.display = (mode === 'select') ? '' : 'none';
        if (mode !== 'select') clearSelection();
        updateSelectedUI();
    }

    function clearSelection() {
        selAll = false;
        document.querySelectorAll('[data-role="tl-conv-check"]').forEach(function(cb) { cb.checked = false; });
        document.querySelectorAll('.timeline-item-card').forEach(function(item) { item.classList.remove('is-selected'); });
        if (btnSelectAll) btnSelectAll.innerHTML = '<i class="fas fa-check-double"></i> Selecionar todas';
        updateSelectedUI();
    }

    function selectAll() {
        selAll = true;
        document.querySelectorAll('[data-role="tl-conv-check"]').forEach(function(cb) { cb.checked = true; });
        document.querySelectorAll('.timeline-item-card').forEach(function(item) { item.classList.add('is-selected'); });
        if (btnSelectAll) btnSelectAll.innerHTML = '<i class="fas fa-xmark"></i> Limpar todas';
        updateSelectedUI();
    }

    function updateSelectedUI() {
        var n = countSelected();
        if (selCountEl) selCountEl.textContent = n;
        if (expCountEl) expCountEl.textContent = n;
        if (btnExportSelected) btnExportSelected.disabled = n === 0;
        if (btnExportCsv) btnExportCsv.disabled = n === 0;
        if (badge) badge.style.display = (n > 0) ? 'inline-flex' : 'none';
    }

    function countSelected() {
        return document.querySelectorAll('[data-role="tl-conv-check"]:checked').length;
    }

    function onChange() {
        document.querySelectorAll('.timeline-item-card').forEach(function(item) {
            var cb = item.querySelector('[data-role="tl-conv-check"]');
            if (cb) item.classList.toggle('is-selected', !!cb.checked);
        });
        selAll = false;
        if (btnSelectAll) btnSelectAll.innerHTML = '<i class="fas fa-check-double"></i> Selecionar todas';
        updateSelectedUI();
    }

    function exportSelected() {
        var ids = [];
        document.querySelectorAll('[data-role="tl-conv-check"]:checked').forEach(function(cb) {
            ids.push(cb.value);
        });
        if (!ids.length) return;
        window.location = pdfBase + '?ids=' + encodeURIComponent(ids.join(','));
    }

    function exportSelectedCsv() {
        var ids = [];
        document.querySelectorAll('[data-role="tl-conv-check"]:checked').forEach(function(cb) {
            ids.push(cb.value);
        });
        if (!ids.length) return;
        window.location = csvBase + '?ids=' + encodeURIComponent(ids.join(','));
    }

    document.addEventListener('click', function(e) {
        var t = e.target.closest('[data-action]');
        if (!t) return;
        var act = t.dataset.action;
        if (act === 'tl-toggle-select') {
            e.preventDefault();
            setMode(mode === 'select' ? 'view' : 'select');
        } else if (act === 'tl-select-all') {
            e.preventDefault();
            if (selAll) clearSelection(); else selectAll();
        } else if (act === 'tl-clear-selection') {
            e.preventDefault();
            clearSelection();
        } else if (act === 'tl-export-selected') {
            e.preventDefault();
            exportSelected();
        } else if (act === 'tl-export-selected-csv') {
            e.preventDefault();
            exportSelectedCsv();
        }
    });

    window.__tlExport = { onChange: onChange };
})();
</script>
