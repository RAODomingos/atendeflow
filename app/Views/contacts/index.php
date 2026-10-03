<?php
$total = $stats['total'] ?? 0;
$with = $stats['with_conversations'] ?? 0;
$without = $stats['without_conversations'] ?? 0;
$newToday = $stats['new_today'] ?? 0;
?>
<div class="contacts-page">
    <div class="contact-stats">
        <div class="contact-stat">
            <div class="contact-stat-icon" style="background:var(--brand-soft);color:var(--brand)">
                <i class="fas fa-address-book"></i>
            </div>
            <div class="contact-stat-body">
                <div class="contact-stat-value"><?= $total ?></div>
                <div class="contact-stat-label">Total</div>
            </div>
        </div>
        <div class="contact-stat">
            <div class="contact-stat-icon" style="background:var(--success-soft);color:var(--success)">
                <i class="fas fa-comments"></i>
            </div>
            <div class="contact-stat-body">
                <div class="contact-stat-value"><?= $with ?></div>
                <div class="contact-stat-label">Com conversa</div>
            </div>
        </div>
        <div class="contact-stat">
            <div class="contact-stat-icon" style="background:var(--warning-soft);color:var(--warning)">
                <i class="fas fa-user-plus"></i>
            </div>
            <div class="contact-stat-body">
                <div class="contact-stat-value"><?= $without ?></div>
                <div class="contact-stat-label">Sem conversa</div>
            </div>
        </div>
        <div class="contact-stat">
            <div class="contact-stat-icon" style="background:var(--info-soft);color:var(--info)">
                <i class="fas fa-sparkles"></i>
            </div>
            <div class="contact-stat-body">
                <div class="contact-stat-value"><?= $newToday ?></div>
                <div class="contact-stat-label">Novos hoje</div>
            </div>
        </div>
    </div>

    <form action="<?= url('contacts') ?>" method="GET" class="contact-search-form contact-search-row">
        <div class="contact-search-box">
            <i class="fas fa-search"></i>
            <input type="text" name="search" class="contact-search-input"
                   placeholder="Buscar por nome, e-mail ou telefone..."
                   value="<?= e($search ?? '') ?>"
                   oninput="debounceContactSearch(this)">
        </div>
        <button type="button" class="btn btn-primary" onclick="openCreateDrawer()">
            <i class="fas fa-plus"></i> Novo Contato
        </button>
    </form>

    <?php if (empty($contacts)): ?>
        <div class="card" style="border-radius:12px;overflow:hidden">
            <div class="card-body" style="padding:0">
                <div class="empty-state-enhanced">
                    <div class="empty-icon"><i class="fas fa-address-book"></i></div>
                    <?php if (!empty($search)): ?>
                        <h3>Nenhum contato encontrado</h3>
                        <p>Tente ajustar a busca ou <a href="<?= url('contacts') ?>">limpar filtros</a>.</p>
                    <?php else: ?>
                        <h3>Nenhum contato cadastrado</h3>
                        <p>Crie um novo contato para começar.</p>
                        <button type="button" class="btn btn-primary mt-2" onclick="openCreateDrawer()">
                            <i class="fas fa-plus"></i> Criar Contato
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="card" style="border-radius:12px;overflow:hidden">
            <div class="card-body" style="padding:0">
                <table class="table contact-table">
                    <thead>
                        <tr><th>Contato</th><th>E-mail</th><th>Telefone</th><th>Conversas</th><th>Último contato</th><th style="width:130px">Ações</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($contacts as $contact):
                            $cId = (int) $contact['id'];
                            $cName = e($contact['name']);
                            $cEmail = e($contact['email'] ?? '');
                            $cPhone = e($contact['phone'] ?? '');
                            $cCompany = e($contact['company'] ?? '');
                            $cStoreNet = e($contact['store_network'] ?? '');
                            $cStoreNets = (int)($contact['store_networks'] ?? 0);
                            $cDoc = e($contact['document'] ?? '');
                            $cNotes = e($contact['notes'] ?? '');
                            $cAvatar = $contact['avatar'] ?? '';
                            $cAvatarUrl = $cAvatar ? (str_starts_with($cAvatar, 'http') ? $cAvatar : upload_url($cAvatar)) : '';
                            $cInitial = mb_strtoupper(mb_substr($contact['name'], 0, 1));
                            $cConvCount = (int)($contact['conversation_count'] ?? 0);
                            $cLastMsg = $contact['last_message_at'] ?? '';
                        ?>
                            <tr class="contact-row"
                                 data-id="<?= $cId ?>"
                                 data-name="<?= $cName ?>"
                                 data-email="<?= $cEmail ?>"
                                 data-phone="<?= $cPhone ?>"
                                 data-document="<?= $cDoc ?>"
                                 data-notes="<?= $cNotes ?>"
                                 data-avatar="<?= e($cAvatar) ?>"
                                 onclick="if(event.target.closest('[data-action]'))return;window.location='<?= url('contacts/') ?><?= $cId ?>'"
                                 style="cursor:pointer">
                                <td>
                                    <div style="display:flex;align-items:center;gap:10px">
                                        <div class="contact-list-avatar">
                                            <?php if ($cAvatarUrl): ?>
                                                <img src="<?= e($cAvatarUrl) ?>" alt="<?= $cName ?>">
                                            <?php else: ?>
                                                <div class="avatar-ph"><?= e($cInitial) ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <div class="contact-row-name" title="<?= $cName ?>"><?= $cName ?></div>
                                            <?php if ($cStoreNet): ?>
                                                <small class="form-hint"><i class="fas fa-store"></i> <?= $cStoreNet ?><?= $cStoreNets > 1 ? ' +' . ($cStoreNets - 1) : '' ?></small>
                                            <?php elseif ($cCompany): ?>
                                                <small class="form-hint"><i class="fas fa-building"></i> <?= $cCompany ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td><?= $cEmail ?: '<span class="text-muted">—</span>' ?></td>
                                <td style="white-space:nowrap"><?= $cPhone ?: '<span class="text-muted">—</span>' ?></td>
                                <td><span class="badge badge-secondary"><?= $cConvCount ?></span></td>
                                <td style="white-space:nowrap"><?= $cLastMsg ? time_elapsed($cLastMsg) : '<span class="text-muted">Nunca</span>' ?></td>
                                <td class="action-cell">
                                    <a href="<?= url('contacts/') ?><?= $cId ?>" class="btn btn-sm btn-outline btn-icon" title="Ver detalhes"><i class="fas fa-eye"></i></a>
                                    <button type="button" class="btn btn-sm btn-outline btn-icon" title="Editar" data-action="edit-contact" data-id="<?= $cId ?>">
                                        <i class="fas fa-pen"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline btn-icon btn-icon-danger" title="Excluir" data-action="delete-contact" data-id="<?= $cId ?>" data-name="<?= e($contact['name']) ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <style>
        .contact-table .contact-list-avatar { width:36px;height:36px;border-radius:50%;overflow:hidden;flex-shrink:0;background:var(--brand-soft);display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--brand); }
        .contact-table .contact-list-avatar img { width:100%;height:100%;object-fit:cover; }
        .contact-table .contact-row-name { font-weight:600; }
        .contact-table .form-hint { font-size:12px;color:#6c757d; }
        .contact-table .action-cell { white-space:nowrap; }
        .contact-table tbody tr:hover { background:var(--bg-content); }
        .guild-group{border:1px solid var(--border-soft);border-radius:8px;padding:10px 12px;margin-top:8px;background:var(--bg-panel-alt)}
        .guild-group-title{font-size:12px;font-weight:700;margin-bottom:6px}
        .guild-opt{display:flex;align-items:center;gap:8px;font-size:13px;padding:3px 0;cursor:pointer}
        .guild-opt input{accent-color:var(--brand)}
        .guild-err{font-size:12.5px;color:var(--danger);margin-top:8px}
        .contact-search-row { display:flex;gap:10px;align-items:center; }
        .contact-search-row .contact-search-box { flex:1;max-width:none; }
        .contact-search-row .btn { flex-shrink:0; }
        </style>
        <?php
        $pg = $pagination ?? ['page' => 1, 'pages' => 1, 'total' => count($contacts)];
        if ($pg['pages'] > 1):
            $pgBase = url('contacts') . '?' . http_build_query(array_filter(['search' => $search ?? null])) ;
            $pgBase .= empty($search) ? 'page=' : '&page=';
        ?>
        <div class="pager" style="display:flex;align-items:center;justify-content:center;gap:12px;margin:22px 0 8px">
            <?php if ($pg['page'] > 1): ?>
                <a href="<?= $pgBase . ($pg['page'] - 1) ?>" class="btn btn-sm btn-outline"><i class="fas fa-chevron-left"></i> Anterior</a>
            <?php endif; ?>
            <span style="font-size:13px;color:var(--text-muted)">Página <?= (int) $pg['page'] ?> de <?= (int) $pg['pages'] ?> · <?= (int) $pg['total'] ?> contatos</span>
            <?php if ($pg['page'] < $pg['pages']): ?>
                <a href="<?= $pgBase . ($pg['page'] + 1) ?>" class="btn btn-sm btn-outline">Próxima <i class="fas fa-chevron-right"></i></a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="contact-drawer-overlay" id="listDrawerOverlay" onclick="closeListDrawer()"></div>
<div class="contact-drawer" id="listDrawer">
    <div class="contact-drawer-header">
        <h3><i class="fas fa-user-edit" style="color:var(--brand)"></i> Editar Contato</h3>
        <button class="contact-drawer-close" onclick="closeListDrawer()">&times;</button>
    </div>
    <div class="contact-drawer-body">
        <form id="listEditForm" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="contact_id" id="listEditId">
            <div class="form-group">
                <label><i class="fas fa-user"></i> Nome *</label>
                <input type="text" name="name" id="listEditName" class="form-control" required placeholder="Nome completo">
            </div>
            <div class="form-group">
                <label><i class="fas fa-store"></i> Loja (Guild)</label>
                <div style="display:flex;gap:8px">
                    <input type="text" id="guildCustomerE" class="form-control" placeholder="Código da loja">
                    <button type="button" class="btn btn-outline btn-sm" onclick="guildBuscar('E')">Buscar</button>
                </div>
                <div id="guildResultE"></div>
                <input type="hidden" name="guild_stores_json" id="guildJsonE" value="">
                <input type="hidden" name="guild_networks_json" id="guildNetE" value="">
            </div>
            <div class="form-group">
                <label><i class="fas fa-envelope"></i> E-mail</label>
                <input type="email" name="email" id="listEditEmail" class="form-control" placeholder="email@exemplo.com">
            </div>
            <div class="form-group">
                <label><i class="fas fa-phone"></i> Telefone</label>
                <input type="text" name="phone" id="listEditPhone" class="form-control" placeholder="(11) 99999-9999">
            </div>
            <div class="form-group">
                <label><i class="fas fa-id-card"></i> CPF/CNPJ</label>
                <input type="text" name="document" id="listEditDocument" class="form-control">
            </div>
            <div class="form-group">
                <label><i class="fas fa-tags"></i> Etiquetas</label>
                <select name="tag_ids[]" class="form-control" multiple>
                    <?php foreach ($tags as $tag): ?>
                        <option value="<?= $tag['id'] ?>"><?= e($tag['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label><i class="fas fa-sticky-note"></i> Observações</label>
                <textarea name="notes" id="listEditNotes" rows="5" class="form-control" placeholder="Informações adicionais sobre o contato..."></textarea>
            </div>
        </form>
    </div>
    <div class="contact-drawer-footer">
        <button type="button" class="btn btn-outline" onclick="closeListDrawer()">Cancelar</button>
        <button type="submit" class="btn btn-primary" form="listEditForm">
            <i class="fas fa-save"></i> Salvar
        </button>
    </div>
</div>

<div class="contact-drawer-overlay" id="createDrawerOverlay" onclick="closeCreateDrawer()"></div>
<div class="contact-drawer" id="createDrawer">
    <div class="contact-drawer-header">
        <h3><i class="fas fa-user-plus" style="color:var(--brand)"></i> Novo Contato</h3>
        <button class="contact-drawer-close" onclick="closeCreateDrawer()">&times;</button>
    </div>
    <div class="contact-drawer-body">
        <form id="createContactForm" method="POST" action="<?= url('contacts/create') ?>">
            <?= csrf_field() ?>
            <div class="form-group">
                <label><i class="fas fa-user"></i> Nome *</label>
                <input type="text" name="name" class="form-control" required placeholder="Nome completo">
            </div>
            <div class="form-group">
                <label><i class="fas fa-store"></i> Loja (Guild)</label>
                <div style="display:flex;gap:8px">
                    <input type="text" id="guildCustomerC" class="form-control" placeholder="Código da loja">
                    <button type="button" class="btn btn-outline btn-sm" onclick="guildBuscar('C')">Buscar</button>
                </div>
                <div id="guildResultC"></div>
                <input type="hidden" name="guild_stores_json" id="guildJsonC" value="">
                <input type="hidden" name="guild_networks_json" id="guildNetC" value="">
            </div>
            <div class="form-group">
                <label><i class="fas fa-envelope"></i> E-mail</label>
                <input type="email" name="email" class="form-control" placeholder="email@exemplo.com">
            </div>
            <div class="form-group">
                <label><i class="fas fa-phone"></i> Telefone</label>
                <input type="text" name="phone" class="form-control" placeholder="(11) 99999-9999">
            </div>
            <div class="form-group">
                <label><i class="fas fa-id-card"></i> CPF/CNPJ</label>
                <input type="text" name="document" class="form-control">
            </div>
            <div class="form-group">
                <label><i class="fas fa-tags"></i> Etiquetas</label>
                <select name="tag_ids[]" class="form-control" multiple>
                    <?php foreach ($tags as $tag): ?>
                        <option value="<?= $tag['id'] ?>"><?= e($tag['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label><i class="fas fa-sticky-note"></i> Observações</label>
                <textarea name="notes" rows="5" class="form-control" placeholder="Informações adicionais sobre o contato..."></textarea>
            </div>
        </form>
    </div>
    <div class="contact-drawer-footer">
        <button type="button" class="btn btn-outline" onclick="closeCreateDrawer()">Cancelar</button>
        <button type="submit" class="btn btn-primary" form="createContactForm">
            <i class="fas fa-plus"></i> Criar Contato
        </button>
    </div>
</div>

<form id="deleteContactForm" method="POST" style="display:none">
    <?= csrf_field() ?>
</form>

<script>
(function() {
    var searchTimer;
    var contactsBaseUrl = <?= json_encode(url('contacts/'), JSON_UNESCAPED_SLASHES) ?>;

    window.debounceContactSearch = function(input) {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function() { input.closest('form').submit(); }, 400);
    };

    document.addEventListener('click', function(e) {
        var editBtn = e.target.closest('[data-action="edit-contact"]');
        if (editBtn) {
            e.preventDefault();
            e.stopPropagation();
            var tr = document.querySelector('.contact-row[data-id="' + editBtn.dataset.id + '"]');
            if (tr) openListDrawerFromCard(tr);
            return;
        }
        var delBtn = e.target.closest('[data-action="delete-contact"]');
        if (delBtn) {
            e.preventDefault();
            e.stopPropagation();
            confirmDeleteContact(delBtn.dataset.id, delBtn.dataset.name);
        }
    });

    window.confirmDeleteContact = function(id, name) {
        OminiConfirm('Excluir contato "' + name + '"? Esta ação não pode ser desfeita.').then(function(ok) {
            if (!ok) return;
            var form = document.getElementById('deleteContactForm');
            form.action = contactsBaseUrl + id + '/delete';
            form.submit();
        });
    };

    window.openListDrawerFromCard = function(card) {
        document.getElementById('listEditId').value = card.dataset.id;
        document.getElementById('listEditName').value = card.dataset.name;
        document.getElementById('listEditEmail').value = card.dataset.email;
        document.getElementById('listEditPhone').value = card.dataset.phone;
        document.getElementById('listEditDocument').value = card.dataset.document;
        document.getElementById('listEditNotes').value = card.dataset.notes;
        document.getElementById('listEditForm').action = contactsBaseUrl + card.dataset.id + '/update';
        guildPrefill('E', card.dataset.id);
        openListDrawer();
    };

    window.guildPrefill = function(sfx, contactId) {
        var box = document.getElementById('guildResult' + sfx);
        if (!box) return;
        box.innerHTML = '';
        var base = document.querySelector('meta[name="base-url"]')?.content || '';
        fetch(base + '/contacts/' + contactId + '/stores', {headers:{'X-Requested-With':'XMLHttpRequest'}})
            .then(function(r){ return r.json(); })
            .then(function(j){
                if (!j.success || !j.stores) return;
                guildRenderGroups(box, j.stores);
            })
            .catch(function(){});
    };

    window.guildRenderGroups = function(box, groups) {
        var byNet = {};
        groups.forEach(function(s){
            (byNet[s.network_name] = byNet[s.network_name] || {network_name: s.network_name, customer_id: s.customer_id, stores: []}).stores.push(s);
        });
        Object.keys(byNet).forEach(function(net){
            var g = byNet[net];
            var div = document.createElement('div');
            div.className = 'guild-group';
            div.dataset.network = g.network_name;
            div.dataset.customer = g.customer_id;
            var h = '<div class="guild-group-title"></div>';
            div.innerHTML = h;
            div.querySelector('.guild-group-title').textContent = g.network_name + ' (' + g.customer_id + ')';
            g.stores.forEach(function(st){
                var lab = document.createElement('label');
                lab.className = 'guild-opt';
                var cb = document.createElement('input');
                cb.type = 'checkbox'; cb.checked = true;
                cb.dataset.sid = st.store_id; cb.dataset.sname = st.store_name;
                lab.appendChild(cb);
                lab.appendChild(document.createTextNode(' ' + st.store_name));
                div.appendChild(lab);
            });
            box.appendChild(div);
        });
    };

    window.guildBuscar = function(sfx){
        var codeEl = document.getElementById('guildCustomer' + sfx);
        var box = document.getElementById('guildResult' + sfx);
        var code = (codeEl.value || '').trim();
        if (!code || !box) return;
        box.insertAdjacentHTML('beforeend', '<p class="text-muted" data-tmp>Buscando lojas...</p>');
        var base = document.querySelector('meta[name="base-url"]')?.content || '';
        fetch(base + '/api/guild/stores?customer_id=' + encodeURIComponent(code), {headers:{'X-Requested-With':'XMLHttpRequest'}})
            .then(function(r){ return r.json(); })
            .then(function(j){
                box.querySelector('[data-tmp]')?.remove();
                if (!j.success) { box.insertAdjacentHTML('beforeend', '<p class="guild-err"></p>'); box.querySelector('.guild-err:last-child').textContent = j.error; return; }
                var exists = false;
                box.querySelectorAll('.guild-group').forEach(function(g){ if (g.dataset.network === j.network_name) exists = true; });
                if (exists) return;
                guildRenderGroups(box, j.stores.map(function(st){ return {customer_id: code, network_name: j.network_name, store_id: st.id, store_name: st.name}; }));
            })
            .catch(function(){ box.querySelector('[data-tmp]')?.remove(); box.insertAdjacentHTML('beforeend', '<p class="guild-err">Falha ao consultar o painel Guild. Tente novamente.</p>'); });
    };

    window.guildCollect = function(sfx){
        var box = document.getElementById('guildResult' + sfx);
        if (!box) return;
        var stores = [], nets = [];
        box.querySelectorAll('.guild-group').forEach(function(g){
            nets.push(g.dataset.network);
            g.querySelectorAll('input[type="checkbox"]:checked').forEach(function(cb){
                stores.push({customer_id: g.dataset.customer, network_name: g.dataset.network, store_id: parseInt(cb.dataset.sid, 10), store_name: cb.dataset.sname});
            });
        });
        document.getElementById('guildJson' + sfx).value = JSON.stringify(stores);
        document.getElementById('guildNet' + sfx).value = JSON.stringify(nets);
    };

    window.openListDrawer = function() {
        document.getElementById('listDrawer').classList.add('open');
        document.getElementById('listDrawerOverlay').classList.add('open');
        document.body.style.overflow = 'hidden';
    };

    window.closeListDrawer = function() {
        document.getElementById('listDrawer').classList.remove('open');
        document.getElementById('listDrawerOverlay').classList.remove('open');
        document.body.style.overflow = '';
    };

    window.openCreateDrawer = function() {
        document.getElementById('createDrawer').classList.add('open');
        document.getElementById('createDrawerOverlay').classList.add('open');
        document.body.style.overflow = 'hidden';
        setTimeout(function() {
            var input = document.querySelector('#createContactForm input[name="name"]');
            if (input) input.focus();
        }, 100);
    };

    window.closeCreateDrawer = function() {
        document.getElementById('createDrawer').classList.remove('open');
        document.getElementById('createDrawerOverlay').classList.remove('open');
        document.body.style.overflow = '';
    };

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeCreateDrawer();
    });

    var createForm = document.getElementById('createContactForm');
    if (createForm) {
        createForm.addEventListener('submit', function(){ guildCollect('C'); });
    }

    var form = document.getElementById('listEditForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            guildCollect('E');
            var btn = this.querySelector('button[type="submit"]');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
            var data = new FormData(this);
            fetch(this.action, {
                method: 'POST',
                body: new URLSearchParams(data),
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            })
            .then(function(r) { return r.json(); })
            .then(function(resp) {
                if (resp.success) {
                    var id = document.getElementById('listEditId').value;
                    var card = document.querySelector('.contact-row[data-id="' + id + '"]');
                    if (card) {
                        card.dataset.name = document.getElementById('listEditName').value;
                        card.dataset.email = document.getElementById('listEditEmail').value;
                        card.dataset.phone = document.getElementById('listEditPhone').value;
                        card.dataset.document = document.getElementById('listEditDocument').value;
                        card.dataset.notes = document.getElementById('listEditNotes').value;
                        var nameEl = card.querySelector('.contact-row-name');
                        if (nameEl) nameEl.textContent = document.getElementById('listEditName').value;
                    }
                    closeListDrawer();
                } else {
                    alert(resp.error || 'Erro ao salvar contato.');
                }
            })
            .catch(function() { alert('Erro de rede. Tente novamente.'); })
            .finally(function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-save"></i> Salvar';
            });
        });
    }
})();
</script>
