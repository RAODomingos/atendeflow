<div class="list-panel" id="listPanel">
    <div class="list-header">
        <h1 class="list-title">Caixa de Entrada <span class="list-title-count"><?= count($conversations) ?></span></h1>
        <button type="button" class="btn-new" onclick="openNewConvModal()">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
            Novo
        </button>
    </div>

    <?php $iq = !empty($activeInbox) ? 'inbox=' . (int)$activeInbox . '&' : ''; ?>
    <div class="search-wrap">
        <form method="GET" action="<?= url('inbox') ?><?= $iq ? '?' . rtrim($iq, '&') : '' ?>" id="inboxSearchForm" data-fstatus="<?= e($fstatus ?? 'active') ?>" class="search-box">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
            <input type="text" name="search" placeholder="Buscar por nome, protocolo..."
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
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11L2 12v6a2 2 0 002 2h16a2 2 0 002-2v-6l-3.45-6.89A2 2 0 0016.76 4H7.24a2 2 0 00-1.79 1.11z"/></svg>
            Em atendimento
        </a>
        <a href="<?= url('inbox') ?>?<?= $iq ?>fstatus=resolved_closed" class="tab <?= ($fstatus ?? '') === 'resolved_closed' ? 'active' : '' ?>">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
            Concluído
        </a>
    </div>

    <div class="conv-list" id="conversationsList">
        <?php
        $activeConvId = !empty($detail) ? $detail['conversation']['id'] : null;
        include __DIR__ . '/_conv_list.php';
        ?>
    </div>
</div>

<div class="chat-panel" id="conversationDetail">
    <?php if (!empty($detail)): ?>
        <?php extract($detail); ?>
        <?php include __DIR__ . '/panel.php'; ?>
    <?php else: ?>
        <div class="chat-empty">
            <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
            <p>Selecione uma conversa para visualizar o conteúdo da mensagem</p>
        </div>
    <?php endif; ?>
</div>

<script>
var searchTimer;
function debounceSearch(input) {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function() { input.closest('form').submit(); }, 400);
}

function loadConversation(convId, updateUrl) {
    var panel = document.getElementById('conversationDetail');
    if (!panel) return;
    var baseUrl = document.querySelector('meta[name="base-url"]')?.content || '';

    panel.innerHTML = '<div class="chat-empty"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:.3"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg><p style="font-size:14px;color:var(--text-muted);margin-top:8px">Carregando...</p></div>';

    var xhr = new XMLHttpRequest();
    xhr.open('GET', baseUrl + '/inbox/' + convId + '/panel');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.timeout = 30000;

    xhr.onload = function() {
        if (xhr.status >= 200 && xhr.status < 300) {
            var html = xhr.responseText;
            if (!html || html.length < 50) {
                panel.innerHTML = '<div class="chat-empty"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:.3"><path d="M12 9v2m0 4h.01"/><circle cx="12" cy="12" r="10"/></svg><p style="font-size:14px;color:var(--text-muted);margin-top:8px">Resposta vazia do servidor.</p></div>';
                return;
            }
            panel.innerHTML = html;
            var scripts = panel.querySelectorAll('script');
            scripts.forEach(function(s) {
                var ns = document.createElement('script');
                if (s.src) { ns.src = s.src; }
                else { ns.textContent = s.textContent; }
                document.body.appendChild(ns);
            });

            if (window.innerWidth <= 992) {
                panel.style.display = 'flex';
                var listPanel = document.getElementById('listPanel');
                if (listPanel) listPanel.style.display = 'none';
            }
        } else {
            panel.innerHTML = '<div class="chat-empty"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:.3"><path d="M12 9v2m0 4h.01"/><circle cx="12" cy="12" r="10"/></svg><p style="font-size:14px;color:var(--text-muted);margin-top:8px">Erro HTTP ' + xhr.status + '</p></div>';
        }
    };

    xhr.onerror = function() {
        panel.innerHTML = '<div class="chat-empty"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:.3"><path d="M12 9v2m0 4h.01"/><circle cx="12" cy="12" r="10"/></svg><p style="font-size:14px;color:var(--text-muted);margin-top:8px">Erro de rede ao carregar conversa.</p></div>';
    };

    xhr.ontimeout = function() {
        panel.innerHTML = '<div class="chat-empty"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:.3"><circle cx="12" cy="12" r="10"/></svg><p style="font-size:14px;color:var(--text-muted);margin-top:8px">Tempo limite excedido.</p></div>';
    };

    xhr.send();
}

