<?php
$roleLabels = [
    'admin'   => 'Administrador',
    'manager' => 'Gestor',
    'agent'   => 'Atendente',
    'viewer'  => 'Visualizador',
];
$roleBadges = [
    'admin'   => 'badge-danger',
    'manager' => 'badge-warning',
    'agent'   => 'badge-primary',
    'viewer'  => 'badge-secondary',
];
$totalUsers = count($users);
$activeUsers = count(array_filter($users, fn($u) => !empty($u['is_active'])));
$onlineUsers = count(array_filter($users, fn($u) => !empty($u['last_login_at']) && strtotime($u['last_login_at']) > (time() - 900)));
$rolesAvailable = array_unique(array_column($users, 'role'));
?>
<div class="users-page">
    <div class="page-actions">
        <div class="search-box">
            <i class="fas fa-search"></i>
            <input type="text" id="userSearch" class="search-input" placeholder="Buscar por nome ou e-mail..." oninput="filterUsers()">
        </div>
        <a href="<?= url('users/create') ?>" class="btn btn-primary btn-sm btn-new-user">
            <i class="fas fa-plus"></i> Novo Usuário
        </a>
    </div>

    <div class="user-stats">
        <div class="user-stat">
            <div class="user-stat-icon" style="background:var(--brand-soft);color:var(--brand)">
                <i class="fas fa-users"></i>
            </div>
            <div class="user-stat-body">
                <div class="user-stat-value"><?= $totalUsers ?></div>
                <div class="user-stat-label">Total</div>
            </div>
        </div>
        <div class="user-stat">
            <div class="user-stat-icon" style="background:var(--success-soft);color:var(--success)">
                <i class="fas fa-user-check"></i>
            </div>
            <div class="user-stat-body">
                <div class="user-stat-value"><?= $activeUsers ?></div>
                <div class="user-stat-label">Ativos</div>
            </div>
        </div>
        <div class="user-stat">
            <div class="user-stat-icon" style="background:var(--info-soft);color:var(--info)">
                <i class="fas fa-signal"></i>
            </div>
            <div class="user-stat-body">
                <div class="user-stat-value"><?= $onlineUsers ?></div>
                <div class="user-stat-label">Online agora</div>
            </div>
        </div>
    </div>

    <div class="user-toolbar">
        <div class="user-filters" id="userFilters">
            <button type="button" class="user-filter is-active" data-role="">Todos</button>
            <?php foreach ($rolesAvailable as $r): ?>
                <button type="button" class="user-filter" data-role="<?= e($r) ?>">
                    <?= e($roleLabels[$r] ?? ucfirst($r)) ?>
                </button>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="user-grid" id="userGrid">
        <?php foreach ($users as $user):
            $initial = mb_strtoupper(mb_substr($user['name'], 0, 1));
            $online = !empty($user['last_login_at']) && strtotime($user['last_login_at']) > (time() - 900);
            $isMe = \App\Core\Auth::id() === (int) $user['id'];
            $roleKey = $user['role'];
            $deptList = array_filter(array_map('trim', explode(',', (string)($user['department_names'] ?? ''))));
        ?>
            <div class="user-card"
                 data-name="<?= e(strtolower($user['name'] . ' ' . $user['email'])) ?>"
                 data-role="<?= e($roleKey) ?>"
                 data-active="<?= !empty($user['is_active']) ? '1' : '0' ?>">
                <div class="user-card-head">
                    <div class="user-card-banner"></div>
                    <div class="user-card-avatar">
                        <?php if (!empty($user['avatar'])): ?>
                            <img src="<?= e(upload_url($user['avatar'])) ?>" alt="<?= e($user['name']) ?>">
                        <?php else: ?>
                            <div class="avatar-ph"><?= e($initial) ?></div>
                        <?php endif; ?>
                        <span class="user-online-dot <?= $online ? 'online' : '' ?>" title="<?= $online ? 'Online' : 'Offline' ?>"></span>
                    </div>
                </div>
                <div class="user-card-body">
                    <div class="user-card-actions">
                        <?php if ($isMe): ?>
                            <span class="user-card-tag">Você</span>
                        <?php endif; ?>
                        <a href="<?= url('users/') ?><?= $user['id'] ?>/edit" class="btn btn-sm btn-outline btn-icon" title="Editar"><i class="fas fa-pen"></i></a>
                        <?php if (!$isMe): ?>
                            <form action="<?= url('users/') ?><?= $user['id'] ?>/delete" method="POST" style="display:inline" data-confirm="Excluir <?= e(addslashes($user['name'])) ?>?">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline btn-icon btn-icon-danger" title="Excluir"><i class="fas fa-trash"></i></button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <div class="user-card-name"><?= e($user['name']) ?></div>
                    <div class="user-card-email"><?= e($user['email']) ?></div>
                    <div class="user-card-meta">
                        <span class="badge <?= $roleBadges[$roleKey] ?? 'badge-primary' ?>"><?= e($roleLabels[$roleKey] ?? ucfirst($roleKey)) ?></span>
                        <?php if (empty($user['is_active'])): ?>
                            <span class="badge badge-secondary">Inativo</span>
                        <?php endif; ?>
                    </div>
                    <div class="user-card-depts">
                        <?php if (!empty($deptList)): ?>
                            <i class="fas fa-layer-group"></i>
                            <span class="user-dept-chips">
                                <?php foreach (array_slice($deptList, 0, 3) as $dn): ?>
                                    <span class="badge badge-secondary"><?= e($dn) ?></span>
                                <?php endforeach; ?>
                                <?php if (count($deptList) > 3): ?>
                                    <span class="badge badge-secondary">+<?= count($deptList) - 3 ?></span>
                                <?php endif; ?>
                            </span>
                        <?php else: ?>
                            <span class="user-no-dept"><i class="fas fa-layer-group"></i> Sem departamento</span>
                        <?php endif; ?>
                    </div>
                    <div class="user-card-stats">
                        <span title="Conversas atribuídas"><i class="fas fa-comments"></i> <?= (int)($user['conversation_count'] ?? 0) ?></span>
                        <span title="Último acesso">
                            <i class="fas <?= $online ? 'fa-signal' : 'fa-clock' ?>" style="<?= $online ? 'color:var(--success)' : '' ?>"></i>
                            <?= $user['last_login_at'] ? time_elapsed($user['last_login_at']) : 'Nunca' ?>
                        </span>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div id="userEmpty" class="empty-state-enhanced" style="display:none">
        <i class="fas fa-users fa-2x" style="opacity:0.3;margin-bottom:8px;display:block"></i>
        <p>Nenhum usuário encontrado.</p>
    </div>
</div>

<script>
(function() {
    var grid = document.getElementById('userGrid');
    var empty = document.getElementById('userEmpty');
    var filters = document.getElementById('userFilters');
    if (!grid) return;

    var state = { q: '', role: '' };

    function apply() {
        var shown = 0;
        var q = state.q.toLowerCase();
        var role = state.role;
        grid.querySelectorAll('.user-card').forEach(function(c) {
            var matchName = !q || (c.dataset.name || '').indexOf(q) !== -1;
            var matchRole = !role || c.dataset.role === role;
            var visible = matchName && matchRole;
            c.style.display = visible ? '' : 'none';
            if (visible) shown++;
        });
        if (empty) empty.style.display = shown ? 'none' : 'block';
    }

    window.filterUsers = function() {
        var input = document.getElementById('userSearch');
        state.q = input ? input.value : '';
        apply();
    };

    if (filters) {
        filters.addEventListener('click', function(e) {
            var btn = e.target.closest('.user-filter');
            if (!btn) return;
            filters.querySelectorAll('.user-filter').forEach(function(b) { b.classList.remove('is-active'); });
            btn.classList.add('is-active');
            state.role = btn.dataset.role || '';
            apply();
        });
    }
})();
</script>
