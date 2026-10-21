<?php
/**
 * Relatório de compliance do SLA.
 *
 * @var string $period
 * @var int $days
 * @var array $compliance ['response' => [...], 'resolution' => [...]]
 * @var array $agentBreakdown
 * @var array $departmentBreakdown
 * @var array $inboxBreakdown
 * @var array $violated
 */
$human = function ($sec): string {
    if ($sec === null) return '—';
    $sec = (int) $sec;
    if ($sec < 60) return $sec . 's';
    if ($sec < 3600) return (int) round($sec / 60) . ' min';
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    return $m > 0 ? $h . 'h' . $m : $h . 'h';
};
$pct = function ($v): string {
    return $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 1, ',', '.'), '0'), ',') . '%';
};
$statusLabels = ['new' => 'Novo', 'open' => 'Aberto', 'waiting_customer' => 'Em atendimento', 'waiting_internal' => 'Aguard. Interno', 'resolved' => 'Resolvido', 'closed' => 'Fechado', 'spam' => 'Spam'];
?>
<div class="reports-page">
    <div class="reports-header">
        <div class="reports-header-left">
            <a href="<?= url('reports') ?>" class="reports-back" title="Voltar">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
        </div>
        <form method="GET" class="reports-period-form">
            <label class="reports-period-label">Período:</label>
            <select name="period" class="form-control form-control-sm" onchange="this.form.submit()">
                <option value="7" <?= $period === '7' ? 'selected' : '' ?>>Últimos 7 dias</option>
                <option value="30" <?= $period === '30' ? 'selected' : '' ?>>Últimos 30 dias</option>
                <option value="90" <?= $period === '90' ? 'selected' : '' ?>>Últimos 90 dias</option>
                <option value="all" <?= $period === 'all' ? 'selected' : '' ?>>Todo período</option>
            </select>
        </form>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon stat-icon-success"><i class="fas fa-bolt"></i></div>
            <div class="stat-info">
                <span class="stat-value"><?= $pct($compliance['response']['percent'] ?? null) ?></span>
                <span class="stat-label">1ª resposta no prazo</span>
                <span class="stat-sub"><?= (int) ($compliance['response']['breached'] ?? 0) ?> de <?= (int) ($compliance['response']['measured'] ?? 0) ?> furaram</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon stat-icon-info"><i class="fas fa-flag-checkered"></i></div>
            <div class="stat-info">
                <span class="stat-value"><?= $pct($compliance['resolution']['percent'] ?? null) ?></span>
                <span class="stat-label">Resolução no prazo</span>
                <span class="stat-sub"><?= (int) ($compliance['resolution']['breached'] ?? 0) ?> de <?= (int) ($compliance['resolution']['measured'] ?? 0) ?> furaram</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon stat-icon-danger"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="stat-info">
                <span class="stat-value"><?= count($violated) ?></span>
                <span class="stat-label">Conversas com violação</span>
                <span class="stat-sub">no período selecionado</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon stat-icon-primary"><i class="fas fa-stopwatch"></i></div>
            <div class="stat-info">
                <span class="stat-value"><?= (int) ($compliance['response']['measured'] ?? 0) ?></span>
                <span class="stat-label">Conversas medidas</span>
                <span class="stat-sub">com SLA registrado</span>
            </div>
        </div>
    </div>

    <div class="dashboard-grid" style="grid-template-columns:1fr 1fr">
        <?php
        $breaks = [
            ['titulo' => 'Por Atendente', 'icone' => 'fa-user', 'dados' => $agentBreakdown],
            ['titulo' => 'Por Departamento', 'icone' => 'fa-users', 'dados' => $departmentBreakdown],
            ['titulo' => 'Por Caixa de Entrada', 'icone' => 'fa-inbox', 'dados' => $inboxBreakdown],
        ];
        foreach ($breaks as $b):
        ?>
            <div class="card chart-card">
                <div class="card-header">
                    <h3><i class="fas <?= $b['icone'] ?>" style="color:var(--primary)"></i> <?= e($b['titulo']) ?></h3>
                </div>
                <div class="card-body">
                    <?php if (empty($b['dados'])): ?>
                        <div class="empty-state"><p>Nenhum dado no período.</p></div>
                    <?php else: ?>
                        <?php $max = max(array_column($b['dados'], 'total')) ?: 1; ?>
                        <?php foreach ($b['dados'] as $row): ?>
                            <?php $p = $row['percent'] === null ? 0 : (float) $row['percent']; ?>
                            <div class="hbar-row">
                                <div class="hbar-top">
                                    <span class="dept-bar-name"><?= e($row['label']) ?></span>
                                    <span class="hbar-pct"><?= $pct($row['percent']) ?></span>
                                    <span class="dept-bar-count"><?= (int) $row['breached'] ?>/<?= (int) $row['total'] ?></span>
                                </div>
                                <div class="dept-bar-track">
                                    <div class="dept-bar-fill" style="width:<?= round((int) $row['total'] / $max * 100) ?>%;background:<?= $p >= 90 ? 'var(--success)' : ($p >= 70 ? 'var(--warning)' : 'var(--danger)') ?>"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card" style="margin-top:16px">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:10px">
            <h3><i class="fas fa-list" style="color:var(--primary)"></i> Conversas com violação</h3>
            <?php if (!empty($violated)): ?>
                <a href="<?= url('reports/sla/csv') ?>?period=<?= e($period) ?>" class="btn btn-sm btn-outline" style="padding:6px 12px;border-radius:8px">
                    <i class="fas fa-file-csv"></i> Exportar CSV
                </a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (empty($violated)): ?>
                <div class="empty-state"><p>Nenhuma violação no período. 🎉</p></div>
            <?php else: ?>
                <div style="overflow-x:auto">
                    <table class="table" style="width:100%;font-size:13px">
                        <thead>
                            <tr>
                                <th>Protocolo</th>
                                <th>Contato</th>
                                <th>Responsável</th>
                                <th>Departamento</th>
                                <th>1ª resposta</th>
                                <th>Resolução</th>
                                <th>Aberta em</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($violated as $v): ?>
                            <tr>
                                <td><a href="<?= url('inbox') ?>?conv=<?= (int) $v['id'] ?>">#<?= e(format_protocol((string) ($v['protocol'] ?? ''))) ?></a></td>
                                <td><?= e($v['contact_name'] ?? '') ?></td>
                                <td><?= e($v['assigned_user_name'] ?: '—') ?></td>
                                <td><?= e($v['department_name'] ?: '—') ?></td>
                                <td>
                                    <?php if (!empty($v['response_breached'])): ?>
                                        <span style="color:var(--danger);font-weight:600"><?= $human($v['response_seconds']) ?></span>
                                        <span style="color:var(--text-muted)">/ <?= $human($v['response_threshold']) ?></span>
                                    <?php else: ?>
                                        <span style="color:var(--success)"><?= $human($v['response_seconds']) ?></span>
                                        <span style="color:var(--text-muted)">/ <?= $human($v['response_threshold']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($v['resolution_breached'])): ?>
                                        <span style="color:var(--danger);font-weight:600"><?= $human($v['resolution_seconds']) ?></span>
                                        <span style="color:var(--text-muted)">/ <?= $human($v['resolution_threshold']) ?></span>
                                    <?php else: ?>
                                        <span style="color:var(--success)"><?= $human($v['resolution_seconds']) ?></span>
                                        <span style="color:var(--text-muted)">/ <?= $human($v['resolution_threshold']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="color:var(--text-muted)"><?= e(date('d/m/Y H:i', strtotime((string) $v['created_at']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
