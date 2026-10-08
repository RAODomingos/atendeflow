<?php $flashSuccess = \App\Core\Session::getFlash('success'); $flashError = \App\Core\Session::getFlash('error'); ?>
<div class="group-show-page">
    <div class="page-actions">
        <a href="<?= url('whatsapp/groups') ?>" class="btn btn-sm btn-outline" title="Voltar"><i class="fas fa-arrow-left"></i> Voltar</a>
        <?php if ($isManager): ?>
            <form action="<?= url('whatsapp/groups/') ?><?= (int) $group['id'] ?>/alert" method="POST" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="mention_alert" value="<?= $group['mention_alert'] ? '0' : '1' ?>">
                <button type="submit" class="btn btn-sm <?= $group['mention_alert'] ? 'btn-outline' : 'btn-secondary' ?>">
                    <i class="fas fa-bell<?= $group['mention_alert'] ? '' : '-slash' ?>"></i>
                    <?= $group['mention_alert'] ? 'Alerta ativo' : 'Alerta inativo' ?>
                </button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($flashSuccess): ?><div class="alert alert-success"><?= e($flashSuccess) ?></div><?php endif; ?>
    <?php if ($flashError): ?><div class="alert alert-danger"><?= e($flashError) ?></div><?php endif; ?>

    <div class="group-meta card">
        <div class="card-body">
            <div><strong>JID:</strong> <code><?= e($group['group_jid']) ?></code></div>
            <div><strong>Conexão:</strong> <?= e($connection['phone_number'] ?? '-') ?> (<?= e($connection['provider'] ?? '') ?>)</div>
            <?php if (!empty($conversationId)): ?>
                <div><a href="<?= url('inbox') ?>?conv=<?= (int) $conversationId ?>" class="btn btn-sm btn-outline"><i class="fas fa-inbox"></i> Abrir conversa na caixa</a></div>
            <?php endif; ?>
            <?php if ($isManager): ?>
                <form action="<?= url('whatsapp/groups/') ?><?= (int) $group['id'] ?>/inbox" method="POST" class="inbox-form">
                    <?= csrf_field() ?>
                    <label><strong>Caixa onde aparecem as conversas deste grupo:</strong></label>
                    <select name="inbox_id" class="form-control" onchange="this.form.submit()" title="As mensagens do grupo aparecem como conversa nesta caixa">
                        <option value="0">Caixa do canal (padrão)</option>
                        <?php foreach ($inboxes as $ib): ?>
                            <option value="<?= (int) $ib['id'] ?>" <?= ((int) ($group['inbox_id'] ?? 0) === (int) $ib['id']) ? 'selected' : '' ?>><?= e($ib['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-hint">Quando alguém <strong>marcar o número da conexão ou @todos</strong>, o sino avisa os membros desta caixa.</small>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mt-2">
        <div class="card-header"><h3><i class="fas fa-at"></i> Menções ao número da conexão</h3></div>
        <div class="card-body p-0">
            <?php if (empty($mentions)): ?>
                <div class="empty-state"><p>Nenhuma menção registrada neste grupo.</p></div>
            <?php else: ?>
                <table class="table">
                    <thead><tr><th>Quando</th><th>Quem</th><th>Mensagem</th></tr></thead>
                    <tbody>
                        <?php foreach ($mentions as $m): ?>
                            <tr>
                                <td style="white-space:nowrap"><?= format_datetime($m['created_at']) ?></td>
                                <td><strong><?= e($m['sender_name'] ?: $m['sender_phone'] ?: '?') ?></strong><br><small class="form-hint"><?= e($m['sender_phone'] ?? '') ?></small></td>
                                <td><?= e($m['content'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($isManager): ?>
    <div class="card mt-2" id="members-card">
        <div class="card-header"><h3><i class="fas fa-users"></i> Participantes <small class="form-hint">(tempo real via provedor)</small></h3>
            <button type="button" id="members-reload" class="btn btn-sm btn-outline"><i class="fas fa-sync"></i> Atualizar lista</button>
        </div>
        <div class="card-body">
            <div id="members-status" class="form-hint">Clique em atualizar para ver quem está no grupo.</div>
            <div id="members-list" class="members-list"></div>
        </div>
    </div>

    <div class="card mt-2">
            <div class="card-header"><h3><i class="fas fa-paper-plane"></i> Enviar mensagem ao grupo</h3></div>
            <div class="card-body">
                <form action="<?= url('whatsapp/groups/') ?><?= (int) $group['id'] ?>/send" method="POST" class="send-form" id="group-send-form">
                    <?= csrf_field() ?>
                    <textarea name="message" id="group-message" class="form-control" rows="2" maxlength="4000" placeholder="Digite a mensagem enviada pelo número conectado... (marque participantes acima para @mencionar)" required></textarea>
                    <div id="mentions-hidden"></div>
                    <button type="submit" class="btn btn-primary btn-sm mt-1"><i class="fas fa-paper-plane"></i> Enviar</button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
.group-show-page .group-meta .card-body { display: flex; flex-direction: column; gap: 8px; }
.group-show-page .inbox-form { display: flex; align-items: center; gap: 8px; }
.group-show-page .inbox-form .form-control { max-width: 320px; }
.group-show-page .form-hint { font-size: 12px; color: #6c757d; }
.group-show-page .empty-state { text-align: center; padding: 32px 20px; color: #6c757d; }
.group-show-page .send-form { display: flex; flex-direction: column; gap: 8px; }
.group-show-page .members-list { display: flex; flex-direction: column; gap: 4px; max-height: 260px; overflow-y: auto; margin-top: 8px; }
.group-show-page .member-row { display: flex; align-items: center; gap: 8px; padding: 4px 6px; border-radius: 6px; }
.group-show-page .member-row:hover { background: #f1f3f5; }
.group-show-page .member-row .badge-admin { font-size: 10px; background: #e7f1ff; color: #0b5ed7; border-radius: 4px; padding: 1px 6px; }
.group-show-page .mt-2 { margin-top: 16px; }
.group-show-page .mt-1 { margin-top: 8px; }
.alert { padding: 10px 14px; border-radius: 8px; margin-bottom: 12px; }
.alert-success { background: #e6f4ea; color: #1b5e20; }
.alert-danger { background: #fdecea; color: #8e1414; }
</style>

<script>
(function () {
    var membersUrl = "<?= url('whatsapp/groups/') ?><?= (int) $group['id'] ?>/members";
    var listEl = document.getElementById('members-list');
    var statusEl = document.getElementById('members-status');
    var reloadBtn = document.getElementById('members-reload');
    var form = document.getElementById('group-send-form');
    var ta = document.getElementById('group-message');
    var hiddenBox = document.getElementById('mentions-hidden');
    if (!listEl || !form) return;
    var selected = {}; // phone -> true; 'all' -> true

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function syncHidden() {
        hiddenBox.innerHTML = '';
        Object.keys(selected).forEach(function (k) {
            if (!selected[k]) return;
            var i = document.createElement('input');
            i.type = 'hidden';
            i.name = 'mentions[]';
            i.value = k;
            hiddenBox.appendChild(i);
        });
    }
    function toggleMention(phone, label, on) {
        if (on) {
            selected[phone] = true;
            if (ta && phone !== 'all' && ta.value.indexOf('@' + phone) === -1) {
                ta.value = (ta.value ? ta.value.replace(/\s+$/, '') + ' ' : '') + '@' + phone;
            }
            if (ta && phone === 'all' && !/@todos/i.test(ta.value)) {
                ta.value = (ta.value ? ta.value.replace(/\s+$/, '') + ' ' : '') + '@todos';
            }
        } else {
            delete selected[phone];
            if (ta) {
                var needle = phone === 'all' ? '@todos' : '@' + phone;
                var re = phone === 'all' ? /@todos/gi : new RegExp(needle.replace(/[^a-zA-Z0-9@]/g, '\\$&'), 'g');
                ta.value = ta.value.replace(re, '').replace(/\s{2,}/g, ' ').trim();
            }
        }
        syncHidden();
    }
    function render(members) {
        listEl.innerHTML = '';
        if (!members.length) {
            statusEl.textContent = 'Nenhum participante retornado pelo provedor.';
            return;
        }
        var all = document.createElement('label');
        all.className = 'member-row';
        all.innerHTML = '<input type="checkbox" data-mention="all"> <strong>@todos</strong> <span class="form-hint">menciona todo o grupo</span>';
        listEl.appendChild(all);
        var limit = 200;
        members.slice(0, limit).forEach(function (m) {
            var key = m.phone || (m.lid ? m.lid + '@lid' : '');
            if (!key) return;
            var row = document.createElement('label');
            row.className = 'member-row';
            var label = esc(m.name || m.phone || ('LID ' + (m.lid || '?')));
            row.innerHTML = '<input type="checkbox" data-mention="' + esc(key) + '"> <span><strong>' + label + '</strong>'
                + ' <small class="form-hint">' + esc(m.phone || ('LID ' + (m.lid || ''))) + '</small></span>'
                + (m.is_admin ? ' <span class="badge-admin">admin</span>' : '');
            listEl.appendChild(row);
        });
        if (members.length > limit) {
            var more = document.createElement('div');
            more.className = 'form-hint';
            more.textContent = 'e mais ' + (members.length - limit) + ' participantes...';
            listEl.appendChild(more);
        }
        statusEl.textContent = members.length + ' participante(s) — marque para @mencionar no envio.';
        listEl.querySelectorAll('input[data-mention]').forEach(function (cb) {
            cb.addEventListener('change', function () {
                toggleMention(cb.getAttribute('data-mention'), '', cb.checked);
            });
        });
    }
    function load() {
        statusEl.textContent = 'Carregando participantes...';
        reloadBtn.disabled = true;
        fetch(membersUrl, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
            .then(function (res) {
                reloadBtn.disabled = false;
                if (res.status !== 200) {
                    statusEl.textContent = 'Lista indisponível no momento (' + esc(res.body.error || res.status) + '). O envio segue funcionando.';
                    return;
                }
                render(res.body.members || []);
            })
            .catch(function () {
                reloadBtn.disabled = false;
                statusEl.textContent = 'Lista indisponível no momento. O envio segue funcionando.';
            });
    }
    reloadBtn.addEventListener('click', load);
    // Anti duplo-submit: POST síncrono — desabilita o botão no envio.
    if (form) {
        form.addEventListener('submit', function () {
            var btn = form.querySelector('button[type=submit]');
            if (btn) btn.disabled = true;
        });
    }
})();
</script>
