<?php $flashSuccess = \App\Core\Session::getFlash('success'); $flashError = \App\Core\Session::getFlash('error'); ?>
<?php
$groupMonitored = \App\Models\WhatsAppGroup::isMonitored($group);
$topicWindow = (int) ($group['topic_window_hours'] ?? 0);
$members = $members ?? [];
$membersSyncedAt = $membersSyncedAt ?? null;
?>
<div class="group-show-page">
    <div class="page-actions">
        <a href="<?= url('whatsapp/groups') ?>" class="btn btn-sm btn-outline" title="Voltar"><i class="fas fa-arrow-left"></i> Voltar</a>
        <?php if ($isManager): ?>
            <form action="<?= url('whatsapp/groups/') ?><?= (int) $group['id'] ?>/monitor" method="POST" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="monitored" value="<?= $groupMonitored ? '0' : '1' ?>">
                <button type="submit" class="btn btn-sm <?= $groupMonitored ? 'btn-outline' : 'btn-secondary' ?>"
                        title="<?= $groupMonitored ? 'Arquivar: para de espelhar na caixa e de avisar' : 'Monitorar: volta a espelhar e avisar' ?>">
                    <i class="fas fa-<?= $groupMonitored ? 'eye' : 'eye-slash' ?>"></i>
                    <?= $groupMonitored ? 'Monitorado' : 'Arquivado' ?>
                </button>
            </form>
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
            <div class="group-head">
                <span class="group-avatar-lg">
                    <?php if (!empty($group['avatar_url'])): ?>
                        <img src="<?= e($group['avatar_url']) ?>" alt="" loading="lazy" onerror="this.remove()">
                    <?php else: ?>
                        <?= e(mb_strtoupper(mb_substr($group['name'] ?: '?', 0, 1))) ?>
                    <?php endif; ?>
                </span>
                <div>
                    <div class="group-title"><?= e($group['name'] ?: '(sem nome)') ?></div>
                    <div class="form-hint">
                        JID <code><?= e($group['group_jid']) ?></code>
                        <?= !empty($group['participant_count']) ? ' · ' . (int) $group['participant_count'] . ' participantes' : '' ?>
                    </div>
                    <div class="form-hint">
                        Conexão: <?= e($connection['phone_number'] ?? '-') ?> (<?= e($connection['provider'] ?? '') ?>)
                    </div>
                </div>
            </div>

            <?php if (!empty($conversationId)): ?>
                <div>
                    <a href="<?= url('inbox') ?>?conv=<?= (int) $conversationId ?>" class="btn btn-sm btn-outline"><i class="fas fa-inbox"></i> Abrir conversa na caixa</a>
                </div>
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
                    <small class="form-hint">Quando alguém <strong>marcar o número da conexão ou @todos</strong>, o sino avisa os membros desta caixa. O restante do histórico aparece na caixa, sem alerta.</small>
                </form>

                <form action="<?= url('whatsapp/groups/') ?><?= (int) $group['id'] ?>/topic-window" method="POST" class="inbox-form">
                    <?= csrf_field() ?>
                    <label for="topicWindow"><strong>Novo tópico após:</strong></label>
                    <select name="topic_window_hours" id="topicWindow" class="form-control" onchange="this.form.submit()" title="Janela de silêncio que abre uma nova conversa para o grupo">
                        <option value="0" <?= $topicWindow === 0 ? 'selected' : '' ?>>Uma conversa só</option>
                        <?php foreach ([1, 2, 4, 8, 12, 24] as $h): ?>
                            <option value="<?= (int) $h ?>" <?= $topicWindow === $h ? 'selected' : '' ?>><?= (int) $h ?>h de silêncio</option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-hint">Com uma janela definida, cada assunto do grupo vira uma conversa nova na caixa — o histórico fica organizado em vez de virar uma conversa só.</small>
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
                    <thead><tr><th>Quando</th><th>Quem</th><th>Mensagem</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($mentions as $m): ?>
                        <tr>
                            <td style="white-space:nowrap"><?= format_datetime($m['created_at']) ?></td>
                            <td><strong><?= e($m['sender_name'] ?: $m['sender_phone'] ?: '?') ?></strong><br><small class="form-hint"><?= e($m['sender_phone'] ?? '') ?></small></td>
                            <td><?= e($m['content'] ?? '') ?></td>
                            <td class="action-cell">
                                <?php if (!empty($m['conversation_id'])): ?>
                                    <a class="btn btn-xs btn-outline"
                                       href="<?= url('inbox') ?>?conv=<?= (int) $m['conversation_id'] ?><?= !empty($m['message_id']) ? '&msg=' . (int) $m['message_id'] : '' ?>"
                                       title="Ver a mensagem na conversa"><i class="fas fa-arrow-right"></i> Ver</a>
                                <?php else: ?>
                                    <span class="form-hint" title="Menção anterior à ligação com a mensagem">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($isManager): ?>
    <div class="card mt-2" id="members-card">
        <div class="card-header">
            <h3><i class="fas fa-users"></i> Participantes <small class="form-hint" id="members-sync-at"><?= $membersSyncedAt ? 'cache de ' . format_datetime($membersSyncedAt) : 'sem cache' ?></small></h3>
            <button type="button" id="members-reload" class="btn btn-sm btn-outline"><i class="fas fa-sync"></i> Atualizar lista</button>
        </div>
        <div class="card-body">
            <div id="members-status" class="form-hint"><?= $membersSyncedAt ? 'Usando o cache; atualize para buscar no provedor.' : 'Clique em atualizar para ver quem está no grupo.' ?></div>
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
.group-show-page .group-meta .card-body{display:flex;flex-direction:column;gap:10px;}
.group-show-page .group-head{display:flex;align-items:center;gap:12px;}
.group-show-page .group-avatar-lg{
    width:56px;height:56px;border-radius:50%;flex-shrink:0;overflow:hidden;
    background:var(--brand-soft);color:var(--brand-text);
    display:flex;align-items:center;justify-content:center;
    font-size:20px;font-weight:700;
}
.group-show-page .group-avatar-lg img{width:100%;height:100%;object-fit:cover;}
.group-show-page .group-title{font-size:16px;font-weight:700;color:var(--text-primary);}
.group-show-page .inbox-form{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.group-show-page .inbox-form .form-control{max-width:320px;}
.group-show-page .form-hint{font-size:12px;color:var(--text-muted);}
.group-show-page .empty-state{text-align:center;padding:32px 20px;color:var(--text-secondary);}
.group-show-page .send-form{display:flex;flex-direction:column;gap:8px;}
.group-show-page .members-list{display:flex;flex-direction:column;gap:4px;max-height:260px;overflow-y:auto;margin-top:8px;}
.group-show-page .member-row{display:flex;align-items:center;gap:8px;padding:4px 6px;border-radius:6px;}
.group-show-page .member-row:hover{background:var(--bg-sidebar-hover);}
.group-show-page .member-row .badge-admin{
    font-size:10px;background:var(--brand-soft);color:var(--brand-text);
    border-radius:4px;padding:1px 6px;
}
.group-show-page .mt-2{margin-top:16px;}
.group-show-page .mt-1{margin-top:8px;}
.group-show-page .action-cell{white-space:nowrap;}
.group-show-page .card-body{overflow-x:auto;}
.group-show-page table{table-layout:fixed;width:100%;}
.group-show-page table th:nth-child(1),.group-show-page table td:nth-child(1){width:130px;}
.group-show-page table th:nth-child(2),.group-show-page table td:nth-child(2){width:210px;}
.group-show-page table th:nth-child(4),.group-show-page table td:nth-child(4){width:74px;}
.group-show-page table td{word-break:break-word;}
.group-show-page .btn-xs{font-size:12px;padding:2px 8px;}
.group-show-page .alert{padding:10px 14px;border-radius:8px;margin-bottom:12px;}
.group-show-page .alert-success{background:var(--success-soft);color:var(--success-text);}
.group-show-page .alert-danger{background:var(--danger-soft);color:var(--danger-text);}
</style>

<script>
(function () {
    var membersUrl = "<?= url('whatsapp/groups/') ?><?= (int) $group['id'] ?>/members";
    var refreshUrl = "<?= url('whatsapp/groups/') ?><?= (int) $group['id'] ?>/refresh-members";
    var listEl = document.getElementById('members-list');
    var statusEl = document.getElementById('members-status');
    var reloadBtn = document.getElementById('members-reload');
    var syncAtEl = document.getElementById('members-sync-at');
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
            var i = document.createElement('input');
            i.type = 'hidden';
            i.name = 'mentions[]';
            i.value = k;
            hiddenBox.appendChild(i);
        });
    }
    function toggleMention(phone, label, on, displayName) {
        if (on) {
            var shown = (displayName || '').replace(/\s+/g, ' ').trim().substring(0, 60).replace(/@/g, '');
            selected[phone] = shown;
            if (ta && phone !== 'all') {
                var insert = shown !== '' ? '@' + shown + ' @' + phone : '@' + phone;
                if (ta.value.indexOf('@' + phone) === -1) {
                    ta.value = (ta.value ? ta.value.replace(/\s+$/, '') + ' ' : '') + insert;
                }
            }
            if (ta && phone === 'all' && !/@todos/i.test(ta.value)) {
                ta.value = (ta.value ? ta.value.replace(/\s+$/, '') + ' ' : '') + '@todos';
            }
        } else {
            var wasShown = selected[phone] || '';
            delete selected[phone];
            if (ta) {
                var t = ta.value;
                if (phone !== 'all') {
                    if (wasShown !== '') {
                        t = t.split('@' + wasShown + ' ').join('').split('@' + wasShown).join('');
                    }
                    var needle = '@' + phone;
                    var re = new RegExp(needle.replace(/[^a-zA-Z0-9@]/g, '\\$&'), 'g');
                    t = t.replace(re, '');
                } else {
                    t = t.replace(/@todos/gi, '');
                }
                ta.value = t.replace(/\s{2,}/g, ' ').trim();
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
            row.innerHTML = '<input type="checkbox" data-mention="' + esc(key) + '" data-name="' + esc(m.name || '') + '"> <span><strong>' + label + '</strong>'
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
                toggleMention(cb.getAttribute('data-mention'), '', cb.checked, cb.getAttribute('data-name'));
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
    // Atualizar: consulta o provedor e guarda em cache (o autocomplete de
    // @menção da caixa usa esse cache em vez de chamar o provedor sempre).
    reloadBtn.addEventListener('click', function () {
        reloadBtn.disabled = true;
        statusEl.textContent = 'Atualizando pelo provedor...';
        fetch(refreshUrl, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData()
        })
            .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
            .then(function (res) {
                reloadBtn.disabled = false;
                if (res.status !== 200) {
                    statusEl.textContent = 'Lista indisponível no momento (' + esc(res.body.error || res.status) + '). O envio segue funcionando.';
                    return;
                }
                if (syncAtEl) syncAtEl.textContent = 'cache de ' + new Date().toLocaleString('pt-BR');
                render(res.body.members || []);
            })
            .catch(function () {
                reloadBtn.disabled = false;
                statusEl.textContent = 'Falha ao atualizar a lista. O envio segue funcionando.';
            });
    });
    // Anti duplo-submit: POST síncrono — desabilita o botão no envio.
    if (form) {
        form.addEventListener('submit', function () {
            var btn = form.querySelector('button[type=submit]');
            if (btn) btn.disabled = true;
        });
    }
})();
</script>
