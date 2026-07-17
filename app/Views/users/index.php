<div class="users-page">
    <div class="page-toolbar">
        <div>
            <h2 class="page-title" style="margin:0">
                <i class="fas fa-users-cog" style="color:var(--primary)"></i>
                Usuários
            </h2>
            <p class="page-subtitle">
                <?= count($users) ?> usuário<?= count($users) !== 1 ? 's' : '' ?> cadastrado<?= count($users) !== 1 ? 's' : '' ?>
            </p>
        </div>
        <div class="toolbar-actions">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="userSearch" class="search-input" placeholder="Buscar usuário..." oninput="filterUsers()">
            </div>
            <a href="<?= url('users/create') ?>" class="btn btn-primary btn-sm btn-new-user">
                <i class="fas fa-plus"></i> Novo Usuário
            </a>
        </div>
    </div>

    <div class="user-grid" id="userGrid">
        <?php foreach ($users as $user):
            $initial = mb_strtoupper(mb_substr($user['name'], 0, 1));
            $online = !empty($user['last_login_at']) && strtotime($user['last_login_at']) > (time() - 900);
            $roleBadge = $user['role'] === 'admin' ? 'danger' : ($user['role'] === 'manager' ? 'warning' : ($user['role'] === 'viewer' ? 'secondary' : 'primary'));
        ?>
            <div class="user-card" data-name="<?= e(strtolower($user['name'] . ' ' . $user['email'])) ?>">
                <div class="user-card-top">
                    <div class="user-card-avatar">
                        <?php if (!empty($user['avatar'])): ?>
                            <img src="<?= e(upload_url($user['avatar'])) ?>" alt="<?= e($user['name']) ?>">
                        <?php else: ?>
                            <div class="avatar-ph"><?= e($initial) ?></div>
                        <?php endif; ?>
                        <span class="user-online-dot <?= $online ? 'online' : '' ?>" title="<?= $online ? 'Online' : 'Offline' ?>"></span>
                    </div>
                    <div class="user-card-actions">
                        <a href="<?= url('users/') ?><?= $user['id'] ?>/edit" class="btn btn-sm btn-outline" title="Editar"><i class="fas fa-edit"></i></a>
                        <?php if (\App\Core\Auth::id() !== $user['id']): ?>
                            <form action="<?= url('users/') ?><?= $user['id'] ?>/delete" method="POST" style="display:inline" onsubmit="return confirm('Excluir este usuário?')">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline btn-icon-danger" title="Excluir"><i class="fas fa-trash"></i></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="user-card-name"><?= e($user['name']) ?></div>
                <div class="user-card-email"><?= e($user['email']) ?></div>
                <div class="user-card-meta">
                    <span class="badge badge-<?= $roleBadge ?>"><?= e($user['role']) ?></span>
                    <span class="badge <?= $user['is_active'] ? 'badge-success' : 'badge-secondary' ?>">
                        <?= $user['is_active'] ? 'Ativo' : 'Inativo' ?>
                    </span>
                </div>
                <div class="user-card-depts">
                    <?php if (!empty(trim((string)($user['department_names'] ?? '')))): ?>
                        <i class="fas fa-layer-group"></i> <?= e(truncate($user['department_names'], 50)) ?>
                    <?php else: ?>
                        <span class="text-muted">Sem departamento</span>
                    <?php endif; ?>
                </div>
                <div class="user-card-stats">
                    <span><i class="fas fa-comments"></i> <?= (int)($user['conversation_count'] ?? 0) ?> atends.</span>
                    <span><i class="fas fa-clock"></i> <?= $user['last_login_at'] ? time_elapsed($user['last_login_at']) : 'Nunca' ?></span>
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
function filterUsers() {
    var q = document.getElementById('userSearch').value.toLowerCase();
    var cards = document.querySelectorAll('#userGrid .user-card');
    var shown = 0;
    cards.forEach(function(c) {
        var match = c.getAttribute('data-name').indexOf(q) !== -1;
        c.style.display = match ? '' : 'none';
        if (match) shown++;
    });
    document.getElementById('userEmpty').style.display = shown ? 'none' : 'block';
}
</script>
