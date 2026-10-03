<div class="settings-page">
    <?php $subPage = 'close-reasons'; require __DIR__ . '/_tabs.php'; ?>

    <div class="page-actions">
        <a href="<?= url('settings') ?>" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Voltar</a>
    </div>

    <?php if (!empty($_SESSION['flash_success'] ?? null)): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= e($_SESSION['flash_success']) ?></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['flash_error'] ?? null)): ?>
        <div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> <?= e($_SESSION['flash_error']) ?></div>
    <?php endif; ?>

    <div class="card" style="margin-bottom:20px">
        <div class="card-header">
            <h3><i class="fas fa-plus"></i> Novo motivo</h3>
        </div>
        <div class="card-body">
            <form method="POST" action="<?= url('settings/close-reasons/create') ?>">
                <?= csrf_field() ?>
                <div style="display:grid;grid-template-columns:1fr 2fr 1fr 1fr auto;gap:10px;align-items:end">
                    <div class="form-group" style="margin:0">
                        <label style="font-size:12px">Código</label>
                        <input type="text" name="code" class="form-control" placeholder="ex: resolvido" pattern="[a-z0-9_]+" required>
                    </div>
                    <div class="form-group" style="margin:0">
                        <label style="font-size:12px">Rótulo</label>
                        <input type="text" name="label" class="form-control" placeholder="Ex: Resolvido" required>
                    </div>
                    <div class="form-group" style="margin:0">
                        <label style="font-size:12px">Ícone (FontAwesome)</label>
                        <input type="text" name="icon" class="form-control" placeholder="fa-check-circle" value="fa-tag">
                    </div>
                    <div class="form-group" style="margin:0">
                        <label style="font-size:12px">Cor</label>
                        <input type="color" name="color" class="form-control" value="#2e7d32" style="height:42px;padding:4px">
                    </div>
                    <button type="submit" class="btn btn-primary" style="height:42px"><i class="fas fa-plus"></i> Adicionar</button>
                </div>
                <div style="display:grid;grid-template-columns:1fr 120px;gap:10px;align-items:end;margin-top:10px">
                    <div class="form-group" style="margin:0">
                        <label style="font-size:12px">Descrição</label>
                        <input type="text" name="description" class="form-control" placeholder="Ex: Solicitação atendida com sucesso." maxlength="255">
                    </div>
                    <div class="form-group" style="margin:0">
                        <label style="font-size:12px">Ordem</label>
                        <input type="number" name="sort_order" class="form-control" value="0" min="0">
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-list"></i> Motivos cadastrados (<?= count($reasons) ?>)</h3>
        </div>
        <div class="card-body p-0">
            <?php if (empty($reasons)): ?>
                <div class="empty-state" style="padding:30px"><i class="fas fa-folder-open"></i><p>Nenhum motivo cadastrado ainda.</p></div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th style="width:50px"></th>
                            <th>Rótulo</th>
                            <th>Descrição</th>
                            <th style="width:80px">Ordem</th>
                            <th style="width:80px">Status</th>
                            <th style="width:120px;text-align:right">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reasons as $r): ?>
                        <tr>
                            <td>
                                <span style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:8px;background:<?= e($r['color']) ?>20;color:<?= e($r['color']) ?>">
                                    <i class="fas <?= e($r['icon'] ?: 'fa-tag') ?>"></i>
                                </span>
                            </td>
                            <td>
                                <strong><?= e($r['label']) ?></strong>
                                <div style="font-size:11px;color:var(--text-muted);font-family:ui-monospace,monospace"><?= e($r['code']) ?></div>
                            </td>
                            <td style="font-size:12.5px;color:var(--text-secondary)"><?= e($r['description'] ?? '—') ?></td>
                            <td><?= (int) $r['sort_order'] ?></td>
                            <td>
                                <?php if ($r['is_active']): ?>
                                    <span class="chip chip-success" style="font-size:11px;padding:2px 8px">Ativo</span>
                                <?php else: ?>
                                    <span class="chip chip-neutral" style="font-size:11px;padding:2px 8px">Inativo</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right;white-space:nowrap">
                                <button type="button" class="btn btn-sm btn-outline" title="Editar"
                                    data-id="<?= (int) $r['id'] ?>"
                                    data-code="<?= e($r['code']) ?>"
                                    data-label="<?= e($r['label']) ?>"
                                    data-description="<?= e($r['description'] ?? '') ?>"
                                    data-icon="<?= e($r['icon'] ?? 'fa-tag') ?>"
                                    data-color="<?= e($r['color'] ?? '#6c757d') ?>"
                                    data-sort="<?= (int) $r['sort_order'] ?>"
                                    data-active="<?= !empty($r['is_active']) ? 1 : 0 ?>"
                                    onclick="editCloseReason(this)">
                                    <i class="fas fa-pen"></i>
                                </button>
                                <form method="POST" action="<?= url('settings/close-reasons/' . $r['id'] . '/delete') ?>" style="display:inline" data-confirm="Remover este motivo?">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm btn-outline" style="color:var(--danger);border-color:var(--danger)" title="Remover">
                                        <i class="fas fa-trash"></i>
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