document.addEventListener('click', function(e) {
    var item = e.target.closest('.conv-item');
    if (!item) return;
    e.preventDefault();
    var convId = item.dataset.convId;
    if (!convId) return;

    document.querySelectorAll('.conv-item.active').forEach(function(el) { el.classList.remove('active'); });
    item.classList.add('active');

    var url = new URL(window.location);
    url.searchParams.set('conv', convId);
    window.history.pushState({}, '', url);

    if (window.__enhancements && window.__enhancements.GlobalNotifier) {
        window.__enhancements.GlobalNotifier.setViewing(convId);
    }
    if (window.__enhancements && window.__enhancements.LiveFeed) {
        window.__enhancements.LiveFeed.setViewing(convId);
    }

    if (window.innerWidth <= 992) {
        var panel = document.getElementById('conversationDetail');
        var listPanel = document.getElementById('listPanel');
        if (panel) panel.style.display = 'flex';
        if (listPanel) listPanel.style.display = 'none';
    }

    loadConversation(convId, true);
});

window.addEventListener('popstate', function() {
    var params = new URLSearchParams(window.location.search);
    var panel = document.getElementById('conversationDetail');
    var listPanel = document.getElementById('listPanel');
    var convId = params.get('conv');
    if (convId) {
        loadConversation(convId, false);
        if (window.innerWidth <= 992) {
            if (panel) panel.style.display = 'flex';
            if (listPanel) listPanel.style.display = 'none';
        }
    } else {
        if (panel) {
            panel.innerHTML = '<div class="chat-empty"><svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg><p>Selecione uma conversa para visualizar o conteúdo da mensagem</p></div>';
            panel.style.display = '';
        }
        if (listPanel) listPanel.style.display = 'flex';
        document.querySelectorAll('.conv-item.active').forEach(function(el) { el.classList.remove('active'); });
    }
});

var listPanel = document.getElementById('listPanel');
if (listPanel && window.innerWidth > 992) {
    listPanel.style.display = 'flex';
}
</script>

