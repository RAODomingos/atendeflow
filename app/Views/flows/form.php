<div class="fb-page">
    <div class="fb-toolbar">
        <a href="<?= url('flows') ?>" class="btn btn-sm btn-outline"><i class="fas fa-arrow-left"></i> Voltar</a>
        <input type="text" class="fb-title-input" id="fbTitleInput" value="<?= e($flow['name'] ?? '') ?>" placeholder="Nome do Fluxo">
        <div class="fb-toolbar-right">
            <?php if ($flow): ?>
                <span class="badge <?= $flow['is_active'] ? 'badge-success' : 'badge-secondary' ?>"><?= $flow['is_active'] ? 'Ativo' : 'Rascunho' ?></span>
            <?php endif; ?>
            <button class="btn btn-sm btn-outline" onclick="zoomIn()" title="Aumentar zoom"><i class="fas fa-search-plus"></i></button>
            <span class="fb-zoom-level" id="zoomLevel">100%</span>
            <button class="btn btn-sm btn-outline" onclick="zoomOut()" title="Diminuir zoom"><i class="fas fa-search-minus"></i></button>
            <button class="btn btn-sm btn-outline" onclick="zoomReset()" title="Redefinir zoom"><i class="fas fa-expand"></i></button>
            <button class="btn btn-primary btn-sm" onclick="saveFlow()"><i class="fas fa-save"></i> Salvar</button>
        </div>
    </div>

    <div class="fb-body">
        <div class="fb-palette" id="fbPalette">
            <div class="fb-palette-title">Tipos de Nó</div>
            <div class="fb-palette-list" id="paletteList"></div>
            <div class="fb-palette-info">Arraste para o canvas ou clique para adicionar</div>
        </div>

        <div class="fb-canvas-wrap">
            <div class="fb-canvas" id="fbCanvas">
                <svg class="fb-svg" id="fbSvg"></svg>
                <div class="fb-empty" id="fbEmpty">
                    <i class="fas fa-diagram-project fa-4x"></i>
                    <p>Arraste nós da paleta ao lado para começar</p>
                </div>
            </div>
            <div class="fb-minimap" id="fbMinimap">
                <div class="fb-minimap-title">Visão Geral</div>
                <div class="fb-minimap-canvas" id="fbMinimapCanvas"></div>
            </div>
        </div>
    </div>

    <form id="flowForm" method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="name" id="flowName" value="<?= e($flow['name'] ?? '') ?>">
        <input type="hidden" name="description" id="flowDesc" value="<?= e($flow['description'] ?? '') ?>">
        <input type="hidden" name="channel_scope" id="flowScope" value="<?= e($flow['channel_scope'] ?? 'all') ?>">
        <input type="hidden" name="is_active" id="flowActive" value="<?= $flow['is_active'] ?? 0 ?>">
        <input type="hidden" name="nodes" id="nodesInput">
    </form>
</div>

<div class="modal" id="fbSettingsModal" style="display:none">
    <div class="modal-content" style="max-width:450px">
        <div class="modal-header"><h3>Configurações do Fluxo</h3><button class="modal-close" onclick="closeSettings()">&times;</button></div>
        <div class="modal-body">
            <div class="form-group">
                <label>Descrição</label>
                <textarea class="form-control" id="cfgDesc" rows="3" placeholder="Descreva a finalidade deste fluxo"></textarea>
            </div>
            <div class="form-group">
                <label>Escopo do Canal</label>
                <select class="form-control" id="cfgScope">
                    <option value="all">Todos os canais</option>
                    <option value="webchat">ChatWeb</option>
                    <option value="whatsapp">WhatsApp</option>
                </select>
            </div>
            <div class="form-group">
                <label class="checkbox-label"><input type="checkbox" id="cfgActive" value="1"> Fluxo ativo</label>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeSettings()">Cancelar</button>
            <button class="btn btn-primary" onclick="saveSettings()">Salvar</button>
        </div>
    </div>
</div>

<div class="modal" id="fbNodeModal" style="display:none">
    <div class="modal-content" style="max-width:520px">
        <div class="modal-header"><h3 id="nodeModalTitle">Editar Nó</h3><button class="modal-close" onclick="closeNodeModal()">&times;</button></div>
        <div class="modal-body" id="nodeModalBody"></div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeNodeModal()">Cancelar</button>
            <button class="btn btn-primary" onclick="saveNodeModal()">Salvar</button>
        </div>
    </div>
</div>

<script>
const NODE_TYPES = {
    start:              { label: 'Início', icon: 'fa-play-circle', color: '#28A745', group: 'flow' },
    message:            { label: 'Mensagem', icon: 'fa-comment', color: '#007BFF', group: 'msg' },
    button_list:        { label: 'Botões', icon: 'fa-thumbs-up', color: '#0D6EFD', group: 'msg' },
    list_menu:          { label: 'Lista', icon: 'fa-bars', color: '#6610F2', group: 'msg' },
    image:              { label: 'Imagem', icon: 'fa-image', color: '#E83E8C', group: 'media' },
    audio:              { label: 'Áudio', icon: 'fa-music', color: '#DC3545', group: 'media' },
    video:              { label: 'Vídeo', icon: 'fa-video', color: '#FD7E14', group: 'media' },
    send_file:          { label: 'Arquivo', icon: 'fa-file', color: '#20C997', group: 'media' },
    menu:               { label: 'Menu Opções', icon: 'fa-list', color: '#17A2B8', group: 'interact' },
    question:           { label: 'Pergunta', icon: 'fa-question-circle', color: '#FFC107', group: 'interact' },
    collect_field:      { label: 'Coletar Dado', icon: 'fa-edit', color: '#6F42C1', group: 'interact' },
    condition:          { label: 'Condição', icon: 'fa-code-branch', color: '#FD7E14', group: 'logic' },
    day_of_week:        { label: 'Dia da Semana', icon: 'fa-calendar-day', color: '#0891B2', group: 'logic' },
    time_range:         { label: 'Horário', icon: 'fa-clock', color: '#7C3AED', group: 'logic' },
    delay:              { label: 'Aguardar', icon: 'fa-hourglass', color: '#6C757D', group: 'logic' },
    assign_department:  { label: 'Definir Depto', icon: 'fa-layer-group', color: '#E83E8C', group: 'action' },
    assign_user:        { label: 'Atribuir', icon: 'fa-user-check', color: '#20C997', group: 'action' },
    add_tag:            { label: 'Etiqueta', icon: 'fa-tag', color: '#6C757D', group: 'action' },
    handoff:            { label: 'Encaminhar', icon: 'fa-forward', color: '#DC3545', group: 'action' },
    notify:             { label: 'Notificar', icon: 'fa-bell', color: '#E83E8C', group: 'action' },
    end:                { label: 'Encerrar', icon: 'fa-stop-circle', color: '#343A40', group: 'flow' },
    finish:             { label: 'Finalizar', icon: 'fa-check-double', color: '#DC2626', group: 'flow' },
};

