<div class="department-detail">
    <div class="page-toolbar">
        <a href="<?= url('departments') ?>" class="btn btn-sm btn-outline">
            <i class="fas fa-arrow-left"></i> Voltar
        </a>
        <div>
            <button class="btn btn-sm btn-outline" onclick="openEditModal()">
                <i class="fas fa-edit"></i> Editar
            </button>
        </div>
    </div>

    <div class="department-grid">
        <div class="card">
            <div class="card-header">
                <div class="dept-color" style="background: <?= e($department['color'] ?? '#4A90D9') ?>"></div>
                <h3><?= e($department['name']) ?></h3>
            </div>
            <div class="card-body">
                <p><?= nl2br(e($department['description'] ?? 'Sem descrição')) ?></p>
                <div class="dept-stats">
                    <div class="stat"><span class="stat-num"><?= count($users) ?></span> Usuários</div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-users"></i> Membros</h3>
                <button class="btn btn-sm btn-primary" onclick="openAddUserModal()">
                    <i class="fas fa-plus"></i> Adicionar
                </button>
            </div>
            <div class="card-body p-0">
                <?php if (empty($users)): ?>
                    <div class="empty-state">
                        <p>Nenhum usuário neste departamento.</p>
                    </div>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Usuário</th>
                                <th>Gestor</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td>
                                        <div class="user-cell">
                                            <div class="avatar-sm"><?= mb_strtoupper(mb_substr($user['name'], 0, 1)) ?></div>
                                            <?= e($user['name']) ?>
                                        </div>
                                    </td>
                                    <td><?= $user['is_manager'] ? '<span class="badge badge-success">Sim</span>' : '-' ?></td>
                                    <td>
                                        <form action="/departments/<?= $department['id'] ?>/users/<?= $user['id'] ?>/remove" method="POST" style="display:inline">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-danger"
                                                    onclick="return confirm('Remover usuário do departamento?')">
                                                <i class="fas fa-times"></i>
                                            </button>
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
</div>

<!-- Edit Modal -->
<div class="modal" id="editModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Editar Departamento</h3>
            <button class="modal-close" onclick="closeEditModal()">&times;</button>
        </div>
        <form action="<?= url('departments/') ?><?= $department['id'] ?>/update" method="POST">
            <?= csrf_field() ?>
            <div class="form-group">
                <label>Nome</label>
                <input type="text" name="name" class="form-control" required value="<?= e($department['name']) ?>">
            </div>
            <div class="form-group">
                <label>Descrição</label>
                <textarea name="description" rows="3" class="form-control"><?= e($department['description'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label>Cor</label>
                <input type="color" name="color" class="form-control" value="<?= e($department['color'] ?? '#4A90D9') ?>">
            </div>
            <button type="submit" class="btn btn-primary">Salvar</button>
        </form>
    </div>
</div>

<!-- Add User Modal -->
<div class="modal" id="addUserModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Adicionar Usuário</h3>
            <button class="modal-close" onclick="closeAddUserModal()">&times;</button>
        </div>
        <form action="<?= url('departments/') ?><?= $department['id'] ?>/users" method="POST">
            <?= csrf_field() ?>
            <div class="form-group">
                <label>Usuário</label>
                <select name="user_id" class="form-control" required>
                    <option value="">Selecione...</option>
                    <?php foreach ($allUsers as $user): ?>
                        <option value="<?= $user['id'] ?>"><?= e($user['name']) ?> (<?= e($user['email']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>
                    <input type="checkbox" name="is_manager" value="1">
                    Gestor do departamento
                </label>
            </div>
            <button type="submit" class="btn btn-primary">Adicionar</button>
        </form>
    </div>
</div>

<script>
function openEditModal() { document.getElementById('editModal').style.display = 'flex'; }
function closeEditModal() { document.getElementById('editModal').style.display = 'none'; }
function openAddUserModal() { document.getElementById('addUserModal').style.display = 'flex'; }
function closeAddUserModal() { document.getElementById('addUserModal').style.display = 'none'; }
</script>
