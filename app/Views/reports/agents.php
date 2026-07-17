<div class="reports-page">
    <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px">
        <div>
            <h1 style="font-size:24px;font-weight:700;margin:0;display:flex;align-items:center;gap:10px">
                <i class="fas fa-users" style="color:var(--primary)"></i> Performance dos Atendentes
            </h1>
            <p style="margin:4px 0 0;font-size:14px;color:var(--text-muted)">Métricas individuais dos atendentes</p>
        </div>
    </div>

    <?php if (empty($agents)): ?>
        <div class="card">
            <div class="card-body">
                <div class="empty-state"><i class="fas fa-users"></i><h3>Nenhum atendente ativo</h3><p>Não há dados de performance disponíveis.</p></div>
            </div>
        </div>
    <?php else: ?>
        <!-- Sumário -->
        <?php
        $totalActive = array_sum(array_column($agents, 'active_convos'));
        $totalMsgs = array_sum(array_column($agents, 'total_messages'));
        $totalResolved = array_sum(array_column($agents, 'total_resolved'));
        $avgCsatOverall = 0;
        $csatCount = 0;
        foreach ($agents as $a) {
            if ($a['avg_csat'] !== null) {
                $avgCsatOverall += (float) $a['avg_csat'];
                $csatCount++;
            }
        }
        $avgCsatOverall = $csatCount > 0 ? round($avgCsatOverall / $csatCount, 2) : null;
        ?>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon stat-icon-primary"><i class="fas fa-headset"></i></div>
                <div class="stat-info">
                    <span class="stat-value"><?= count($agents) ?></span>
                    <span class="stat-label">Atendentes</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon stat-icon-warning"><i class="fas fa-comments"></i></div>
                <div class="stat-info">
                    <span class="stat-value"><?= $totalActive ?></span>
                    <span class="stat-label">Ativos agora</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon stat-icon-success"><i class="fas fa-check-circle"></i></div>
                <div class="stat-info">
                    <span class="stat-value"><?= $totalResolved ?></span>
                    <span class="stat-label">Resolvidos (total)</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#fff8e1;color:#f57f17"><i class="fas fa-star"></i></div>
                <div class="stat-info">
                    <span class="stat-value"><?= $avgCsatOverall !== null ? number_format($avgCsatOverall, 2, ',', '.') : '—' ?></span>
                    <span class="stat-label">CSAT médio geral</span>
                </div>
            </div>
        </div>

        <!-- Tabela detalhada -->
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-list" style="color:var(--primary)"></i> Detalhamento</h3></div>
            <div class="card-body p-0">
                <div style="overflow-x:auto">
                    <table class="table table-hover" style="min-width:900px">
                        <thead>
                            <tr>
                                <th>Atendente</th>
                                <th>Função</th>
                                <th style="text-align:center">Ativos</th>
                                <th style="text-align:center">Msgs (7d)</th>
                                <th style="text-align:center">Total Msgs</th>
                                <th style="text-align:center">Resolv. (30d)</th>
                                <th style="text-align:center">Total Resolv.</th>
                                <th style="text-align:center">Tempo Resp.</th>
                                <th style="text-align:center">CSAT</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($agents as $a): ?>
                            <tr>
                                <td><strong><?= e($a['name']) ?></strong></td>
                                <td><span class="badge badge-<?= $a['role'] === 'admin' ? 'danger' : ($a['role'] === 'manager' ? 'warning' : 'info') ?>"><?= ucfirst(e($a['role'])) ?></span></td>
                                <td style="text-align:center"><?= $a['active_convos'] ?></td>
                                <td style="text-align:center"><?= $a['msgs_7d'] ?></td>
                                <td style="text-align:center"><?= $a['total_messages'] ?></td>
                                <td style="text-align:center"><?= $a['resolved_30d'] ?></td>
                                <td style="text-align:center"><?= $a['total_resolved'] ?></td>
                                <td style="text-align:center"><?= $a['avg_response_min'] !== null ? $a['avg_response_min'] . 'min' : '—' ?></td>
                                <td style="text-align:center">
                                    <?php if ($a['avg_csat'] !== null): ?>
                                        <span class="csat-stars">
                                            <?php for ($s = 1; $s <= 5; $s++): ?>
                                                <i class="fas fa-star <?= $s <= round((float) $a['avg_csat']) ? 'on' : '' ?>" style="font-size:12px"></i>
                                            <?php endfor; ?>
                                        </span>
                                        <br><small style="color:var(--text-muted)"><?= number_format((float) $a['avg_csat'], 2, ',', '.') ?></small>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
