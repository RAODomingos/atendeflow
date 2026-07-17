<div class="contact-detail">
    <div class="page-toolbar">
        <a href="<?= url('contacts') ?>" class="btn btn-sm btn-outline">
            <i class="fas fa-arrow-left"></i> Voltar
        </a>
        <div>
            <a href="<?= url('contacts/') ?><?= $contact['id'] ?>/edit" class="btn btn-sm btn-outline">
                <i class="fas fa-edit"></i> Editar
            </a>
            <button class="btn btn-sm btn-outline" onclick="openMergeContactModal()">
                <i class="fas fa-code-merge"></i> Mesclar
            </button>
            <button class="btn btn-sm btn-danger" onclick="confirmDelete()">
                <i class="fas fa-trash"></i>
            </button>
        </div>
    </div>

    <div class="contact-grid">
        <div class="card">
            <div class="card-body">
                <div class="contact-header">
                    <div class="contact-avatar-lg">
                        <?php if (!empty($contact['avatar'])): ?>
                            <img src="<?= e(str_starts_with($contact['avatar'], 'http') ? $contact['avatar'] : upload_url($contact['avatar'])) ?>" alt="<?= e($contact['name']) ?>">
                        <?php else: ?>
                            <?= mb_strtoupper(mb_substr($contact['name'], 0, 1)) ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h2 style="margin:0 0 2px"><?= e($contact['name']) ?></h2>
                        <p class="text-muted" style="margin:0;font-size:12px">Contato desde <?= format_datetime($contact['created_at']) ?></p>
                    </div>
                </div>

                <div class="contact-details">
                    <div class="detail-item">
                        <span class="detail-label"><i class="fas fa-envelope"></i> E-mail</span>
                        <span class="detail-value"><?= e($contact['email'] ?? '-') ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label"><i class="fas fa-phone"></i> Telefone</span>
                        <span class="detail-value"><?= e($contact['phone'] ?? '-') ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label"><i class="fas fa-building"></i> Empresa</span>
                        <span class="detail-value"><?= e($contact['company'] ?? '-') ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label"><i class="fas fa-id-card"></i> Documento</span>
                        <span class="detail-value"><?= e($contact['document'] ?? '-') ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label"><i class="fas fa-calendar"></i> Criado em</span>
                        <span class="detail-value"><?= format_datetime($contact['created_at'] ?? '') ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label"><i class="fas fa-history"></i> Última atividade</span>
                        <span class="detail-value"><?= !empty($contact['last_activity_at']) ? format_datetime($contact['last_activity_at']) : '-' ?></span>
                    </div>
                </div>

                <?php if (!empty($contact['phones'])): $phoneFields = array_filter($contact['phones'], fn($p) => ($p['phone'] ?? null) !== ($contact['phone'] ?? null)); if (!empty($phoneFields)): ?>
                <div class="contact-sub-list">
                    <span class="contact-sub-title"><i class="fas fa-phone-alt"></i> Outros telefones</span>
                    <?php foreach ($phoneFields as $ph): ?>
                        <div class="contact-sub-item">
                            <span class="contact-sub-value"><?= e($ph['phone']) ?></span>
                            <?php if (!empty($ph['label'])): ?>
                                <span class="contact-sub-label"><?= e($ph['label']) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; endif; ?>

                <?php if (!empty($contact['emails'])): $emailFields = array_filter($contact['emails'], fn($e) => ($e['email'] ?? null) !== ($contact['email'] ?? null)); if (!empty($emailFields)): ?>
                <div class="contact-sub-list">
                    <span class="contact-sub-title"><i class="fas fa-envelope-open-text"></i> Outros e-mails</span>
                    <?php foreach ($emailFields as $em): ?>
                        <div class="contact-sub-item">
                            <span class="contact-sub-value"><?= e($em['email']) ?></span>
                            <?php if (!empty($em['label'])): ?>
                                <span class="contact-sub-label"><?= e($em['label']) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; endif; ?>

                <?php if (!empty($contact['tags'])): ?>
                    <div class="contact-tags">
                        <?php foreach ($contact['tags'] as $tag): ?>
                            <span class="conv-tag" style="background:<?= e($tag['color'] ?? '#6c757d') ?>;color:<?= e(contrast_color($tag['color'] ?? '#6c757d')) ?>">
                                <?= e($tag['name']) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($contact['notes'])): ?>
                    <div class="contact-notes">
                        <h4><i class="fas fa-sticky-note"></i> Observações</h4>
                        <p><?= nl2br(e($contact['notes'])) ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-comments"></i> Histórico de Conversas <span class="badge badge-tag-count"><?= count($conversations) ?></span></h3>
            </div>
            <div class="card-body p-0">
                <?php if (empty($conversations)): ?>
                    <div class="empty-state">
                        <p>Nenhuma conversa encontrada.</p>
                    </div>
                <?php else: ?>
                    <div class="conv-card-list">
                        <?php foreach ($conversations as $conv): ?>
                            <a href="<?= url('inbox/') ?><?= $conv['id'] ?>" class="conv-card-row">
                                <div class="conv-row-top">
                                    <div class="conv-row-channel">
                                        <i class="<?= channel_icon($conv['channel_type'] ?? 'webchat') ?>"></i>
                                        <?= e($conv['channel_name'] ?? '') ?>
                                    </div>
                                    <div class="conv-row-time"><?= time_elapsed($conv['created_at']) ?></div>
                                </div>
                                <div class="conv-row-subject">
                                    <?= e($conv['subject'] ?: truncate($conv['last_message'] ?? 'Sem mensagens', 80)) ?>
                                </div>
                                <div class="conv-row-meta">
                                    <?= status_badge($conv['status']) ?>
                                    <?= priority_badge($conv['priority'] ?? 'normal') ?>
                                    <?php if ($conv['department_name']): ?>
                                        <span class="conv-department"><i class="fas fa-layer-group"></i> <?= e($conv['department_name']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="conv-row-footer">
                                    <span><i class="fas fa-user"></i> <?= e($conv['assigned_user_name'] ?? 'Não atribuído') ?></span>
                                    <span><i class="fas fa-comment-dots"></i> <?= (int)($conv['message_count'] ?? 0) ?> msgs</span>
                                    <span><i class="fas fa-calendar"></i> <?= format_datetime($conv['created_at']) ?></span>
                                </div>
                                <?php if (in_array($conv['status'], ['resolved', 'closed']) && (!empty($conv['close_reason']) || !empty($conv['close_description']))): ?>
                                    <div class="conv-close-info">
                                        <?php if (!empty($conv['close_reason'])): ?>
                                            <span class="conv-close-reason"><i class="fas fa-flag"></i> <?= e($conv['close_reason']) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($conv['close_description'])): ?>
                                            <p class="conv-close-desc"><?= nl2br(e($conv['close_description'])) ?></p>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
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
            <h3>Mesclar contato</h3>
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
