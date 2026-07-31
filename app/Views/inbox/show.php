<div class="conversation-view">
    <div class="conv-sidebar">
        <div class="conv-sidebar-header">
            <a href="<?= url('inbox') ?>" class="btn btn-sm btn-outline">
                <i class="fas fa-arrow-left"></i> Voltar
            </a>
            <div class="conv-status-badge"><?= status_badge($conversation['status']) ?></div>
            <div class="conv-actions">
                <a href="<?= url('inbox/') ?><?= $conversation['id'] ?>/pdf" class="btn btn-sm btn-outline" title="Baixar PDF">
                    <i class="fas fa-file-pdf"></i>
                </a>
                <button class="btn btn-sm btn-outline" onclick="openTransferModal()" title="Transferir">
                    <i class="fas fa-exchange-alt"></i>
                </button>
            </div>
        </div>

        <div class="conv-contact-info">
            <div class="conv-contact-avatar">
                <?php if ($contact['avatar']): ?>
                    <img src="<?= e(str_starts_with($contact['avatar'], 'http') ? $contact['avatar'] : upload_url($contact['avatar'])) ?>" alt="">
                <?php else: ?>
                    <div class="avatar-lg">
                        <?= mb_strtoupper(mb_substr($contact['name'] ?? '?', 0, 1)) ?>
                    </div>
                <?php endif; ?>
            </div>
            <h3><?= e($contact['name']) ?></h3>

            <div class="contact-details">
                <?php if ($contact['email']): ?>
                    <p class="contact-detail"><i class="fas fa-envelope"></i> <?= e($contact['email']) ?></p>
                <?php endif; ?>
                <?php if (!empty($contact['phones'])): ?>
                    <?php foreach ($contact['phones'] as $ph): ?>
                        <p class="contact-detail">
                            <i class="fas fa-phone"></i> <?= e($ph['phone']) ?>
                            <?php if (!empty($ph['label'])): ?> <small>(<?= e($ph['label']) ?>)</small><?php endif; ?>
                        </p>
                    <?php endforeach; ?>
                <?php elseif ($contact['phone']): ?>
                    <p class="contact-detail"><i class="fas fa-phone"></i> <?= e($contact['phone']) ?></p>
                <?php endif; ?>
                <?php if (!empty($contact['emails'])): ?>
                    <?php foreach ($contact['emails'] as $em): ?>
                        <p class="contact-detail">
                            <i class="fas fa-envelope"></i> <?= e($em['email']) ?>
                            <?php if (!empty($em['label'])): ?> <small>(<?= e($em['label']) ?>)</small><?php endif; ?>
                        </p>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if ($contact['document']): ?>
                    <p class="contact-detail"><i class="fas fa-id-card"></i> CNPJ: <?= e($contact['document']) ?></p>
                <?php endif; ?>
                <?php if ($contact['company']): ?>
                    <p class="contact-detail"><i class="fas fa-building"></i> <?= e($contact['company']) ?></p>
                <?php endif; ?>
            </div>

            <?php if (!empty($contact['tags'])): ?>
                <div class="conv-tags">
                    <?php foreach ($contact['tags'] as $tag): ?>
                        <span class="conv-tag" style="background: <?= e($tag['color'] ?? '#6c757d') ?>20; color: <?= e($tag['color'] ?? '#6c757d') ?>">
                            <?= e($tag['name']) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="conv-meta-info">
                <div class="meta-row">
                    <span class="meta-label">Canal:</span>
                    <span class="meta-value">
                        <i class="<?= channel_icon($conversation['channel_type'] ?? 'webchat') ?>"></i>
                        <?= e($conversation['channel_name'] ?? '') ?>
                    </span>
                </div>
                <div class="meta-row">
                    <span class="meta-label">Departamento:</span>
                    <span class="meta-value" style="color: <?= e($conversation['department_color'] ?? '#666') ?>">
                        <?= e($conversation['department_name'] ?? 'Nenhum') ?>
                    </span>
                </div>
                <div class="meta-row">
                    <span class="meta-label">Responsável:</span>
                    <span class="meta-value"><?= e($conversation['assigned_user_name'] ?? 'Não atribuído') ?></span>
                </div>
                <div class="meta-row">
                    <span class="meta-label">Status:</span>
                    <span class="meta-value clickable-badge" onclick="openStatusModal()" title="Clique para alterar status"><?= status_badge($conversation['status']) ?></span>
                </div>
                <div class="meta-row">
                    <span class="meta-label">Prioridade:</span>
                    <span class="meta-value clickable-badge" onclick="openPriorityModal()" title="Clique para alterar prioridade"><?= priority_badge($conversation['priority']) ?></span>
                </div>
                <div class="meta-row">
                    <span class="meta-label">Criado em:</span>
                    <span class="meta-value"><?= format_datetime($conversation['created_at']) ?></span>
                </div>
                <?php if (!empty($csat)): ?>
                    <div class="meta-row">
                        <span class="meta-label">Avalia&ccedil;&atilde;o:</span>
                        <span class="meta-value"><?= csat_stars($csat) ?></span>
                    </div>
                    <?php if (!empty($csat['comment'])): ?>
                        <div class="meta-row">
                            <span class="meta-label">Coment&aacute;rio:</span>
                            <span class="meta-value"><?= e($csat['comment']) ?></span>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div style="margin-top:14px">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
                    <span style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px">Etiquetas</span>
                    <span class="badge badge-tag-count" id="convTagCountS"><?= count($conversation['tags'] ?? []) ?></span>
                </div>
                <div class="conv-tags" id="convTagListS" style="margin-bottom:8px">
                    <?php if (!empty($conversation['tags'])): ?>
                        <?php foreach ($conversation['tags'] as $tag): ?>
                            <span class="conv-tag-modern applied" data-tag-id="<?= $tag['id'] ?>" style="background:<?= e($tag['color'] ?? '#6c757d') ?>;color:<?= e(contrast_color($tag['color'] ?? '#6c757d')) ?>">
                                <?= e($tag['name']) ?>
                                <button type="button" class="tag-remove-btn" data-tag-id="<?= $tag['id'] ?>" title="Remover">&times;</button>
                            </span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="font-size:12px;color:var(--text-muted);margin:0">Nenhuma.</p>
                    <?php endif; ?>
                </div>
                <div class="tag-grid" id="tagGridS">
                    <?php
                    $convTagIdsS = array_column($conversation['tags'] ?? [], 'id');
                    foreach ($allTags as $t):
                        $appliedS = in_array($t['id'], $convTagIdsS);
                    ?>
                        <button type="button"
                            class="tag-grid-item <?= $appliedS ? 'applied' : '' ?>"
                            data-tag-id="<?= $t['id'] ?>"
                            data-color="<?= e($t['color'] ?? '#6c757d') ?>"
                            style="--tag-color:<?= e($t['color'] ?? '#6c757d') ?>">
                            <?= e($t['name']) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                <div class="tag-create-row" style="margin-top:6px">
                    <input type="text" class="tag-create-input" id="tagCreateInputS" placeholder="Nova etiqueta..." maxlength="40">
                    <button type="button" class="btn btn-sm btn-primary tag-create-btn" id="tagCreateBtnS" disabled>Criar</button>
                </div>
            </div>
        </div>
    </div>

    <div class="conv-main">
        <div class="conv-messages" id="convMessages">
            <?php foreach ($messages as $msg):
                $isFile = in_array($msg['type'], ['image', 'audio', 'video', 'file', 'sticker'], true);
                $mediaType = $msg['type'];
                if (!$isFile) {
                    $inferred = media_type_from_content((string) ($msg['content'] ?? ''));
                    if ($inferred !== null) {
                        $isFile = true;
                        $mediaType = $inferred;
                    }
                }
                $meta = $isFile ? message_file_meta($msg['content']) : null;
            ?>
                <div class="message <?= $msg['direction'] === 'outbound' ? 'message-out' : 'message-in' ?> <?= $msg['type'] === 'internal_note' ? 'message-note' : '' ?>" data-mid="<?= $msg['id'] ?>">
                    <div class="message-body">
                        <?php if ($msg['type'] === 'internal_note'): ?>
                            <div class="message-note-header">
                                <i class="fas fa-lock"></i> Nota interna
                                <?php if ($msg['user_name']): ?> - <?= e($msg['user_name']) ?><?php endif; ?>
                            </div>
                            <div class="message-content"><?= e($msg['content']) ?></div>
                        <?php elseif ($msg['type'] === 'csat_request'):
                            $csatData = json_decode($msg['content'], true) ?: [];
                            $csatPrompt = $csatData['prompt'] ?? 'Solicitação de avaliação enviada ao cliente.';
                            $csatUrl = $csatData['url'] ?? '';
                        ?>
                            <div class="message-content csat-request-note">
                                <i class="fas fa-smile"></i> <?= e($csatPrompt) ?>
                                <?php if ($csatUrl): ?>
                                    <a href="<?= e($csatUrl) ?>" target="_blank" rel="noopener">Avaliar</a>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($isFile && $meta): ?>
                            <div class="message-content">
                                <?php if ($mediaType === 'image' || $mediaType === 'sticker'): ?>
                                    <a href="<?= e($meta['url']) ?>" target="_blank" rel="noopener">
                                        <img class="msg-img" src="<?= e($meta['url']) ?>" alt="<?= e($meta['name']) ?>">
                                    </a>
                                <?php elseif ($mediaType === 'audio'): ?>
                                    <audio controls preload="metadata" src="<?= e($meta['url']) ?>"></audio>
                                <?php elseif ($mediaType === 'video'): ?>
                                    <video controls preload="metadata" src="<?= e($meta['url']) ?>"></video>
                                <?php else: ?>
                                    <a class="msg-file" href="<?= e($meta['url']) ?>" target="_blank" rel="noopener" download>
                                        <i class="fas fa-file-download"></i>
                                        <span class="msg-file-name"><?= e($meta['name']) ?></span>
                                        <?php if ($meta['size']): ?><span class="msg-file-size">(<?= format_bytes($meta['size']) ?>)</span><?php endif; ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="message-content"><?= e($msg['content']) ?></div>
                        <?php endif; ?>
                        <div class="message-time">
                            <?= format_datetime($msg['created_at']) ?>
                            <?php if ($msg['user_name'] && $msg['direction'] === 'outbound'): ?>
                                - <?= e($msg['user_name']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="conv-composer">
            <?php $convFinished = in_array($conversation['status'], ['resolved', 'closed', 'spam']); ?>
            <?php if ($convFinished): ?>
                <div class="composer-locked">
                    <i class="fas fa-lock"></i> Conversa <?= $conversation['status'] === 'spam' ? 'marcada como spam' : 'finalizada' ?>. Não é possível enviar mensagens.
                </div>
            <?php endif; ?>
            <form action="<?= $convFinished ? '#' : url('inbox/') . $conversation['id'] . '/messages' ?>" method="POST" enctype="multipart/form-data" class="composer-form" id="composerForm">
                <?= csrf_field() ?>
                <label class="btn btn-sm btn-outline composer-attach" title="Anexar arquivo">
                    <i class="fas fa-paperclip"></i>
                    <input type="file" name="file" id="attachInput"
                           accept="image/*,audio/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip" hidden <?= $convFinished ? 'disabled' : '' ?>>
                </label>
                <textarea name="content" id="messageInput" rows="2"
                          placeholder="<?= $convFinished ? 'Conversa finalizada' : 'Digite sua mensagem... (Enter para enviar)' ?>"
                          <?= $convFinished ? 'disabled' : '' ?>></textarea>
                <div class="composer-actions">
                    <button type="button" class="btn btn-sm btn-outline" title="Resposta pronta" onclick="openCannedModal()" <?= $convFinished ? 'disabled' : '' ?>>
                        <i class="fas fa-bookmark"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline" title="Macros" onclick="openMacrosModal()" <?= $convFinished ? 'disabled' : '' ?>>
                        <i class="fas fa-bolt"></i>
                    </button>
                    <?php if (($conversation['channel_type'] ?? '') === 'whatsapp'): ?>
                    <button type="button" class="btn btn-sm btn-outline composer-signature <?= empty($conversation['signature_enabled']) ? '' : 'active' ?>" id="signatureToggle"
                            onclick="toggleSignature(<?= (int) $conversation['id'] ?>)" title="Assinatura automática no WhatsApp" <?= $convFinished ? 'disabled' : '' ?>>
                        <i class="fas fa-signature"></i> <span>Assinatura</span>
                    </button>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary btn-sm send-btn" <?= $convFinished ? 'disabled' : '' ?>>
                        <i class="fas fa-paper-plane"></i> Enviar
                    </button>
                </div>
                <div class="composer-file" id="composerFile" style="display:none">
                    <i class="fas fa-file"></i> <span id="composerFileName"></span>
                    <button type="button" class="composer-file-x" id="composerFileX" title="Remover">&times;</button>
                </div>
            </form>
        </div>
    </div>

    <div class="conv-history">
        <div class="card">
            <div class="card-header">
                <h4><i class="fas fa-history"></i> Histórico <span class="badge badge-tag-count"><?= count($events) ?></span></h4>
            </div>
            <div class="card-body p-0">
                <div class="history-list-modern" id="convHistoryS">
                    <?php if (empty($events)): ?>
                        <p class="tag-empty-msg">Nenhum evento registrado.</p>
                    <?php else: ?>
                        <?php foreach ($events as $event): ?>
                            <div class="history-item-modern">
                                <div class="history-icon-modern" style="color:<?= event_color($event['event_type']) ?>">
                                    <i class="fas <?= event_icon($event['event_type']) ?>"></i>
                                </div>
                                <div class="history-content">
                                    <p style="font-size:13px;margin:0"><?= e($event['description']) ?></p>
                                    <span class="history-time" style="font-size:11px">
                                        <?= format_datetime($event['created_at']) ?>
                                        <?php if ($event['user_name']): ?> &middot; <?= e($event['user_name']) ?><?php endif; ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card mt-2">
            <div class="card-header">
                <h4><i class="fas fa-lock"></i> Notas Internas</h4>
            </div>
            <div class="card-body">
                <form action="<?= url('inbox/') ?><?= $conversation['id'] ?>/notes" method="POST">
                    <?= csrf_field() ?>
                    <textarea name="content" rows="3" class="form-control" placeholder="Adicionar nota interna..."></textarea>
                    <button type="submit" class="btn btn-sm btn-outline mt-1"><i class="fas fa-save"></i> Salvar nota</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Transfer Modal -->
<div class="modal" id="transferModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Transferir Atendimento</h3>
            <button class="modal-close" onclick="closeTransferModal()">&times;</button>
        </div>
        <form action="<?= url('inbox/') ?><?= $conversation['id'] ?>/transfer" method="POST">
            <?= csrf_field() ?>
            <div class="form-group">
                <label>Departamento</label>
                <select name="department_id" class="form-control">
                    <option value="">Manter atual</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?= $dept['id'] ?>" <?= $dept['id'] == $conversation['department_id'] ? 'selected' : '' ?>>
                            <?= e($dept['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Atendente</label>
                <select name="user_id" class="form-control">
                    <option value="">Fila do departamento</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Transferir</button>
        </form>
    </div>
</div>

<!-- Status Modal -->
<div class="modal" id="statusModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Alterar Status</h3>
            <button class="modal-close" onclick="closeStatusModal()">&times;</button>
        </div>
        <form action="<?= url('inbox/') ?><?= $conversation['id'] ?>/status" method="POST">
            <?= csrf_field() ?>
            <div class="form-group">
                <label>Novo status</label>
                <select name="status" class="form-control">
                    <option value="open" <?= $conversation['status'] === 'open' ? 'selected' : '' ?>>Aberto</option>
                    <option value="waiting_customer" <?= $conversation['status'] === 'waiting_customer' ? 'selected' : '' ?>>Em atendimento</option>
                    <option value="waiting_internal" <?= $conversation['status'] === 'waiting_internal' ? 'selected' : '' ?>>Aguardando Interno</option>
                    <option value="resolved" <?= $conversation['status'] === 'resolved' ? 'selected' : '' ?>>Resolvido</option>
                    <option value="closed" <?= $conversation['status'] === 'closed' ? 'selected' : '' ?>>Fechado</option>
                </select>
            </div>
            <div id="closeFields" style="display:none">
                <div class="form-group">
                    <label>Motivo (ao concluir/fechar)</label>
                    <select name="reason" class="form-control">
                        <option value="">—</option>
                        <option value="Resolvido">Resolvido</option>
                        <option value="Duplicado">Duplicado</option>
                        <option value="Não respondeu">Não respondeu</option>
                        <option value="Fora de escopo">Fora de escopo</option>
                        <option value="Solicitação cancelada">Solicitação cancelada</option>
                        <option value="Outro">Outro</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Descrição</label>
                    <textarea name="description" class="form-control" rows="3" placeholder="Detalhes do encerramento..."></textarea>
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Alterar</button>
        </form>
    </div>
</div>

<!-- Priority Modal -->
<div class="modal" id="priorityModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-flag"></i> Alterar Prioridade</h3>
            <button class="modal-close" onclick="closePriorityModal()">&times;</button>
        </div>
        <form action="<?= url('inbox/') ?><?= $conversation['id'] ?>/priority" method="POST">
            <?= csrf_field() ?>
            <div class="modal-body">
                <div class="form-group">
                    <label>Nível de prioridade</label>
                    <select name="priority" class="form-control">
                        <option value="low" <?= ($conversation['priority'] ?? 'normal') === 'low' ? 'selected' : '' ?>>Baixa</option>
                        <option value="normal" <?= ($conversation['priority'] ?? 'normal') === 'normal' ? 'selected' : '' ?>>Normal</option>
                        <option value="high" <?= ($conversation['priority'] ?? 'normal') === 'high' ? 'selected' : '' ?>>Alta</option>
                        <option value="urgent" <?= ($conversation['priority'] ?? 'normal') === 'urgent' ? 'selected' : '' ?>>Urgente</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer" style="justify-content:center">
                <button type="submit" class="btn btn-primary">Salvar</button>
            </div>
        </form>
    </div>
</div>

<script>
function openTransferModal() { document.getElementById('transferModal').style.display = 'flex'; }
function closeTransferModal() { document.getElementById('transferModal').style.display = 'none'; }
function openStatusModal() { document.getElementById('statusModal').style.display = 'flex'; }
function closeStatusModal() { document.getElementById('statusModal').style.display = 'none'; }
function openPriorityModal() { document.getElementById('priorityModal').style.display = 'flex'; }
function closePriorityModal() { document.getElementById('priorityModal').style.display = 'none'; }

// Show close fields when resolved/closed selected
document.getElementById('statusModal')?.querySelector('select[name="status"]')?.addEventListener('change', function() {
    var cf = document.getElementById('closeFields');
    if (cf) cf.style.display = (this.value === 'resolved' || this.value === 'closed') ? 'block' : 'none';
});

// Scroll to bottom of messages
const msgContainer = document.getElementById('convMessages');
if (msgContainer) msgContainer.scrollTop = msgContainer.scrollHeight;

// Enter to send
document.getElementById('messageInput')?.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        this.closest('form').submit();
    }
});