const GROUP_NAMES = {
    flow: 'Controle de Fluxo', msg: 'Mensagens', media: 'Mídia', interact: 'Interação', logic: 'Lógica', action: 'Ações'
};

const WHATSAPP_CHANNELS = <?= json_encode($whatsappChannels ?? [], JSON_UNESCAPED_UNICODE) ?>;

let nodes = [];
let connections = [];
let nodeIdCounter = 0;
let selectedNode = null;
let connectSource = null;
let dragNode = null;
let dragOffsetX = 0, dragOffsetY = 0;
let panX = 0, panY = 0;
let zoom = 1;
let isPanning = false, panStartX = 0, panStartY = 0, panStartPanX = 0, panStartPanY = 0;
let editNodeId = null;
let nextZIndex = 1;

<?php if ($flow && !empty($flow['nodes'])): ?>
<?php
$nodeMap = [];
foreach ($flow['nodes'] as $n) {
    $nid = $n['id'];
    $nodeMap[$nid] = $n;
}
$conns = [];
foreach ($flow['nodes'] as $n) {
    foreach ($n['options'] ?? [] as $optIdx => $opt) {
        if (!empty($opt['next_node_id']) && isset($nodeMap[$opt['next_node_id']])) {
            $label = $opt['label'];
            $sourceId = 'node_' . $n['id'];
            $targetId = 'node_' . $opt['next_node_id'];
            $conns[] = "{source: '$sourceId', target: '$targetId', label: " . json_encode($label) . ", sourceOption: $optIdx}";
        }
    }
}
?>
nodes = [<?php foreach ($flow['nodes'] as $n): ?>{
    id: 'node_<?= $n['id'] ?>',
    type: '<?= $n['node_type'] ?>',
    title: <?= json_encode($n['title']) ?>,
    content: <?= json_encode($n['content']) ?>,
    config: <?= json_encode($n['config'] ?? new stdClass()) ?>,
    options: <?= json_encode(array_map(function($o) {
        return ['label' => $o['label'], 'value' => $o['value'], 'next_node_id' => $o['next_node_id'] ? 'node_' . $o['next_node_id'] : null];
    }, $n['options'] ?? [])) ?>,
    x: <?= (int) ($n['position_x'] ?: 100 + count($n) * 30) ?>,
    y: <?= (int) ($n['position_y'] ?: 50 + count($n) * 120) ?>,
},<?php endforeach; ?>];
connections = [<?= implode(',', $conns) ?>];
<?php endif; ?>

/* -------- Init -------- */
function init() {
    buildPalette();
    if (nodes.length === 0) {
        const start = { id: genId(), type: 'start', title: 'Início', content: '', config: {}, options: [], x: 250, y: 30 };
        nodes.push(start);
        const msg = { id: genId(), type: 'message', title: 'Mensagem', content: 'Olá! Como posso ajudar?', config: {}, options: [], x: 250, y: 200 };
        nodes.push(msg);
        connections.push({ source: start.id, target: msg.id, label: '' });
    }
    // Update nodeIdCounter to not conflict with PHP node IDs
    nodes.forEach(n => {
        const num = parseInt(n.id.replace(/\D/g, ''), 10);
        if (num > nodeIdCounter) nodeIdCounter = num;
    });
    document.getElementById('zoomLevel').textContent = Math.round(zoom * 100) + '%';
    renderAll();
    setupCanvasDrag();
    setupPaletteDrag();
    setupKeyboard();
}

function genId() { return 'n' + (++nodeIdCounter); }

/* -------- Palette -------- */
function buildPalette() {
    const list = document.getElementById('paletteList');
    list.innerHTML = '';
    const groups = {};
    Object.entries(NODE_TYPES).forEach(([key, val]) => {
        if (!groups[val.group]) groups[val.group] = [];
        groups[val.group].push(key);
    });
    Object.entries(GROUP_NAMES).forEach(([g, gname]) => {
        if (!groups[g]) return;
        const sec = document.createElement('div');
        sec.className = 'fb-palette-group';
        sec.innerHTML = '<div class="fb-palette-group-title">' + gname + '</div>';
        groups[g].forEach(key => {
            const nt = NODE_TYPES[key];
            const btn = document.createElement('button');
            btn.className = 'fb-palette-item';
            btn.dataset.type = key;
            btn.draggable = true;
            btn.innerHTML = '<i class="fas ' + nt.icon + '" style="color:' + nt.color + '"></i> ' + nt.label;
            btn.addEventListener('dragstart', e => { e.dataTransfer.setData('text/plain', key); });
            btn.addEventListener('click', () => addNodeAtCenter(key));
            sec.appendChild(btn);
        });
        list.appendChild(sec);
    });
}

