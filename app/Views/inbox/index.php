<div class="inbox-page inbox-3col" id="inboxApp">
    <div class="inbox-col-list">
        <div class="pane-header">
            <div style="display:flex;align-items:center;justify-content:space-between;">
                <h1 class="pane-title" style="margin-bottom:0;">
                    <i class="fas fa-inbox" style="color:var(--primary);font-size:22px"></i>
                    Caixa de Entrada
                </h1>
                <button type="button" class="btn btn-primary btn-sm" style="white-space:nowrap;" onclick="openNewConvModal()">
                    <i class="fas fa-plus"></i> Novo
                </button>
            </div>
            <?php $iq = !empty($activeInbox) ? 'inbox=' . (int)$activeInbox . '&' : ''; ?>
            <div class="pane-search">
                <form method="GET" action="<?= url('inbox') ?><?= $iq ? '?' . rtrim($iq, '&') : '' ?>" id="inboxSearchForm" data-fstatus="<?= e($fstatus ?? 'active') ?>">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" name="search" class="pane-search-input" placeholder="Buscar conversas..."
                           value="<?= e($search ?? '') ?>"
                           oninput="debounceSearch(this)" autocomplete="off">
                    <input type="hidden" name="fstatus" value="<?= e($fstatus ?? 'active') ?>">
                    <?php if (!empty($activeInbox)): ?>
                        <input type="hidden" name="inbox" value="<?= (int)$activeInbox ?>">
                    <?php endif; ?>
                    <button type="submit" style="display:none"></button>
                </form>
            </div>
            <div class="tabs" id="inboxTabs">
                <a href="<?= url('inbox') ?>?<?= $iq ?>fstatus=active" class="tab <?= ($fstatus ?? 'active') === 'active' ? 'active' : '' ?>">
                    <i class="fas fa-headset"></i> Em atendimento
                </a>
                <a href="<?= url('inbox') ?>?<?= $iq ?>fstatus=resolved_closed" class="tab <?= ($fstatus ?? '') === 'resolved_closed' ? 'active' : '' ?>">
                    <i class="fas fa-check"></i> Concluído
                </a>
            </div>
        </div>

        <div class="conversations" id="conversationsList">
            <?php if (empty($conversations)): ?>
                <div class="empty-state-enhanced">
                    <div class="empty-icon"><i class="fas fa-inbox"></i></div>
                    <h3>Nenhuma conversa encontrada</h3>
                    <p>As conversas aparecerão aqui quando receberem mensagens.</p>
                </div>
            <?php else: ?>
                <?php
                $convParams = [];
                if (!empty($activeInbox)) $convParams['inbox'] = (int)$activeInbox;
                if (!empty($fstatus)) $convParams['fstatus'] = $fstatus;
                $convQs = $convParams ? '?' . http_build_query($convParams) . '&conv=' : '?conv=';
                $convBase = url('inbox') . $convQs;
                $activeConvId = !empty($detail) ? $detail['conversation']['id'] : null;
                $channelColors = [
                    'whatsapp' => '#25D366',
                    'webchat'  => '#4361ee',
                    'email'    => '#f59e0b',
                    'telegram' => '#0088cc',
                    'facebook' => '#1877f2',
                    'instagram'=> '#e1306c',
                    'phone'    => '#6c757d',
                ];
                ?>
                <?php foreach ($conversations as $conv): ?>
                    <a href="<?= $convBase ?><?= $conv['id'] ?>"
                       class="conversation-item <?= $activeConvId == $conv['id'] ? 'active' : '' ?> conv-card-hover"
                       data-conv-id="<?= $conv['id'] ?>"
                       data-msg-count="<?= (int)($conv['message_count'] ?? 0) ?>"
                       data-unread="<?= (int)($conv['unread_count'] ?? 0) ?>">
                        <div class="avatar-container">
                            <?php if ($conv['contact_avatar']): ?>
                                <img class="avatar" src="<?= e(str_starts_with($conv['contact_avatar'], 'http') ? $conv['contact_avatar'] : upload_url($conv['contact_avatar'])) ?>" alt="">
                            <?php else: ?>
                                <div class="avatar avatar-placeholder-sm">
                                    <?= mb_strtoupper(mb_substr($conv['contact_name'] ?? '?', 0, 1)) ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($conv['status'] === 'open' || $conv['status'] === 'new'): ?>
                                <div class="status-badge-dot"></div>
                            <?php endif; ?>
                            <?php $unread = (int)($conv['unread_count'] ?? 0); ?>
                            <?php if ($unread > 0): ?>
                                <span class="unread-badge"><?= $unread > 99 ? '99+' : $unread ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="convo-info">
                            <div class="convo-header">
                                <span class="convo-name"><?= e($conv['contact_name']) ?></span>
                                <span class="convo-time">
                                    <?= time_elapsed($conv['last_message_at'] ?? $conv['created_at']) ?>
                                </span>
                            </div>
                            <?php if (!empty($conv['subject'])): ?>
                                <div class="convo-subject"><?= e($conv['subject']) ?></div>
                            <?php endif; ?>
                            <p class="convo-preview">
                                <?= e(truncate($conv['last_message'] ?? 'Sem mensagens', 80)) ?>
                            </p>
                            <div class="convo-meta">
                                <?php $chColor = $channelColors[$conv['channel_type'] ?? ''] ?? '#6c757d'; ?>
                                <span class="convo-channel" style="background: <?= e($chColor) ?>">
                                    <i class="<?= channel_icon($conv['channel_type'] ?? 'webchat') ?>"></i>
                                    <?= e($conv['channel_name'] ?? '') ?>
                                </span>
                                <?= priority_badge($conv['priority'] ?? 'normal') ?>
                                <?= status_badge($conv['status']) ?>
                            </div>
                            <div class="convo-footer">
                                <span><i class="fas fa-user"></i> <?= e($conv['assigned_user_name'] ?? 'Não atribuído') ?></span>
                                <span><i class="fas fa-comment-dots"></i> <?= (int)($conv['message_count'] ?? 0) ?></span>
                                <?php if ($conv['department_name']): ?>
                                    <span><i class="fas fa-layer-group"></i> <?= e($conv['department_name']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="inbox-col-detail" id="conversationDetail">
        <?php if (!empty($detail)): ?>
            <?php extract($detail); ?>
            <?php include __DIR__ . '/panel.php'; ?>
        <?php else: ?>
            <div class="detail-empty">
                <i class="fas fa-comments fa-3x" style="opacity:0.3"></i>
                <p style="font-size:15px;color:var(--text-muted);margin-top:12px">Selecione uma conversa para visualizar o conteúdo da mensagem</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Inbox search debounce
var searchTimer;
function debounceSearch(input) {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function() { input.closest('form').submit(); }, 400);
}

