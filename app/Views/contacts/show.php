<div class="contact-detail">
    <div class="page-toolbar">
        <a href="<?= url('contacts') ?>" class="btn btn-sm btn-outline">
            <i class="fas fa-arrow-left"></i> Voltar
        </a>
        <div>
            <button class="btn btn-sm btn-primary" onclick="openContactDrawer()">
                <i class="fas fa-edit"></i> Editar
            </button>
            <a href="<?= url('contacts/') ?><?= $contact['id'] ?>/pdf" target="_blank" class="btn btn-sm btn-outline" title="Baixar PDF">
                <i class="fas fa-file-pdf"></i> PDF
            </a>
            <button class="btn btn-sm btn-outline" onclick="openMergeContactModal()">
                <i class="fas fa-code-merge"></i> Mesclar
            </button>
            <button class="btn btn-sm btn-danger" onclick="confirmDelete()">
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
                        <?php
                        $online = !empty($contact['last_activity_at']) && (time() - strtotime($contact['last_activity_at']) < 600);
                        $isOnline = $online;
                        ?>
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

    <div class="contact-info-columns">
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
                        <div class="contact-info-value"><?= e($contact['email'] ?? '<span style="color:var(--text-muted)">Não informado</span>') ?></div>
                    </div>
                </div>
                <div class="contact-info-item">
                    <span class="contact-info-icon ci-phone"><i class="fas fa-phone"></i></span>
                    <div>
                        <div class="contact-info-label">Telefone</div>
                        <div class="contact-info-value"><?= e($contact['phone'] ?? '<span style="color:var(--text-muted)">Não informado</span>') ?></div>
                    </div>
                </div>
                <div class="contact-info-item">
                    <span class="contact-info-icon ci-building"><i class="fas fa-building"></i></span>
                    <div>
                        <div class="contact-info-label">Empresa</div>
                        <div class="contact-info-value"><?= e($contact['company'] ?? '<span style="color:var(--text-muted)">Não informado</span>') ?></div>
                    </div>
                </div>
                <div class="contact-info-item">
                    <span class="contact-info-icon ci-document"><i class="fas fa-id-card"></i></span>
                    <div>
                        <div class="contact-info-label">Documento</div>
                        <div class="contact-info-value"><?= e($contact['document'] ?? '<span style="color:var(--text-muted)">Não informado</span>') ?></div>
                    </div>
                </div>
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

        <div>
            <?php $hasExtraPhones = !empty($contact['phones']) && count(array_filter($contact['phones'], fn($p) => ($p['phone'] ?? null) !== ($contact['phone'] ?? null))) > 0; ?>
            <?php $hasExtraEmails = !empty($contact['emails']) && count(array_filter($contact['emails'], fn($e) => ($e['email'] ?? null) !== ($contact['email'] ?? null))) > 0; ?>
            <?php if ($hasExtraPhones || $hasExtraEmails): ?>
                <div class="contact-info-card mb-3">
                    <div class="contact-info-header">
                        <i class="fas fa-ellipsis-h" style="color:var(--text-muted)"></i>
                        Informações Adicionais
                    </div>
                    <?php if ($hasExtraPhones): ?>
                        <div class="contact-extra-section">
                            <div class="contact-extra-title"><i class="fas fa-phone-alt" style="color:var(--success)"></i> Outros telefones</div>
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
                        <div class="contact-extra-section">
                            <div class="contact-extra-title"><i class="fas fa-envelope-open-text" style="color:var(--info)"></i> Outros e-mails</div>
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
            <?php endif; ?>

            <div class="contact-info-card">
                <div class="contact-info-header">
                    <i class="fas fa-chart-simple" style="color:var(--brand)"></i>
                    Estatísticas
                </div>
                <div class="contact-info-body">
                    <div class="contact-info-item">
                        <span class="contact-info-icon ci-email" style="background:var(--brand-soft);color:var(--brand-2)"><i class="fas fa-comments"></i></span>
                        <div>
                            <div class="contact-info-label">Total de conversas</div>
                            <div class="contact-info-value"><?= count($conversations) ?></div>
                        </div>
                    </div>
                    <?php
                    $resolvedCount = 0;
                    foreach ($conversations as $c) { if (in_array($c['status'], ['resolved','closed'])) $resolvedCount++; }
                    $openCount = count($conversations) - $resolvedCount;
                    ?>
                    <div class="contact-info-item">
                        <span class="contact-info-icon ci-phone" style="background:#e8f5e9;color:var(--success)"><i class="fas fa-check-circle"></i></span>
                        <div>
                            <div class="contact-info-label">Resolvidas</div>
                            <div class="contact-info-value"><?= $resolvedCount ?></div>
                        </div>
                    </div>
                    <div class="contact-info-item">
                        <span class="contact-info-icon ci-document" style="background:#fff3e0;color:#e65100"><i class="fas fa-spinner"></i></span>
                        <div>
                            <div class="contact-info-label">Em aberto</div>
                            <div class="contact-info-value"><?= $openCount ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="contact-info-card">
        <div class="contact-info-header">
            <i class="fas fa-comments" style="color:var(--brand)"></i>
            Histórico de Conversas
            <span class="badge badge-tag-count"><?= count($conversations) ?></span>
        </div>
        <div>
            <?php if (empty($conversations)): ?>
                <div class="empty-state-enhanced" style="padding:60px 24px">
                    <div class="empty-icon"><i class="fas fa-inbox"></i></div>
                    <h3>Nenhuma conversa encontrada</h3>
                    <p>Este cliente ainda não possui conversas registradas.</p>
                </div>
            <?php else: ?>
                <div class="conversation-timeline">
                    <?php foreach ($conversations as $conv): ?>
                        <a href="<?= url('inbox') ?>?conv=<?= $conv['id'] ?>" class="timeline-item">
                            <div class="timeline-dot"></div>
                            <div class="timeline-content">
                                <div class="timeline-top">
                                    <div class="timeline-channel">
                                        <i class="<?= channel_icon($conv['channel_type'] ?? 'webchat') ?>" style="color:<?= ['whatsapp'=>'#25D366','webchat'=>'var(--brand)','email'=>'#f59e0b','telegram'=>'#0088cc','facebook'=>'#1877f2','instagram'=>'#e1306c','phone'=>'#6c757d'][$conv['channel_type'] ?? ''] ?? 'var(--text-muted)' ?>"></i>
                                        <?= e($conv['channel_name'] ?? '') ?>
                                        <?php if ($conv['department_name']): ?>
                                            <span class="timeline-dept-badge" style="background:<?= e($conv['department_color'] ?? 'var(--border-soft)') ?>20;color:<?= e($conv['department_color'] ?? 'var(--text-muted)') ?>">
                                                <i class="fas fa-layer-group"></i> <?= e($conv['department_name']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="timeline-time"><?= time_elapsed($conv['created_at']) ?></div>
                                </div>
                                <div class="timeline-subject">
                                    <?= e($conv['subject'] ?: truncate($conv['last_message'] ?? 'Sem mensagens', 100)) ?>
                                </div>
                                <div class="timeline-meta">
                                    <?= status_badge($conv['status']) ?>
                                    <?= priority_badge($conv['priority'] ?? 'normal') ?>
                                    <span style="font-size:13px;color:var(--text-muted);display:flex;align-items:center;gap:5px"><i class="fas fa-comment-dots" style="font-size:11px"></i> <?= (int)($conv['message_count'] ?? 0) ?> mensagens</span>
                                </div>
                                <div class="timeline-footer">
                                    <div class="timeline-footer-left">
                                        <span class="timeline-agent">
                                            <i class="fas fa-user-circle" style="color:var(--brand);font-size:14px"></i>
                                            <?= e($conv['assigned_user_name'] ?? 'Não atribuído') ?>
                                        </span>
                                        <?php if (!empty($conv['unit'])): ?>
                                            <span class="timeline-unit">
                                                <i class="fas fa-location-dot"></i> <?= e($conv['unit']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="timeline-footer-right"><i class="far fa-calendar-alt"></i> <?= format_datetime($conv['created_at']) ?></div>
                                </div>
                                <?php if (in_array($conv['status'], ['resolved', 'closed']) && (!empty($conv['close_reason']) || !empty($conv['close_description']))): ?>
                                    <div class="timeline-close-info">
                                        <?php if (!empty($conv['close_reason'])): ?>
                                            <span class="timeline-close-reason"><i class="fas fa-flag"></i> <?= e($conv['close_reason']) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($conv['close_description'])): ?>
                                            <p class="timeline-close-desc"><?= nl2br(e($conv['close_description'])) ?></p>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </a>
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
    if (confirm('Tem certeza que deseja excluir este contato?')) {
        document.getElementById('deleteForm').submit();
    }
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
    if (!confirm('Mesclar este contato em "' + name + '"? Esta ação não pode ser desfeita.')) {
        return;
    }
    document.getElementById('mergeContactTarget').value = id;
    document.getElementById('mergeContactForm').submit();
}
</script>