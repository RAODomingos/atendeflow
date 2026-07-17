<div class="reports-csat">
    <div class="page-toolbar">
        <div class="reports-filters">
            <form method="GET" class="form-inline">
                <label for="period" class="mr-2">Período:</label>
                <select name="period" id="period" class="form-control form-control-sm" onchange="this.form.submit()">
                    <option value="7" <?= $period === '7' ? 'selected' : '' ?>>Últimos 7 dias</option>
                    <option value="30" <?= $period === '30' ? 'selected' : '' ?>>Últimos 30 dias</option>
                    <option value="90" <?= $period === '90' ? 'selected' : '' ?>>Últimos 90 dias</option>
                    <option value="all" <?= $period === 'all' ? 'selected' : '' ?>>Todo o período</option>
                </select>
            </form>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?= $total ?></div>
            <div class="stat-label">Avaliações</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $average !== null ? number_format($average, 2, ',', '.') : '—' ?></div>
            <div class="stat-label">Média (1 a 5)</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">
                <?php if ($total > 0): ?>
                    <?= number_format(($distribution[5] + $distribution[4]) / $total * 100, 0) ?>%
                <?php else: ?>—<?php endif; ?>
            </div>
            <div class="stat-label">Satisfeitos (4–5★)</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">
                <?php if ($total > 0): ?>
                    <?= number_format(($distribution[1] + $distribution[2]) / $total * 100, 0) ?>%
                <?php else: ?>—<?php endif; ?>
            </div>
            <div class="stat-label">Insatisfeitos (1–2★)</div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>Distribuição das notas</h3></div>
        <div class="card-body">
            <?php for ($i = 5; $i >= 1; $i--): ?>
                <?php $count = $distribution[$i]; $pct = $total > 0 ? ($count / $total * 100) : 0; ?>
                <div class="csat-bar-row">
                    <span class="csat-bar-label"><?= $i ?>★</span>
                    <div class="csat-bar-track">
                        <div class="csat-bar-fill" style="width: <?= $pct ?>%"></div>
                    </div>
                    <span class="csat-bar-count"><?= $count ?></span>
                </div>
            <?php endfor; ?>
        </div>
    </div>

    <div class="card mt-3">
        <div class="card-header"><h3>Avaliações recentes</h3></div>
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
                            <th>Nota</th>
                            <th>Comentário</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent as $r): ?>
                            <tr>
                                <td><?= format_datetime($r['created_at']) ?></td>
                                <td>
                                    <a href="<?= base_url('inbox/' . ((int) $r['conversation_id'])) ?>" target="_blank">
                                        #<?= (int) $r['conversation_id'] ?>
                                    </a>
                                </td>
                                <td><?= e($r['contact_name'] ?? '—') ?></td>
                                <td><?= e($r['channel_type'] ?? '—') ?></td>
                                <td>
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
