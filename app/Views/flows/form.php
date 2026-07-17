<div class="flow-editor-page">
    <div class="page-toolbar">
        <a href="<?= url('flows') ?>" class="btn btn-sm btn-outline">
            <i class="fas fa-arrow-left"></i> Voltar
        </a>
        <div>
            <?php if ($flow): ?>
                <span class="badge <?= $flow['is_active'] ? 'badge-success' : 'badge-secondary' ?>">
                    <?= $flow['is_active'] ? 'Ativo' : 'Rascunho' ?>
                </span>
            <?php endif; ?>
        </div>
    </div>

    <div class="flow-editor">
        <div class="flow-config-panel">
            <div class="card">
                <div class="card-header">
                    <h3>Configuração do Fluxo</h3>
                </div>
                <div class="card-body">
                    <form id="flowForm" method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="nodes" id="nodesInput">

                        <div class="form-group">
                            <label>Nome do Fluxo</label>
                            <input type="text" name="name" class="form-control" required
                                   value="<?= e($flow['name'] ?? '') ?>"
                                   placeholder="Ex: Triagem Comercial">
                        </div>
                        <div class="form-group">
                            <label>Descrição</label>
                            <textarea name="description" rows="3" class="form-control"
                                      placeholder="Descreva a finalidade deste fluxo"><?= e($flow['description'] ?? '') ?></textarea>
                        </div>
                        <div class="form-group">
                            <label>Escopo do Canal</label>
                            <select name="channel_scope" class="form-control">
                                <option value="all" <?= ($flow['channel_scope'] ?? 'all') === 'all' ? 'selected' : '' ?>>Todos os canais</option>
                                <option value="webchat" <?= ($flow['channel_scope'] ?? '') === 'webchat' ? 'selected' : '' ?>>ChatWeb</option>
                                <option value="whatsapp" <?= ($flow['channel_scope'] ?? '') === 'whatsapp' ? 'selected' : '' ?>>WhatsApp</option>
                                <option value="email" <?= ($flow['channel_scope'] ?? '') === 'email' ? 'selected' : '' ?>>E-mail</option>
                            </select>
                        </div>
                        <?php if ($flow): ?>
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" name="is_active" value="1" <?= $flow['is_active'] ? 'checked' : '' ?>>
                                    Fluxo ativo
                                </label>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3>Nós do Fluxo</h3>
                    <button class="btn btn-sm btn-primary" onclick="addNode()">
                        <i class="fas fa-plus"></i> Adicionar
                    </button>
                </div>
                <div class="card-body node-list" id="nodeList">
                    <p class="text-muted">Clique em "Adicionar" para começar a criar seu fluxo.</p>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3>Ações</h3>
                </div>
                <div class="card-body">
                    <button class="btn btn-primary btn-block" onclick="saveFlow()">
                        <i class="fas fa-save"></i> Salvar Fluxo
                    </button>
                </div>
            </div>
        </div>

        <div class="flow-preview-panel">
            <div class="card">
                <div class="card-header">
                    <h3>Visualização do Fluxo</h3>
                </div>
                <div class="card-body flow-canvas" id="flowCanvas">
                    <div class="empty-state">
                        <i class="fas fa-diagram-project fa-3x"></i>
                        <p>Os nós do fluxo aparecerão aqui como uma sequência.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let nodes = [];
let tempKeyCounter = 0;

<?php if ($flow && !empty($flow['nodes'])): ?>
// Load existing nodes
nodes = <?= json_encode(array_map(function($n) {
    $options = [];
    foreach ($n['options'] ?? [] as $opt) {
        $options[] = [
            'label' => $opt['label'],
            'value' => $opt['value'],
            'next_node_temp_key' => null,
        ];
    }
    return [
        'temp_key' => 'existing_' . $n['id'],
        'type' => $n['node_type'],
        'title' => $n['title'],
        'content' => $n['content'],
        'options' => $options,
        'department_id' => $n['config']['department_id'] ?? null,
        'user_id' => $n['config']['user_id'] ?? null,
        'tag_id' => $n['config']['tag_id'] ?? null,
    ];
}, $flow['nodes'])) ?>;
renderNodes();
<?php endif; ?>

const nodeTypes = {
    start: { label: 'Início', icon: 'fa-play-circle', color: '#28A745' },
    message: { label: 'Mensagem', icon: 'fa-comment', color: '#007BFF' },
    menu: { label: 'Menu/Botões', icon: 'fa-list', color: '#17A2B8' },
    question: { label: 'Pergunta', icon: 'fa-question-circle', color: '#FFC107' },
    collect_field: { label: 'Coletar Dado', icon: 'fa-edit', color: '#6F42C1' },
    condition: { label: 'Condição', icon: 'fa-code-branch', color: '#FD7E14' },
    assign_department: { label: 'Definir Depto', icon: 'fa-layer-group', color: '#E83E8C' },
    assign_user: { label: 'Atribuir Atend.', icon: 'fa-user-check', color: '#20C997' },
    add_tag: { label: 'Aplicar Etiqueta', icon: 'fa-tag', color: '#6C757D' },
    handoff: { label: 'Encaminhar', icon: 'fa-forward', color: '#DC3545' },
    end: { label: 'Encerrar', icon: 'fa-stop-circle', color: '#343A40' },
};