<div class="modal-overlay" id="newConvModal" style="display:none" onclick="if(event.target===this)closeNewConvModal()">
    <div class="modal-container" style="max-width:560px">
        <div class="modal-header">
            <h3><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M12 8v8M8 12h8"/></svg> Novo Atendimento</h3>
            <button type="button" class="modal-close" onclick="closeNewConvModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form id="newConvForm" onsubmit="return submitNewConv(event)">
                <div class="form-group" style="position:relative">
                    <label><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> Contato</label>
                    <input type="text" id="contactSearch" class="form-control"
                           placeholder="Buscar por nome ou telefone..."
                           autocomplete="off" required
                           oninput="searchContact(this.value)">
                    <input type="hidden" name="contact_id" id="contactId">
                    <div id="contactResults" class="autocomplete-dropdown" style="display:none"></div>
                </div>
                <div class="form-row">
                    <div class="form-group col-6">
                        <label><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="8" height="8" rx="2"/><rect x="14" y="2" width="8" height="8" rx="2"/><rect x="2" y="14" width="8" height="8" rx="2"/><rect x="14" y="14" width="8" height="8" rx="2"/></svg> Departamento</label>
                        <select name="department_id" class="form-control">
                            <option value="">Nenhum</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?= $dept['id'] ?>"><?= e($dept['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-6">
                        <label><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><path d="M6 6h.01M6 18h.01"/></svg> Canal WhatsApp</label>
                        <select name="channel_id" class="form-control" required>
                            <option value="">Selecione um canal</option>
                            <?php foreach ($channels as $ch): ?>
                                <option value="<?= $ch['id'] ?>"><?= e($ch['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg> Mensagem inicial</label>
                    <textarea name="message" rows="3" class="form-control" placeholder="Digite a primeira mensagem..."></textarea>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeNewConvModal()">Cancelar</button>
            <button type="submit" class="btn btn-primary" form="newConvForm"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M12 8v8M8 12h8"/></svg> Criar Atendimento</button>
        </div>
    </div>
</div>

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

/* ---- Atualização periódica da lista de conversas (60s) ---- */
var listRefreshTimer = null;

function refreshConversationList() {
    var container = document.getElementById('conversationsList');
    if (!container) return;
    var baseUrl = document.querySelector('meta[name="base-url"]')?.content || '';
    var p = new URLSearchParams(window.location.search);
    var params = new URLSearchParams();

    var fv = p.get('fstatus');
    if (!fv) { var h = document.querySelector('input[name="fstatus"]'); if (h) fv = h.value; }
    if (fv) params.set('fstatus', fv);
    var iv = p.get('inbox');
    if (!iv) { var ih = document.querySelector('input[name="inbox"]'); if (ih) iv = ih.value; }
    if (iv) params.set('inbox', iv);
    var sw = p.get('search');
    if (!sw) { var si = document.querySelector('input[name="search"]'); if (si) sw = si.value; }
    if (sw) params.set('search', sw);
    var cv = p.get('conv');
    if (cv) params.set('conv', cv);

    fetch(baseUrl + '/api/conversations-list?' + params.toString(), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r) { if (!r.ok) throw new Error(String(r.status)); return r.text(); })
    .then(function(html) {
        if (!html) return;
        var prevActive = container.querySelector('.conv-item.active');
        var prevId = prevActive ? prevActive.dataset.convId : (cv || null);
        container.innerHTML = html;

        var countEl = document.querySelector('.list-title-count');
        if (countEl) countEl.textContent = container.querySelectorAll('.conv-item').length;

        if (prevId) {
            var el = container.querySelector('.conv-item[data-conv-id="' + prevId + '"]');
            if (el) el.classList.add('active');
        }

        if (window.__enhancements && window.__enhancements.LiveFeed) {
            var lf = window.__enhancements.LiveFeed;
            lf._known = {};
            container.querySelectorAll('.conv-item').forEach(function(el) {
                var id = parseInt(el.dataset.convId, 10);
                if (!isNaN(id)) {
                    lf._known[id] = {
                        unread: parseInt(el.dataset.unread || '0', 10),
                        msgCount: parseInt(el.dataset.msgCount || '0', 10)
                    };
                }
            });
        }
    })
    .catch(function() {});
}

document.addEventListener('visibilitychange', function() {
    if (!document.hidden && document.getElementById('conversationsList')) {
        refreshConversationList();
    }
});

if (document.getElementById('conversationsList')) {
    listRefreshTimer = setInterval(function() {
        if (document.hidden) return;
        refreshConversationList();
    }, 60000);
}

function submitNewConv(event) {
    event.preventDefault();
    if (!document.getElementById('contactId').value) {
        window.toast('Selecione um contato válido.');
        return;
    }
    var form = document.getElementById('newConvForm');
    var data = new FormData(form);
    var btn = form.querySelector('.btn-primary');
    btn.disabled = true;
    btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="animation:spin .6s linear infinite"><circle cx="12" cy="12" r="10"/><path d="M12 8v8M8 12h8"/></svg> Criando...';

    fetch('<?= url('api/conversations') ?>', { method:'POST', body:data })
        .then(function(r) { return r.json().then(function(j) { return { status: r.status, json: j }; }); })
        .then(function(resp) {
            if (resp.json.error) {
                window.toast(resp.json.error);
                btn.disabled = false;
                btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M12 8v8M8 12h8"/></svg> Criar Atendimento';
                return;
            }
            closeNewConvModal();
            window.location.href = '<?= url('inbox') ?>?conv=' + resp.json.id;
        })
        .catch(function() {
            window.toast('Erro ao criar atendimento.');
            btn.disabled = false;
            btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M12 8v8M8 12h8"/></svg> Criar Atendimento';
        });
    return false;
}
</script>