function setupPaletteDrag() {
    document.getElementById('fbCanvas').addEventListener('dragover', e => e.preventDefault());
    document.getElementById('fbCanvas').addEventListener('drop', e => {
        e.preventDefault();
        const type = e.dataTransfer.getData('text/plain');
        if (!type || !NODE_TYPES[type]) return;
        const r = document.getElementById('fbCanvas').getBoundingClientRect();
        addNode(type, (e.clientX - r.left - panX) / zoom - 75, (e.clientY - r.top - panY) / zoom - 30);
    });
}

/* -------- Canvas Drag / Pan / Zoom -------- */
function setupCanvasDrag() {
    const canvas = document.getElementById('fbCanvas');
    canvas.addEventListener('wheel', e => {
        e.preventDefault();
        const delta = e.deltaY > 0 ? -0.15 : 0.15;
        setZoom(zoom + delta);
    }, { passive: false });
    canvas.addEventListener('mousedown', e => {
        if (e.target === canvas || e.target.classList.contains('fb-svg') || e.target === document.getElementById('fbEmpty')) {
            isPanning = true;
            panStartX = e.clientX;
            panStartY = e.clientY;
            panStartPanX = panX;
            panStartPanY = panY;
            canvas.style.cursor = 'grabbing';
        }
    });
    document.addEventListener('mousemove', e => {
        if (isPanning) {
            panX = panStartPanX + (e.clientX - panStartX);
            panY = panStartPanY + (e.clientY - panStartY);
            panX = Math.min(2000, Math.max(-3000, panX));
            panY = Math.min(2000, Math.max(-3000, panY));
            updateTransform();
        }
        if (dragNode) {
            const r = document.getElementById('fbCanvas').getBoundingClientRect();
            const rawX = (e.clientX - r.left - panX) / zoom - dragOffsetX;
            const rawY = (e.clientY - r.top - panY) / zoom - dragOffsetY;
            dragNode.x = Math.max(0, rawX);
            dragNode.y = Math.max(0, rawY);
            const el = document.getElementById(dragNode.id);
            if (el) {
                el.style.left = dragNode.x + 'px';
                el.style.top = dragNode.y + 'px';
            }
            updateTransform();
        }
    });
    document.addEventListener('mouseup', () => {
        if (dragNode) renderAll();
        isPanning = false;
        dragNode = null;
        document.getElementById('fbCanvas').style.cursor = '';
    });
}

function zoomIn() { setZoom(zoom + 0.2); }
function zoomOut() { setZoom(zoom - 0.2); }
function zoomReset() { setZoom(1); }
function setZoom(newZoom) {
    newZoom = Math.max(0.2, Math.min(3, newZoom));
    const canvas = document.getElementById('fbCanvas');
    const wrap = canvas.parentElement;
    const wr = wrap.getBoundingClientRect();
    const cx = wr.width / 2, cy = wr.height / 2;
    panX = cx - (cx - panX) * newZoom / zoom;
    panY = cy - (cy - panY) * newZoom / zoom;
    zoom = newZoom;
    document.getElementById('zoomLevel').textContent = Math.round(zoom * 100) + '%';
    updateTransform();
}

/* -------- Render -------- */
function connKey(c) {
    return c.source + '|' + c.target + '|' + (c.sourceOption !== undefined ? c.sourceOption : '');
}