// Load conversation panel via AJAX
function loadConversation(convId, updateUrl) {
    var panel = document.getElementById('conversationDetail');
    if (!panel) return;
    var baseUrl = document.querySelector('meta[name="base-url"]')?.content || '';

    panel.innerHTML = '<div class="detail-empty"><i class="fas fa-spinner fa-spin fa-3x" style="opacity:0.3"></i><p style="font-size:15px;color:var(--text-muted);margin-top:12px">Carregando...</p></div>';

    var xhr = new XMLHttpRequest();
    xhr.open('GET', baseUrl + '/inbox/' + convId + '/panel');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.timeout = 30000;

    xhr.onload = function() {
        if (xhr.status >= 200 && xhr.status < 300) {
            var html = xhr.responseText;
            if (!html || html.length < 50) {
                panel.innerHTML = '<div class="detail-empty"><i class="fas fa-exclamation-triangle fa-3x" style="opacity:0.3"></i><p style="font-size:15px;color:var(--text-muted);margin-top:12px">Resposta vazia do servidor.</p></div>';
                return;
            }
            panel.innerHTML = html;
            // Execute <script> tags from the HTML (they don't run via innerHTML)
            var scripts = panel.querySelectorAll('script');
            scripts.forEach(function(s) {
                var ns = document.createElement('script');
                if (s.src) { ns.src = s.src; }
                else { ns.textContent = s.textContent; }
                document.body.appendChild(ns);
            });

            if (window.innerWidth <= 768) {
                var backBtn = document.createElement('button');
                backBtn.className = 'btn btn-sm btn-outline';
                backBtn.innerHTML = '<i class="fas fa-arrow-left"></i> Voltar';
                backBtn.style.cssText = 'position:absolute;top:12px;left:12px;z-index:5';
                backBtn.addEventListener('click', function() {
                    document.querySelector('.inbox-3col')?.classList.remove('show-detail');
                });
                var header = panel.querySelector('.conv-detail-header');
                if (header) header.appendChild(backBtn);
            }
        } else {
            panel.innerHTML = '<div class="detail-empty"><i class="fas fa-exclamation-triangle fa-3x" style="opacity:0.3"></i><p style="font-size:15px;color:var(--text-muted);margin-top:12px">Erro HTTP ' + xhr.status + '</p></div>';
        }
    };

    xhr.onerror = function() {
        panel.innerHTML = '<div class="detail-empty"><i class="fas fa-exclamation-triangle fa-3x" style="opacity:0.3"></i><p style="font-size:15px;color:var(--text-muted);margin-top:12px">Erro de rede ao carregar conversa.</p></div>';
    };

    xhr.ontimeout = function() {
        panel.innerHTML = '<div class="detail-empty"><i class="fas fa-exclamation-triangle fa-3x" style="opacity:0.3"></i><p style="font-size:15px;color:var(--text-muted);margin-top:12px">Tempo limite excedido.</p></div>';
    };

    xhr.send();
}

