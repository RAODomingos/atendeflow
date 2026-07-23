<div class="channels-page">
    <div class="page-toolbar">
        <h2 class="page-title"><i class="fas fa-network-wired"></i> Canais de Atendimento</h2>
        <button class="btn btn-primary btn-sm" onclick="openDrawer('webchat')">
            <i class="fas fa-plus"></i> Novo Canal
        </button>
    </div>

    <!-- ChatWeb -->
    <div class="card">
        <div class="card-header d-flex between">
            <h3><i class="fas fa-comment-dots"></i> ChatWeb</h3>
            <button class="btn btn-sm btn-outline" onclick="openDrawer('webchat')"><i class="fas fa-plus"></i> Adicionar</button>
        </div>
        <div class="card-body p-0">
            <?php if (empty($webchatWidgets)): ?>
                <div class="empty-state"><p>Nenhum widget de ChatWeb.</p></div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr><th>Nome</th><th>Widget Key</th><th>Setor</th><th>Título</th><th>Ativo</th><th>Ações</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($webchatWidgets as $w): ?>
                            <tr>
                                <td><?= e($w['channel_name']) ?></td>
                                <td>
                                    <code class="copy-target" data-copy="<?= e($w['widget_key']) ?>"><?= e(truncate($w['widget_key'], 20)) ?>…</code>
                                    <button class="btn btn-sm btn-outline" onclick="copyText('<?= e($w['widget_key']) ?>', this)" title="Copiar chave"><i class="fas fa-copy"></i></button>
                                </td>
                                <td><?= e($w['department_name'] ?? '-') ?></td>
                                <td><?= e($w['title']) ?></td>
                                <td><span class="badge <?= $w['is_active'] ? 'badge-success' : 'badge-secondary' ?>"><?= $w['is_active'] ? 'Sim' : 'Não' ?></span></td>
                                <td class="action-cell">
                                    <button class="btn btn-sm btn-outline" onclick="editChannel(<?= $w['channel_id'] ?>)" title="Editar"><i class="fas fa-edit"></i></button>
                                    <a href="<?= url('widget/demo/') ?><?= e($w['widget_key']) ?>" class="btn btn-sm btn-outline" target="_blank" title="Testar widget"><i class="fas fa-eye"></i></a>
                                    <button class="btn btn-sm btn-outline" onclick="showWidgetCode('<?= e($w['widget_key']) ?>')" title="Código do widget"><i class="fas fa-code"></i></button>
                                    <form action="<?= url('channels/widget/') ?><?= $w['id'] ?>/regenerate-key" method="POST" style="display:inline">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-outline" title="Nova chave"><i class="fas fa-key"></i></button>
                                    </form>
                                    <form action="<?= url('channels/') ?><?= $w['channel_id'] ?>/delete" method="POST" style="display:inline" onsubmit="return confirm('Excluir este canal?')">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-danger" title="Excluir"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- WhatsApp -->
    <div class="card mt-2">
        <div class="card-header d-flex between">
            <h3><i class="fab fa-whatsapp"></i> WhatsApp</h3>
            <button class="btn btn-sm btn-outline" onclick="openDrawer('whatsapp')"><i class="fas fa-plus"></i> Adicionar</button>
        </div>
        <div class="card-body p-0">
            <?php if (empty($whatsappConnections)): ?>
                <div class="empty-state"><p>Nenhuma conexão WhatsApp.</p></div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr><th>Canal</th><th>Provedor</th><th>Número</th><th>Status</th><th>Setor</th><th>Fluxo</th><th>Última conexão</th><th>Ações</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($whatsappConnections as $wc): ?>
                            <tr>
                                <td><?= e($wc['channel_name']) ?></td>
                                <td><span class="badge badge-info"><?= e(ucfirst($wc['provider'] ?? 'waha')) ?></span></td>
                                <td><?= e($wc['phone_number'] ?? '-') ?></td>
                                <td>
                                    <span class="badge <?= $wc['status'] === 'connected' ? 'badge-success' : ($wc['status'] === 'waiting_qr' ? 'badge-warning' : 'badge-secondary') ?>">
                                        <?= e($wc['status'] === 'waiting_qr' ? 'Aguardando QR Code' : ($wc['status'] === 'connected' ? 'Conectado' : 'Desconectado')) ?>
                                    </span>
                                </td>
                                <td><?= e($wc['department_name'] ?? '-') ?></td>
                                <td><?= e($wc['flow_name'] ?? '-') ?></td>
                                <td><?= $wc['last_connected_at'] ? format_datetime($wc['last_connected_at']) : '-' ?></td>
                                <td class="action-cell">
                                    <button class="btn btn-sm btn-outline" onclick="openQrModal(<?= (int) $wc['channel_id'] ?>, '<?= e($wc['channel_name']) ?>')" title="Conectar / QR Code">
                                        <i class="fas fa-qrcode"></i> Conectar
                                    </button>
                                    <button class="btn btn-sm btn-outline" onclick="editWhatsappChannel(<?= (int) $wc['channel_id'] ?>)" title="Editar canal">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <?php if ($wc['status'] === 'connected'): ?>
                                        <form action="<?= url('whatsapp/') ?><?= (int) $wc['channel_id'] ?>/disconnect" method="POST" style="display:inline" onsubmit="return confirm('Desconectar este número?');">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-outline" title="Desconectar"><i class="fas fa-power-off"></i></button>
                                        </form>
                                    <?php endif; ?>
                                    <form action="<?= url('channels/') ?><?= (int) $wc['channel_id'] ?>/delete" method="POST" style="display:inline" onsubmit="return confirm('Excluir este canal?')">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-danger" title="Excluir"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- Drawer: Criar / Editar Canal -->