function renderAll() {
    const canvas = document.getElementById('fbCanvas');
    const svg = document.getElementById('fbSvg');
    const empty = document.getElementById('fbEmpty');

    empty.style.display = nodes.length === 0 ? 'flex' : 'none';

    canvas.querySelectorAll('.fb-node').forEach(el => el.remove());
    svg.innerHTML = '';

    // Render nodes first (need DOM for connection positions)
    nodes.forEach(node => {
        const nt = NODE_TYPES[node.type] || { label: node.type, icon: 'fa-circle', color: '#666' };
        const el = document.createElement('div');
        el.className = 'fb-node' + (selectedNode === node.id ? ' fb-node-selected' : '');
        el.id = node.id;
        el.style.left = node.x + 'px';
        el.style.top = node.y + 'px';
        el.style.borderTopColor = nt.color;
        el.style.zIndex = node.zIndex || 1;

        const hasOptionPorts = node.options && node.options.length > 0 && (['menu','button_list','list_menu','condition','day_of_week','time_range'].includes(node.type));
        let optsHtml = '';
        if (hasOptionPorts) {
            optsHtml = '<div class="fb-node-options">' + node.options.map((o, idx) =>
                '<span class="fb-opt-chip" data-optidx="' + idx + '">' + e(o.label || '') +
                '<span class="fb-opt-port" data-optidx="' + idx + '" title="Conectar esta opção"></span></span>'
            ).join('') + '</div>';
        } else if (node.options && node.options.length > 0) {
            optsHtml = '<div class="fb-node-options">' + node.options.map(o =>
                '<span class="fb-opt-chip">' + e(o.label || '') + '</span>'
            ).join('') + '</div>';
        }

        el.innerHTML = `
            <div class="fb-node-header" style="background:${nt.color}15;border-bottom:2px solid ${nt.color}">
                <i class="fas ${nt.icon}" style="color:${nt.color}"></i>
                <span class="fb-node-title">${e(node.title || nt.label)}</span>
                <div class="fb-node-actions">
                    <button class="fb-btn-icon" onclick="editNode('${node.id}')" title="Editar"><i class="fas fa-pen"></i></button>
                    <button class="fb-btn-icon fb-btn-del" onclick="deleteNode('${node.id}')" title="Remover"><i class="fas fa-times"></i></button>
                </div>
            </div>
            <div class="fb-node-body">
                ${node.content ? '<div class="fb-node-content">' + e(truncate(node.content, 80)) + '</div>' : ''}
                ${optsHtml}
                ${node.type === 'delay' && node.config?.seconds ? '<div class="fb-node-meta">⏱ ' + node.config.seconds + 's</div>' : ''}
                ${(node.type === 'image' || node.type === 'audio' || node.type === 'video' || node.type === 'send_file') && node.config?.file_url ? '<div class="fb-node-meta">📎 ' + e(truncate(node.config.file_url, 40)) + '</div>' : ''}
                ${node.type === 'button_list' && node.options ? '<div class="fb-node-meta">🔘 ' + node.options.length + ' botão(ns)</div>' : ''}
                ${node.type === 'notify' && node.config?.phones ? '<div class="fb-node-meta">📞 ' + node.config.phones.length + ' tel.' + (node.config.phones[0] ? ' (' + e(truncate(node.config.phones[0], 13)) + '...)' : '') + '</div>' : ''}
                ${node.type === 'notify' && !node.config?.phones && node.config?.phone_number ? '<div class="fb-node-meta">📞 ' + e(truncate(node.config.phone_number, 15)) + '</div>' : ''}
                ${node.type === 'day_of_week' && node.config?.days ? '<div class="fb-node-meta">📅 ' + node.config.days.map(d => ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'][d]).join(', ') + '</div>' : ''}
                ${node.type === 'time_range' && node.config?.start_time ? '<div class="fb-node-meta">⏰ ' + e(node.config.start_time) + ' - ' + e(node.config.end_time || '') + '</div>' : ''}
            </div>
            <div class="fb-node-port fb-port-in" title="Conectar entrada"></div>
            <div class="fb-node-port fb-port-out" title="Conectar saída"></div>
        `;

        el.querySelector('.fb-port-in').addEventListener('click', e => {
            e.stopPropagation();
            if (connectSource) {
                if (connectSource.nodeId !== node.id) {
                    const conn = { source: connectSource.nodeId, target: node.id, label: '' };
                    if (connectSource.optionIndex !== undefined) conn.sourceOption = connectSource.optionIndex;
                    connections.push(conn);
                }
                connectSource = null;
                document.getElementById('fbCanvas').removeEventListener('mousemove', followConnLine);
                renderAll();
            }
        });
        el.querySelector('.fb-port-out').addEventListener('click', e => {
            e.stopPropagation();
            connectSource = { nodeId: node.id };
            el.classList.add('fb-node-connecting');
            renderAll();
            document.getElementById('fbCanvas').addEventListener('mousemove', followConnLine);
        });
        el.querySelectorAll('.fb-opt-port').forEach(port => {
            port.addEventListener('click', e => {
                e.stopPropagation();
                const idx = parseInt(e.currentTarget.dataset.optidx);
                connectSource = { nodeId: node.id, optionIndex: idx };
                el.classList.add('fb-node-connecting');
                renderAll();
                document.getElementById('fbCanvas').addEventListener('mousemove', followConnLine);
            });
        });

        el.querySelector('.fb-node-header').addEventListener('mousedown', e => {
            if (e.target.closest('.fb-node-actions')) return;
            dragNode = node;
            node.zIndex = ++nextZIndex;
            const r = document.getElementById('fbCanvas').getBoundingClientRect();
            dragOffsetX = (e.clientX - r.left - panX) / zoom - node.x;
            dragOffsetY = (e.clientY - r.top - panY) / zoom - node.y;
        });

        el.addEventListener('click', () => {
            selectedNode = node.id;
            renderAll();
        });
        el.addEventListener('dblclick', () => editNode(node.id));

        canvas.appendChild(el);
    });

    // Render connections using actual DOM positions
    const canvasRect = canvas.getBoundingClientRect();
    connections.forEach(conn => {
        const srcNode = nodes.find(n => n.id === conn.source);
        const tgtNode = nodes.find(n => n.id === conn.target);
        if (!srcNode || !tgtNode) return;

        const srcEl = document.getElementById(conn.source);
        const tgtEl = document.getElementById(conn.target);

        let sx, sy;
        if (conn.sourceOption !== undefined && srcEl) {
            const optPort = srcEl.querySelector(`.fb-opt-port[data-optidx="${conn.sourceOption}"]`);
            if (optPort) {
                const pr = optPort.getBoundingClientRect();
                sx = (pr.left - canvasRect.left - panX + pr.width / 2) / zoom;
                sy = (pr.top - canvasRect.top - panY + pr.height / 2) / zoom;
            } else {
                sx = srcNode.x + 150;
                sy = srcNode.y + (srcEl.offsetHeight || 110) + 7;
            }
        } else {
            sx = srcNode.x + 150;
            sy = srcNode.y + (srcEl ? srcEl.offsetHeight : 110) + 7;
        }

        const tx = tgtNode.x + 150;
        const ty = tgtNode.y - 7;
        const dy = Math.abs(ty - sy);
        const cy1 = sy + Math.max(40, dy * 0.4);
        const cy2 = ty - Math.max(40, dy * 0.4);
        const d = 'M' + sx + ' ' + sy + ' C' + sx + ' ' + cy1 + ',' + tx + ' ' + cy2 + ',' + tx + ' ' + ty;

        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('d', d);
        path.setAttribute('class', 'fb-conn-path');
        path.dataset.connKey = connKey(conn);
        path.addEventListener('click', e => {
            e.stopPropagation();
            if (confirm('Remover esta conexão?')) {
                const key = e.currentTarget.dataset.connKey;
                connections = connections.filter(c => connKey(c) !== key);
                renderAll();
            }
        });
        svg.appendChild(path);

        const angle = Math.atan2(ty - cy2, tx - sx);
        const ax = tx - 12 * Math.cos(angle);
        const ay = ty - 12 * Math.sin(angle);
        const arrow = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
        arrow.setAttribute('points', '-6,-4 6,-4 0,6');
        arrow.setAttribute('fill', '#4A90D9');
        arrow.setAttribute('transform', 'translate(' + ax + ',' + ay + ') rotate(' + (angle * 180 / Math.PI) + ')');
        svg.appendChild(arrow);

        if (conn.label) {
            const mx = (sx + tx) / 2, my = (sy + cy1 + ty + cy2) / 4;
            const label = document.createElementNS('http://www.w3.org/2000/svg', 'text');
            label.setAttribute('x', mx);
            label.setAttribute('y', my - 6);
            label.setAttribute('text-anchor', 'middle');
            label.setAttribute('fill', '#666');
            label.setAttribute('font-size', '11');
            label.setAttribute('font-weight', 'bold');
            label.textContent = conn.label;
            svg.appendChild(label);

            const bw = conn.label.length * 7;
            const bg = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
            bg.setAttribute('x', mx - bw / 2 - 4);
            bg.setAttribute('y', my - 10);
            bg.setAttribute('width', bw + 8);
            bg.setAttribute('height', 18);
            bg.setAttribute('rx', '4');
            bg.setAttribute('fill', 'white');
            bg.setAttribute('opacity', '0.9');
            svg.insertBefore(bg, label);
        }
    });

    // Temp line when connecting
    if (connectSource) {
        const src = nodes.find(n => n.id === connectSource.nodeId);
        if (src) {
            const srcEl = document.getElementById(connectSource.nodeId);
            let x1, y1;
            if (connectSource.optionIndex !== undefined && srcEl) {
                const optPort = srcEl.querySelector(`.fb-opt-port[data-optidx="${connectSource.optionIndex}"]`);
                if (optPort) {
                    const pr = optPort.getBoundingClientRect();
                    x1 = (pr.left - canvasRect.left - panX + pr.width / 2) / zoom;
                    y1 = (pr.top - canvasRect.top - panY + pr.height / 2) / zoom;
                } else {
                    x1 = src.x + 150;
                    y1 = src.y + (srcEl.offsetHeight || 110) + 7;
                }
            } else {
                x1 = src.x + 150;
                y1 = src.y + (srcEl ? srcEl.offsetHeight : 110) + 7;
            }
            const tempLine = document.createElementNS('http://www.w3.org/2000/svg', 'line');
            tempLine.setAttribute('id', 'tempConnLine');
            tempLine.setAttribute('x1', x1);
            tempLine.setAttribute('y1', y1);
            tempLine.setAttribute('x2', x1);
            tempLine.setAttribute('y2', y1);
            tempLine.setAttribute('stroke', '#4A90D9');
            tempLine.setAttribute('stroke-width', '2');
            tempLine.setAttribute('stroke-dasharray', '5,5');
            svg.appendChild(tempLine);
        }
    }

    updateTransform();
}

