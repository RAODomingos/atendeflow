<div class="channels-page">
    <div class="page-toolbar">
        <h2 class="page-title"><i class="fas fa-inbox"></i> Caixas de Entrada</h2>
        <a href="<?= url('inboxes/create') ?>" class="btn btn-primary btn-sm">
            <i class="fas fa-plus"></i> Nova Caixa
        </a>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <?php if (empty($inboxes)): ?>
                <div class="empty-state"><p>Nenhuma caixa de entrada cadastrada.</p></div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Tipo</th>
                            <th>Canais vinculados</th>
                            <th>Departamentos</th>
                            <th>Usuários</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($inboxes as $ib): ?>
                            <tr>
                                <td><strong><?= e($ib['name']) ?></strong></td>
                                <td>
                                    <?php if ($ib['type'] === 'department'): ?>
                                        <span class="badge badge-info">Departamento</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">Pessoal</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (empty($ib['channels'])): ?>
                                        <span class="text-muted">—</span>
                                    <?php else: ?>
                                        <?php foreach ($ib['channels'] as $ch): ?>
                                            <span class="badge badge-light"><i class="<?= channel_icon($ch['type']) ?>"></i> <?= e($ch['name']) ?></span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (empty($ib['departments'])): ?>
                                        <span class="text-muted">—</span>
                                    <?php else: ?>
                                        <?php foreach ($ib['departments'] as $d): ?>
                                            <span class="badge badge-light"><?= e($d['name']) ?></span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (empty($ib['users'])): ?>
                                        <span class="text-muted">—</span>
                                    <?php else: ?>
                                        <?php foreach ($ib['users'] as $u): ?>
                                            <span class="badge badge-light"><?= e($u['name']) ?></span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="action-cell">
                                    <a href="<?= url('inboxes/' . $ib['id'] . '/edit') ?>" class="btn btn-sm btn-outline" title="Editar"><i class="fas fa-edit"></i></a>
                                    <form action="<?= url('inboxes/' . $ib['id'] . '/delete') ?>" method="POST" style="display:inline" onsubmit="return confirm('Remover esta caixa? As conversas nela ficarão sem caixa.')">
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

    <p class="text-muted mt-2">
        <i class="fas fa-info-circle"></i>
        Cada caixa pode ter vários canais. As conversas que chegam por um canal vinculado entram automaticamente na caixa.
        Caixas <strong>pessoais</strong> só são vistas por quem as criou e por administradores.
    </p>
</div>
