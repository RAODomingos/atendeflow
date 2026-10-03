<?php
$filterBase = url('contacts/' . (int) $contact['id']);
$hasFilters = ($filters['year'] ?? 0) || ($filters['month'] ?? 0) || ($filters['department'] ?? 0) || ($filters['status'] ?? null);
$statusLabels = [
    'new' => 'Novos', 'open' => 'Abertos',
    'waiting_customer' => 'Aguardando cliente', 'waiting_internal' => 'Aguardando interno',
    'resolved' => 'Resolvidos', 'closed' => 'Fechados', 'spam' => 'Spam',
];
$statusKeys = ['new', 'open', 'waiting_customer', 'waiting_internal', 'resolved', 'closed', 'spam'];
$totalCount = (int) ($statusCounts['_total'] ?? count($conversations));
?>
<div class="contact-detail cd-page">
    <div class="page-actions">
        <a href="<?= url('contacts') ?>" class="btn btn-sm btn-outline">
            <i class="fas fa-arrow-left"></i> Voltar
        </a>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <a href="<?= url('contacts/') ?><?= (int) $contact['id'] ?>/pdf" target="_blank" class="btn btn-sm btn-outline" title="Baixar PDF">
                <i class="fas fa-file-pdf"></i> PDF
            </a>
            <button class="btn btn-sm btn-outline" onclick="openMergeContactModal()">
                <i class="fas fa-code-merge"></i> Mesclar
            </button>
            <button class="btn btn-sm btn-primary" onclick="openContactDrawer()">
                <i class="fas fa-edit"></i> Editar
            </button>
            <button class="btn btn-sm btn-danger" onclick="confirmDelete()" title="Excluir">
                <i class="fas fa-trash"></i>
            </button>
        </div>
    </div>

    <style>
    .cd-page{margin:0;padding:2px 26px 32px 30px}
    .cd-head{display:flex;gap:14px;align-items:flex-start;padding:6px 2px 12px}
    .cd-avatar{width:44px;height:44px;border-radius:50%;background:var(--brand-soft);color:var(--brand-dark);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:18px;flex-shrink:0;overflow:hidden}
    .cd-avatar img{width:100%;height:100%;object-fit:cover}
    .cd-id{flex:1;min-width:0}
    .cd-name{font-size:17px;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:8px;flex-wrap:wrap}
    .cd-sub{font-size:12.5px;color:var(--text-secondary);margin-top:2px;display:flex;gap:6px;flex-wrap:wrap;align-items:center}
    .cd-sub i{font-size:11px;color:var(--text-muted)}
    .cd-tags{display:inline-flex;gap:4px;flex-wrap:wrap}
    .cd-info{margin:0 0 14px}
    .cd-info-body{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:4px 20px;padding:6px 18px 14px}
    .cd-info-item{display:flex;align-items:center;gap:12px;padding:10px 0;min-width:0}
    .cd-info-icon{width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0}
    .cd-info-text{min-width:0}
    .cd-info-label{font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em}
    .cd-info-value{font-size:14px;font-weight:600;color:var(--text-primary);overflow:hidden;text-overflow:ellipsis}
    .cd-info-sub{font-size:12px;color:var(--text-secondary);margin-top:1px}
    .cd-notes{margin:0 18px 16px;padding:12px 14px;background:var(--bg-panel-alt);border:1px solid var(--border-soft);border-radius:8px;font-size:13px;color:var(--text-secondary);line-height:1.6}
    .cd-notes strong{display:flex;align-items:center;gap:6px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px}
    .cd-pivots{display:flex;gap:2px;margin:0 0 14px;flex-wrap:wrap;border-bottom:1px solid var(--border-soft)}
    .cd-pivot{border:none;background:none;font-family:inherit;font-size:13px;font-weight:600;color:var(--text-secondary);padding:9px 10px;cursor:pointer;position:relative;display:inline-flex;align-items:center;text-decoration:none}
    .cd-pivot:hover{color:var(--text-primary)}
    .cd-pivot.active{color:var(--brand-dark);font-weight:700}
    .cd-pivot.active::after{content:'';position:absolute;left:8px;right:8px;bottom:-1px;height:2px;background:var(--brand)}
    .cd-pivot .cnt{font-size:11px;font-weight:700;background:var(--bg-panel-alt);border:1px solid var(--border-soft);color:var(--text-secondary);border-radius:10px;padding:0 7px;margin-left:7px;font-variant-numeric:tabular-nums}
    .cd-pivot.active .cnt{background:var(--brand-soft);border-color:transparent;color:var(--brand-dark)}
    .cd-sec{background:var(--bg-panel);border:1px solid var(--border-soft);border-radius:8px;overflow:hidden}
    .cd-sec-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:14px 18px;border-bottom:1px solid var(--border-soft)}
    .cd-sec-title{font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px;margin-right:auto}
    .cd-conv{position:relative}
    .cd-conv.is-selected{background:var(--brand-soft)}
    .cd-conv .conv-checkbox{display:none;align-items:center;justify-content:center;padding:0 2px 0 12px;cursor:pointer;background:none;border:none}
    .cd-chan{width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}
    .cd-subject{font-size:14px;font-weight:700;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;text-decoration:none}
    .cd-subject:hover{color:var(--brand)}
    .timeline-dept-badge{font-size:11px;font-weight:600;padding:1px 8px;border-radius:10px}
    .conv-list-close{color:var(--text-muted)}
    @media(max-width:768px){.cd-page{padding:2px 12px 24px}}
    </style>

    <div class="cd-head">
        <div class="cd-avatar">
            <?php if (!empty($contact['avatar'])): ?>
                <img src="<?= e(str_starts_with($contact['avatar'], 'http') ? $contact['avatar'] : upload_url($contact['avatar'])) ?>" alt="<?= e($contact['name']) ?>">
            <?php else: ?>
                <?= mb_strtoupper(mb_substr($contact['name'], 0, 1)) ?>
            <?php endif; ?>
        </div>
        <div class="cd-id">
            <div class="cd-name">
                <?= e($contact['name']) ?>
                <?php $isOnline = !empty($contact['last_activity_at']) && (time() - strtotime($contact['last_activity_at']) < 600); ?>
                <span class="contact-status-dot <?= $isOnline ? 'online' : 'offline' ?>" title="<?= $isOnline ? 'Online' : 'Offline' ?>"></span>
                <?php if (!empty($contact['tags'])): ?>
                    <span class="cd-tags">
                        <?php foreach ($contact['tags'] as $tag): ?>
                            <span class="conv-tag-modern" style="background:<?= e($tag['color'] ?? '#6c757d') ?>;color:<?= e(contrast_color($tag['color'] ?? '#6c757d')) ?>">
                                <?= e($tag['name']) ?>
                            </span>
                        <?php endforeach; ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="cd-sub">
                <?php if (!empty($contact['company'])): ?>
                    <span><i class="fas fa-building"></i> <?= e($contact['company']) ?></span>
                    <span>·</span>
                <?php endif; ?>
                <span>Contato desde <?= format_datetime($contact['created_at'] ?? '') ?></span>
                <span>·</span>
                <span>Última atividade: <?= !empty($contact['last_activity_at']) ? time_elapsed($contact['last_activity_at']) : '-' ?></span>
            </div>
        </div>
    </div>

    <div class="cd-sec cd-info">
        <div class="cd-sec-head">
            <span class="cd-sec-title">
                <i class="fas fa-id-card" style="color:var(--brand)"></i>
                Informações do contato
            </span>
            <button type="button" class="btn btn-sm btn-outline" onclick="openContactDrawer()">
                <i class="fas fa-edit"></i> Editar
            </button>
        </div>
        <div class="cd-info-body">
            <div class="cd-info-item">
                <span class="cd-info-icon ci-email"><i class="fas fa-envelope"></i></span>
                <div class="cd-info-text">
                    <div class="cd-info-label">E-mail</div>
                    <div class="cd-info-value"><?= !empty($contact['email']) ? e($contact['email']) : '<span class="text-muted">Não informado</span>' ?></div>
                    <?php if (!empty($contact['emails'])): ?>
                        <?php foreach (array_filter($contact['emails'], fn($e) => ($e['email'] ?? null) !== ($contact['email'] ?? null)) as $em): ?>
                            <div class="cd-info-sub"><?= e($em['email']) ?><?= !empty($em['label']) ? ' (' . e($em['label']) . ')' : '' ?></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="cd-info-item">
                <span class="cd-info-icon ci-phone"><i class="fas fa-phone"></i></span>
                <div class="cd-info-text">
                    <div class="cd-info-label">Telefone</div>
                    <div class="cd-info-value"><?= !empty($contact['phone']) ? e($contact['phone']) : '<span class="text-muted">Não informado</span>' ?></div>
                    <?php if (!empty($contact['phones'])): ?>
                        <?php foreach (array_filter($contact['phones'], fn($p) => ($p['phone'] ?? null) !== ($contact['phone'] ?? null)) as $ph): ?>
                            <div class="cd-info-sub"><?= e($ph['phone']) ?><?= !empty($ph['label']) ? ' (' . e($ph['label']) . ')' : '' ?></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!empty($contact['document'])): ?>
                <div class="cd-info-item">
                    <span class="cd-info-icon ci-document"><i class="fas fa-id-card"></i></span>
                    <div class="cd-info-text">
                        <div class="cd-info-label">Documento</div>
                        <div class="cd-info-value"><?= e($contact['document']) ?></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php if (!empty($contact['notes'])): ?>
            <div class="cd-notes">
                <strong><i class="fas fa-sticky-note"></i> Observações</strong>
                <?= nl2br(e($contact['notes'])) ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="cd-pivots" role="tablist" aria-label="Filtrar por status">
        <?php
        $pivotQ = function ($status) use ($filterBase, $filters) {
            $q = [];
            if ((int)($filters['year'] ?? 0)) $q['year'] = (int)$filters['year'];
            if ((int)($filters['month'] ?? 0)) $q['month'] = (int)$filters['month'];
            if ((int)($filters['department'] ?? 0)) $q['department'] = (int)$filters['department'];
            if ($status !== '') $q['status'] = $status;
            return $filterBase . ($q ? '?' . http_build_query($q) : '');
        };
        $curStatus = (string)($filters['status'] ?? '');
        ?>
        <a href="<?= $pivotQ('') ?>" class="cd-pivot <?= $curStatus === '' ? 'active' : '' ?>">Todos<span class="cnt"><?= $totalCount ?></span></a>
        <?php foreach ($statusKeys as $sk):
            $count = (int)($statusCounts[$sk] ?? 0);
            if ($count === 0 && $curStatus !== $sk) continue;
        ?>
            <a href="<?= $pivotQ($sk) ?>" class="cd-pivot <?= $curStatus === $sk ? 'active' : '' ?>"><?= e($statusLabels[$sk] ?? $sk) ?><span class="cnt"><?= $count ?></span></a>
        <?php endforeach; ?>
    </div>

    <div class="cd-sec">
        <div class="cd-sec-head">
            <span class="cd-sec-title">
                <i class="fas fa-comments" style="color:var(--brand)"></i>
                Histórico de Conversas
                <span class="badge badge-tag-count"><?= $totalCount ?></span>
            </span>
            <form method="GET" action="<?= $filterBase ?>" class="conv-filter-form" id="convFilterForm">
                <div class="conv-filter-group" title="Mês">
                    <i class="fas fa-calendar-day"></i>
                    <select name="month" onchange="document.getElementById('convFilterForm').submit()">
                        <option value="0">Todos os meses</option>
                        <?php
                        $monthNames = [1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
                                       'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
                        foreach ($monthNames as $m => $label): ?>
                            <option value="<?= $m ?>" <?= (int)($filters['month'] ?? 0) === $m ? 'selected' : '' ?>>
                                <?= $label ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="conv-filter-group" title="Ano">
                    <i class="fas fa-calendar"></i>
                    <select name="year" onchange="document.getElementById('convFilterForm').submit()">
                        <option value="0">Todos os anos</option>
                        <?php
                        $years = array_unique(array_merge(
                            array_column($availableMonths, 'year'),
                            [(int) date('Y')]
                        ));
                        rsort($years);
                        foreach ($years as $y): ?>
                            <option value="<?= $y ?>" <?= (int)($filters['year'] ?? 0) === (int) $y ? 'selected' : '' ?>>
                                <?= $y ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="conv-filter-group" title="Departamento">
                    <i class="fas fa-layer-group"></i>
                    <select name="department" onchange="document.getElementById('convFilterForm').submit()">
                        <option value="0">Todos os departamentos</option>
                        <?php foreach ($availableDepartments as $d): ?>
                            <option value="<?= (int) $d['id'] ?>" <?= (int)($filters['department'] ?? 0) === (int) $d['id'] ? 'selected' : '' ?>>
                                <?= e($d['name']) ?> (<?= (int) $d['total'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="conv-filter-group" title="Status">
                    <i class="fas fa-circle-info"></i>
                    <select name="status" onchange="document.getElementById('convFilterForm').submit()">
                        <option value="">Todos os status</option>
                        <?php foreach ($statusKeys as $sk): ?>
                            <option value="<?= e($sk) ?>" <?= ($filters['status'] ?? '') === $sk ? 'selected' : '' ?>>
                                <?= e($statusLabels[$sk] ?? $sk) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($hasFilters): ?>
                    <a href="<?= $filterBase ?>" class="btn btn-sm btn-outline" title="Limpar filtros">
                        <i class="fas fa-xmark"></i> Limpar
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <div class="conv-export-bar" id="convExportBar">
            <div class="conv-export-info">
                <span class="conv-export-count"><i class="fas fa-comments"></i> <span id="convVisibleCount"><?= count($conversations) ?></span> visíveis</span>
                <span class="conv-export-selected" id="convSelectedBadge" style="display:none">
                    <i class="fas fa-check-square"></i> <span id="convSelectedCount">0</span> selecionada(s)
                </span>
            </div>
            <div class="conv-export-actions">
                <button type="button" class="btn btn-sm btn-outline" data-action="toggle-conv-select" id="btnToggleConvSelect">
                    <i class="fas fa-list-check"></i> <span id="btnToggleConvSelectLabel">Selecionar</span>
                </button>
                <button type="button" class="btn btn-sm btn-outline" data-action="select-all-conv" id="btnSelectAllConv" style="display:none">
                    <i class="fas fa-check-double"></i> Selecionar todas
                </button>
                <button type="button" class="btn btn-sm btn-outline" data-action="clear-conv-selection" id="btnClearConvSelection" style="display:none">
                    <i class="fas fa-xmark"></i> Limpar
                </button>
                <button type="button" class="btn btn-sm btn-primary" data-action="export-selected-conv" id="btnExportSelected" disabled>
                    <i class="fas fa-file-pdf"></i> Exportar selecionadas (<span id="exportSelectedCount">0</span>)
                </button>
                <a href="<?= $filterBase . '/pdf' . ($hasFilters ? '?' . http_build_query($filters) : '') ?>"
                   class="btn btn-sm btn-primary"
                   id="btnExportAll"
                   title="Exportar todas as conversas<?= $hasFilters ? ' (filtradas)' : '' ?>"
                   <?= count($conversations) === 0 ? 'style="display:none"' : '' ?>>
                    <i class="fas fa-file-pdf"></i> <?= $hasFilters ? 'Exportar filtradas' : 'Exportar todas' ?>
                </a>
            </div>
        </div>

        <div>
            <?php if (empty($conversations)): ?>
                <div class="empty-state-enhanced" style="padding:60px 24px">
                    <div class="empty-icon"><i class="fas fa-inbox"></i></div>
                    <h3><?= $hasFilters ? 'Nenhuma conversa neste filtro' : 'Nenhuma conversa registrada' ?></h3>
                    <p><?= $hasFilters ? 'Tente ajustar o mês, ano ou departamento.' : 'Este cliente ainda não possui conversas registradas.' ?></p>
                </div>
            <?php else: ?>
                <div class="conversation-list" id="convTimeline" data-mode="view">
                    <?php foreach ($conversations as $conv):
                        $chColor = ['whatsapp'=>'#25D366','webchat'=>'var(--brand)','email'=>'#f59e0b','telegram'=>'#0088cc','facebook'=>'#1877f2','instagram'=>'#e1306c','phone'=>'#6c757d'][$conv['channel_type'] ?? ''] ?? 'var(--text-muted)';
                        $chSoft = ['whatsapp'=>'var(--success-soft)','webchat'=>'var(--brand-soft)','email'=>'var(--warning-soft)','telegram'=>'var(--info-soft)','facebook'=>'var(--info-soft)','instagram'=>'var(--danger-soft)','phone'=>'var(--bg-panel-alt)'][$conv['channel_type'] ?? ''] ?? 'var(--bg-panel-alt)';
                        $convPreview = trim((string) ($conv['subject'] ?? ''));
                        if ($convPreview === '') {
                            $lmType = $conv['last_message_type'] ?? '';
                            $lmLabels = ['image' => '🖼️ Imagem', 'video' => '🎬 Vídeo', 'audio' => '🎵 Áudio', 'file' => '📎 Arquivo', 'sticker' => '🖼️ Figurinha', 'csat_request' => '⭐ Avaliação', 'system' => '🔔 Sistema'];
                            if (isset($lmLabels[$lmType])) {
                                $convPreview = $lmLabels[$lmType];
                            } else {
                                $rawLm = trim((string) ($conv['last_message'] ?? ''));
                                if ($rawLm !== '' && str_starts_with($rawLm, '{')) {
                                    $decLm = json_decode($rawLm, true);
                                    if (is_array($decLm)) {
                                        if (isset($decLm['title']) || isset($decLm['prompt'])) $convPreview = '⭐ Avaliação';
                                        elseif (!empty($decLm['url'])) $convPreview = '📎 Mídia';
                                        else $convPreview = 'Sem mensagens';
                                    } else {
                                        $convPreview = truncate($rawLm, 90);
                                    }
                                } else {
                                    $convPreview = $rawLm !== '' ? truncate($rawLm, 90) : 'Sem mensagens';
                                }
                            }
                        }
                    ?>
                        <div class="conversation-item cd-conv" data-conv-id="<?= (int) $conv['id'] ?>"
                            data-conv-name="<?= e($conv['subject'] ?: ('Conversa #' . $conv['id'])) ?>">
                            <label class="conv-checkbox" data-role="conv-checkbox" style="display:none">
                                <input type="checkbox" data-role="conv-check" value="<?= (int) $conv['id'] ?>" onchange="window.__convExport.onChange()">
                            </label>
                            <div class="cd-chan" style="background:<?= $chSoft ?>;color:<?= $chColor ?>">
                                <i class="<?= channel_icon($conv['channel_type'] ?? 'webchat') ?>"></i>
                            </div>
                            <div class="convo-info">
                                <div class="convo-header">
                                    <a href="<?= url('inbox') ?>?conv=<?= $conv['id'] ?>" class="cd-subject"><?= e(truncate($convPreview, 90)) ?></a>
                                    <span class="convo-time" title="<?= e(format_datetime($conv['created_at'])) ?>"><?= time_elapsed($conv['created_at']) ?></span>
                                </div>
                                <p class="convo-preview">
                                    <?= e($conv['channel_name'] ?? '') ?>
                                    <?php if (!empty($conv['protocol'])): ?>
                                        · #<?= e(format_protocol($conv['protocol'])) ?>
                                    <?php endif; ?>
                                    · <?= (int)($conv['message_count'] ?? 0) ?> msgs · <?= e($conv['assigned_user_name'] ?? 'Não atribuído') ?>
                                </p>
                                <div class="convo-meta">
                                    <?= status_badge($conv['status']) ?>
                                    <?= priority_badge($conv['priority'] ?? 'normal') ?>
                                    <?php if (!empty($conv['department_name'])): ?>
                                        <span class="timeline-dept-badge" style="background:<?= e($conv['department_color'] ?? 'var(--border-soft)') ?>20;color:<?= e($conv['department_color'] ?? 'var(--text-muted)') ?>">
                                            <i class="fas fa-layer-group"></i> <?= e($conv['department_name']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($conv['unit'])): ?>
                                        <span class="convo-unit"><i class="fas fa-location-dot"></i> <?= e($conv['unit']) ?></span>
                                    <?php endif; ?>
                                    <?php if (in_array($conv['status'], ['resolved', 'closed']) && (!empty($conv['close_reason']) || !empty($conv['close_description']))): ?>
                                        <span class="conv-list-close" title="<?= e(trim(($conv['close_reason'] ?? '') . (!empty($conv['close_description']) ? ' — ' . $conv['close_description'] : ''))) ?>"><i class="fas fa-flag"></i> <?= e(truncate($conv['close_reason'] ?: $conv['close_description'], 60)) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <a href="<?= url('inbox/' . (int) $conv['id'] . '/pdf') ?>" target="_blank"
                               class="btn btn-sm btn-outline btn-icon" style="margin-left:auto;flex-shrink:0;align-self:center" title="Baixar PDF desta conversa (com mensagens)">
                                <i class="fas fa-file-pdf"></i>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="contact-drawer-overlay" id="contactDrawerOverlay" onclick="closeContactDrawer()"></div>
<div class="contact-drawer" id="contactDrawer">
    <div class="contact-drawer-header">
        <h3><i class="fas fa-user-edit" style="color:var(--brand)"></i> Editar Contato</h3>
        <button class="contact-drawer-close" onclick="closeContactDrawer()">&times;</button>
    </div>
    <div class="contact-drawer-body">
        <form id="contactEditForm" action="<?= url("contacts/{$contact['id']}/update") ?>" method="POST">
            <?= csrf_field() ?>
            <div class="form-group">
                <label><i class="fas fa-user"></i> Nome *</label>
                <input type="text" name="name" class="form-control" required
                       value="<?= e($contact['name'] ?? '') ?>"
                       placeholder="Nome completo">
            </div>
            <div class="form-group">
                <label><i class="fas fa-building"></i> Empresa</label>
                <input type="text" name="company" class="form-control"
                       value="<?= e($contact['company'] ?? '') ?>"
                       placeholder="Empresa">
            </div>
            <div class="form-group">
                <label><i class="fas fa-envelope"></i> E-mail</label>
                <input type="email" name="email" class="form-control"
                       value="<?= e($contact['email'] ?? '') ?>"
                       placeholder="email@exemplo.com">
            </div>
            <div class="form-group">
                <label><i class="fas fa-phone"></i> Telefone</label>
                <input type="text" name="phone" class="form-control"
                       value="<?= e($contact['phone'] ?? '') ?>"
                       placeholder="(11) 99999-9999">
            </div>
            <div class="form-group">
                <label><i class="fas fa-id-card"></i> CPF/CNPJ</label>
                <input type="text" name="document" class="form-control"
                       value="<?= e($contact['document'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label><i class="fas fa-tags"></i> Etiquetas</label>
                <select name="tag_ids[]" class="form-control" multiple>
                    <?php foreach ($tags as $tag): ?>
                        <option value="<?= $tag['id'] ?>"
                            <?= $contact && in_array($tag['id'], array_column($contact['tags'] ?? [], 'id')) ? 'selected' : '' ?>>
                            <?= e($tag['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label><i class="fas fa-sticky-note"></i> Observações</label>
                <textarea name="notes" rows="5" class="form-control"
                          placeholder="Informações adicionais sobre o contato..."><?= e($contact['notes'] ?? '') ?></textarea>
            </div>
        </form>
    </div>
    <div class="contact-drawer-footer">
        <button type="button" class="btn btn-outline" onclick="closeContactDrawer()">Cancelar</button>
        <button type="submit" class="btn btn-primary" form="contactEditForm">
            <i class="fas fa-save"></i> Salvar
        </button>
    </div>
</div>

<form id="deleteForm" action="<?= url('contacts/') ?><?= $contact['id'] ?>/delete" method="POST" style="display:none">
    <?= csrf_field() ?>
</form>

<form id="mergeContactForm" action="<?= url('contacts/') ?><?= $contact['id'] ?>/merge" method="POST" style="display:none">
    <?= csrf_field() ?>
    <input type="hidden" name="target_id" id="mergeContactTarget">
</form>

<div class="modal" id="mergeContactModal" style="display:none">
    <div class="modal-overlay" onclick="closeMergeContactModal()"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-code-merge" style="color:var(--brand)"></i> Mesclar contato</h3>
            <button class="modal-close" type="button" onclick="closeMergeContactModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p class="text-muted">Unifique este contato em outro. O histórico de conversas, telefones, e-mails e etiquetas serão consolidados no contato de destino.</p>
            <input type="text" id="mergeContactSearch" class="form-control" placeholder="Buscar contato de destino..." oninput="searchMergeContacts()">
            <div id="mergeContactResults" class="merge-contact-results"></div>
        </div>
    </div>
</div>

<script>
function confirmDelete() {
    OminiConfirm('Tem certeza que deseja excluir este contato? Todas as conversas vinculadas também serão removidas.').then(function(ok) {
        if (!ok) return;
        document.getElementById('deleteForm').submit();
    });
}

function openContactDrawer() {
    document.getElementById('contactDrawer').classList.add('open');
    document.getElementById('contactDrawerOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeContactDrawer() {
    document.getElementById('contactDrawer').classList.remove('open');
    document.getElementById('contactDrawerOverlay').classList.remove('open');
    document.body.style.overflow = '';
}

// ===== Exportação de conversas (individual / selecionadas / filtradas) =====
window.__convExport = (function() {
    var pdfBase = <?= json_encode($filterBase . '/pdf', JSON_UNESCAPED_SLASHES) ?>;
    var timeline = document.getElementById('convTimeline');
    var btnToggle = document.getElementById('btnToggleConvSelect');
    var btnLabel = document.getElementById('btnToggleConvSelectLabel');
    var btnSelectAll = document.getElementById('btnSelectAllConv');
    var btnClear = document.getElementById('btnClearConvSelection');
    var btnExportSelected = document.getElementById('btnExportSelected');
    var badge = document.getElementById('convSelectedBadge');
    var selCount = document.getElementById('convSelectedCount');
    var expCount = document.getElementById('exportSelectedCount');
    var selAll = false;
    var mode = 'view';

    function setMode(next) {
        mode = next;
        if (!timeline) return;
        timeline.dataset.mode = mode;
        timeline.querySelectorAll('[data-role="conv-checkbox"]').forEach(function (cb) {
            cb.style.display = (mode === 'select') ? 'flex' : 'none';
        });
        timeline.querySelectorAll('.cd-conv').forEach(function (item) {
            item.classList.toggle('is-selecting', mode === 'select');
            item.classList.toggle('is-selected', !!item.querySelector('[data-role="conv-check"]').checked);
        });
        if (btnLabel) btnLabel.textContent = (mode === 'select') ? 'Cancelar seleção' : 'Selecionar';
        if (btnSelectAll) btnSelectAll.style.display = (mode === 'select') ? '' : 'none';
        if (btnClear) btnClear.style.display = (mode === 'select') ? '' : 'none';
        if (mode !== 'select') {
            clearSelection();
        }
        updateSelectedUI();
    }

    function clearSelection() {
        selAll = false;
        if (!timeline) return;
        timeline.querySelectorAll('[data-role="conv-check"]').forEach(function (cb) { cb.checked = false; });
        timeline.querySelectorAll('.cd-conv').forEach(function (item) { item.classList.remove('is-selected'); });
        if (btnSelectAll) btnSelectAll.innerHTML = '<i class="fas fa-check-double"></i> Selecionar todas';
        updateSelectedUI();
    }

    function selectAll() {
        if (!timeline) return;
        var checks = timeline.querySelectorAll('[data-role="conv-check"]');
        selAll = true;
        checks.forEach(function (cb) { cb.checked = true; });
        timeline.querySelectorAll('.cd-conv').forEach(function (item) { item.classList.add('is-selected'); });
        if (btnSelectAll) btnSelectAll.innerHTML = '<i class="fas fa-xmark"></i> Limpar todas';
        updateSelectedUI();
    }

    function updateSelectedUI() {
        var n = countSelected();
        if (selCount) selCount.textContent = n;
        if (expCount) expCount.textContent = n;
        if (btnExportSelected) btnExportSelected.disabled = n === 0;
        if (badge) badge.style.display = (n > 0) ? 'inline-flex' : 'none';
    }

    function countSelected() {
        if (!timeline) return 0;
        return timeline.querySelectorAll('[data-role="conv-check"]:checked').length;
    }

    function onChange() {
        if (!timeline) return;
        timeline.querySelectorAll('.cd-conv').forEach(function (item) {
            var cb = item.querySelector('[data-role="conv-check"]');
            if (cb) item.classList.toggle('is-selected', !!cb.checked);
        });
        selAll = false;
        if (btnSelectAll) btnSelectAll.innerHTML = '<i class="fas fa-check-double"></i> Selecionar todas';
        updateSelectedUI();
    }

    function exportSelected() {
        var ids = [];
        if (!timeline) return;
        timeline.querySelectorAll('[data-role="conv-check"]:checked').forEach(function (cb) {
            ids.push(cb.value);
        });
        if (!ids.length) return;
        window.location = pdfBase + '?ids=' + encodeURIComponent(ids.join(','));
    }

    document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-action]');
        if (!t) return;
        var act = t.dataset.action;
        if (act === 'toggle-conv-select') {
            e.preventDefault();
            setMode(mode === 'select' ? 'view' : 'select');
        } else if (act === 'select-all-conv') {
            e.preventDefault();
            if (selAll) clearSelection(); else selectAll();
        } else if (act === 'clear-conv-selection') {
            e.preventDefault();
            clearSelection();
        } else if (act === 'export-selected-conv') {
            e.preventDefault();
            exportSelected();
        }
    });

    return { onChange: onChange };
})();

function openMergeContactModal() {
    document.getElementById('mergeContactModal').style.display = 'flex';
    document.getElementById('mergeContactSearch').value = '';
    searchMergeContacts();
    document.getElementById('mergeContactSearch').focus();
}

function closeMergeContactModal() {
    document.getElementById('mergeContactModal').style.display = 'none';
}

function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function searchMergeContacts() {
    var q = document.getElementById('mergeContactSearch').value;
    fetch('<?= base_url('contacts/search') ?>?q=' + encodeURIComponent(q))
        .then(function (r) { return r.json(); })
        .then(function (list) {
            var box = document.getElementById('mergeContactResults');
            var html = '';
            var selfId = <?= (int) $contact['id'] ?>;
            (list || []).forEach(function (c) {
                if (Number(c.id) === selfId) { return; }
                var sub = [c.email, c.phone].filter(Boolean).join(' · ');
                html += '<div class="merge-contact-item" onclick="selectMergeTarget(' + c.id + ', ' + JSON.stringify(c.name || 'Contato') + ')">'
                    + '<div class="mc-name">' + escapeHtml(c.name || 'Contato') + '</div>'
                    + (sub ? '<div class="mc-sub">' + escapeHtml(sub) + '</div>' : '')
                    + '</div>';
            });
            if (!html) { html = '<p class="text-muted">Nenhum contato encontrado.</p>'; }
            box.innerHTML = html;
        })
        .catch(function () {
            document.getElementById('mergeContactResults').innerHTML = '<p class="text-muted">Erro ao buscar contatos.</p>';
        });
}

function selectMergeTarget(id, name) {
    OminiConfirm('Mesclar este contato em "' + name + '"? Esta ação não pode ser desfeita.').then(function(ok) {
        if (!ok) return;
        document.getElementById('mergeContactTarget').value = id;
        document.getElementById('mergeContactForm').submit();
    });
}
</script>
