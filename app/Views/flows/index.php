<div class="flows-page">
    <div class="page-actions">
        <a href="<?= url('flows/create') ?>" class="btn btn-primary btn-sm" style="padding:8px 18px;border-radius:10px">
            <i class="fas fa-plus"></i> Novo Fluxo
        </a>
    </div>

    <div class="card" style="border-radius:12px;overflow:hidden">
        <div class="card-body p-0">
            <?php if (empty($flows)): ?>
                <div class="empty-state-enhanced" style="padding:80px 24px">
                    <div class="empty-icon"><i class="fas fa-diagram-project"></i></div>
                    <h3>Nenhum fluxo de atendimento</h3>
                    <p>Crie fluxos para automatizar a triagem dos seus clientes.</p>
                    <a href="<?= url('flows/create') ?>" class="btn btn-primary mt-2" style="margin-top:16px">
                        <i class="fas fa-plus"></i> Criar Primeiro Fluxo
                    </a>
                </div>
            <?php else: ?>
                <div class="flow-list" style="padding:16px">
                    <?php foreach ($flows as $flow): ?>
                        <div class="flow-card" style="transition:all 0.25s cubic-bezier(.4,0,.2,1);position:relative;overflow:hidden">
                            <div style="position:absolute;top:0;left:0;right:0;height:4px;background:<?= $flow['is_active'] ? 'var(--success)' : 'var(--secondary)' ?>;opacity:0.6"></div>
                            <div class="flow-card-header">
                                <div class="flow-status <?= $flow['is_active'] ? 'active' : 'inactive' ?>" style="margin-top:6px"></div>
                                <div class="flow-info" style="flex:1">
                                    <h3 style="display:flex;align-items:center;gap:8px">
                                        <?= e($flow['name']) ?>
                                        <?php if ($flow['is_active']): ?>
                                            <span class="badge badge-success" style="font-size:10px">Ativo</span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary" style="font-size:10px">Inativo</span>
                                        <?php endif; ?>
                                    </h3>
                                    <p><?= e(truncate($flow['description'] ?? 'Sem descrição', 100)) ?></p>
                                </div>
                            </div>
                            <div class="flow-card-meta" style="display:flex;gap:16px;flex-wrap:wrap;margin:12px 0;padding:8px 0;border-top:1px solid var(--border-color);border-bottom:1px solid var(--border-color)">
                                <span style="display:flex;align-items:center;gap:4px;font-size:12px;color:var(--text-muted)">
                                    <i class="fas fa-code-branch" style="color:var(--primary)"></i> <?= $flow['node_count'] ?> nós
                                </span>
                                <span style="display:flex;align-items:center;gap:4px;font-size:12px;color:var(--text-muted)">
                                    <i class="fas fa-globe" style="color:var(--info)"></i> <?= e($flow['channel_scope']) ?>
                                </span>
                                <span style="display:flex;align-items:center;gap:4px;font-size:12px;color:var(--text-muted)">
                                    <i class="fas fa-user" style="color:var(--secondary)"></i> <?= e($flow['created_by_name']) ?>
                                </span>
                            </div>
                            <div class="flow-card-actions" style="display:flex;gap:6px;justify-content:flex-end;padding-top:8px">
                                <a href="<?= url('flows/') ?><?= $flow['id'] ?>/edit" class="btn btn-sm btn-outline" title="Editar">
                                    <i class="fas fa-edit"></i> Editar
                                </a>
                                <?php if (!$flow['is_active']): ?>
                                    <form action="<?= url('flows/') ?><?= $flow['id'] ?>/publish" method="POST" style="display:inline">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-success">
                                            <i class="fas fa-check"></i> Publicar
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <form action="<?= url('flows/') ?><?= $flow['id'] ?>/duplicate" method="POST" style="display:inline">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm btn-outline" title="Duplicar">
                                        <i class="fas fa-copy"></i>
                                    </button>
                                </form>
                                <form action="<?= url('flows/') ?><?= $flow['id'] ?>/delete" method="POST" style="display:inline"
                                      data-confirm="Excluir este fluxo?">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm btn-danger" title="Excluir">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