// Click on conversation item: AJAX load + pushState
document.addEventListener('click', function(e) {
    var item = e.target.closest('.conversation-item');
    if (!item) return;
    e.preventDefault();
    var convId = item.dataset.convId;
    if (!convId) return;

    document.querySelectorAll('.conversation-item.active').forEach(function(el) { el.classList.remove('active'); });
    item.classList.add('active');

    var url = new URL(window.location);
    url.searchParams.set('conv', convId);
    window.history.pushState({}, '', url);

    if (window.innerWidth <= 768) {
        document.querySelector('.inbox-3col')?.classList.add('show-detail');
    }

    loadConversation(convId, true);
});

// Browser back/forward navigation
window.addEventListener('popstate', function() {
    var params = new URLSearchParams(window.location.search);
    var convId = params.get('conv');
    if (convId) {
        loadConversation(convId, false);
    } else {
        document.getElementById('conversationDetail').innerHTML =
            '<div class="detail-empty"><i class="fas fa-comments fa-3x" style="opacity:0.3"></i><p style="font-size:15px;color:var(--text-muted);margin-top:12px">Selecione uma conversa para visualizar o conteúdo da mensagem</p></div>';
        document.querySelectorAll('.conversation-item.active').forEach(function(el) { el.classList.remove('active'); });
    }
});
</script>

