<?php
$filterBase = url('contacts/' . (int) $contact['id']);
$hasFilters = ($filters['year'] ?? 0) || ($filters['month'] ?? 0) || ($filters['department'] ?? 0) || ($filters['status'] ?? null);
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
$statusKeys = ['new', 'open', 'waiting_customer', 'waiting_internal', 'resolved', 'closed', 'spam'];
$totalCount = (int) ($statusCounts['_total'] ?? count($conversations));
?>
<div class="contact-detail">
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

    <div class="contact-profile">
        <div class="contact-profile-cover"></div>
        <div class="contact-profile-body">
            <div class="contact-profile-row">
                <div class="contact-profile-avatar">
                    <?php if (!empty($contact['avatar'])): ?>
                        <img src="<?= e(str_starts_with($contact['avatar'], 'http') ? $contact['avatar'] : upload_url($contact['avatar'])) ?>" alt="<?= e($contact['name']) ?>">
                    <?php else: ?>
                        <?= mb_strtoupper(mb_substr($contact['name'], 0, 1)) ?>
                    <?php endif; ?>
                </div>
                <div class="contact-profile-info">
                    <div class="contact-profile-name">
                        <?= e($contact['name']) ?>
                        <?php $isOnline = !empty($contact['last_activity_at']) && (time() - strtotime($contact['last_activity_at']) < 600); ?>
                        <span class="contact-status-dot <?= $isOnline ? 'online' : 'offline' ?>" title="<?= $isOnline ? 'Online' : 'Offline' ?>"></span>
                    </div>
                    <div class="contact-profile-company">
                        <?php if (!empty($contact['company'])): ?>
                            <i class="fas fa-building" style="color:var(--brand);font-size:12px"></i>
                            <?= e($contact['company']) ?>
                        <?php else: ?>
                            <span style="color:var(--text-muted)">Sem empresa</span>
                        <?php endif; ?>
                    </div>
                    <div class="contact-profile-meta">
                        <span><i class="far fa-calendar-alt"></i> Contato desde <?= format_datetime($contact['created_at'] ?? '') ?></span>
                        <span><i class="far fa-clock"></i> Última atividade: <?= !empty($contact['last_activity_at']) ? time_elapsed($contact['last_activity_at']) : '-' ?></span>
                    </div>
                </div>
                <?php if (!empty($contact['tags'])): ?>
                    <div class="contact-profile-tags">
                        <?php foreach ($contact['tags'] as $tag): ?>
                            <span class="conv-tag-modern" style="background:<?= e($tag['color'] ?? '#6c757d') ?>;color:<?= e(contrast_color($tag['color'] ?? '#6c757d')) ?>">
                                <?= e($tag['name']) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="contact-stat-row">
        <div class="contact-stat">
            <div class="contact-stat-icon" style="background:var(--brand-soft);color:var(--brand)">
                <i class="fas fa-comments"></i>
            </div>
            <div class="contact-stat-body">
                <div class="contact-stat-value"><?= $totalCount ?></div>
                <div class="contact-stat-label">Conversas <?= $hasFilters ? 'filtradas' : 'no total' ?></div>
            </div>
        </div>
        <?php foreach ($statusKeys as $sk):
            $count = (int) ($statusCounts[$sk] ?? 0);
            if ($count === 0 && $hasFilters) continue;
            $cls = ['new'=>'primary','open'=>'primary','waiting_customer'=>'info','waiting_internal'=>'warning','resolved'=>'success','closed'=>'secondary','spam'=>'danger'][$sk] ?? 'secondary';
            $bg = ['new'=>'var(--brand-soft)','open'=>'var(--brand-soft)','waiting_customer'=>'var(--info-soft)','waiting_internal'=>'var(--warning-soft)','resolved'=>'var(--success-soft)','closed'=>'var(--bg-panel-alt)','spam'=>'var(--danger-soft)'][$sk] ?? 'var(--bg-panel-alt)';
            $color = ['new'=>'var(--brand)','open'=>'var(--brand)','waiting_customer'=>'var(--info)','waiting_internal'=>'var(--warning)','resolved'=>'var(--success)','closed'=>'var(--text-muted)','spam'=>'var(--danger)'][$sk] ?? 'var(--text-muted)';
        ?>
            <div class="contact-stat">
                <div class="contact-stat-icon" style="background:<?= $bg ?>;color:<?= $color ?>">
                    <i class="fas <?= $statusIcons[$sk] ?? 'fa-circle' ?>"></i>
                </div>
                <div class="contact-stat-body">
                    <div class="contact-stat-value"><?= $count ?></div>
                    <div class="contact-stat-label"><?= $statusLabels[$sk] ?? $sk ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="contact-info-card">
        <div class="contact-info-header">
            <i class="fas fa-id-card" style="color:var(--brand)"></i>
            Informações do Contato
        </div>
        <div class="contact-info-body">
            <div class="contact-info-item">
                <span class="contact-info-icon ci-email"><i class="fas fa-envelope"></i></span>
                <div>
                    <div class="contact-info-label">E-mail</div>
                    <div class="contact-info-value"><?= !empty($contact['email']) ? e($contact['email']) : '<span class="text-muted">Não informado</span>' ?></div>
                </div>
            </div>
            <div class="contact-info-item">
                <span class="contact-info-icon ci-phone"><i class="fas fa-phone"></i></span>
                <div>
                    <div class="contact-info-label">Telefone</div>
                    <div class="contact-info-value"><?= !empty($contact['phone']) ? e($contact['phone']) : '<span class="text-muted">Não informado</span>' ?></div>
                </div>
            </div>
            <?php if (!empty($contact['company'])): ?>
            <div class="contact-info-item">
                <span class="contact-info-icon ci-building"><i class="fas fa-building"></i></span>
                <div>
                    <div class="contact-info-label">Empresa</div>
                    <div class="contact-info-value"><?= e($contact['company']) ?></div>
                </div>
            </div>
            <?php endif; ?>
            <?php if (!empty($contact['document'])): ?>
            <div class="contact-info-item">
                <span class="contact-info-icon ci-document"><i class="fas fa-id-card"></i></span>
                <div>
                    <div class="contact-info-label">Documento</div>
                    <div class="contact-info-value"><?= e($contact['document']) ?></div>
                </div>
            </div>
            <?php endif; ?>
            <div class="contact-info-item">
                <span class="contact-info-icon ci-calendar"><i class="fas fa-calendar-alt"></i></span>
                <div>
                    <div class="contact-info-label">Criado em</div>
                    <div class="contact-info-value"><?= format_datetime($contact['created_at'] ?? '') ?></div>
                </div>
            </div>
            <div class="contact-info-item">
                <span class="contact-info-icon ci-clock"><i class="fas fa-history"></i></span>
                <div>
                    <div class="contact-info-label">Última atividade</div>
                    <div class="contact-info-value"><?= !empty($contact['last_activity_at']) ? format_datetime($contact['last_activity_at']) : '-' ?></div>
                </div>
            </div>
        </div>
        <?php if (!empty($contact['notes'])): ?>
            <div class="contact-notes-section">
                <h4><i class="fas fa-sticky-note" style="color:var(--warning)"></i> Observações</h4>
                <p><?= nl2br(e($contact['notes'])) ?></p>
            </div>
        <?php endif; ?>
    </div>

    <?php
    $hasExtraPhones = !empty($contact['phones']) && count(array_filter($contact['phones'], fn($p) => ($p['phone'] ?? null) !== ($contact['phone'] ?? null))) > 0;
    $hasExtraEmails = !empty($contact['emails']) && count(array_filter($contact['emails'], fn($e) => ($e['email'] ?? null) !== ($contact['email'] ?? null))) > 0;
    if ($hasExtraPhones || $hasExtraEmails): ?>
    <div class="contact-info-card">
        <div class="contact-info-header">
            <i class="fas fa-ellipsis-h" style="color:var(--text-muted)"></i>
            Informações Adicionais
        </div>
        <div class="contact-info-body" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px">
            <?php if ($hasExtraPhones): ?>
                <div>
                    <div style="font-size:12px;font-weight:700;text-transform:uppercase;color:var(--text-muted);margin-bottom:8px;letter-spacing:.04em">
                        <i class="fas fa-phone-alt" style="color:var(--success)"></i> Outros telefones
                    </div>
                    <?php foreach (array_filter($contact['phones'], fn($p) => ($p['phone'] ?? null) !== ($contact['phone'] ?? null)) as $ph): ?>
                        <div class="contact-extra-item">
                            <i class="fas fa-phone" style="color:var(--success);font-size:12px;width:16px"></i>
                            <span><?= e($ph['phone']) ?></span>
                            <?php if (!empty($ph['label'])): ?>
                                <span class="label"><?= e($ph['label']) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($hasExtraEmails): ?>
                <div>
                    <div style="font-size:12px;font-weight:700;text-transform:uppercase;color:var(--text-muted);margin-bottom:8px;letter-spacing:.04em">
                        <i class="fas fa-envelope-open-text" style="color:var(--info)"></i> Outros e-mails
                    </div>
                    <?php foreach (array_filter($contact['emails'], fn($e) => ($e['email'] ?? null) !== ($contact['email'] ?? null)) as $em): ?>
                        <div class="contact-extra-item">
                            <i class="fas fa-envelope" style="color:var(--info);font-size:12px;width:16px"></i>
                            <span><?= e($em['email']) ?></span>
                            <?php if (!empty($em['label'])): ?>
                                <span class="label"><?= e($em['label']) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="contact-info-card">
        <div class="contact-info-header" style="flex-wrap:wrap;gap:12px">
            <span style="display:flex;align-items:center;gap:8px">
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
                <style>
                .conv-list-table tbody tr.is-selected td{background:var(--brand-soft)}
                .conv-list-subject{font-weight:600;color:var(--text-primary);text-decoration:none;display:flex;align-items:center;gap:9px;min-width:0}
                .conv-list-subject:hover{color:var(--brand)}
                .conv-list-subject span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
                .conv-list-sub{font-size:12px;color:var(--text-secondary);margin-top:4px;display:flex;gap:10px;flex-wrap:wrap;align-items:center}
                .conv-list-sub .timeline-dept-badge{font-size:11px;font-weight:600;padding:1px 8px;border-radius:10px}
                .conv-list-close{color:var(--text-muted)}
                </style>
                <div class="table-wrap">
                <table class="table table-hover conv-list-table">
                    <thead>
                        <tr><th style="width:38px"></th><th>Conversa</th><th style="width:170px">Status</th><th style="width:80px">Msgs</th><th style="width:170px">Atendente</th><th style="width:120px">Data</th><th style="width:54px"></th></tr>
                    </thead>
                    <tbody id="convTimeline" data-mode="view">
                    <?php foreach ($conversations as $conv):
                        $chColor = ['whatsapp'=>'#25D366','webchat'=>'var(--brand)','email'=>'#f59e0b','telegram'=>'#0088cc','facebook'=>'#1877f2','instagram'=>'#e1306c','phone'=>'#6c757d'][$conv['channel_type'] ?? ''] ?? 'var(--text-muted)';
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
                        <tr class="timeline-item-card" data-conv-id="<?= (int) $conv['id'] ?>"
                            data-conv-name="<?= e($conv['subject'] ?: ('Conversa #' . $conv['id'])) ?>">
                            <td>
                                <label class="conv-checkbox" data-role="conv-checkbox" style="display:none">
                                    <input type="checkbox" data-role="conv-check" value="<?= (int) $conv['id'] ?>" onchange="window.__convExport.onChange()">
                                </label>
                            </td>
                            <td>
                                <a href="<?= url('inbox') ?>?conv=<?= $conv['id'] ?>" class="conv-list-subject">
                                    <i class="<?= channel_icon($conv['channel_type'] ?? 'webchat') ?>" style="color:<?= $chColor ?>"></i>
                                    <span><?= e(truncate($convPreview, 90)) ?></span>
                                </a>
                                <div class="conv-list-sub">
                                    <span><?= e($conv['channel_name'] ?? '') ?></span>
                                    <?php if (!empty($conv['protocol'])): ?>
                                        <span title="Protocolo">#<?= e(format_protocol($conv['protocol'])) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($conv['department_name'])): ?>
                                        <span class="timeline-dept-badge" style="background:<?= e($conv['department_color'] ?? 'var(--border-soft)') ?>20;color:<?= e($conv['department_color'] ?? 'var(--text-muted)') ?>">
                                            <i class="fas fa-layer-group"></i> <?= e($conv['department_name']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($conv['unit'])): ?>
                                        <span><i class="fas fa-location-dot"></i> <?= e($conv['unit']) ?></span>
                                    <?php endif; ?>
                                    <?php if (in_array($conv['status'], ['resolved', 'closed']) && (!empty($conv['close_reason']) || !empty($conv['close_description']))): ?>
                                        <span class="conv-list-close" title="<?= e(trim(($conv['close_reason'] ?? '') . (!empty($conv['close_description']) ? ' — ' . $conv['close_description'] : ''))) ?>"><i class="fas fa-flag"></i> <?= e(truncate($conv['close_reason'] ?: $conv['close_description'], 60)) ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td style="white-space:nowrap"><?= status_badge($conv['status']) ?> <?= priority_badge($conv['priority'] ?? 'normal') ?></td>
                            <td><span class="badge badge-secondary"><?= (int)($conv['message_count'] ?? 0) ?></span></td>
                            <td style="font-size:12.5px"><?= e($conv['assigned_user_name'] ?? 'Não atribuído') ?></td>
                            <td style="white-space:nowrap;font-size:12.5px;color:var(--text-secondary)" title="<?= e(format_datetime($conv['created_at'])) ?>"><?= time_elapsed($conv['created_at']) ?></td>
                            <td>
                                <a href="<?= url('inbox/' . (int) $conv['id'] . '/pdf') ?>" target="_blank"
                                   class="btn btn-sm btn-outline btn-icon" title="Baixar PDF desta conversa (com mensagens)">
                                    <i class="fas fa-file-pdf"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
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
        timeline.querySelectorAll('.timeline-item-card').forEach(function (item) {
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
        timeline.querySelectorAll('.timeline-item-card').forEach(function (item) { item.classList.remove('is-selected'); });
        if (btnSelectAll) btnSelectAll.innerHTML = '<i class="fas fa-check-double"></i> Selecionar todas';
        updateSelectedUI();
    }

    function selectAll() {
        if (!timeline) return;
        var checks = timeline.querySelectorAll('[data-role="conv-check"]');
        selAll = true;
        checks.forEach(function (cb) { cb.checked = true; });
        timeline.querySelectorAll('.timeline-item-card').forEach(function (item) { item.classList.add('is-selected'); });
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
        timeline.querySelectorAll('.timeline-item-card').forEach(function (item) {
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
