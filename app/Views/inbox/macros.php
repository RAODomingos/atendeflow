<?php
/** @var array $macros */
/** @var array $departments */
/** @var array $tags */
?>
<div class="page-form">
    <div class="page-toolbar">
        <h2 class="page-title"><i class="fas fa-bolt"></i> Macros</h2>
    </div>
    <p class="form-hint" style="margin-bottom:20px">Macros aplicam uma resposta e ações (status, etiqueta, atribuição) em um clique no chat.</p>

    <div class="card">
        <div class="card-header"><h4><i class="fas fa-plus-circle"></i> Nova Macro</h4></div>
        <div class="card-body">
            <form method="POST" action="<?= url('macros') ?>" class="macros-form">
                <?= csrf_field() ?>
                <div class="form-row">
                    <div class="form-group col-4">
                        <label><i class="fas fa-heading"></i> Título</label>
                        <input type="text" name="title" class="form-control" required placeholder="Ex: Saudação inicial">
                    </div>
                    <div class="form-group col-4">
                        <label><i class="fas fa-layer-group"></i> Departamento</label>
                        <select name="department_id" class="form-control">
                            <option value="">Todos</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-4">
                        <label><i class="fas fa-tag"></i> Ação: status</label>
                        <select name="action_status" class="form-control">
                            <option value="">Nenhuma</option>
                            <option value="open">Aberto</option>
                            <option value="waiting_customer">Em atendimento</option>
                            <option value="resolved">Resolvido</option>
                            <option value="closed">Fechado</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-8">
                        <label><i class="fas fa-comment"></i> Conteúdo da mensagem</label>
                        <textarea name="content" class="form-control" rows="3" placeholder="Texto enviado ao aplicar a macro"></textarea>
                    </div>
                    <div class="form-group col-4">
                        <label><i class="fas fa-tags"></i> Ação: etiqueta</label>
                        <select name="action_tag_id" class="form-control">
                            <option value="">Nenhuma</option>
                            <?php foreach ($tags as $t): ?>
                                <option value="<?= $t['id'] ?>"><?= e($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-check" style="margin-top:8px">
                            <input class="form-check-input" type="checkbox" name="action_assign_me" id="am" value="1">
                            <label class="form-check-label" for="am">Atribuir a mim</label>
                        </div>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Criar Macro
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <div class="card-header"><h4><i class="fas fa-list"></i> Macros existentes</h4></div>
        <div class="card-body p-0">
            <?php if (empty($macros)): ?>
                <div class="empty-state"><p>Nenhuma macro criada.</p></div>
            <?php else: ?>
                <table class="table">
                    <thead><tr><th>Título</th><th>Conteúdo</th><th>Ações</th></tr></thead>
                    <tbody>
                    <?php foreach ($macros as $m):
                        $acts = $m['actions'] ? json_decode($m['actions'], true) : [];
                        $actLabels = [];
                        if (!empty($acts['status'])) $actLabels[] = 'Status: ' . $acts['status'];
                        if (!empty($acts['tag_id'])) $actLabels[] = 'Etiqueta #' . $acts['tag_id'];
                        if (!empty($acts['assign_me'])) $actLabels[] = 'Atribuir a mim';
                    ?>
                        <tr>
                            <td><strong><?= e($m['title']) ?></strong></td>
                            <td><?= e(truncate($m['content'] ?? '', 60)) ?></td>
                            <td><span class="badge badge-info"><?= e(implode(', ', $actLabels)) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>
