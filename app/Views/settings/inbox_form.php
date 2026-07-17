<div class="channels-page">
    <div class="page-toolbar">
        <h2 class="page-title"><i class="fas fa-inbox"></i> <?= e($title) ?></h2>
        <a href="<?= url('inboxes') ?>" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
    </div>

    <div class="card">
        <div class="card-body">
            <form action="<?= url($inbox ? 'inboxes/' . $inbox['id'] : 'inboxes') ?>" method="POST">
                <?= csrf_field() ?>

                <div class="form-group">
                    <label><i class="fas fa-tag"></i> Nome da caixa *</label>
                    <input type="text" name="name" class="form-control" required
                           value="<?= e($inbox['name'] ?? '') ?>" placeholder="Ex.: Suporte Avançado">
                </div>

                <div class="form-group">
                    <label><i class="fas fa-cog"></i> Tipo de caixa</label>
                    <select name="type" class="form-control" id="inboxType">
                        <option value="department" <?= ($inbox['type'] ?? 'department') === 'department' ? 'selected' : '' ?>>Departamento (membros do departamento)</option>
                        <option value="personal" <?= ($inbox['type'] ?? '') === 'personal' ? 'selected' : '' ?>>Pessoal (só o dono + admin)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-network-wired"></i> Canais vinculados</label>
                    <p class="form-hint">As conversas que chegarem por estes canais entram nesta caixa.</p>
                    <div class="inbox-check-grid">
                        <?php foreach ($channels as $ch): ?>
                            <label class="inbox-check-item">
                                <input type="checkbox" name="channels[]" value="<?= $ch['id'] ?>"
                                    <?= isset($linkedChannels) && in_array($ch['id'], $linkedChannels) ? 'checked' : '' ?>>
                                <i class="<?= channel_icon($ch['type']) ?>"></i> <?= e($ch['name']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-layer-group"></i> Departamentos com acesso</label>
                    <div class="inbox-check-grid">
                        <?php foreach ($departments as $d): ?>
                            <label class="inbox-check-item">
                                <input type="checkbox" name="departments[]" value="<?= $d['id'] ?>"
                                    <?= isset($linkedDepartments) && in_array($d['id'], $linkedDepartments) ? 'checked' : '' ?>>
                                <?= e($d['name']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-users"></i> Usuários com acesso</label>
                    <div class="inbox-check-grid">
                        <?php foreach ($users as $u): ?>
                            <label class="inbox-check-item">
                                <input type="checkbox" name="users[]" value="<?= $u['id'] ?>"
                                    <?= isset($linkedUsers) && in_array($u['id'], $linkedUsers) ? 'checked' : '' ?>>
                                <?= e($u['name']) ?> <span class="text-muted">(<?= e($u['role']) ?>)</span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> <?= $inbox ? 'Salvar' : 'Criar caixa' ?>
                    </button>
                    <a href="<?= url('inboxes') ?>" class="btn btn-outline">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</div>