function addNode() {
    const typeSelect = document.createElement('div');
    typeSelect.className = 'modal';
    typeSelect.id = 'typeSelector';
    typeSelect.style.display = 'flex';
    typeSelect.innerHTML = `
        <div class="modal-content" style="max-width:400px">
            <div class="modal-header">
                <h3>Selecione o tipo de nó</h3>
                <button class="modal-close" onclick="closeTypeSelector()">&times;</button>
            </div>
            <div class="node-type-grid">
                ${Object.entries(nodeTypes).map(([key, val]) => `
                    <button class="node-type-btn" onclick="createNode('${key}')">
                        <i class="fas ${val.icon}" style="color:${val.color}"></i>
                        <span>${val.label}</span>
                    </button>
                `).join('')}
            </div>
        </div>
    `;
    document.body.appendChild(typeSelect);
}

function closeTypeSelector() {
    document.getElementById('typeSelector')?.remove();
}

function createNode(type) {
    closeTypeSelector();
    const tempKey = 'node_' + (++tempKeyCounter);

    const node = {
        temp_key: tempKey,
        type: type,
        title: nodeTypes[type]?.label || type,
        content: '',
        options: [],
        department_id: null,
        user_id: null,
        tag_id: null,
    };

    nodes.push(node);
    renderNodes();
}

function renderNodes() {
    const list = document.getElementById('nodeList');
    const canvas = document.getElementById('flowCanvas');

    if (nodes.length === 0) {
        list.innerHTML = '<p class="text-muted">Clique em "Adicionar" para começar.</p>';
        canvas.innerHTML = '<div class="empty-state"><i class="fas fa-diagram-project fa-3x"></i><p>Os nós aparecerão aqui.</p></div>';
        return;
    }

    list.innerHTML = '';
    let canvasHtml = '<div class="flow-sequence">';

    nodes.forEach((node, index) => {
        const nt = nodeTypes[node.type] || { label: node.type, icon: 'fa-circle', color: '#666' };

        // List item
        const item = document.createElement('div');
        item.className = 'node-list-item';
        item.dataset.key = node.temp_key;
        item.innerHTML = `
            <div class="node-item-header">
                <span class="node-type-icon" style="color:${nt.color}">
                    <i class="fas ${nt.icon}"></i> ${nt.label}
                </span>
                <div class="node-item-actions">
                    <button type="button" class="btn btn-sm btn-outline" onclick="editNode('${node.temp_key}')">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-danger" onclick="removeNode('${node.temp_key}')">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            ${node.title ? `<div class="node-item-title">${node.title}</div>` : ''}
            ${node.content ? `<div class="node-item-content">${truncateText(node.content, 80)}</div>` : ''}
            ${node.options.length > 0 ? `<div class="node-item-options">${node.options.map(o => `<span class="badge badge-info">${o.label}</span>`).join(' ')}</div>` : ''}
        `;
        list.appendChild(item);

        // Canvas element
        canvasHtml += `
            <div class="flow-node-card" style="border-left: 4px solid ${nt.color}">
                <div class="flow-node-header">
                    <span class="flow-node-icon"><i class="fas ${nt.icon}"></i></span>
                    <span class="flow-node-title">${node.title || nt.label}</span>
                </div>
                ${node.content ? `<div class="flow-node-content">${truncateText(node.content, 60)}</div>` : ''}
                ${node.options.length > 0 ? `<div class="flow-node-options">${node.options.map((o, i) => `<div class="flow-option-item">${i+1}. ${o.label}</div>`).join('')}</div>` : ''}
            </div>
            ${index < nodes.length - 1 ? '<div class="flow-arrow"><i class="fas fa-arrow-down"></i></div>' : ''}
        `;
    });

    canvasHtml += '</div>';
    canvas.innerHTML = canvasHtml;
}

