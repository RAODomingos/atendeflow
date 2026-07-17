<div class="departments-page">
    <div class="page-toolbar">
        <div>
            <h2 class="page-title" style="margin:0">
                <i class="fas fa-layer-group" style="color:var(--primary)"></i>
                Departamentos
            </h2>
            <p style="margin:4px 0 0;font-size:13px;color:var(--text-muted)">Organize sua equipe por setores</p>
        </div>
        <button class="btn btn-primary btn-sm" onclick="openCreateModal()" style="padding:8px 18px;border-radius:10px">
            <i class="fas fa-plus"></i> Novo Departamento
        </button>
    </div>

    <?php if (empty($departments)): ?>
        <div class="empty-state-enhanced" style="background:var(--bg-card);border-radius:12px;border:1px solid var(--border-color);padding:60px 24px">
            <div class="empty-icon"><i class="fas fa-layer-group"></i></div>
            <h3>Nenhum departamento</h3>
            <p>Crie departamentos para organizar sua equipe.</p>
            <button class="btn btn-primary mt-2" onclick="openCreateModal()" style="margin-top:16px">
                <i class="fas fa-plus"></i> Criar Departamento
            </button>
        </div>
    <?php else: ?>
        <div class="department-grid" style="padding:0">
            <?php foreach ($departments as $dept): ?>
                <a href="<?= url('departments/') ?><?= $dept['id'] ?>" class="department-card" style="transition:all 0.25s cubic-bezier(.4,0,.2,1)">
                    <div class="dept-color" style="background: <?= e($dept['color'] ?? '#4A90D9') ?>;height:6px"></div>
                    <div class="dept-info" style="padding:16px">
                        <h3 style="display:flex;align-items:center;gap:8px">
                            <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?= e($dept['color'] ?? '#4A90D9') ?>"></span>
                            <?= e($dept['name']) ?>
                        </h3>
                        <p style="font-size:13px;color:var(--secondary);margin-bottom:12px"><?= e(truncate($dept['description'] ?? 'Sem descrição', 80)) ?></p>
                        <div class="dept-stats" style="display:flex;gap:16px;font-size:12px;color:var(--text-muted);padding-top:8px;border-top:1px solid var(--border-color)">
                            <span><i class="fas fa-users" style="color:var(--primary)"></i> <?= $dept['user_count'] ?> usuários</span>
                            <span><i class="fas fa-comments" style="color:var(--warning)"></i> <?= $dept['open_conversations'] ?> abertos</span>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Create Modal -->
<div class="modal" id="createModal">
    <div class="modal-content" style="max-width:440px">
        <div class="modal-header">
            <h3><i class="fas fa-plus-circle" style="color:var(--primary)"></i> Novo Departamento</h3>
            <button class="modal-close" onclick="closeCreateModal()">&times;</button>
        </div>
        <form action="<?= url('departments/create') ?>" method="POST">
            <?= csrf_field() ?>
            <div class="modal-body">
                <div class="form-group">
                    <label>Nome</label>
                    <input type="text" name="name" class="form-control" required placeholder="Ex: Suporte Técnico">
                </div>
                <div class="form-group">
                    <label>Descrição</label>
                    <textarea name="description" rows="3" class="form-control" placeholder="Descreva a finalidade deste departamento"></textarea>
                </div>
                <div class="form-group">
                    <label>Cor</label>
                    <input type="color" name="color" class="form-control" value="#4A90D9" style="height:40px;padding:4px;cursor:pointer">
                </div>
            </div>
            <div class="modal-footer" style="justify-content:center">
                <button type="button" class="btn btn-outline" onclick="closeCreateModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Criar Departamento</button>
            </div>
        </form>
    </div>
</div>

<script>
function openCreateModal() { document.getElementById('createModal').style.display = 'flex'; }
function closeCreateModal() { document.getElementById('createModal').style.display = 'none'; }
document.getElementById('createModal')?.addEventListener('click', function(e) { if (e.target === this) this.style.display = 'none'; });
</script>
