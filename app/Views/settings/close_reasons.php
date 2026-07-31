<div class="settings-page">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px">
        <div>
            <h1 style="font-size:24px;font-weight:700;margin:0;display:flex;align-items:center;gap:10px">
                <i class="fas fa-clipboard-check" style="color:var(--primary);font-size:22px"></i>
                Motivos de Encerramento
            </h1>
            <p style="margin:4px 0 0;font-size:14px;color:var(--text-muted)">
                Defina os motivos que aparecem quando o atendente resolve, fecha ou marca como spam uma conversa.
            </p>
        </div>
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
            <form method="POST" action="<?= url('settings/close-reasons/create') ?>" style="display:grid;grid-template-columns:1fr 2fr 1fr 1fr auto;gap:10px;align-items:end">
                <?= csrf_field() ?>
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
                            <td style="text-align:right">
                                <form method="POST" action="<?= url('settings/close-reasons/' . $r['id'] . '/delete') ?>" style="display:inline" onsubmit="return confirm('Remover este motivo?')">
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
</div>