function followConnLine(me) {
    const line = document.getElementById('tempConnLine');
    if (!line) return;
    const r = document.getElementById('fbCanvas').getBoundingClientRect();
    line.setAttribute('x2', (me.clientX - r.left - panX) / zoom);
    line.setAttribute('y2', (me.clientY - r.top - panY) / zoom);
}

function updateTransform() {
    const canvas = document.getElementById('fbCanvas');
    const svg = document.getElementById('fbSvg');
    canvas.style.transformOrigin = '0 0';
    canvas.style.transform = 'translate(' + panX + 'px, ' + panY + 'px) scale(' + zoom + ')';
    svg.setAttribute('width', canvas.scrollWidth || 6000);
    svg.setAttribute('height', canvas.scrollHeight || 4000);
}

/* -------- CRUD -------- */
function addNode(type, x, y) {
    const node = {
        id: genId(),
        type: type,
        title: NODE_TYPES[type]?.label || type,
        content: '',
        config: {},
        options: [],
        x: x || 100,
        y: y || 100,
        zIndex: ++nextZIndex,
    };
    if (type === 'start') { return; }
    if (['condition', 'day_of_week', 'time_range'].includes(type)) {
        node.options = [
            { label: 'SIM', value: 'sim', next_node_id: null },
            { label: 'NÃO', value: 'nao', next_node_id: null },
        ];
    }
    nodes.push(node);
    if (connectSource) {
        const conn = { source: connectSource.nodeId, target: node.id, label: '' };
        if (connectSource.optionIndex !== undefined) conn.sourceOption = connectSource.optionIndex;
        connections.push(conn);
        connectSource = null;
    }
    renderAll();
    setTimeout(() => editNode(node.id), 100);
}

function addNodeAtCenter(type) {
    if (type === 'start' && nodes.some(n => n.type === 'start')) return;
    const canvas = document.getElementById('fbCanvas');
    const wrap = canvas.parentElement;
    const wr = wrap.getBoundingClientRect();
    const cx = (wr.width / 2 - panX) / zoom - 150;
    const cy = (wr.height / 2 - panY) / zoom - 50;
    addNode(type, Math.max(0, cx), Math.max(0, cy));
}

function deleteNode(id) {
    if (!confirm('Remover este nó e suas conexões?')) return;
    nodes = nodes.filter(n => n.id !== id);
    connections = connections.filter(c => c.source !== id && c.target !== id);
    if (selectedNode === id) selectedNode = null;
    if (connectSource && connectSource.nodeId === id) { connectSource = null; }
    renderAll();
}