function editNode(tempKey) {
    const node = nodes.find(n => n.temp_key === tempKey);
    if (!node) return;

    const nt = nodeTypes[node.type] || { label: 'Nó' };
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.style.display = 'flex';
    modal.id = 'editNodeModal';
    modal.innerHTML = `
        <div class="modal-content" style="max-width:500px">
            <div class="modal-header">
                <h3>Editar: ${nt.label}</h3>
                <button class="modal-close" onclick="closeEditModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Título</label>
                    <input type="text" class="form-control" id="editTitle" value="${e(node.title)}">
                </div>
                ${node.type === 'message' || node.type === 'menu' || node.type === 'question' || node.type === 'collect_field' || node.type === 'handoff' ? `
                <div class="form-group">
                    <label>Conteúdo da mensagem</label>
                    <textarea class="form-control" id="editContent" rows="4">${e(node.content || '')}</textarea>
                    <small class="text-muted">Variáveis disponíveis: {nome}, {email}, {telefone}, {departamento}</small>
                </div>` : ''}
                ${node.type === 'menu' ? `
                <div class="form-group">
                    <label>Opções do menu</label>
                    <div id="editOptions">
                        ${node.options.map((opt, i) => `
                            <div class="option-row">
                                <input type="text" class="form-control" value="${e(opt.label)}" placeholder="Opção ${i+1}" data-option-index="${i}">
                                <button type="button" class="btn btn-sm btn-danger" onclick="removeOption(${i}, '${tempKey}')"><i class="fas fa-times"></i></button>
                            </div>
                        `).join('')}
                    </div>
                    <button type="button" class="btn btn-sm btn-outline mt-1" onclick="addOption('${tempKey}')">
                        <i class="fas fa-plus"></i> Adicionar opção
                    </button>
                </div>` : ''}
                ${node.type === 'assign_department' ? `
                <div class="form-group">
                    <label>Departamento</label>
                    <select class="form-control" id="editDepartment">
                        <option value="">Selecione...</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['id'] ?>" ${node.department_id == <?= $dept['id'] ?> ? 'selected' : ''}>
                                <?= e($dept['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>` : ''}
                ${node.type === 'assign_user' ? `
                <div class="form-group">
                    <label>Atendente</label>
                    <select class="form-control" id="editUser">
                        <option value="">Selecione...</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= $user['id'] ?>" ${node.user_id == <?= $user['id'] ?> ? 'selected' : ''}>
                                <?= e($user['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>` : ''}
                ${node.type === 'add_tag' ? `
                <div class="form-group">
                    <label>Etiqueta</label>
                    <select class="form-control" id="editTag">
                        <option value="">Selecione...</option>
                        <?php foreach ($tags as $tag): ?>
                            <option value="<?= $tag['id'] ?>" ${node.tag_id == <?= $tag['id'] ?> ? 'selected' : ''}>
                                <?= e($tag['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>` : ''}
                ${node.type === 'collect_field' ? `
                <div class="form-group">
                    <label>Campo para salvar</label>
                    <select class="form-control" id="editField">
                        <option value="name">Nome</option>
                        <option value="email">E-mail</option>
                        <option value="phone">Telefone</option>
                        <option value="company">Empresa</option>
                        <option value="document">Documento</option>
                        <option value="notes">Observações</option>
                    </select>
                </div>` : ''}
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" onclick="closeEditModal()">Cancelar</button>
                <button class="btn btn-primary" onclick="saveNodeEdit('${tempKey}')">Salvar</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
}

function closeEditModal() {
    document.getElementById('editNodeModal')?.remove();
}

function saveNodeEdit(tempKey) {
    const node = nodes.find(n => n.temp_key === tempKey);
    if (!node) return;

    node.title = document.getElementById('editTitle')?.value || node.title;
    node.content = document.getElementById('editContent')?.value || node.content;

    if (node.type === 'menu') {
        const optionInputs = document.querySelectorAll('#editOptions input');
        optionInputs.forEach((input, i) => {
            if (node.options[i]) {
                node.options[i].label = input.value;
                node.options[i].value = input.value;
            }
        });
    }

    if (node.type === 'assign_department') {
        node.department_id = document.getElementById('editDepartment')?.value || null;
    }
    if (node.type === 'assign_user') {
        node.user_id = document.getElementById('editUser')?.value || null;
    }
    if (node.type === 'add_tag') {
        node.tag_id = document.getElementById('editTag')?.value || null;
    }

    closeEditModal();
    renderNodes();
}

function addOption(tempKey) {
    const node = nodes.find(n => n.temp_key === tempKey);
    if (node) {
        node.options.push({ label: 'Nova opção', value: 'Nova opção', next_node_temp_key: null });
        editNode(tempKey);
    }
}

function removeOption(index, tempKey) {
    const node = nodes.find(n => n.temp_key === tempKey);
    if (node) {
        node.options.splice(index, 1);
        editNode(tempKey);
    }
}

function removeNode(tempKey) {
    if (!confirm('Remover este nó?')) return;
    nodes = nodes.filter(n => n.temp_key !== tempKey);
    renderNodes();
}

function saveFlow() {
    document.getElementById('nodesInput').value = JSON.stringify(nodes);
    document.getElementById('flowForm').submit();
}

function truncateText(text, max) {
    if (!text) return '';
    return text.length > max ? text.substring(0, max) + '...' : text;
}

function e(str) {
    if (!str) return '';
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
</script>