<div id="editCloseReasonModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.4);z-index:9999;align-items:center;justify-content:center" onclick="if(event.target===this)this.style.display='none'">
    <div style="background:var(--bg-panel);border-radius:var(--radius-lg);padding:28px;width:520px;max-width:94vw;box-shadow:var(--shadow-md);max-height:92vh;overflow:auto">
        <h3 style="margin:0 0 6px;font-size:18px;font-weight:700">Editar motivo</h3>
        <p class="text-muted" style="margin:0 0 20px;font-size:12.5px">Alterar o código atualiza também as conversas que usavam o código antigo.</p>
        <form method="POST" action="" id="editCloseReasonForm">
            <?= csrf_field() ?>
            <div style="display:grid;grid-template-columns:1fr 2fr;gap:10px">
                <div class="form-group" style="margin:0">
                    <label>Código *</label>
                    <input type="text" name="code" id="editReasonCode" class="form-control" required maxlength="50" pattern="[a-z0-9_]+" title="Letras minúsculas, números e _">
                </div>
                <div class="form-group" style="margin:0">
                    <label>Rótulo *</label>
                    <input type="text" name="label" id="editReasonLabel" class="form-control" required maxlength="100">
                </div>
            </div>
            <div class="form-group">
                <label>Descrição</label>
                <input type="text" name="description" id="editReasonDescription" class="form-control" maxlength="255" placeholder="Ex: Solicitação atendida com sucesso.">
            </div>
            <div style="display:grid;grid-template-columns:1fr 140px 110px;gap:10px">
                <div class="form-group" style="margin:0">
                    <label>Ícone (FontAwesome)</label>
                    <input type="text" name="icon" id="editReasonIcon" class="form-control" placeholder="fa-tag">
                </div>
                <div class="form-group" style="margin:0">
                    <label>Cor</label>
                    <input type="color" name="color" id="editReasonColor" class="form-control" style="height:42px;padding:4px">
                </div>
                <div class="form-group" style="margin:0">
                    <label>Ordem</label>
                    <input type="number" name="sort_order" id="editReasonOrder" class="form-control" min="0">
                </div>
            </div>
            <div class="form-group" style="margin-top:12px">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                    <input type="checkbox" name="is_active" id="editReasonActive" value="1" style="width:18px;height:18px;accent-color:var(--primary)">
                    Motivo ativo (aparece no atendimento)
                </label>
            </div>
            <div style="display:flex;gap:10px;margin-top:20px;justify-content:flex-end">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('editCloseReasonModal').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar alterações</button>
            </div>
        </form>
    </div>
</div>

<script>
function editCloseReason(btn) {
    var d = btn.dataset;
    document.getElementById('editCloseReasonForm').action = '<?= url('settings/close-reasons/') ?>' + d.id + '/update';
    document.getElementById('editReasonCode').value = d.code || '';
    document.getElementById('editReasonLabel').value = d.label || '';
    document.getElementById('editReasonDescription').value = d.description || '';
    document.getElementById('editReasonIcon').value = d.icon || 'fa-tag';
    document.getElementById('editReasonColor').value = /^#[0-9a-fA-F]{6}$/.test(d.color || '') ? d.color : '#6c757d';
    document.getElementById('editReasonOrder').value = d.sort || 0;
    document.getElementById('editReasonActive').checked = d.active === '1';
    document.getElementById('editCloseReasonModal').style.display = 'flex';
}
</script>
</div>
