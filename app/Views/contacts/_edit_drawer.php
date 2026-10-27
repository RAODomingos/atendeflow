<?php
/**
 * Drawer de edição de contato — parcial compartilhado.
 *
 * Renderiza exatamente o mesmo drawer (campos, Guild, etiquetas, rodapé) na
 * página do cliente (contacts/show.php) e na caixa de entrada
 * (inbox/panel/_drawer.php), para que a edição do cliente tenha a mesma
 * aparência e o mesmo comportamento nos dois lugares.
 *
 * Variáveis esperadas:
 *  - $contact              array do contato (id, name, email, phone, document, notes, stores, tags)
 *  - $tags                 lista de todas as etiquetas (select múltiplo)
 *  - $contactDrawerAjax    bool: true envia via fetch (caixa de entrada); false faz POST normal
 *  - $contactDrawerSuffix  sufixo dos ids do bloco Guild (evita colisão de ids na mesma tela)
 */
$contactDrawerSuffix = $contactDrawerSuffix ?? 'D';
$contactDrawerAjax   = $contactDrawerAjax ?? false;
$drawerTagOptions    = $tags ?? ($contact['tags'] ?? []);
$drawerStores        = $contact['stores'] ?? [];
$drawerContactId     = (int) ($contact['id'] ?? 0);
?>
<style>
.contact-drawer .guild-group{border:1px solid var(--border-soft);border-radius:8px;padding:10px 12px;margin-top:8px;background:var(--bg-panel-alt)}
.contact-drawer .guild-group-title{font-size:12px;font-weight:700;margin-bottom:6px}
.contact-drawer .guild-units{font-size:12px;color:var(--text-secondary);margin:0 0 6px}
.contact-drawer .guild-opt{display:flex;align-items:center;gap:8px;font-size:13px;padding:3px 0;cursor:pointer}
.contact-drawer .guild-opt input{accent-color:var(--brand)}
.contact-drawer .guild-err{font-size:12.5px;color:var(--danger);margin-top:8px}
</style>

<div class="contact-drawer-overlay" id="contactDrawerOverlay" onclick="closeContactDrawer()"></div>
<div class="contact-drawer" id="contactDrawer" role="dialog" aria-modal="true" aria-label="Editar Contato" aria-hidden="true" inert>
    <div class="contact-drawer-header">
        <h3><i class="fas fa-user-edit" style="color:var(--brand)"></i> Editar Contato</h3>
        <button class="contact-drawer-close" type="button" onclick="closeContactDrawer()" aria-label="Fechar">&times;</button>
    </div>
    <div class="contact-drawer-body">
        <form id="contactEditForm" action="<?= url("contacts/{$drawerContactId}/update") ?>" method="POST" data-ajax="<?= $contactDrawerAjax ? '1' : '0' ?>">
            <?= csrf_field() ?>
            <div class="form-group">
                <label><i class="fas fa-user"></i> Nome *</label>
                <input type="text" name="name" class="form-control" required
                       value="<?= e($contact['name'] ?? '') ?>"
                       placeholder="Nome completo">
            </div>
            <div class="form-group">
                <label><i class="fas fa-store"></i> Loja (Guild)</label>
                <div style="display:flex;gap:8px">
                    <input type="text" id="guildCustomer<?= $contactDrawerSuffix ?>" class="form-control" placeholder="Código da loja">
                    <button type="button" class="btn btn-outline btn-sm" onclick="guildBuscar('<?= $contactDrawerSuffix ?>')">Buscar</button>
                </div>
                <div id="guildResult<?= $contactDrawerSuffix ?>">
                    <?php foreach ($drawerStores as $gs): ?>
                        <div class="guild-group" data-network="<?= e($gs['network_name']) ?>" data-customer="<?= e($gs['customer_id']) ?>">
                            <div class="guild-group-title"><?= e($gs['network_name']) ?> <span class="text-muted">(<?= e($gs['customer_id']) ?>)</span></div>
                            <label class="guild-opt"><input type="checkbox" checked> Vincular esta loja</label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="guild_stores_json" id="guildJson<?= $contactDrawerSuffix ?>" value="">
                <input type="hidden" name="guild_networks_json" id="guildNet<?= $contactDrawerSuffix ?>" value="">
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
                    <?php foreach ($drawerTagOptions as $tag): ?>
                        <option value="<?= $tag['id'] ?>"
                            <?= in_array($tag['id'], array_column($contact['tags'] ?? [], 'id')) ? 'selected' : '' ?>>
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
        <button type="submit" class="btn btn-primary" id="contactEditBtn" form="contactEditForm">
            <i class="fas fa-save"></i> Salvar
        </button>
    </div>
</div>