<!-- Novo Atendimento Modal -->
<div class="modal-overlay" id="newConvModal" style="display:none" onclick="if(event.target===this)closeNewConvModal()">
    <div class="modal-container" style="max-width:560px">
        <div class="modal-header">
            <h3><i class="fas fa-plus-circle"></i> Novo Atendimento</h3>
            <button type="button" class="modal-close" onclick="closeNewConvModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form id="newConvForm" onsubmit="return submitNewConv(event)">
                <div class="form-group" style="position:relative">
                    <label><i class="fas fa-user"></i> Contato</label>
                    <input type="text" id="contactSearch" class="form-control"
                           placeholder="Buscar por nome ou telefone..."
                           autocomplete="off" required
                           oninput="searchContact(this.value)">
                    <input type="hidden" name="contact_id" id="contactId">
                    <div id="contactResults" class="autocomplete-dropdown" style="display:none"></div>
                </div>
                <div class="form-row">
                    <div class="form-group col-6">
                        <label><i class="fas fa-layer-group"></i> Departamento</label>
                        <select name="department_id" class="form-control">
                            <option value="">Nenhum</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?= $dept['id'] ?>"><?= e($dept['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-6">
                        <label><i class="fab fa-whatsapp"></i> Canal WhatsApp</label>
                        <select name="channel_id" class="form-control" required>
                            <option value="">Selecione um canal</option>
                            <?php foreach ($channels as $ch): ?>
                                <option value="<?= $ch['id'] ?>">
                                    <?= e($ch['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-comment"></i> Mensagem inicial</label>
                    <textarea name="message" rows="3" class="form-control" placeholder="Digite a primeira mensagem..."></textarea>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeNewConvModal()">Cancelar</button>
            <button type="submit" class="btn btn-primary" form="newConvForm">
                <i class="fas fa-plus"></i> Criar Atendimento
            </button>
        </div>
    </div>
</div>

<style>
.modal-overlay {
    position:fixed;top:0;left:0;right:0;bottom:0;
    background:rgba(0,0,0,0.5);z-index:9999;
    display:flex;align-items:center;justify-content:center;
    backdrop-filter:blur(4px);
}
.modal-container {
    background:#fff;
    border-radius:12px;width:90%;max-width:560px;
    box-shadow:0 20px 60px rgba(0,0,0,0.3);
    animation:fadeInUp .3s ease;
}
.modal-header {
    display:flex;align-items:center;justify-content:space-between;
    padding:18px 24px;border-bottom:1px solid var(--border);
}
.modal-header h3 {margin:0;font-size:17px}
.modal-close {
    background:none;border:none;font-size:24px;
    cursor:pointer;color:var(--text-muted);padding:0;line-height:1;
}
.modal-close:hover {color:var(--text)}
.modal-body {padding:20px 24px;max-height:60vh;overflow-y:auto}
.autocomplete-dropdown {
    position:absolute;top:100%;left:0;right:0;z-index:100;
    background:#fff;border:1px solid var(--border);
    border-radius:8px;max-height:200px;overflow-y:auto;
    box-shadow:0 8px 24px rgba(0,0,0,0.12);
}
.autocomplete-item {
    display:flex;align-items:center;gap:10px;
    padding:10px 14px;cursor:pointer;
    border-bottom:1px solid var(--border);transition:background .15s;
}
.autocomplete-item:last-child {border-bottom:none}
.autocomplete-item:hover,.autocomplete-item.active {background:#f0f4ff}
.autocomplete-item .ac-name {font-weight:500;color:var(--text)}
.autocomplete-item .ac-meta {font-size:12px;color:var(--text-muted)}
[data-theme="dark"] .modal-container,
[data-theme="dark"] .autocomplete-dropdown,
[data-theme="dark"] .autocomplete-item {background:#1e1e2e}
[data-theme="dark"] .autocomplete-item:hover,
[data-theme="dark"] .autocomplete-item.active {background:#2a2a3e}
.modal-footer {
    display:flex;align-items:center;justify-content:flex-end;gap:10px;
    padding:14px 24px;border-top:1px solid var(--border);
}
</style>

<script>
var _contactTimer;

function openNewConvModal() {
    document.getElementById('newConvModal').style.display = 'flex';
    document.getElementById('contactSearch').focus();
}

function closeNewConvModal() {
    document.getElementById('newConvModal').style.display = 'none';
}

function selectContact(id, name) {
    document.getElementById('contactId').value = id;
    document.getElementById('contactSearch').value = name;
    document.getElementById('contactResults').style.display = 'none';
}

function searchContact(val) {
    clearTimeout(_contactTimer);
    val = val.trim();
    if (val.length < 1) {
        document.getElementById('contactResults').style.display = 'none';
        document.getElementById('contactId').value = '';
        return;
    }
    _contactTimer = setTimeout(function() {
        fetch('<?= url('api/contacts/search') ?>?q=' + encodeURIComponent(val))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var box = document.getElementById('contactResults');
                if (!data.length) {
                    box.innerHTML = '<div style="padding:12px 14px;color:var(--text-muted);font-size:13px">Nenhum contato encontrado</div>';
                    box.style.display = 'block';
                    return;
                }
                var html = '';
                for (var i = 0; i < data.length; i++) {
                    var c = data[i];
                    var phone = c.phone || '';
                    var email = c.email || '';
                    var meta = phone || email;
                    html += '<div class="autocomplete-item" onclick="selectContact(' + c.id + ',\'' + c.name.replace(/'/g,"\\'") + '\')">' +
                        '<div class="ac-name">' + c.name + '</div>' +
                        (meta ? '<div class="ac-meta">' + meta + '</div>' : '') +
                        '</div>';
                }
                box.innerHTML = html;
                box.style.display = 'block';
            });
    }, 250);
}

document.addEventListener('click', function(e) {
    var box = document.getElementById('contactResults');
    if (box && !e.target.closest('.form-group') && box.style.display !== 'none') {
        box.style.display = 'none';
    }
});

function submitNewConv(event) {
    event.preventDefault();
    if (!document.getElementById('contactId').value) {
        alert('Selecione um contato válido.');
        return;
    }
    var form = document.getElementById('newConvForm');
    var data = new FormData(form);
    var btn = form.querySelector('.btn-primary');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Criando...';

    fetch('<?= url('api/conversations') ?>', { method:'POST', body:data })
        .then(function(r) { return r.json().then(function(j) { return { status: r.status, json: j }; }); })
        .then(function(resp) {
            if (resp.json.error) {
                alert(resp.json.error);
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-plus"></i> Criar Atendimento';
                return;
            }
            closeNewConvModal();
            window.location.href = '<?= url('inbox') ?>?conv=' + resp.json.id;
        })
        .catch(function() {
            alert('Erro ao criar atendimento.');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-plus"></i> Criar Atendimento';
        });
    return false;
}
</script>