// Anexo: pré-visualização do arquivo escolhido
(function() {
    var fi = document.getElementById('attachInput');
    var box = document.getElementById('composerFile');
    var nameEl = document.getElementById('composerFileName');
    var x = document.getElementById('composerFileX');
    if (!fi) return;
    fi.addEventListener('change', function() {
        if (fi.files && fi.files[0]) {
            nameEl.textContent = fi.files[0].name;
            box.style.display = 'flex';
        } else {
            box.style.display = 'none';
        }
    });
    x.addEventListener('click', function() {
        fi.value = '';
        box.style.display = 'none';
    });
})();

// Atualização automática de mensagens (polling)
(function() {
    var convEl = document.getElementById('convMessages');
    if (!convEl) return;

    var convId = <?= (int) $conversation['id'] ?>;
    var apiUrl = '<?= rtrim(base_url('api/conversations'), '/') ?>/' + convId + '/messages';
    var uploadsBase = '<?= rtrim(base_url('uploads'), '/') ?>';

    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
    function nl2br(s) { return esc(s); }
    function fmtBytes(b) { var u = ['B','KB','MB','GB'], i = 0; b = b || 0; while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; } return Math.round(b * 10) / 10 + ' ' + u[i]; }
    function fmtDt(s) {
        if (!s) return '';
        var d = new Date(String(s).replace(' ', 'T'));
        if (isNaN(d)) return s;
        var p = function(n) { return (n < 10 ? '0' : '') + n; };
        return p(d.getDate()) + '/' + p(d.getMonth() + 1) + '/' + d.getFullYear() + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
    }
    function fileMeta(content) {
        try { var m = JSON.parse(content); if (m && m.url) return m; } catch (e) {}
        return { url: content };
    }
    function mediaTypeOf(m) {
        var mt = m.type;
        if (['image','audio','video','file','sticker'].indexOf(mt) >= 0) return mt;
        try {
            var c = JSON.parse(m.content);
            if (c && c.url) {
                // CSAT payloads have title/prompt — not a file
                if (c.title || c.prompt) return null;
                var mime = c.mime || '';
                if (mime.indexOf('image/') === 0) return (mime === 'image/webp') ? 'sticker' : 'image';
                if (mime.indexOf('audio/') === 0) return 'audio';
                if (mime.indexOf('video/') === 0) return 'video';
                if (mime.indexOf('application/') === 0 || mime.indexOf('text/') === 0) return 'file';
                var ext = (c.url.split('?')[0].split('.').pop() || '').toLowerCase();
                if (['jpg','jpeg','png','gif','webp','bmp','heic','heif'].indexOf(ext) >= 0) return 'image';
                if (['mp3','ogg','m4a','aac','wav','amr'].indexOf(ext) >= 0) return 'audio';
                if (['mp4','mov','avi','mkv','webm','3gp'].indexOf(ext) >= 0) return 'video';
                return 'file';
            }
        } catch (e) {}
        return null;
    }
    function fileContentHtml(type, content) {
        var m = fileMeta(content);
        var url = /^https?:\/\//i.test(m.url) ? m.url : (uploadsBase + '/' + m.url);
        if (type === 'image' || type === 'sticker') return '<a href="' + esc(url) + '" target="_blank" rel="noopener"><img class="msg-img" src="' + esc(url) + '" alt="' + esc(m.name || 'imagem') + '"></a>';
        if (type === 'audio') return '<audio controls preload="metadata" src="' + esc(url) + '"></audio>';
        if (type === 'video') return '<video controls preload="metadata" src="' + esc(url) + '"></video>';
        var name = m.name || 'arquivo';
        var size = m.size ? ' (' + fmtBytes(m.size) + ')' : '';
        return '<a class="msg-file" href="' + esc(url) + '" target="_blank" rel="noopener" download><i class="fas fa-file-download"></i><span class="msg-file-name">' + esc(name) + '</span><span class="msg-file-size">' + esc(size) + '</span></a>';
    }
    function appendMessage(msg) {
        var wrap = document.createElement('div');
        var cls = 'message ' + (msg.direction === 'outbound' ? 'message-out' : 'message-in');
        if (msg.type === 'internal_note') cls += ' message-note';
        wrap.className = cls;
        wrap.setAttribute('data-mid', msg.id);
        var body = '';
        var mtype = mediaTypeOf(msg);
        if (msg.type === 'internal_note') {
            body += '<div class="message-note-header"><i class="fas fa-lock"></i> Nota interna' + (msg.user_name ? ' - ' + esc(msg.user_name) : '') + '</div>';
            body += '<div class="message-content">' + nl2br(msg.content) + '</div>';
        } else if (msg.type === 'csat_request') {
            var cdat = {}; try { cdat = JSON.parse(msg.content); } catch (e) {}
            body += '<div class="message-content csat-request-note"><i class="fas fa-smile"></i> ' + nl2br(cdat.prompt || 'Solicitação de avaliação enviada ao cliente.') +
                (cdat.url ? ' <a href="' + esc(cdat.url) + '" target="_blank" rel="noopener">Avaliar</a>' : '') + '</div>';
        } else if (mtype) {
            body += '<div class="message-content">' + fileContentHtml(mtype, msg.content) + '</div>';
        } else {
            body += '<div class="message-content">' + nl2br(msg.content) + '</div>';
        }
        var time = fmtDt(msg.created_at);
        if (msg.user_name && msg.direction === 'outbound') time += ' - ' + esc(msg.user_name);
        body += '<div class="message-time">' + time + '</div>';
        wrap.innerHTML = '<div class="message-body">' + body + '</div>';
        convEl.appendChild(wrap);
        var mid = parseInt(msg.id, 10);
        if (!isNaN(mid)) lastMid = Math.max(lastMid, mid);
    }

    var lastMid = 0;
    convEl.querySelectorAll('.message[data-mid]').forEach(function(el) {
        var id = parseInt(el.getAttribute('data-mid'), 10);
        if (id > lastMid) lastMid = id;
    });
    function nearBottom() { return convEl.scrollHeight - convEl.scrollTop - convEl.clientHeight < 80; }

    var notifyCount = 0, origTitle = document.title;

    function playNotifySound() {
        try {
            var ctx = new (window.AudioContext || window.webkitAudioContext)();
            var o = ctx.createOscillator(), g = ctx.createGain();
            o.type = 'sine';
            o.connect(g); g.connect(ctx.destination);
            o.frequency.value = 520; g.gain.value = 0.08;
            o.start();
            o.frequency.linearRampToValueAtTime(780, ctx.currentTime + 0.12);
            g.gain.linearRampToValueAtTime(0, ctx.currentTime + 0.25);
            setTimeout(function() { o.stop(); }, 250);
        } catch (e) {}
    }

    function notifyInbound() {
        notifyCount++;
        playNotifySound();
        if (window.__enhancements?.SoundManager) { window.__enhancements.SoundManager.play('message'); }
        if ('Notification' in window && Notification.permission === 'granted') {
            new Notification('Nova mensagem de ' + '<?= e($contact['name'] ?? 'cliente') ?>');
        }
        if (document.title !== origTitle) document.title = origTitle;
        document.title = '(' + notifyCount + ') ' + origTitle;
        setTimeout(function() { document.title = origTitle; }, 5000);
        if (typeof window.utils !== 'undefined' && window.utils.toast) {
            window.utils.toast('Nova mensagem de <?= e($contact['name'] ?? 'cliente') ?>', 'info');
        }
    }

    function poll() {
        fetch(apiUrl)
            .then(function(r) { return r.json(); })
            .then(function(msgs) {
                var added = false;
                var inboundNew = false;
                (msgs || []).forEach(function(m) {
                    var id = parseInt(m.id, 10);
                    if (id > lastMid) { appendMessage(m); lastMid = id; added = true; if (m.direction === 'inbound') inboundNew = true; }
                });
                if (added) {
                    convEl.scrollTop = convEl.scrollHeight;
                    if (inboundNew) notifyInbound();
                }
            })
            .catch(function() {});
    }
    setInterval(poll, 3000);
    poll();
    convEl.scrollTop = convEl.scrollHeight;
    if ('Notification' in window && Notification.permission === 'default') { Notification.requestPermission(); }

    var composerForm = document.getElementById('composerForm');
    if (composerForm) {
        composerForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var ta = document.getElementById('messageInput');
            var fileInput = document.getElementById('attachInput');
            var btn = composerForm.querySelector('button[type=submit]');
            if (!ta.value.trim() && !(fileInput && fileInput.files.length)) return;
            if (btn) btn.disabled = true;
            var fd = new FormData(composerForm);
            fetch(composerForm.action, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: fd
            }).then(function(r) { return r.json(); }).then(function(resp) {
                if (resp && resp.ok && resp.messages) {
                    (resp.messages || []).forEach(function(m) { appendMessage(m); });
                    convEl.scrollTop = convEl.scrollHeight;
                    ta.value = '';
                    if (fileInput) fileInput.value = '';
                    var fb = document.getElementById('composerFile');
                    if (fb) fb.style.display = 'none';
                } else if (resp && resp.error) {
                    alert(resp.error);
                }
            }).catch(function() {
                alert('Erro ao enviar mensagem. Tente novamente.');
            }).finally(function() { if (btn) btn.disabled = false; });
        });
    }
})();
</script>
<script>
function toggleSignature(id) {
    var btn = document.getElementById('signatureToggle');
    if (!btn) return;
    var next = btn.classList.contains('active') ? 0 : 1;
    btn.classList.toggle('active', next === 1);
    var fd = new FormData();
    var tok = document.querySelector('input[name=_csrf_token]');
    if (tok) fd.append('_csrf_token', tok.value);
    fd.append('field', 'signature_enabled');
    fd.append('value', next);
    fetch('<?= rtrim(parse_url(base_url('/'), PHP_URL_PATH), '/') ?>/inbox/' + id + '/settings', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    }).catch(function() {});
}
</script>
<script>
const CSRF = document.querySelector('input[name=_csrf_token]')?.value || '';
const BASE = '<?= rtrim(parse_url(base_url('/'), PHP_URL_PATH), '/') ?>';
function csrfFormS() { const f = new FormData(); f.append('_csrf_token', CSRF); return f; }
function postJsonS(url, body) {
    if (typeof url === 'string' && url.charAt(0) === '/') url = BASE + url;
    const fd = csrfFormS();
    for (const k in (body || {})) fd.append(k, body[k]);
    return fetch(url, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(r => r.json()).catch(() => ({ success: false }));
}
(function(){
    var convId = <?= (int) $conversation['id'] ?>;
    var tagList = document.getElementById('convTagListS');
    var tagCount = document.getElementById('convTagCountS');
    var grid = document.getElementById('tagGridS');
    var createInput = document.getElementById('tagCreateInputS');
    var createBtn = document.getElementById('tagCreateBtnS');
    if (!grid) return;
    var allTags = <?= json_encode(array_map(function($t) { return ['id' => (int)$t['id'], 'name' => $t['name'], 'color' => $t['color'] ?? '#6c757d']; }, $allTags)) ?>;

    function contrast(c) {
        var h = c.replace('#','');
        if (h.length===3) h = h[0]+h[0]+h[1]+h[1]+h[2]+h[2];
        var r=parseInt(h.substr(0,2),16), g=parseInt(h.substr(2,2),16), b=parseInt(h.substr(4,2),16);
        return (0.299*r+0.587*g+0.114*b)/255 > 0.5 ? '#1a1a2e' : '#fff';
    }
    function tagHtml(t) {
        return '<span class="conv-tag-modern applied" data-tag-id="'+t.id+'" style="background:'+t.color+';color:'+contrast(t.color)+'">' +
            t.name + ' <button type="button" class="tag-remove-btn" data-tag-id="'+t.id+'" title="Remover">&times;</button></span>';
    }
    function gridItemHtml(t, applied) {
        return '<button type="button" class="tag-grid-item'+(applied?' applied':'')+'" data-tag-id="'+t.id+'" data-color="'+t.color+'" style="--tag-color:'+t.color+'">'+t.name+'</button>';
    }
    function bindEvents() {
        grid.querySelectorAll('.tag-grid-item').forEach(function(el) {
            el.addEventListener('click', function() {
                var tid = parseInt(el.dataset.tagId, 10);
                var adding = !el.classList.contains('applied');
                el.disabled = true;
                (adding
                    ? postJsonS('/inbox/'+convId+'/tags', { tag_id: tid })
                    : postJsonS('/inbox/'+convId+'/untag', { tag_id: tid })
                ).then(function(resp) {
                    if (resp.success !== false) {
                        var t = allTags.find(function(x){return x.id===tid});
                        if (!t) return;
                        if (adding) {
                            el.classList.add('applied');
                            updateAppliedTags(t, true);
                        } else {
                            el.classList.remove('applied');
                            updateAppliedTags(t, false);
                        }
                    }
                }).finally(function() { el.disabled = false; });
            });
        });
        tagList.querySelectorAll('.tag-remove-btn').forEach(function(el) {
            el.addEventListener('click', function(e) {
                e.stopPropagation();
                var tid = parseInt(el.dataset.tagId, 10);
                el.disabled = true;
                postJsonS('/inbox/'+convId+'/untag', { tag_id: tid }).then(function(resp) {
                    if (resp.success !== false) {
                        var t = allTags.find(function(x){return x.id===tid});
                        if (t) {
                            var btn = grid.querySelector('.tag-grid-item[data-tag-id="'+tid+'"]');
                            if (btn) btn.classList.remove('applied');
                            updateAppliedTags(t, false);
                        }
                    }
                }).finally(function() { el.disabled = false; });
            });
        });
    }
    function updateAppliedTags(tag, add) {
        if (add) {
            var p = tagList.querySelector('p');
            if (p) p.remove();
            tagList.insertAdjacentHTML('beforeend', tagHtml(tag));
        } else {
            var el = tagList.querySelector('.conv-tag-modern[data-tag-id="'+tag.id+'"]');
            if (el) el.remove();
        }
        var pills = tagList.querySelectorAll('.conv-tag-modern');
        tagCount.textContent = pills.length;
        if (!pills.length) tagList.innerHTML = '<p style="font-size:12px;color:var(--text-muted);margin:0">Nenhuma.</p>';
        bindEvents();
    }
    if (createInput) {
        createInput.addEventListener('input', function() {
            createBtn.disabled = createInput.value.trim().length < 2;
        });
        createInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !createBtn.disabled) createBtn.click();
        });
    }
    if (createBtn) {
        createBtn.addEventListener('click', function() {
            var name = createInput.value.trim();
            if (name.length < 2) return;
            createBtn.disabled = true; createBtn.textContent = '...';
            postJsonS('/inbox/'+convId+'/tags', { tag_name: name }).then(function(resp) {
                if (resp.success !== false && resp.tag_id) {
                    var newTag = { id: resp.tag_id, name: name, color: resp.color || '#6c757d' };
                    allTags.push(newTag);
                    grid.insertAdjacentHTML('beforeend', gridItemHtml(newTag, true));
                    updateAppliedTags(newTag, true);
                    createInput.value = '';
                }
            }).finally(function() { createBtn.disabled = true; createBtn.textContent = 'Criar'; });
        });
    }
    bindEvents();
})();
</script>