function editNode(id) {
    const node = nodes.find(n => n.id === id);
    if (!node) return;
    editNodeId = id;
    const nt = NODE_TYPES[node.type] || { label: 'Nó' };

    document.getElementById('nodeModalTitle').textContent = 'Editar: ' + nt.label;
    const body = document.getElementById('nodeModalBody');
    let html = '<div class="form-group"><label>Título</label><input type="text" class="form-control" id="neTitle" value="' + e(node.title) + '"></div>';

    const showContent = ['message', 'menu', 'question', 'collect_field', 'handoff', 'button_list', 'list_menu'];
    if (showContent.includes(node.type)) {
        html += '<div class="form-group"><label>Conteúdo da mensagem</label><textarea class="form-control" id="neContent" rows="4">' + e(node.content || '') + '</textarea>';
        html += '<small class="text-muted">Variáveis: {nome}, {email}, {telefone}, {departamento}, {atendente}</small></div>';
    }

    if (node.type === 'menu') {
        html += buildOptionsEditor(node.options, 'Opções do menu');
    }

    if (node.type === 'button_list') {
        html += '<div class="form-group"><label>Texto dos botões (máx. 3)</label><div id="neButtons">';
        const btns = node.options.length > 0 ? node.options : [{ label: 'Sim' }, { label: 'Não' }];
        btns.forEach((b, i) => {
            html += '<div class="fb-opt-row"><input type="text" class="form-control" value="' + e(b.label) + '" placeholder="Botão ' + (i+1) + '" data-idx="' + i + '"><button type="button" class="btn btn-sm btn-danger" onclick="removeOpt(' + i + ')"><i class="fas fa-times"></i></button></div>';
        });
        html += '<button type="button" class="btn btn-sm btn-outline mt-1" onclick="addOpt()"><i class="fas fa-plus"></i> Adicionar botão</button></div></div>';
        html += '<div class="form-group"><label>Qtd. botões por linha</label><select class="form-control" id="neBtnPerRow"><option value="1">1</option><option value="2" selected>2</option><option value="3">3</option></select></div>';
    }

    if (node.type === 'list_menu') {
        html += '<div class="form-group"><label>Título da lista</label><input type="text" class="form-control" id="neListTitle" value="' + e(node.config?.list_title || 'Opções') + '"></div>';
        html += buildOptionsEditor(node.options, 'Itens da lista');
    }

    if (node.type === 'image') {
        html += '<div class="form-group"><label>URL da imagem</label><input type="text" class="form-control" id="neFileUrl" value="' + e(node.config?.file_url || '') + '" placeholder="https://..."></div>';
    }
    if (node.type === 'audio') {
        html += '<div class="form-group"><label>URL do áudio</label><input type="text" class="form-control" id="neFileUrl" value="' + e(node.config?.file_url || '') + '" placeholder="https://..."></div>';
    }
    if (node.type === 'video') {
        html += '<div class="form-group"><label>URL do vídeo</label><input type="text" class="form-control" id="neFileUrl" value="' + e(node.config?.file_url || '') + '" placeholder="https://..."></div>';
    }
    if (node.type === 'send_file') {
        html += '<div class="form-group"><label>URL do arquivo</label><input type="text" class="form-control" id="neFileUrl" value="' + e(node.config?.file_url || '') + '" placeholder="https://..."></div>';
        html += '<div class="form-group"><label>Nome do arquivo</label><input type="text" class="form-control" id="neFileName" value="' + e(node.config?.file_name || '') + '" placeholder="documento.pdf"></div>';
    }
    if (node.type === 'delay') {
        html += '<div class="form-group"><label>Segundos de espera</label><input type="number" class="form-control" id="neDelay" value="' + (node.config?.seconds || 2) + '" min="1" max="300"></div>';
    }
    if (node.type === 'condition') {
        html += '<div class="form-group"><label>Variável</label><input type="text" class="form-control" id="neCondVar" value="' + e(node.config?.variable || '') + '" placeholder="Ex: {opcao}"></div>';
        html += '<div class="form-group"><label>Valor esperado</label><input type="text" class="form-control" id="neCondVal" value="' + e(node.config?.expected || '') + '" placeholder="Ex: Sim"></div>';
    }
    if (node.type === 'day_of_week') {
        html += '<div class="form-group"><label>Dias da semana (marque os que deseja)</label><div id="neDays">';
        const dayNames = ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'];
        const checked = node.config?.days || [1,2,3,4,5];
        dayNames.forEach((name, i) => {
            html += '<label class="checkbox-inline" style="display:inline-flex;align-items:center;gap:4px;margin:4px 8px 4px 0;font-size:13px">';
            html += '<input type="checkbox" value="' + i + '"' + (checked.includes(i) ? ' checked' : '') + '> ' + name + '</label>';
        });
        html += '</div><small class="text-muted">Se o hoje estiver entre os dias marcados, segue o ramo SIM</small>';
    }
    if (node.type === 'time_range') {
        html += '<div class="form-group"><label>Horário início</label><input type="time" class="form-control" id="neTimeStart" value="' + e(node.config?.start_time || '08:00') + '"></div>';
        html += '<div class="form-group"><label>Horário fim</label><input type="time" class="form-control" id="neTimeEnd" value="' + e(node.config?.end_time || '18:00') + '"></div>';
        html += '<small class="text-muted">Se agora estiver entre início e fim, segue o ramo SIM</small>';
    }
    if (node.type === 'assign_department') {
        html += '<div class="form-group"><label>Departamento</label><select class="form-control" id="neDept"><option value="">Selecione...</option>';
        <?php foreach ($departments as $dept): ?>
        html += '<option value="<?= $dept['id'] ?>"' + (node.config?.department_id == <?= $dept['id'] ?> ? ' selected' : '') + '><?= e($dept['name']) ?></option>';
        <?php endforeach; ?>
        html += '</select></div>';
    }
    if (node.type === 'assign_user') {
        html += '<div class="form-group"><label>Atendente</label><select class="form-control" id="neUser"><option value="">Selecione...</option>';
        <?php foreach ($users as $user): ?>
        html += '<option value="<?= $user['id'] ?>"' + (node.config?.user_id == <?= $user['id'] ?> ? ' selected' : '') + '><?= e($user['name']) ?></option>';
        <?php endforeach; ?>
        html += '</select></div>';
    }
    if (node.type === 'add_tag') {
        html += '<div class="form-group"><label>Etiqueta</label><select class="form-control" id="neTag"><option value="">Selecione...</option>';
        <?php foreach ($tags as $tag): ?>
        html += '<option value="<?= $tag['id'] ?>"' + (node.config?.tag_id == <?= $tag['id'] ?> ? ' selected' : '') + '><?= e($tag['name']) ?></option>';
        <?php endforeach; ?>
        html += '</select></div>';
    }
    if (node.type === 'notify') {
        html += '<div class="form-group"><label>Canal WhatsApp para envio</label><select class="form-control" id="neNotifyChannel">';
        html += '<option value="">Usar canal da conversa</option>';
        WHATSAPP_CHANNELS.forEach(function(ch) {
            html += '<option value="' + ch.id + '"' + (node.config?.notify_channel_id == ch.id ? ' selected' : '') + '>' + e(ch.name) + (ch.instance_name ? ' (' + e(ch.instance_name) + ')' : '') + '</option>';
        });
        html += '</select></div>';
        html += '<div class="form-group"><label>Telefones destino (com DDD, um por linha)</label><textarea class="form-control" id="neNotifyPhones" rows="3" placeholder="5511999999999">' + e((node.config?.phones || [node.config?.phone_number || '']).join('\n')) + '</textarea>';
        html += '<small class="text-muted">Cada número em uma linha. Mínimo 10 dígitos.</small></div>';
        html += '<div class="form-group"><label>Mensagem a enviar</label><textarea class="form-control" id="neNotifyMsg" rows="4">' + e(node.config?.message_template || '') + '</textarea>';
        html += '<small class="text-muted">Variáveis: {nome}, {telefone}, {email}, {empresa}, {documento}, {mensagem}</small></div>';
    }
    if (node.type === 'collect_field') {
        html += '<div class="form-group"><label>Campo para salvar</label><select class="form-control" id="neField">';
        ['name','email','phone','company','document','notes'].forEach(f => {
            html += '<option value="' + f + '"' + (node.config?.save_field === f ? ' selected' : '') + '>' + f.charAt(0).toUpperCase() + f.slice(1) + '</option>';
        });
        html += '</select></div>';
        html += '<div class="form-group"><label>Mensagem de erro</label><input type="text" class="form-control" id="neInvalidMsg" value="' + e(node.config?.invalid_message || '') + '" placeholder="Opção inválida, tente novamente"></div>';
    }
    if (node.type === 'question') {
        html += '<div class="form-group"><label>Campo para salvar (opcional)</label><select class="form-control" id="neSaveField"><option value="">Não salvar</option>';
        ['name','email','phone','company','document','notes'].forEach(f => {
            html += '<option value="' + f + '">' + f.charAt(0).toUpperCase() + f.slice(1) + '</option>';
        });
        html += '</select></div>';
    }

    body.innerHTML = html;
    document.getElementById('fbNodeModal').style.display = 'flex';
}

