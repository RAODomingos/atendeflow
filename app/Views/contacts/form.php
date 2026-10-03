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
                .guild-opt{display:flex;align-items:center;gap:8px;font-size:13px;padding:3px 0;cursor:pointer}
                .guild-opt input{accent-color:var(--brand)}
                .guild-err{font-size:12.5px;color:var(--danger);margin-top:8px}
                </style>
                <div id="guildResultF">
                    <?php
                    $guildGroupsF = [];
                    foreach (($contact['stores'] ?? []) as $gs) {
                        $guildGroupsF[$gs['network_name']]['network_name'] = $gs['network_name'];
                        $guildGroupsF[$gs['network_name']]['customer_id'] = $gs['customer_id'];
                        $guildGroupsF[$gs['network_name']]['stores'][] = $gs;
                    }
                    ?>
                    <?php foreach ($guildGroupsF as $g): ?>
                        <div class="guild-group" data-network="<?= e($g['network_name']) ?>" data-customer="<?= e($g['customer_id']) ?>">
                            <div class="guild-group-title"><?= e($g['network_name']) ?> <span class="text-muted">(<?= e($g['customer_id']) ?>)</span></div>
                            <?php foreach ($g['stores'] as $s): ?>
                                <label class="guild-opt"><input type="checkbox" data-sid="<?= (int) $s['store_id'] ?>" data-sname="<?= e($s['store_name']) ?>" checked> <?= e($s['store_name']) ?></label>
                            <?php endforeach; ?>
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
                function guildBuscar(sfx){
                    var codeEl = document.getElementById('guildCustomer' + sfx);
                    var box = document.getElementById('guildResult' + sfx);
                    var code = (codeEl.value || '').trim();
                    if (!code) return;
                    box.insertAdjacentHTML('beforeend', '<p class="text-muted" data-tmp>Buscando lojas...</p>');
                    var base = document.querySelector('meta[name="base-url"]')?.content || '';
                    fetch(base + '/api/guild/stores?customer_id=' + encodeURIComponent(code), {headers:{'X-Requested-With':'XMLHttpRequest'}})
                        .then(function(r){ return r.json(); })
                        .then(function(j){
                            box.querySelector('[data-tmp]')?.remove();
                            if (!j.success) { box.insertAdjacentHTML('beforeend', '<p class="guild-err">' + j.error + '</p>'); return; }
                            if (box.querySelector('.guild-group[data-network="' + j.network_name.replace(/"/g,'') + '"]')) return;
                            var h = '<div class="guild-group" data-network="' + j.network_name + '" data-customer="' + code + '">'
                                + '<div class="guild-group-title">' + j.network_name + ' <span class="text-muted">(' + code + ')</span></div>';
                            j.stores.forEach(function(st){
                                h += '<label class="guild-opt"><input type="checkbox" data-sid="' + st.id + '" data-sname="' + st.name + '" checked> ' + st.name + '</label>';
                            });
                            box.insertAdjacentHTML('beforeend', h + '</div>');
                        })
                        .catch(function(){ box.querySelector('[data-tmp]')?.remove(); box.insertAdjacentHTML('beforeend', '<p class="guild-err">Falha ao consultar o painel Guild. Tente novamente.</p>'); });
                }
                function guildCollect(sfx){
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