<div class="af-backdrop" id="drawerBackdrop" onclick="closeDrawer()"></div>
<aside class="af-drawer" id="channelDrawer" aria-hidden="true">
    <header class="af-drawer-head">
        <h3 id="drawerTitle">Novo Canal</h3>
        <button class="af-drawer-close" onclick="closeDrawer()" aria-label="Fechar">&times;</button>
    </header>

    <div class="af-drawer-body">
        <div class="af-type-tabs" id="typeTabs">
            <button type="button" class="af-type-tab active" data-type="webchat" onclick="setType('webchat')">ChatWeb</button>
            <button type="button" class="af-type-tab" data-type="whatsapp" onclick="setType('whatsapp')">WhatsApp</button>
        </div>

        <form id="channelForm" method="POST" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="type" id="fType" value="webchat">
            <input type="hidden" name="channel_id" id="fChannelId" value="">

            <!-- ============ ChatWeb ============ -->
            <div id="form-webchat" class="af-subform">
                <div class="form-group">
                    <label>Nome do canal *</label>
                    <input type="text" name="name" id="wName" class="form-control" placeholder="Ex: Suporte Comercial" required>
                </div>
                <div class="form-group">
                    <label>Título exibido no widget</label>
                    <input type="text" name="widget_title" id="wTitle" class="form-control" placeholder="Ex: Atendimento">
                </div>
                <div class="form-group">
                    <label>Setor (departamento)</label>
                    <select name="department_id" id="wDept" class="form-control">
                        <option value="">Nenhum</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['id'] ?>"><?= e($dept['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Fluxo de atendimento</label>
                    <select name="flow_id" id="wFlow" class="form-control">
                        <option value="">Nenhum (atendimento livre)</option>
                        <?php foreach ($flows as $flow): ?>
                            <option value="<?= $flow['id'] ?>"><?= e($flow['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-hint">Opcional. Se definido, o fluxo automatizado inicia o atendimento.</small>
                </div>

                <div class="af-section-title">Informações solicitadas do cliente</div>
                <div class="af-client-card">
                    <?php foreach ([
                        'name' => ['label' => 'Nome', 'default' => true],
                        'email' => ['label' => 'E-mail', 'default' => true],
                        'phone' => ['label' => 'Telefone', 'default' => true],
                        'cnpj' => ['label' => 'CNPJ', 'default' => true],
                    ] as $key => $f): ?>
                        <div class="af-field-row">
                            <div class="af-field-info">
                                <span class="af-field-name"><?= $f['label'] ?></span>
                                <?php if ($key === 'name'): ?><span class="af-field-note">Recomendado</span><?php endif; ?>
                            </div>
                            <div class="af-field-toggles">
                                <label class="af-switch" title="Solicitar este dado">
                                    <input type="checkbox" name="ask_<?= $key ?>" id="ask_<?= $key ?>" value="1" <?= $f['default'] ? 'checked' : '' ?> onchange="toggleReq('<?= $key ?>', this)">
                                    <span class="af-slider"></span>
                                </label>
                                <label class="af-req-check">
                                    <input type="checkbox" name="require_<?= $key ?>" id="req_<?= $key ?>" <?= $f['default'] ? 'checked' : '' ?>>
                                    <span>Obrigatório</span>
                                </label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <label class="af-inline-check">
                    <input type="checkbox" name="is_active" id="wActive" value="1" checked>
                    <span>Widget ativo</span>
                </label>
            </div>

            <!-- ============ WhatsApp ============ -->
            <div id="form-whatsapp" class="af-subform" style="display:none">
                <div class="form-group">
                    <label>Nome do canal *</label>
                    <input type="text" name="name" id="waName" class="form-control" placeholder="Ex: WhatsApp Vendas" required>
                </div>
                <div class="form-group">
                    <label>Provedor</label>
                    <select name="provider" id="waProvider" class="form-control">
                        <?php foreach ($whatsappProviders as $p): ?>
                            <option value="<?= e($p) ?>" <?= $p === $defaultProvider ? 'selected' : '' ?>><?= e(ucfirst($p)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-hint">Pode ser trocado no futuro sem perder o histórico.</small>
                </div>
                <div class="form-group">
                    <label>Nome da instância (opcional)</label>
                    <input type="text" name="instance_name" id="waInstanceName" class="form-control" placeholder="Gerado automaticamente">
                </div>
                <div class="form-group">
                    <label>Setor (departamento)</label>
                    <select name="department_id" id="waDept" class="form-control">
                        <option value="">Nenhum</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['id'] ?>"><?= e($dept['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Fluxo de atendimento</label>
                    <select name="flow_id" id="waFlow" class="form-control">
                        <option value="">Nenhum (atendimento livre)</option>
                        <?php foreach ($flows as $flow): ?>
                            <option value="<?= $flow['id'] ?>"><?= e($flow['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-hint">Opcional. Se definido, o fluxo automatizado inicia o atendimento.</small>
                </div>
                <div class="form-hint">Após salvar, clique em <strong>Conectar / QR Code</strong> para parear o número.</div>
            </div>

            <!-- ============ E-mail ============ -->
        </form>
    </div>

    <footer class="af-drawer-foot">
        <button class="btn btn-outline" type="button" onclick="closeDrawer()">Cancelar</button>
        <button class="btn btn-primary" type="submit" form="channelForm" id="drawerSubmit">Salvar Canal</button>
    </footer>
</aside>

<!-- Modal: Código do Widget -->
<div class="modal" id="codeModal">
    <div class="modal-content" style="max-width:600px">
        <div class="modal-header">
            <h3><i class="fas fa-code"></i> Código do Widget</h3>
            <button class="modal-close" onclick="closeModal('codeModal')">&times;</button>
        </div>
        <div class="modal-body">
            <p>Copie e cole este código no <strong>&lt;/body&gt;</strong> do seu site:</p>
            <div class="code-block">
                <button class="btn btn-sm btn-outline copy-btn" onclick="copyCode(this)" title="Copiar código"><i class="fas fa-copy"></i> Copiar</button>
                <pre id="widgetCodeBlock" style="background:#1a1d23;color:#e8e8e8;padding:16px;border-radius:8px;font-size:13px;overflow-x:auto;margin-top:8px"></pre>
            </div>
            <div class="form-group mt-2">
                <label>Personalização</label>
                <div class="form-row">
                    <div class="form-group col-6">
                        <label>Cor primária</label>
                        <input type="color" id="codeColor" value="#4A90D9" class="form-control" style="height:40px;padding:4px" onchange="updateCodePreview()">
                    </div>
                    <div class="form-group col-6">
                        <label>Posição</label>
                        <select id="codePosition" class="form-control" onchange="updateCodePreview()">
                            <option value="right">Direita</option>
                            <option value="left">Esquerda</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Título</label>
                    <input type="text" id="codeTitle" value="Atendimento" class="form-control" oninput="updateCodePreview()">
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Categorias de E-mail -->
<!-- Modal: QR Code de conexão WhatsApp -->
<div class="modal" id="qrModal">
    <div class="modal-content" style="max-width:420px">
        <div class="modal-header">
            <h3><i class="fab fa-whatsapp"></i> Conectar WhatsApp</h3>
            <button class="modal-close" onclick="closeQrModal()">&times;</button>
        </div>
        <div class="modal-body text-center">
            <p id="qrChannelName" class="mb-2"></p>
            <div id="qrStatus" class="badge badge-warning mb-2">Iniciando…</div>
            <div id="qrImageWrap" class="qr-image-wrap">
                <img id="qrImage" src="" alt="QR Code" style="display:none;max-width:260px;border-radius:8px">
                <div id="qrLoading" class="qr-loading"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Gerando QR Code…</p></div>
            </div>
            <p class="form-hint" id="qrHint">Escaneie o QR Code com o WhatsApp do número desejado.</p>
        </div>
        <div class="modal-footer" style="text-align:right;padding:12px 16px">
            <button class="btn btn-outline" type="button" onclick="closeQrModal()">Fechar</button>
        </div>
    </div>
</div>

<script>
let currentWidgetKey = '';
const BASE_URL = '<?= rtrim(base_url(), '/') ?>';
const WIDGETS = <?= json_encode(
    array_combine(
        array_column($webchatWidgets, 'channel_id'),
        array_map(function ($w) {
            return [
                'name' => $w['channel_name'],
                'title' => $w['title'],
                'department_id' => $w['department_id'],
                'flow_id' => $w['flow_id'],
                'is_active' => $w['is_active'],
                'ask_name' => $w['ask_name'],
                'require_name' => $w['require_name'],
                'ask_email' => $w['ask_email'],
                'require_email' => $w['require_email'],
                'ask_phone' => $w['ask_phone'],
                'require_phone' => $w['require_phone'],
                'ask_cnpj' => $w['ask_cnpj'],
                'require_cnpj' => $w['require_cnpj'],
            ];
        }, $webchatWidgets)
    ),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
) ?>;

const WHATSAPP_CHANNELS = <?= json_encode(
    empty($whatsappConnections) ? (object) [] : array_combine(
        array_column($whatsappConnections, 'channel_id'),
        array_map(function ($wc) {
            return [
                'name' => $wc['channel_name'],
                'provider' => $wc['provider'] ?? 'waha',
                'instance_name' => $wc['instance_name'] ?? '',
                'department_id' => $wc['department_id'],
                'flow_id' => $wc['flow_id'],
            ];
        }, $whatsappConnections)
    ),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
) ?>;

let drawerMode = 'create';

function openDrawer(type) {
    drawerMode = 'create';
    document.getElementById('drawerTitle').textContent = 'Novo Canal';
    document.getElementById('drawerSubmit').textContent = 'Criar Canal';
    document.getElementById('channelForm').action = BASE_URL + '/channels/create';
    document.getElementById('fChannelId').value = '';
    document.getElementById('typeTabs').style.display = 'flex';
    resetForm();
    setType(type || 'webchat');
    showDrawer();
}

function editChannel(channelId) {
    const w = WIDGETS[channelId];
    if (!w) return;
    drawerMode = 'edit';
    document.getElementById('drawerTitle').textContent = 'Editar Canal ChatWeb';
    document.getElementById('drawerSubmit').textContent = 'Salvar Alterações';
    document.getElementById('channelForm').action = BASE_URL + '/channels/' + channelId + '/update';
    document.getElementById('fChannelId').value = channelId;
    document.getElementById('typeTabs').style.display = 'none';

    document.getElementById('wName').value = w.name || '';
    document.getElementById('wTitle').value = w.title || '';
    document.getElementById('wDept').value = w.department_id || '';
    document.getElementById('wFlow').value = w.flow_id || '';
    document.getElementById('wActive').checked = !!w.is_active;

    ['name','email','phone','cnpj'].forEach(k => {
        document.getElementById('ask_' + k).checked = !!w['ask_' + k];
        document.getElementById('req_' + k).checked = !!w['require_' + k];
        document.getElementById('req_' + k).disabled = !w['ask_' + k];
    });

    setType('webchat');
    showDrawer();
}

function editWhatsappChannel(channelId) {
    const wc = WHATSAPP_CHANNELS[channelId];
    if (!wc) return;
    drawerMode = 'edit';
    document.getElementById('drawerTitle').textContent = 'Editar Canal WhatsApp';
    document.getElementById('drawerSubmit').textContent = 'Salvar Alterações';
    document.getElementById('channelForm').action = BASE_URL + '/channels/' + channelId + '/update';
    document.getElementById('fChannelId').value = channelId;
    document.getElementById('typeTabs').style.display = 'none';

    document.getElementById('waName').value = wc.name || '';
    if (document.getElementById('waProvider')) document.getElementById('waProvider').value = wc.provider || 'waha';
    if (document.getElementById('waInstanceName')) document.getElementById('waInstanceName').value = wc.instance_name || '';
    if (document.getElementById('waDept')) document.getElementById('waDept').value = wc.department_id || '';
    if (document.getElementById('waFlow')) document.getElementById('waFlow').value = wc.flow_id || '';

    setType('whatsapp');
    showDrawer();
}

function resetForm() {
    const f = document.getElementById('channelForm');
    f.reset();
    ['name','email','phone','cnpj'].forEach(k => {
        const def = (k === 'name');
        document.getElementById('ask_' + k).checked = def;
        document.getElementById('req_' + k).checked = def;
        document.getElementById('req_' + k).disabled = !def;
    });
    document.getElementById('wActive').checked = true;
}

function setType(type) {
    document.getElementById('fType').value = type;
    document.querySelectorAll('.af-type-tab').forEach(t => t.classList.toggle('active', t.dataset.type === type));
    ['webchat','whatsapp'].forEach(t => {
        const sub = document.getElementById('form-' + t);
        const active = (t === type);
        sub.style.display = active ? 'block' : 'none';
        sub.querySelectorAll('input, select, textarea').forEach(el => { el.disabled = !active; });
    });
}

function toggleReq(key, chk) {
    const req = document.getElementById('req_' + key);
    req.disabled = !chk.checked;
    if (!chk.checked) req.checked = false;
}

function showDrawer() {
    document.getElementById('drawerBackdrop').classList.add('show');
    document.getElementById('channelDrawer').classList.add('open');
    document.getElementById('channelDrawer').setAttribute('aria-hidden', 'false');
}
function closeDrawer() {
    document.getElementById('drawerBackdrop').classList.remove('show');
    document.getElementById('channelDrawer').classList.remove('open');
    document.getElementById('channelDrawer').setAttribute('aria-hidden', 'true');
}

function openModal(id) { document.getElementById(id).style.display = 'flex'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }

function showWidgetCode(widgetKey) {
    currentWidgetKey = widgetKey;
    updateCodePreview();
    openModal('codeModal');
}

function updateCodePreview() {
    const key = currentWidgetKey;
    const color = document.getElementById('codeColor').value;
    const pos = document.getElementById('codePosition').value;
    const title = document.getElementById('codeTitle').value || 'Atendimento';
    const baseUrl = '<?= base_url('widget/chat.js') ?>';

    const code = `<script>
 window.ATENDIMENTO_CONFIG = {
     widgetId: "${key}",
     title: "${title}",
     color: "${color}",
     position: "${pos}"
 };
 <\/script>
 <script async src="${baseUrl}"><\/script>`;

    document.getElementById('widgetCodeBlock').textContent = code;
}

function copyCode(btn) {
    const code = document.getElementById('widgetCodeBlock').textContent;
    navigator.clipboard.writeText(code).then(() => {
        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i> Copiado!';
        setTimeout(() => btn.innerHTML = orig, 2000);
    });
}

function copyText(text, btn) {
    navigator.clipboard.writeText(text).then(() => {
        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i>';
        setTimeout(() => btn.innerHTML = orig, 2000);
    });
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDrawer(); });
document.querySelectorAll('.modal').forEach(m => {
    m.addEventListener('click', function(e) { if (e.target === this) this.style.display = 'none'; });
});

// Estado inicial: garante que apenas o sub-form ativo envie dados
setType('webchat');
function escHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// ------------------------------------------------------------
// WhatsApp — QR Code / pareamento
// ------------------------------------------------------------
let qrPollTimer = null;
let qrChannelId = null;
let currentQr = null;

function openQrModal(channelId, channelName) {
    qrChannelId = channelId;
    currentQr = null;
    document.getElementById('qrChannelName').textContent = channelName || '';
    document.getElementById('qrStatus').className = 'badge badge-warning mb-2';
    document.getElementById('qrStatus').textContent = 'Iniciando…';
    document.getElementById('qrImage').style.display = 'none';
    document.getElementById('qrLoading').style.display = 'block';
    document.getElementById('qrHint').textContent = 'Escaneie o QR Code com o WhatsApp do número desejado.';
    openModal('qrModal');

    fetch(BASE_URL + '/whatsapp/' + channelId + '/connect', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: new URLSearchParams({
            _csrf_token: document.querySelector('input[name="_csrf_token"]')?.value || ''
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.error) {
            showQrError(data.error);
            return;
        }
        applyQrState(data);
        startQrPolling();
    })
    .catch(err => showQrError(err.message));
}

function startQrPolling() {
    stopQrPolling();
    qrPollTimer = setInterval(() => {
        fetch(BASE_URL + '/whatsapp/' + qrChannelId + '/status', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(data => {
                if (data.error) { showQrError(data.error); return; }
                applyQrState(data);
                if (data.status === 'connected') {
                    stopQrPolling();
                    document.getElementById('qrHint').textContent = 'Conectado! Você já pode receber e enviar mensagens.';
                    setTimeout(closeQrModal, 1500);
                }
            })
            .catch(() => {});
    }, 3000);
}

function stopQrPolling() {
    if (qrPollTimer) { clearInterval(qrPollTimer); qrPollTimer = null; }
}

function applyQrState(data) {
    const statusEl = document.getElementById('qrStatus');
    const statusLabels = {
        'connected': 'Conectado',
        'waiting_qr': 'Aguardando QR Code',
        'disconnected': 'Desconectado',
        'error': 'Erro'
    };
    statusEl.textContent = statusLabels[data.status] || data.status;
    statusEl.className = 'badge mb-2 ' + (
        data.status === 'connected' ? 'badge-success'
        : (data.status === 'waiting_qr' ? 'badge-warning' : 'badge-secondary')
    );

    const img = document.getElementById('qrImage');
    const loading = document.getElementById('qrLoading');

    if (data.status === 'connected') {
        img.style.display = 'none';
        loading.style.display = 'none';
        currentQr = null;
        return;
    }

    // Mantém o QR já exibido caso o status não retorne um novo (evita "piscar"/gerar de novo).
    if (data.qr_code) {
        if (data.qr_code !== currentQr) {
            currentQr = data.qr_code;
            img.src = currentQr;
        }
        img.style.display = 'inline-block';
        loading.style.display = 'none';
    } else if (currentQr) {
        img.src = currentQr;
        img.style.display = 'inline-block';
        loading.style.display = 'none';
    } else {
        img.style.display = 'none';
        loading.style.display = 'block';
    }
}

function showQrError(msg) {
    stopQrPolling();
    const statusEl = document.getElementById('qrStatus');
    statusEl.textContent = 'Erro';
    statusEl.className = 'badge badge-danger mb-2';
    document.getElementById('qrLoading').style.display = 'none';
    document.getElementById('qrImage').style.display = 'none';
    document.getElementById('qrHint').textContent = 'Falha: ' + msg;
}

function closeQrModal() {
    stopQrPolling();
    closeModal('qrModal');
    location.reload();
}
</script>

<style>
.page-toolbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; }
.page-title { font-size: 20px; font-weight: 600; display: flex; align-items: center; gap: 10px; }
.card-header.d-flex { display: flex; align-items: center; justify-content: space-between; }
.card-header.d-flex h3 { margin: 0; }
.mt-2 { margin-top: 16px; }
.form-hint { font-size: 12px; color: #6c757d; margin-top: 4px; display: block; }
.action-cell { white-space: nowrap; }
.action-cell .btn { margin: 0 2px; }
.code-block { position: relative; }
.code-block .copy-btn { position: absolute; top: 24px; right: 16px; z-index: 1; background: #fff; }
code.copy-target { background: #f0f2f5; padding: 2px 6px; border-radius: 4px; font-size: 12px; }
.text-center { text-align: center; }
.mb-2 { margin-bottom: 12px; }

/* ── Drawer ── */
.af-backdrop {
    position: fixed; inset: 0; background: rgba(15,23,42,.45);
    opacity: 0; visibility: hidden; transition: opacity .25s, visibility .25s; z-index: 1040;
}
.af-backdrop.show { opacity: 1; visibility: visible; }
.af-drawer {
    position: fixed; top: 0; right: 0; height: 100vh; width: 440px; max-width: 100vw;
    background: var(--bg-card, #fff); z-index: 1050; display: flex; flex-direction: column;
    box-shadow: -12px 0 40px rgba(15,23,42,.18);
    transform: translateX(100%); transition: transform .3s cubic-bezier(.4,0,.2,1);
}
.af-drawer.open { transform: translateX(0); }
.af-drawer-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 22px; border-bottom: 1px solid var(--border-strong, #eef0f4); flex-shrink: 0;
}
.af-drawer-head h3 { font-size: 17px; font-weight: 600; }
.af-drawer-close {
    background: none; border: none; font-size: 26px; line-height: 1; color: #6c757d;
    cursor: pointer; width: 34px; height: 34px; border-radius: 8px; transition: background .15s;
}
.af-drawer-close:hover { background: var(--bg-content, #f1f3f6); }
.af-drawer-body { flex: 1; overflow-y: auto; padding: 20px 22px; }
.af-drawer-foot {
    display: flex; gap: 10px; justify-content: flex-end; padding: 16px 22px;
    border-top: 1px solid var(--border-strong, #eef0f4); flex-shrink: 0;
}
.af-drawer-foot .btn { min-width: 120px; }

/* ── Type tabs ── */
.af-type-tabs { display: flex; gap: 6px; background: var(--bg-content, #f1f3f6); padding: 4px; border-radius: 10px; margin-bottom: 20px; }
.af-type-tab {
    flex: 1; border: none; background: transparent; padding: 9px; border-radius: 7px;
    font-size: 13px; font-weight: 600; color: #6c757d; cursor: pointer; transition: all .15s;
}
.af-type-tab.active { background: var(--bg-card, #fff); color: var(--primary-blue, #2f6fed); box-shadow: 0 1px 3px rgba(0,0,0,.08); }

.af-subform { display: flex; flex-direction: column; gap: 16px; }
.af-section-title { font-size: 13px; font-weight: 700; color: var(--text-main, #1a2332); margin-top: 4px; text-transform: uppercase; letter-spacing: .4px; }

/* ── Client info card ── */
.af-client-card { border: 1px solid var(--border-color, #e7eaf1); border-radius: 12px; overflow: hidden; }
.af-field-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 12px 14px; border-bottom: 1px solid var(--border-color, #eef0f4);
}
.af-field-row:last-child { border-bottom: none; }
.af-field-info { display: flex; align-items: center; gap: 8px; }
.af-field-name { font-size: 14px; font-weight: 600; color: var(--text-main, #1a2332); }
.af-field-note { font-size: 10px; font-weight: 600; color: #2f6fed; background: #eaf1fe; padding: 2px 7px; border-radius: 10px; }
.af-field-toggles { display: flex; align-items: center; gap: 14px; }

/* switch */
.af-switch { position: relative; display: inline-block; width: 42px; height: 24px; cursor: pointer; }
.af-switch input { opacity: 0; width: 0; height: 0; }
.af-slider {
    position: absolute; inset: 0; background: #cfd4dd; border-radius: 24px; transition: .2s;
}
.af-slider::before {
    content: ""; position: absolute; height: 18px; width: 18px; left: 3px; top: 3px;
    background: #fff; border-radius: 50%; transition: .2s; box-shadow: 0 1px 3px rgba(0,0,0,.2);
}
.af-switch input:checked + .af-slider { background: var(--primary-blue, #2f6fed); }
.af-switch input:checked + .af-slider::before { transform: translateX(18px); }

.af-req-check { display: flex; align-items: center; gap: 6px; font-size: 12.5px; color: var(--text-muted, #495057); cursor: pointer; user-select: none; }
.af-req-check input { width: 15px; height: 15px; accent-color: var(--primary-blue, #2f6fed); }
.af-req-check input:disabled { opacity: .4; cursor: not-allowed; }

.af-inline-check { display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 500; cursor: pointer; margin-top: 4px; }
.af-inline-check input { width: 16px; height: 16px; accent-color: var(--primary-blue, #2f6fed); }

/* ── QR modal ── */
.qr-image-wrap { min-height: 200px; display: flex; align-items: center; justify-content: center; padding: 12px; }
.qr-loading { color: #6c757d; }
.qr-loading p { margin-top: 10px; font-size: 13px; }

/* ── Dark mode overrides ── */
body.dark-mode .af-drawer { background: var(--bg-card); }
body.dark-mode .af-drawer-head,
body.dark-mode .af-drawer-foot { border-color: var(--border-strong); }
body.dark-mode .af-type-tabs { background: #1e2029; }
body.dark-mode .af-type-tab.active { background: var(--bg-card); }
body.dark-mode .af-client-card { border-color: var(--border-color); }
body.dark-mode .af-field-row { border-color: var(--border-color); }
body.dark-mode .af-field-name { color: var(--text-main); }
body.dark-mode .af-slider { background: #40404a; }
body.dark-mode code.copy-target { background: #1e2029; }
body.dark-mode .code-block .copy-btn { background: var(--bg-card); border-color: var(--border-strong); }
body.dark-mode .af-req-check { color: var(--text-muted); }
body.dark-mode .form-hint { color: var(--text-muted); }
</style>