function buildOptionsEditor(options, label) {
    let html = '<div class="form-group"><label>' + label + '</label><div id="neOptions">';
    const opts = options.length > 0 ? options : [{ label: 'Opção 1' }];
    opts.forEach((o, i) => {
        html += '<div class="fb-opt-row"><input type="text" class="form-control" value="' + e(o.label) + '" placeholder="Opção ' + (i+1) + '" data-idx="' + i + '"><button type="button" class="btn btn-sm btn-danger" onclick="removeOpt(' + i + ')"><i class="fas fa-times"></i></button></div>';
    });
    html += '<button type="button" class="btn btn-sm btn-outline mt-1" onclick="addOpt()"><i class="fas fa-plus"></i> Adicionar</button></div></div>';
    return html;
}

function addOpt() {
    const node = nodes.find(n => n.id === editNodeId);
    if (!node) return;
    node.options.push({ label: 'Novo', value: 'Novo', next_node_id: null });
    editNode(editNodeId);
}

function removeOpt(idx) {
    const node = nodes.find(n => n.id === editNodeId);
    if (!node) return;
    node.options.splice(idx, 1);
    editNode(editNodeId);
}

function saveNodeModal() {
    const node = nodes.find(n => n.id === editNodeId);
    if (!node) return;

    node.title = document.getElementById('neTitle')?.value || node.title;
    node.content = document.getElementById('neContent')?.value || node.content || '';

    if (node.type === 'menu') {
        const inputs = document.querySelectorAll('#neOptions input');
        node.options = [];
        inputs.forEach(inp => node.options.push({ label: inp.value, value: inp.value, next_node_id: null }));
    }

    if (node.type === 'button_list') {
        const inputs = document.querySelectorAll('#neButtons input');
        node.options = [];
        inputs.forEach(inp => node.options.push({ label: inp.value, value: inp.value, next_node_id: null }));
        if (!node.config) node.config = {};
        node.config.buttons_per_row = parseInt(document.getElementById('neBtnPerRow')?.value || '2');
    }

    if (node.type === 'list_menu') {
        node.config = node.config || {};
        node.config.list_title = document.getElementById('neListTitle')?.value || 'Opções';
        const inputs = document.querySelectorAll('#neOptions input');
        node.options = [];
        inputs.forEach(inp => node.options.push({ label: inp.value, value: inp.value, next_node_id: null }));
    }

    if (['image', 'audio', 'video', 'send_file'].includes(node.type)) {
        node.config = node.config || {};
        node.config.file_url = document.getElementById('neFileUrl')?.value || '';
        if (node.type === 'send_file') {
            node.config.file_name = document.getElementById('neFileName')?.value || '';
        }
    }

    if (node.type === 'delay') {
        node.config = node.config || {};
        node.config.seconds = parseInt(document.getElementById('neDelay')?.value || '2');
    }

    if (node.type === 'condition') {
        node.config = node.config || {};
        node.config.variable = document.getElementById('neCondVar')?.value || '';
        node.config.expected = document.getElementById('neCondVal')?.value || '';
    }

    if (node.type === 'day_of_week') {
        node.config = node.config || {};
        const checks = document.querySelectorAll('#neDays input[type=checkbox]');
        node.config.days = [];
        checks.forEach((cb, i) => { if (cb.checked) node.config.days.push(i); });
    }

    if (node.type === 'time_range') {
        node.config = node.config || {};
        node.config.start_time = document.getElementById('neTimeStart')?.value || '08:00';
        node.config.end_time = document.getElementById('neTimeEnd')?.value || '18:00';
    }

    if (node.type === 'assign_department') {
        node.config = node.config || {};
        node.config.department_id = document.getElementById('neDept')?.value || null;
    }
    if (node.type === 'assign_user') {
        node.config = node.config || {};
        node.config.user_id = document.getElementById('neUser')?.value || null;
    }
    if (node.type === 'add_tag') {
        node.config = node.config || {};
        node.config.tag_id = document.getElementById('neTag')?.value || null;
    }
    if (node.type === 'notify') {
        node.config = node.config || {};
        node.config.notify_channel_id = document.getElementById('neNotifyChannel')?.value || '';
        const raw = document.getElementById('neNotifyPhones')?.value || '';
        node.config.phones = raw.split('\n').map(s => s.trim()).filter(s => s.replace(/\D/g, '').length >= 10);
        node.config.message_template = document.getElementById('neNotifyMsg')?.value || '';
    }
    if (node.type === 'collect_field') {
        node.config = node.config || {};
        node.config.save_field = document.getElementById('neField')?.value || 'name';
        node.config.invalid_message = document.getElementById('neInvalidMsg')?.value || '';
    }
    if (node.type === 'question') {
        node.config = node.config || {};
        node.config.save_field = document.getElementById('neSaveField')?.value || '';
    }

    closeNodeModal();
    renderAll();
}

