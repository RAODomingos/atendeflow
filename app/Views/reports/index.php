<div class="reports-page">
    <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px">
        <div>
            <h1 style="font-size:24px;font-weight:700;margin:0;display:flex;align-items:center;gap:10px">
                <i class="fas fa-chart-bar" style="color:var(--primary)"></i> Relatórios
            </h1>
            <p style="margin:4px 0 0;font-size:14px;color:var(--text-muted)">Visão geral do período</p>
        </div>
        <form method="GET" class="form-inline" style="display:flex;gap:8px;align-items:center">
            <label style="font-size:13px;color:var(--text-muted)">Período:</label>
            <select name="period" class="form-control form-control-sm" style="width:auto" onchange="this.form.submit()">
                <option value="7" <?= $period === '7' ? 'selected' : '' ?>>Últimos 7 dias</option>
                <option value="30" <?= $period === '30' ? 'selected' : '' ?>>Últimos 30 dias</option>
                <option value="90" <?= $period === '90' ? 'selected' : '' ?>>Últimos 90 dias</option>
                <option value="all" <?= $period === 'all' ? 'selected' : '' ?>>Todo período</option>
            </select>
        </form>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon stat-icon-primary"><i class="fas fa-comment-dots"></i></div>
            <div class="stat-info">
                <span class="stat-value"><?= $totalConversations ?></span>
                <span class="stat-label">Conversas</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon stat-icon-success"><i class="fas fa-check-circle"></i></div>
            <div class="stat-info">
                <span class="stat-value"><?= $resolvedCount ?></span>
                <span class="stat-label">Resolvidos</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:#e3f2fd;color:#1565c0"><i class="fas fa-envelope"></i></div>
            <div class="stat-info">
                <span class="stat-value"><?= $totalMessages ?></span>
                <span class="stat-label">Mensagens</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:#fff8e1;color:#f57f17"><i class="fas fa-star"></i></div>
            <div class="stat-info">
                <span class="stat-value"><?= $avgCsat !== null ? number_format($avgCsat, 2, ',', '.') : '—' ?></span>
                <span class="stat-label">CSAT médio (<?= $csatCount ?> avals)</span>
            </div>
        </div>
    </div>

    <div class="dashboard-grid" style="grid-template-columns:1fr 1fr">
        <!-- Conversas por canal -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-share-alt" style="color:var(--primary)"></i> Por Canal</h3>
            </div>
            <div class="card-body">
                <?php if (empty($channelData)): ?>
                    <div class="empty-state"><p>Nenhum dado no período.</p></div>
                <?php else: ?>
                    <?php $channelTotal = array_sum(array_column($channelData, 'total')); ?>
                    <?php foreach ($channelData as $ch): ?>
                        <?php $pct = $channelTotal > 0 ? round($ch['total'] / $channelTotal * 100) : 0; ?>
                        <div class="dept-bar-row">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
                                <i class="<?= channel_icon($ch['type']) ?>" style="width:16px;color:var(--primary)"></i>
                                <span class="dept-bar-name" style="text-transform:capitalize"><?= e($ch['type']) ?></span>
                                <span class="dept-bar-count"><?= $ch['total'] ?> (<?= $pct ?>%)</span>
                            </div>
                            <div class="dept-bar-track">
                                <div class="dept-bar-fill" style="width:<?= $pct ?>%;background:var(--primary)"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Status breakdown -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-tasks" style="color:var(--primary)"></i> Por Status</h3>
            </div>
            <div class="card-body">
                <?php if (empty($statusData)): ?>
                    <div class="empty-state"><p>Nenhum dado no período.</p></div>
                <?php else: ?>
                    <?php $statusTotal = array_sum(array_column($statusData, 'total')); ?>
                    <?php foreach ($statusData as $st): ?>
                        <?php $pct = $statusTotal > 0 ? round($st['total'] / $statusTotal * 100) : 0; ?>
                        <?php
                        $colors = [
                            'new' => 'var(--info)',
                            'open' => 'var(--primary)',
                            'waiting_customer' => 'var(--warning)',
                            'waiting_internal' => 'var(--secondary)',
                            'resolved' => 'var(--success)',
                            'closed' => '#6c757d',
                            'spam' => 'var(--danger)',
                        ];
                        $color = $colors[$st['status']] ?? 'var(--secondary)';
                        ?>
                        <div class="dept-bar-row">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
                                <span style="width:8px;height:8px;border-radius:50%;background:<?= $color ?>;flex-shrink:0"></span>
                                <span class="dept-bar-name" style="text-transform:capitalize"><?= e(str_replace('_', ' ', $st['status'])) ?></span>
                                <span class="dept-bar-count"><?= $st['total'] ?> (<?= $pct ?>%)</span>
                            </div>
                            <div class="dept-bar-track">
                                <div class="dept-bar-fill" style="width:<?= $pct ?>%;background:<?= $color ?>"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Sub-report navigation -->
    <div class="card" style="margin-top:8px">
        <div class="card-header">
            <h3><i class="fas fa-file-alt" style="color:var(--primary)"></i> Relatórios Detalhados</h3>
        </div>
        <div class="card-body">
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px">
                <a href="<?= url('reports/conversations') ?>" class="report-nav-card">
                    <i class="fas fa-comments" style="color:var(--info)"></i>
                    <strong>Conversas</strong>
                    <span style="font-size:12px;color:var(--text-muted)">Volume, tendências, canais</span>
                </a>
                <a href="<?= url('reports/agents') ?>" class="report-nav-card">
                    <i class="fas fa-users" style="color:var(--success)"></i>
                    <strong>Atendentes</strong>
                    <span style="font-size:12px;color:var(--text-muted)">Performance individual</span>
                </a>
                <a href="<?= url('reports/csat') ?>" class="report-nav-card">
                    <i class="fas fa-star" style="color:var(--warning)"></i>
                    <strong>CSAT</strong>
                    <span style="font-size:12px;color:var(--text-muted)">Satisfação do cliente</span>
                </a>
            </div>
        </div>
    </div>
</div>

<style>
.report-nav-card {
    display:flex;flex-direction:column;align-items:center;gap:8px;padding:24px 16px;
    border:1px solid var(--border-color);border-radius:12px;text-decoration:none;
    color:var(--text-main);transition:all 0.2s;text-align:center
}
.report-nav-card:hover {
    border-color:var(--primary);box-shadow:var(--shadow-md);transform:translateY(-2px);
    color:var(--text-main)
}
.report-nav-card i { font-size:32px }
</style>
