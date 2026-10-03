<div class="page-form">
    <div class="page-actions">
        <a href="<?= url('contacts') ?>" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
    </div>
    <div class="card">
        <div class="card-body">
            <form action="<?= $contact ? url("contacts/{$contact['id']}/update") : url('contacts/create') ?>" method="POST">
                <?= csrf_field() ?>
                <div class="form-row">
                    <div class="form-group col-8">
                        <label><i class="fas fa-user"></i> Nome *</label>
                        <input type="text" name="name" class="form-control" required
                               value="<?= e($contact['name'] ?? '') ?>"
                               placeholder="Nome completo">
                    </div>
                    <div class="form-group col-4">
                        <label><i class="fas fa-store"></i> Loja (Guild)</label>
                        <div style="display:flex;gap:8px">
                            <input type="text" id="guildCustomerF" class="form-control" placeholder="Código da loja">
                            <button type="button" class="btn btn-outline btn-sm" onclick="guildBuscar('F')">Buscar</button>
                        </div>
                    </div>
                </div>
                <style>
                .guild-group{border:1px solid var(--border-soft);border-radius:8px;padding:10px 12px;margin-top:8px;background:var(--bg-panel-alt)}
                .guild-group-title{font-size:12px;font-weight:700;margin-bottom:6px}
                .guild-units{font-size:12px;color:var(--text-secondary);margin:0 0 6px}
                .guild-opt{display:flex;align-items:center;gap:8px;font-size:13px;padding:3px 0;cursor:pointer}
                .guild-opt input{accent-color:var(--brand)}
                .guild-err{font-size:12.5px;color:var(--danger);margin-top:8px}
                </style>
                <div id="guildResultF">
                    <?php foreach (($contact['stores'] ?? []) as $gs): ?>
                        <div class="guild-group" data-network="<?= e($gs['network_name']) ?>" data-customer="<?= e($gs['customer_id']) ?>">
                            <div class="guild-group-title"><?= e($gs['network_name']) ?> <span class="text-muted">(<?= e($gs['customer_id']) ?>)</span></div>
                            <label class="guild-opt"><input type="checkbox" checked> Vincular esta loja</label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="guild_stores_json" id="guildJsonF" value="">
                <input type="hidden" name="guild_networks_json" id="guildNetF" value="">
                <script>
                (function(){
                    var form = document.querySelector('.page-form form');
                    if (form) form.addEventListener('submit', function(){ guildCollect('F'); });
                })();
                function guildErr(box, msg){
                    var p = document.createElement('p');
                    p.className = 'guild-err';
                    p.textContent = msg;
                    box.appendChild(p);
                }
                function guildBuscar(sfx){
                    var codeEl = document.getElementById('guildCustomer' + sfx);
                    var box = document.getElementById('guildResult' + sfx);
                    var code = (codeEl.value || '').trim();
                    if (!code || !box) return;
                    var tmp = document.createElement('p');
                    tmp.className = 'text-muted'; tmp.dataset.tmp = '';
                    tmp.textContent = 'Buscando lojas...';
                    box.appendChild(tmp);
                    var base = document.querySelector('meta[name="base-url"]')?.content || '';
                    fetch(base + '/api/guild/stores?customer_id=' + encodeURIComponent(code), {headers:{'X-Requested-With':'XMLHttpRequest'}})
                        .then(function(r){ return r.json(); })
                        .then(function(j){
                            tmp.remove();
                            if (!j.success) { guildErr(box, j.error || 'Falha ao consultar o painel Guild.'); return; }
                            var dup = false;
                            box.querySelectorAll('.guild-group').forEach(function(g){ if (g.dataset.network === j.network_name) dup = true; });
                            if (dup) return;
                            guildRenderGroup(box, {customer_id: code, network_name: j.network_name, units: j.stores.map(function(st){ return st.name; })});
                        })
                        .catch(function(){ tmp.remove(); guildErr(box, 'Falha ao consultar o painel Guild. Tente novamente.'); });
                }
                function guildRenderGroup(box, g){
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
                function guildCollect(sfx){
                    var box = document.getElementById('guildResult' + sfx);
                    if (!box) return;
                    var stores = [], nets = [];
                    box.querySelectorAll('.guild-group').forEach(function(g){
                        nets.push(g.dataset.network);
                        if (g.querySelector('input[type="checkbox"]:checked')) {
                            stores.push({customer_id: g.dataset.customer, network_name: g.dataset.network});
                        }
                    });
                    document.getElementById('guildJson' + sfx).value = JSON.stringify(stores);
                    document.getElementById('guildNet' + sfx).value = JSON.stringify(nets);
                }
                </script>
                <div class="form-row">
                    <div class="form-group col-6">
                        <label><i class="fas fa-envelope"></i> E-mail</label>
                        <input type="email" name="email" class="form-control"
                               value="<?= e($contact['email'] ?? '') ?>"
                               placeholder="email@exemplo.com">
                    </div>
                    <div class="form-group col-6">
                        <label><i class="fas fa-phone"></i> Telefone</label>
                        <input type="text" name="phone" class="form-control"
                               value="<?= e($contact['phone'] ?? '') ?>"
                               placeholder="(11) 99999-9999">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-6">
                        <label><i class="fas fa-id-card"></i> CPF/CNPJ</label>
                        <input type="text" name="document" class="form-control"
                               value="<?= e($contact['document'] ?? '') ?>">
                    </div>
                    <div class="form-group col-6">
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
                </div>
                <div class="form-group">
                    <label><i class="fas fa-sticky-note"></i> Observações</label>
                    <textarea name="notes" rows="4" class="form-control"
                              placeholder="Informações adicionais sobre o contato..."><?= e($contact['notes'] ?? '') ?></textarea>
                </div>
                <div class="form-actions">
                    <a href="<?= url('contacts') ?>" class="btn btn-outline">Cancelar</a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Salvar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