function closeNodeModal() {
    document.getElementById('fbNodeModal').style.display = 'none';
    editNodeId = null;
}

function closeSettings() {
    document.getElementById('fbSettingsModal').style.display = 'none';
}

/* -------- Save -------- */
function saveFlow() {
    // Resolve next_node_id in options based on connections
    const nodeMap = {};
    nodes.forEach(n => nodeMap[n.id] = n);

    nodes.forEach(node => {
        const outConns = connections.filter(c => c.source === node.id);
        if (!node.options || node.options.length === 0) {
            if (outConns.length) {
                node.options = [{ label: 'Continuar', value: 'next', next_node_id: outConns[0].target }];
            }
        } else {
            node.options.forEach((opt, idx) => {
                const conn = outConns.find(c => c.sourceOption === idx) || outConns[idx] || null;
                opt.next_node_id = conn ? conn.target : null;
            });
        }
    });

    document.getElementById('nodesInput').value = JSON.stringify(nodes);
    document.getElementById('flowName').value = document.getElementById('fbTitleInput').value.trim();
    document.getElementById('flowDesc').value = document.getElementById('cfgDesc')?.value || document.getElementById('flowDesc').value;
    document.getElementById('flowScope').value = document.getElementById('cfgScope')?.value || document.getElementById('flowScope').value;
    document.getElementById('flowActive').value = document.getElementById('cfgActive')?.checked ? '1' : document.getElementById('flowActive').value;

    document.getElementById('flowForm').submit();
}

function openSettings() {
    document.getElementById('cfgDesc').value = document.getElementById('flowDesc').value;
    document.getElementById('cfgScope').value = document.getElementById('flowScope').value;
    document.getElementById('cfgActive').checked = document.getElementById('flowActive').value === '1';
    document.getElementById('fbSettingsModal').style.display = 'flex';
}

function saveSettings() {
    document.getElementById('flowDesc').value = document.getElementById('cfgDesc').value;
    document.getElementById('flowScope').value = document.getElementById('cfgScope').value;
    document.getElementById('flowActive').value = document.getElementById('cfgActive').checked ? '1' : '0';
    closeSettings();
}

/* -------- Keyboard -------- */
function setupKeyboard() {
    document.addEventListener('keydown', e => {
        if (e.key === 'Delete' || e.key === 'Backspace') {
            if (document.activeElement?.tagName === 'INPUT' || document.activeElement?.tagName === 'TEXTAREA') return;
            if (selectedNode && !e.target.closest('.modal')) {
                deleteNode(selectedNode);
            }
        }
        if (e.key === 'Escape') {
            connectSource = null;
            closeNodeModal();
            closeSettings();
            renderAll();
        }
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            saveFlow();
        }
    });
}

/* -------- Utils -------- */
function e(str) { if (!str) return ''; return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function truncate(text, max) { if (!text) return ''; return text.length > max ? text.substring(0, max) + '...' : text; }

/* -------- Toolbar overrides -------- */
document.querySelector('.fb-toolbar .btn-primary')?.addEventListener('click', e => {
    // Already handled by onclick
});

document.addEventListener('DOMContentLoaded', () => {
    // Add settings gear to toolbar
    const toolbar = document.querySelector('.fb-toolbar');
    if (toolbar) {
        const right = toolbar.querySelector('.fb-toolbar-right');
        const gear = document.createElement('button');
        gear.className = 'btn btn-sm btn-outline';
        gear.innerHTML = '<i class="fas fa-cog"></i>';
        gear.title = 'Configurações do Fluxo';
        gear.addEventListener('click', openSettings);
        right.insertBefore(gear, right.firstChild);
    }
    init();
});
</script>
