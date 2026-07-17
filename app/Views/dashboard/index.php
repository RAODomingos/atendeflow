<div class="dashboard" id="dashboardApp">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px">
        <div>
            <h1 style="font-size:24px;font-weight:700;margin:0;display:flex;align-items:center;gap:10px">
                <i class="fas fa-th-large" style="color:var(--primary);font-size:22px"></i>
                Dashboard
            </h1>
            <p style="margin:4px 0 0;font-size:14px;color:var(--text-muted)">Visão geral dos atendimentos</p>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <span style="font-size:13px;color:var(--text-muted)">
                <i class="fas fa-sync-alt" style="font-size:11px"></i> Atualizado <span id="dashUpdateTime">agora</span>
            </span>
            <button class="btn btn-sm btn-outline" onclick="refreshDashboard()" title="Atualizar">
                <i class="fas fa-redo"></i>
            </button>
        </div>
    </div>

    <!-- Stats principais -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon stat-icon-primary">
                <i class="fas fa-comments"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value" id="statMyOpen"><?= $totalOpen ?></span>
                <span class="stat-label">Meus Atendimentos</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon stat-icon-warning">
                <i class="fas fa-clock"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value" id="statWaiting"><?= $counts['waiting_customer'] ?? 0 ?></span>
                <span class="stat-label">Aguardando Cliente</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon stat-icon-success">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value" id="statResolved"><?= $counts['resolved'] ?? 0 ?></span>
                <span class="stat-label">Resolvidos</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon stat-icon-info">
                <i class="fas fa-user-check"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value" id="statOnline"><?= $onlineUsers ?></span>
                <span class="stat-label">Atendentes Online</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:#e8f5e9;color:var(--success)">
                <i class="fas fa-calendar-day"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?= $todayConversations ?></span>
                <span class="stat-label">Hoje (conversas)</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:#fff3e0;color:#e65100">
                <i class="fas fa-envelope-open-text"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?= $todayMessages ?></span>
                <span class="stat-label">Msgs hoje</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:#f3e5f5;color:#7b1fa2">
                <i class="fas fa-check-double"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?= $todayResolved ?></span>
                <span class="stat-label">Resolvidos hoje</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:#e1f5fe;color:#0277bd">
                <i class="fas fa-stopwatch"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?= $avgResponseTime !== null ? $avgResponseTime . 'min' : '—' ?></span>
                <span class="stat-label">Tempo médio resposta</span>
            </div>
        </div>
    </div>

    <div class="dashboard-grid" style="grid-template-columns:2fr 1fr">
        <!-- Tendência últimos 7 dias -->
        <div class="card" style="border-radius:12px">
            <div class="card-header">
                <h3><i class="fas fa-chart-line" style="color:var(--primary)"></i> Conversas (últimos 7 dias)</h3>
            </div>
            <div class="card-body">
                <div class="trend-chart">
                    <?php $maxVal = max($trendValues) ?: 1; ?>
                    <?php foreach ($trendValues as $i => $val): ?>
                        <div class="trend-bar-item">
                            <span class="trend-bar-value" style="font-size:11px;color:var(--text-muted);text-align:center;display:block;margin-bottom:4px"><?= $val ?></span>
                            <div class="trend-bar-track">
                                <div class="trend-bar-fill" style="height:<?= ($val / $maxVal) * 140 ?>px;background:var(--primary);border-radius:4px 4px 0 0;transition:height 0.5s ease"></div>
                            </div>
                            <span class="trend-bar-label" style="font-size:11px;color:var(--text-muted);text-align:center;display:block;margin-top:4px"><?= $trendLabels[$i] ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Conversas por departamento -->
        <div class="card" style="border-radius:12px">
            <div class="card-header">
                <h3><i class="fas fa-layer-group" style="color:var(--primary)"></i> Por Departamento</h3>
            </div>
            <div class="card-body">
                <?php if (empty($deptData)): ?>
                    <div class="empty-state"><p>Nenhum departamento com conversas ativas.</p></div>
                <?php else: ?>
                    <?php $deptMax = max(array_column($deptData, 'total')) ?: 1; ?>
                    <?php foreach ($deptData as $dept): ?>
                        <div class="dept-bar-row">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
                                <span style="width:8px;height:8px;border-radius:50%;background:<?= e($dept['color'] ?? '#6c757d') ?>;flex-shrink:0"></span>
                                <span class="dept-bar-name"><?= e($dept['name']) ?></span>
                                <span class="dept-bar-count"><?= $dept['total'] ?></span>
                            </div>
                            <div class="dept-bar-track">
                                <div class="dept-bar-fill" style="width:<?= ($dept['total'] / $deptMax) * 100 ?>%;background:<?= e($dept['color'] ?? '#6c757d') ?>"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="dashboard-grid" style="margin-top:0">
        <!-- Meus Atendimentos Recentes -->
        <div class="card" style="border-radius:12px;overflow:hidden">
            <div class="card-header">
                <h3><i class="fas fa-bell" style="color:var(--primary)"></i> Meus Atendimentos</h3>
                <a href="<?= url('inbox/mine') ?>" class="btn btn-sm btn-outline">Ver todos <i class="fas fa-arrow-right" style="font-size:10px"></i></a>
            </div>
            <div class="card-body p-0" id="recentConversations">
                <?php if (empty($myConversations)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <p>Nenhum atendimento no momento.</p>
                    </div>
                <?php else: ?>
                    <div class="conversation-list">
                        <?php foreach (array_slice($myConversations, 0, 5) as $conv): ?>
                            <a href="<?= url('inbox/'.$conv['id']) ?>" class="conversation-item conv-card-hover">
                                <div class="conv-avatar" style="color:var(--primary)">
                                    <i class="fas fa-user-circle fa-2x"></i>
                                </div>
                                <div class="conv-info">
                                    <div class="conv-header">
                                        <span class="conv-name"><?= e($conv['contact_name']) ?></span>
                                        <span class="conv-status"><?= status_badge($conv['status']) ?></span>
                                    </div>
                                    <div class="conv-meta">
                                        <span class="conv-channel"><i class="<?= channel_icon($conv['channel_type'] ?? 'webchat') ?>" style="color:var(--text-muted)"></i></span>
                                        <span><?= e($conv['department_name'] ?? '') ?></span>
                                        <span class="conv-time"><?= time_elapsed($conv['last_message_at'] ?? $conv['created_at']) ?></span>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Status dos Atendimentos -->
        <div class="card" style="border-radius:12px">
            <div class="card-header">
                <h3><i class="fas fa-chart-pie" style="color:var(--primary)"></i> Status</h3>
            </div>
            <div class="card-body" id="statusChart">
                <div class="status-chart">
                    <div class="status-bar-item">
                        <span class="status-label" style="width:120px">Novos</span>
                        <div class="status-bar-track">
                            <div class="status-bar-fill new" style="width: <?= $totalOpen > 0 ? ($counts['new'] / $totalOpen * 100) : 0 ?>%"></div>
                        </div>
                        <span class="status-count" id="countNew"><?= $counts['new'] ?></span>
                    </div>
                    <div class="status-bar-item">
                        <span class="status-label" style="width:120px">Abertos</span>
                        <div class="status-bar-track">
                            <div class="status-bar-fill open" style="width: <?= $totalOpen > 0 ? ($counts['open'] / $totalOpen * 100) : 0 ?>%"></div>
                        </div>
                        <span class="status-count" id="countOpen"><?= $counts['open'] ?></span>
                    </div>
                    <div class="status-bar-item">
                        <span class="status-label" style="width:120px">Agu. Cliente</span>
                        <div class="status-bar-track">
                            <div class="status-bar-fill waiting" style="width: <?= $totalOpen > 0 ? ($counts['waiting_customer'] / $totalOpen * 100) : 0 ?>%"></div>
                        </div>
                        <span class="status-count" id="countWaiting"><?= $counts['waiting_customer'] ?></span>
                    </div>
                    <div class="status-bar-item">
                        <span class="status-label" style="width:120px">Agu. Interno</span>
                        <div class="status-bar-track">
                            <div class="status-bar-fill internal" style="width: <?= $totalOpen > 0 ? ($counts['waiting_internal'] / $totalOpen * 100) : 0 ?>%"></div>
                        </div>
                        <span class="status-count" id="countInternal"><?= $counts['waiting_internal'] ?></span>
                    </div>
                    <div class="status-bar-item">
                        <span class="status-label" style="width:120px">Resolvidos</span>
                        <div class="status-bar-track">
                            <div class="status-bar-fill" style="width:<?= $totalOpen > 0 ? ($counts['resolved'] / max($totalOpen,1) * 100) : 0 ?>%;background:var(--success)"></div>
                        </div>
                        <span class="status-count" id="countResolved"><?= $counts['resolved'] ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Performance dos Agentes (admin only) -->
    <?php if ($isAdmin && !empty($agentData)): ?>
    <div class="card" style="border-radius:12px">
        <div class="card-header">
            <h3><i class="fas fa-trophy" style="color:var(--primary)"></i> Performance dos Atendentes</h3>
            <a href="<?= url('reports/agents') ?>" class="btn btn-sm btn-outline">Relatório completo <i class="fas fa-arrow-right" style="font-size:10px"></i></a>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Atendente</th>
                        <th>Ativos</th>
                        <th>Msgs (7d)</th>
                        <th>Resolvidos (30d)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($agentData as $a): ?>
                    <tr>
                        <td><strong><?= e($a['name']) ?></strong></td>
                        <td><?= $a['active_convos'] ?></td>
                        <td><?= $a['msgs_7d'] ?></td>
                        <td><?= $a['resolved_30d'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
.trend-chart { display:flex;align-items:flex-end;justify-content:space-around;gap:8px;padding:8px 0;min-height:180px }
.trend-bar-item { flex:1;display:flex;flex-direction:column;align-items:center }
.trend-bar-track { width:100%;max-width:40px;display:flex;justify-content:center }
.dept-bar-row { margin-bottom:12px }
.dept-bar-name { font-size:13px;font-weight:500;flex:1 }
.dept-bar-count { font-size:13px;font-weight:700;color:var(--text-muted) }
.dept-bar-track { height:8px;background:var(--bg-content);border-radius:4px;overflow:hidden }
.dept-bar-fill { height:100%;border-radius:4px;transition:width 0.5s ease }
</style>

<script>
var dashTimer;
function refreshDashboard() {
    fetch('/api/conversations?status=new,open,waiting_customer,waiting_internal,resolved')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data) return;
            var counts = data.counts || {};
            ['new', 'open', 'waiting_customer', 'waiting_internal', 'resolved'].forEach(function(k) {
                var el = document.getElementById('count' + k.charAt(0).toUpperCase() + k.slice(1).replace('_customer', '').replace('_internal', ''));
                if (el) el.textContent = counts[k] || 0;
            });
            var total = 0;
            ['new','open','waiting_customer','waiting_internal'].forEach(function(k) { total += counts[k] || 0; });
            var myOpen = document.getElementById('statMyOpen');
            if (myOpen) myOpen.textContent = total;
            var waiting = document.getElementById('statWaiting');
            if (waiting) waiting.textContent = counts.waiting_customer || 0;
            var resolved = document.getElementById('statResolved');
            if (resolved) resolved.textContent = counts.resolved || 0;
            document.getElementById('dashUpdateTime').textContent = 'agora';
        })
        .catch(function() {});
}
dashTimer = setInterval(refreshDashboard, 20000);
</script>
