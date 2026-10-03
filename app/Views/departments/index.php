<?php
$stats = $stats ?? ['total' => 0, 'users' => 0, 'open' => 0, 'closed' => 0];
?>
<div class="departments-page">
    <div class="page-actions">
        <button class="btn btn-primary btn-sm" data-action="open-create-dept">
            <i class="fas fa-plus"></i> Novo Departamento
        </button>
    </div>

    <div class="dept-stats">
        <div class="dept-stat">
            <div class="dept-stat-icon" style="background:var(--brand-soft);color:var(--brand)">
                <i class="fas fa-layer-group"></i>
            </div>
            <div class="dept-stat-body">
                <div class="dept-stat-value"><?= (int) $stats['total'] ?></div>
                <div class="dept-stat-label">Departamentos</div>
            </div>
        </div>
        <div class="dept-stat">
            <div class="dept-stat-icon" style="background:var(--info-soft);color:var(--info)">
                <i class="fas fa-users"></i>
            </div>
            <div class="dept-stat-body">
                <div class="dept-stat-value"><?= (int) $stats['users'] ?></div>
                <div class="dept-stat-label">Membros únicos</div>
            </div>
        </div>
        <div class="dept-stat">
            <div class="dept-stat-icon" style="background:var(--warning-soft);color:var(--warning)">
                <i class="fas fa-comments"></i>
            </div>
            <div class="dept-stat-body">
                <div class="dept-stat-value"><?= (int) $stats['open'] ?></div>
                <div class="dept-stat-label">Em aberto</div>
            </div>
        </div>
        <div class="dept-stat">
            <div class="dept-stat-icon" style="background:var(--success-soft);color:var(--success)">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="dept-stat-body">
                <div class="dept-stat-value"><?= (int) $stats['closed'] ?></div>
                <div class="dept-stat-label">Concluídas</div>
            </div>
        </div>
    </div>

    <?php if (empty($departments)): ?>
        <div class="card" style="border-radius:12px;overflow:hidden">
            <div class="card-body" style="padding:0">
                <div class="empty-state-enhanced">
                    <div class="empty-icon"><i class="fas fa-layer-group"></i></div>
                    <h3>Nenhum departamento</h3>
                    <p>Crie departamentos para organizar sua equipe e direcionar os atendimentos.</p>
                    <button class="btn btn-primary mt-2" data-action="open-create-dept">
                        <i class="fas fa-plus"></i> Criar Departamento
                    </button>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="dept-grid">
            <?php foreach ($departments as $dept):
                $color = $dept['color'] ?? '#4A90D9';
                $userCount = (int)($dept['user_count'] ?? 0);
                $convCount = (int)($dept['conversation_count'] ?? 0);
                $openCount = (int)($dept['open_conversations'] ?? 0);
                $closedCount = max(0, $convCount - $openCount);
                $contactCount = (int)($dept['contact_count'] ?? 0);
            ?>
                <a href="<?= url('departments/' . (int) $dept['id']) ?>" class="dept-card">
                    <div class="dept-card-banner" style="background:<?= e($color) ?>"></div>
                    <div class="dept-card-head">
                        <div class="dept-card-dot" style="background:<?= e($color) ?>"></div>
                        <h3 class="dept-card-name"><?= e($dept['name']) ?></h3>
                    </div>
                    <p class="dept-card-desc">
                        <?= e(truncate($dept['description'] ?? 'Sem descrição', 90)) ?>
                    </p>
                    <div class="dept-card-stats">
                        <div class="dept-card-stat" title="Membros">
                            <i class="fas fa-users" style="color:var(--brand)"></i>
                            <span><?= $userCount ?> membro<?= $userCount !== 1 ? 's' : '' ?></span>
                        </div>
                        <div class="dept-card-stat" title="Conversas">
                            <i class="fas fa-comments" style="color:var(--info)"></i>
                            <span><?= $convCount ?> conversa<?= $convCount !== 1 ? 's' : '' ?></span>
                        </div>
                        <div class="dept-card-stat" title="Contatos">
                            <i class="fas fa-user" style="color:var(--warning)"></i>
                            <span><?= $contactCount ?> contato<?= $contactCount !== 1 ? 's' : '' ?></span>
                        </div>
                    </div>
                    <?php if ($openCount > 0): ?>
                        <div class="dept-card-badge">
                            <span class="badge badge-warning" style="font-size:11px">
                                <i class="fas fa-spinner" style="font-size:10px"></i>
                                <?= $openCount ?> em aberto
                            </span>
                        </div>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="modal" id="createDeptModal" style="display:none" onclick="if(event.target===this)closeCreateDeptModal()">
    <div class="modal-content" style="max-width:480px">
        <div class="modal-header">
            <h3><i class="fas fa-plus-circle" style="color:var(--primary)"></i> Novo Departamento</h3>
            <button class="modal-close" type="button" onclick="closeCreateDeptModal()">&times;</button>
        </div>
        <form action="<?= url('departments/create') ?>" method="POST" id="createDeptForm">
            <?= csrf_field() ?>
            <div class="modal-body">
                <div class="form-group">
                    <label>Nome *</label>
                    <input type="text" name="name" class="form-control" required placeholder="Ex: Suporte Técnico">
                </div>
                <div class="form-group">
                    <label>Descrição</label>
                    <textarea name="description" rows="3" class="form-control" placeholder="Descreva a finalidade deste departamento"></textarea>
                </div>
                <div class="form-group">
                    <label>Cor de identificação</label>
                    <div style="display:flex;align-items:center;gap:8px">
                        <input type="color" name="color" value="#4A90D9" id="deptColorInput" style="width:48px;height:36px;padding:2px;border-radius:8px;border:1px solid var(--border-soft);cursor:pointer">
                        <input type="text" name="color_hex" id="deptColorHex" value="#4A90D9" class="form-control" style="flex:1;font-family:monospace;font-size:12px" maxlength="7" placeholder="#4A90D9">
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="justify-content:flex-end;display:flex;gap:8px;padding:14px 18px;border-top:1px solid var(--border-soft)">
                <button type="button" class="btn btn-outline" onclick="closeCreateDeptModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Criar Departamento</button>
            </div>
        </form>
    </div>
</div>

<script>
function openCreateDeptModal() {
    document.getElementById('createDeptModal').style.display = 'flex';
    setTimeout(function() {
        var input = document.querySelector('#createDeptModal input[name="name"]');
        if (input) input.focus();
    }, 50);
}
function closeCreateDeptModal() {
    document.getElementById('createDeptModal').style.display = 'none';
}

document.addEventListener('click', function(e) {
    var btn = e.target.closest('[data-action="open-create-dept"]');
    if (btn) {
        e.preventDefault();
        openCreateDeptModal();
    }
});

// Sincroniza color picker com input hex
(function() {
    var picker = document.getElementById('deptColorInput');
    var hex = document.getElementById('deptColorHex');
    if (!picker || !hex) return;
    picker.addEventListener('input', function() { hex.value = picker.value; });
    hex.addEventListener('input', function() {
        var v = hex.value.trim();
        if (/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(v)) picker.value = v;
    });
})();
</script>