<script>
(function () {
    var SUFFIX = '<?= $contactDrawerSuffix ?>';
    var AJAX   = <?= $contactDrawerAjax ? 'true' : 'false' ?>;

    /* ---------- Bloco Guild (mesma lógica da página do cliente) ---------- */
    function guildErr(box, msg) {
        var p = document.createElement('p');
        p.className = 'guild-err';
        p.textContent = msg;
        box.appendChild(p);
    }
    function guildRenderGroup(box, g) {
        var div = document.createElement('div');
        div.className = 'guild-group';
        div.dataset.network = g.network_name;
        div.dataset.customer = g.customer_id;
        var title = document.createElement('div');
        title.className = 'guild-group-title';
        title.textContent = g.network_name + ' (' + g.customer_id + ')';
        div.appendChild(title);
        if (g.units && g.units.length) {
            var u = document.createElement('div');
            u.className = 'guild-units';
            u.textContent = g.units.length + ' unidades: ' + g.units.join(', ');
            div.appendChild(u);
        }
        var lab = document.createElement('label');
        lab.className = 'guild-opt';
        var cb = document.createElement('input');
        cb.type = 'checkbox'; cb.checked = true;
        lab.appendChild(cb);
        lab.appendChild(document.createTextNode(' Vincular esta loja'));
        div.appendChild(lab);
        box.appendChild(div);
    }
    function guildBuscar(sfx) {
        var codeEl = document.getElementById('guildCustomer' + sfx);
        var box = document.getElementById('guildResult' + sfx);
        if (!codeEl || !box) return;
        var code = (codeEl.value || '').trim();
        if (!code) return;
        var tmp = document.createElement('p');
        tmp.className = 'text-muted'; tmp.dataset.tmp = '';
        tmp.textContent = 'Buscando lojas...';
        box.appendChild(tmp);
        var base = document.querySelector('meta[name="base-url"]')?.content || '';
        fetch(base + '/api/guild/stores?customer_id=' + encodeURIComponent(code), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                tmp.remove();
                if (!j.success) { guildErr(box, j.error || 'Falha ao consultar o painel Guild.'); return; }
                var dup = false;
                box.querySelectorAll('.guild-group').forEach(function (g) { if (g.dataset.network === j.network_name) dup = true; });
                if (dup) return;
                guildRenderGroup(box, { customer_id: code, network_name: j.network_name, units: j.stores.map(function (st) { return st.name; }) });
            })
            .catch(function () { tmp.remove(); guildErr(box, 'Falha ao consultar o painel Guild. Tente novamente.'); });
    }
    function guildCollect(sfx) {
        var box = document.getElementById('guildResult' + sfx);
        if (!box) return;
        var stores = [], nets = [];
        box.querySelectorAll('.guild-group').forEach(function (g) {
            nets.push(g.dataset.network);
            if (g.querySelector('input[type="checkbox"]:checked')) {
                stores.push({ customer_id: g.dataset.customer, network_name: g.dataset.network });
            }
        });
        var jsonEl = document.getElementById('guildJson' + sfx);
        var netEl = document.getElementById('guildNet' + sfx);
        if (jsonEl) jsonEl.value = JSON.stringify(stores);
        if (netEl) netEl.value = JSON.stringify(nets);
    }

    /* ---------- Abrir/fechar ---------- */
    function openContactDrawer() {
        var d = document.getElementById('contactDrawer');
        var o = document.getElementById('contactDrawerOverlay');
        if (!d || !o) return;
        d.classList.add('open');
        o.classList.add('open');
        d.removeAttribute('inert');
        d.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        var first = d.querySelector('input[name="name"]');
        if (first) setTimeout(function () { first.focus(); }, 120);
    }
    function closeContactDrawer() {
        var d = document.getElementById('contactDrawer');
        var o = document.getElementById('contactDrawerOverlay');
        if (!d || !o) return;
        d.classList.remove('open');
        o.classList.remove('open');
        d.setAttribute('inert', '');
        d.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }
    window.openContactDrawer = openContactDrawer;
    window.closeContactDrawer = closeContactDrawer;
    window.guildErr = guildErr;
    window.guildRenderGroup = guildRenderGroup;
    window.guildBuscar = guildBuscar;
    window.guildCollect = guildCollect;

    /* ---------- Escape fecha o drawer ---------- */
    if (window.__contactDrawerEsc) {
        document.removeEventListener('keydown', window.__contactDrawerEsc);
    }
    window.__contactDrawerEsc = function (e) {
        if (e.key !== 'Escape') return;
        var d = document.getElementById('contactDrawer');
        if (!d || !d.classList.contains('open')) return;
        closeContactDrawer();
        // Fecha só a camada de cima (não derruba a gaveta de cliente de baixo).
        e.stopImmediatePropagation();
    };
    document.addEventListener('keydown', window.__contactDrawerEsc);

    /* ---------- Envio ---------- */
    function drawerToast(msg) {
        if (typeof window.toast === 'function') { window.toast(msg); return; }
        var t = document.createElement('div');
        t.className = 'toast-msg';
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(function () { t.remove(); }, 2400);
    }
    var form = document.getElementById('contactEditForm');
    if (!form) return;
    form.addEventListener('submit', function (e) {
        guildCollect(SUFFIX);
        if (!AJAX) return; // página do cliente: POST normal
        e.preventDefault();
        var btn = document.getElementById('contactEditBtn');
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...'; }
        fetch(form.action, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(form)
        }).then(function (r) { return r.json(); }).then(function (resp) {
            if (resp && resp.success !== false) {
                closeContactDrawer();
                drawerToast('Contato atualizado com sucesso');
                setTimeout(function () { location.reload(); }, 1200);
            } else {
                drawerToast(resp.error || 'Erro ao atualizar contato');
            }
        }).catch(function () {
            drawerToast('Erro de conexão. Tente novamente.');
        }).finally(function () {
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Salvar'; }
        });
    });
})();
</script>
