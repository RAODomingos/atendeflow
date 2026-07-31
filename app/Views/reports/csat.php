<div class="reports-page">
    <div class="reports-header">
        <div class="reports-header-left">
            <a href="<?= url('reports') ?>" class="reports-back" title="Voltar">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <div class="reports-header-icon reports-header-icon--warning"><i class="fas fa-star"></i></div>
            <div>
                <h1 class="reports-title">CSAT</h1>
                <p class="reports-subtitle">Satisfação do cliente com o atendimento</p>
            </div>
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
            <div class="stat-icon stat-icon-info"><i class="fas fa-poll"></i></div>
            <div class="stat-info">
                <span class="stat-value"><?= number_format($total) ?></span>
                <span class="stat-label">Avaliações</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon stat-icon-amber"><i class="fas fa-star"></i></div>
            <div class="stat-info">
                <span class="stat-value"><?= $average !== null ? number_format($average, 2, ',', '.') : '—' ?></span>
                <span class="stat-label">Média (1 a 5)</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon stat-icon-success"><i class="fas fa-smile"></i></div>
            <div class="stat-info">
                <span class="stat-value">
                    <?php if ($total > 0): ?>
                        <?= number_format(($distribution[5] + $distribution[4]) / $total * 100, 0) ?>%
                    <?php else: ?>—<?php endif; ?>
                </span>
                <span class="stat-label">Satisfeitos (4–5★)</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon stat-icon-danger"><i class="fas fa-frown"></i></div>
            <div class="stat-info">
                <span class="stat-value">
                    <?php if ($total > 0): ?>
                        <?= number_format(($distribution[1] + $distribution[2]) / $total * 100, 0) ?>%
                    <?php else: ?>—<?php endif; ?>
                </span>
                <span class="stat-label">Insatisfeitos (1–2★)</span>
            </div>
        </div>
    </div>

    <!-- Distribuição -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-chart-bar" style="color:var(--primary)"></i> Distribuição das notas</h3>
        </div>
        <div class="card-body">
            <?php for ($i = 5; $i >= 1; $i--): ?>
                <?php $count = $distribution[$i]; $pct = $total > 0 ? ($count / $total * 100) : 0; ?>
                <div class="csat-bar-row">
                    <span class="csat-bar-label"><?= $i ?>★</span>
                    <div class="csat-bar-track">
                        <div class="csat-bar-fill" style="width:<?= $pct ?>%"></div>
                    </div>
                    <span class="csat-bar-count"><?= $count ?></span>
                </div>
            <?php endfor; ?>
        </div>
    </div>

    <!-- CSAT por Atendente -->
    <?php if (!empty($agentCsat)): ?>
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-users" style="color:var(--primary)"></i> CSAT por Atendente</h3>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover">
                <thead>
                    <tr><th>Atendente</th><th class="text-center">Nota Média</th><th class="text-center">Avaliações</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($agentCsat as $ac): ?>
                    <tr>
                        <td><strong><?= e($ac['agent_name']) ?></strong></td>
                        <td class="text-center">
                            <span class="csat-stars">
                                <?php for ($s = 1; $s <= 5; $s++): ?>
                                    <i class="fas fa-star <?= $s <= round((float) $ac['avg_rating']) ? 'on' : '' ?>" style="font-size:12px"></i>
                                <?php endfor; ?>
                            </span>
                            <br><small class="text-muted"><?= number_format((float) $ac['avg_rating'], 2, ',', '.') ?></small>
                        </td>
                        <td class="text-center"><?= (int) $ac['total'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Avaliações recentes -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-clock" style="color:var(--primary)"></i> Avaliações recentes</h3>
        </div>
        <div class="card-body p-0">
            <?php if (empty($recent)): ?>
                <div class="empty-state"><p>Nenhuma avaliação registrada neste período.</p></div>
            <?php else: ?>
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Ticket</th>
                            <th>Cliente</th>
                            <th>Canal</th>
                            <th class="text-center">Nota</th>
                            <th>Comentário</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent as $r): ?>
                            <tr>
                                <td><?= format_datetime($r['created_at']) ?></td>
                                <td>
                                    <a href="<?= base_url('inbox/' . ((int) $r['conversation_id'])) ?>" target="_blank" class="text-primary">
                                        #<?= (int) $r['conversation_id'] ?>
                                    </a>
                                </td>
                                <td><?= e($r['contact_name'] ?? '—') ?></td>
                                <td><?= e($r['channel_type'] ?? '—') ?></td>
                                <td class="text-center">
                                    <span class="csat-stars">
                                        <?php for ($s = 1; $s <= 5; $s++): ?>
                                            <i class="fas fa-star <?= $s <= (int) $r['rating'] ? 'on' : '' ?>"></i>
                                        <?php endfor; ?>
                                    </span>
                                </td>
                                <td><?= e($r['comment'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>
