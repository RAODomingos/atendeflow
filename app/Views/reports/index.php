<div class="reports-page">
    <div class="reports-header">
        <div class="reports-header-left">
            <a href="<?= url('reports') ?>" class="reports-back" title="Voltar">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <div class="reports-header-icon reports-header-icon--primary"><i class="fas fa-chart-bar"></i></div>
            <div>
                <h1 class="reports-title">Relatórios</h1>
                <p class="reports-subtitle">Visão geral do período</p>
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
            <div class="stat-icon stat-icon-primary"><i class="fas fa-comment-dots"></i></div>
            <div class="stat-info">
                <span class="stat-value"><?= number_format($totalConversations) ?></span>
                <span class="stat-label">Conversas</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon stat-icon-success"><i class="fas fa-check-circle"></i></div>
            <div class="stat-info">
                <span class="stat-value"><?= number_format($resolvedCount) ?></span>
                <span class="stat-label">Resolvidos</span>
                <?php if ($totalConversations > 0): ?>
                <span class="stat-sub"><?= round($resolvedCount / $totalConversations * 100) ?>% do total</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon stat-icon-info"><i class="fas fa-envelope"></i></div>
            <div class="stat-info">
                <span class="stat-value"><?= number_format($totalMessages) ?></span>
                <span class="stat-label">Mensagens</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon stat-icon-amber"><i class="fas fa-star"></i></div>
            <div class="stat-info">
                <span class="stat-value"><?= $avgCsat !== null ? number_format($avgCsat, 2, ',', '.') : '—' ?></span>
                <span class="stat-label">CSAT médio</span>
                <span class="stat-sub"><?= $csatCount ?> avaliação<?= $csatCount !== 1 ? 's' : '' ?></span>
            </div>
        </div>
    </div>

    <div class="dashboard-grid" style="grid-template-columns:1fr 1fr">
        <!-- Conversas por canal -->
        <div class="card chart-card">
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

        <!-- Status breakdown -->
        <div class="card chart-card">
            <div class="card-header">
                <h3><i class="fas fa-tasks" style="color:var(--primary)"></i> Por Status</h3>
            </div>
            <div class="card-body">
                <?php if (empty($statusData)): ?>
                    <div class="empty-state"><p>Nenhum dado no período.</p></div>
                <?php else: ?>
                    <?php $statusTotal = array_sum(array_column($statusData, 'total')); ?>
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
                        <?php $pct = $statusTotal > 0 ? round($st['total'] / $statusTotal * 100) : 0; ?>
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
    </div>

    <!-- Sub-report navigation -->
    <div class="reports-sub-nav">
        <a href="<?= url('reports/conversations') ?>" class="report-nav-card">
            <div class="report-nav-icon report-nav-icon--info"><i class="fas fa-comments"></i></div>
            <strong>Conversas</strong>
            <span>Volume, tendências, canais</span>
        </a>
        <a href="<?= url('reports/agents') ?>" class="report-nav-card">
            <div class="report-nav-icon report-nav-icon--success"><i class="fas fa-users"></i></div>
            <strong>Atendentes</strong>
            <span>Performance individual</span>
        </a>
        <a href="<?= url('reports/csat') ?>" class="report-nav-card">
            <div class="report-nav-icon report-nav-icon--warning"><i class="fas fa-star"></i></div>
            <strong>CSAT</strong>
            <span>Satisfação do cliente</span>
        </a>
    </div>
</div>
