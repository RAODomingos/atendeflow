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
        <a href="<?= url('reports/timeline') ?>" class="btn btn-sm btn-outline" title="Listar as conversas e exportar em PDF/CSV">
            <i class="fas fa-list"></i> Listagem + Exportar PDF/CSV
        </a>
    </div>

    <!-- Tendência -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-chart-line" style="color:var(--primary)"></i> Tendência</h3>
        </div>
        <div class="card-body">
            <?php if (empty($trendValues)): ?>
                <div class="empty-state"><p>Nenhum dado no período.</p></div>
            <?php else: ?>
                <?php $maxVal = max($trendValues) ?: 1; ?>
                <div class="trend-chart">
                    <?php foreach ($trendValues as $i => $val): ?>
                        <div class="trend-bar-item">
                            <span class="trend-bar-val"><?= $val ?></span>
                            <div class="trend-bar-col">
                                <div class="trend-bar-fill" style="height:<?= ($val / $maxVal) * 100 ?>%"></div>
                            </div>
                            <span class="trend-bar-label"><?= $trendLabels[$i] ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="dashboard-grid" style="grid-template-columns:1fr 1fr">
        <!-- Por Status -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-tasks" style="color:var(--primary)"></i> Por Status</h3>
            </div>
            <div class="card-body">
                <?php if (empty($statusData)): ?>
                    <div class="empty-state"><p>Nenhum dado.</p></div>
                <?php else: ?>
                    <?php $stTotal = array_sum(array_column($statusData, 'total')); ?>
                    <?php
                    $colors = [
                        'new' => 'var(--info)',
                        'open' => 'var(--primary)',
                        'waiting_customer' => 'var(--warning)',
                        'waiting_internal' => '#b45309',
                        'resolved' => 'var(--success)',
                        'closed' => '#6c757d',
                        'spam' => 'var(--danger)',
                    ];
                    $labels = [
                        'new' => 'Novos', 'open' => 'Abertos',
                        'waiting_customer' => 'Agu. cliente', 'waiting_internal' => 'Agu. interno',
                        'resolved' => 'Resolvidos', 'closed' => 'Fechados', 'spam' => 'Spam',
                    ];
                    ?>
                    <?php foreach ($statusData as $st): ?>
                        <?php $pct = $stTotal > 0 ? round($st['total'] / $stTotal * 100) : 0; ?>
                        <div class="hbar-row">
                            <div class="hbar-top">
                                <span class="hbar-dot" style="background:<?= $colors[$st['status']] ?? '#6c757d' ?>"></span>
                                <span class="dept-bar-name"><?= $labels[$st['status']] ?? e(ucfirst(str_replace('_', ' ', $st['status']))) ?></span>
                                <span class="hbar-pct"><?= $pct ?>%</span>
                                <span class="dept-bar-count"><?= $st['total'] ?></span>
                            </div>
                            <div class="dept-bar-track">
                                <div class="dept-bar-fill" style="width:<?= $pct ?>%;background:<?= $colors[$st['status']] ?? '#6c757d' ?>"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Por Canal -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-share-alt" style="color:var(--primary)"></i> Por Canal</h3>
            </div>
            <div class="card-body">
                <?php if (empty($channelData)): ?>
                    <div class="empty-state"><p>Nenhum dado.</p></div>
                <?php else: ?>
                    <?php $chTotal = array_sum(array_column($channelData, 'total')); ?>
                    <?php foreach ($channelData as $ch): ?>
                        <?php $pct = $chTotal > 0 ? round($ch['total'] / $chTotal * 100) : 0; ?>
                        <div class="hbar-row">
                            <div class="hbar-top">
                                <i class="<?= channel_icon($ch['type']) ?> hbar-icon"></i>
                                <span class="dept-bar-name"><?= e(ucfirst($ch['type'])) ?></span>
                                <span class="hbar-pct"><?= $pct ?>%</span>
                                <span class="dept-bar-count"><?= $ch['total'] ?></span>
                            </div>
                            <div class="dept-bar-track">
                                <div class="dept-bar-fill" style="width:<?= $pct ?>%;background:var(--primary)"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="dashboard-grid" style="grid-template-columns:1fr 1fr">
        <!-- Por Departamento -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-layer-group" style="color:var(--primary)"></i> Por Departamento</h3>
            </div>
            <div class="card-body">
                <?php if (empty($deptData)): ?>
                    <div class="empty-state"><p>Nenhum dado.</p></div>
                <?php else: ?>
                    <?php $dpMax = max(array_column($deptData, 'total')) ?: 1; ?>
                    <?php foreach ($deptData as $dp): ?>
                        <div class="hbar-row">
                            <div class="hbar-top">
                                <span class="hbar-dot" style="background:<?= e($dp['color'] ?? '#6c757d') ?>"></span>
                                <span class="dept-bar-name"><?= e($dp['name']) ?></span>
                                <span class="hbar-pct"><?= round($dp['total'] / $dpMax * 100) ?>%</span>
                                <span class="dept-bar-count"><?= $dp['total'] ?></span>
                            </div>
                            <div class="dept-bar-track">
                                <div class="dept-bar-fill" style="width:<?= ($dp['total'] / $dpMax) * 100 ?>%;background:<?= e($dp['color'] ?? '#6c757d') ?>"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Top Contatos -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-address-book" style="color:var(--primary)"></i> Top Contatos</h3>
            </div>
            <div class="card-body p-0">
                <?php if (empty($topContacts)): ?>
                    <div class="empty-state"><p>Nenhum contato com múltiplas conversas.</p></div>
                <?php else: ?>
                    <table class="table table-hover">
                        <thead><tr><th>Nome</th><th>Conversas</th></tr></thead>
                        <tbody>
                            <?php foreach ($topContacts as $ct): ?>
                            <tr>
                                <td>
                                    <?= e($ct['name']) ?>
                                    <br><small class="text-muted"><?= e($ct['email'] ?: $ct['phone'] ?: '') ?></small>
                                </td>
                                <td><strong><?= $ct['convos'] ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
