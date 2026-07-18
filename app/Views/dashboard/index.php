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

    <!-- Stats row 1: Global -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon stat-icon-primary">
                <i class="fas fa-comments"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value" id="statGlobalOpen"><?= $globalOpen ?></span>
                <span class="stat-label">Total Abertos</span>
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
                <span class="stat-value" id="statTodayConvs"><?= $todayConversations ?></span>
                <span class="stat-label">Conversas hoje</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:#fff3e0;color:#e65100">
                <i class="fas fa-envelope-open-text"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value" id="statTodayMsgs"><?= $todayMessages ?></span>
                <span class="stat-label">Mensagens hoje</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:#f3e5f5;color:#7b1fa2">
                <i class="fas fa-check-double"></i>
            </div>
            <div class="stat-info">
                <span class="stat-value" id="statTodayResolved"><?= $todayResolved ?></span>
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

    <!-- Stats row 2: Personal -->
    <div style="margin-top:8px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <span style="font-size:13px;font-weight:600;color:var(--text-muted)"><i class="fas fa-user"></i> Meus números</span>
        <span class="badge bg-primary" id="myOpen">Abertos: <?= $myOpen ?></span>
        <span class="badge bg-warning text-dark" id="myWaiting">Agu. cliente: <?= $myCounts['waiting_customer'] ?? 0 ?></span>
        <span class="badge bg-info" id="myInternal">Agu. interno: <?= $myCounts['waiting_internal'] ?? 0 ?></span>
        <span class="badge bg-success" id="myResolved">Resolvidos: <?= $myCounts['resolved'] ?? 0 ?></span>
        <span class="badge bg-danger" id="myUnread">Não lidas: <?= $unread ?></span>
    </div>

    <!-- Row: chart + departments -->
    <div class="dashboard-grid" style="grid-template-columns:2fr 1fr;margin-top:20px">
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

    <!-- Row: status bars + inbox breakdown -->
    <div class="dashboard-grid" style="margin-top:0">
        <div class="card" style="border-radius:12px">
            <div class="card-header">
                <h3><i class="fas fa-chart-pie" style="color:var(--primary)"></i> Status Global</h3>
            </div>
            <div class="card-body">
                <div class="status-chart">
                    <div class="status-bar-item">
                        <span class="status-label" style="width:120px">Novos</span>
                        <div class="status-bar-track">
                            <div class="status-bar-fill new" style="width: <?= $globalOpen > 0 ? ($globalCounts['new'] / $globalOpen * 100) : 0 ?>%"></div>
                        </div>
                        <span class="status-count" id="gCountNew"><?= $globalCounts['new'] ?></span>
                    </div>
                    <div class="status-bar-item">
                        <span class="status-label" style="width:120px">Abertos</span>
                        <div class="status-bar-track">
                            <div class="status-bar-fill open" style="width: <?= $globalOpen > 0 ? ($globalCounts['open'] / $globalOpen * 100) : 0 ?>%"></div>
                        </div>
                        <span class="status-count" id="gCountOpen"><?= $globalCounts['open'] ?></span>
                    </div>
                    <div class="status-bar-item">
                        <span class="status-label" style="width:120px">Agu. Cliente</span>
                        <div class="status-bar-track">
                            <div class="status-bar-fill waiting" style="width: <?= $globalOpen > 0 ? ($globalCounts['waiting_customer'] / $globalOpen * 100) : 0 ?>%"></div>
                        </div>
                        <span class="status-count" id="gCountWaiting"><?= $globalCounts['waiting_customer'] ?></span>
                    </div>
                    <div class="status-bar-item">
                        <span class="status-label" style="width:120px">Agu. Interno</span>
                        <div class="status-bar-track">
                            <div class="status-bar-fill internal" style="width: <?= $globalOpen > 0 ? ($globalCounts['waiting_internal'] / $globalOpen * 100) : 0 ?>%"></div>
                        </div>
                        <span class="status-count" id="gCountInternal"><?= $globalCounts['waiting_internal'] ?></span>
                    </div>
                    <div class="status-bar-item">
                        <span class="status-label" style="width:120px">Resolvidos</span>
                        <div class="status-bar-track">
                            <div class="status-bar-fill" style="width:<?= $globalOpen > 0 ? ($globalCounts['resolved'] / max($globalOpen,1) * 100) : 0 ?>%;background:var(--success)"></div>
                        </div>
                        <span class="status-count" id="gCountResolved"><?= $globalCounts['resolved'] ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card" style="border-radius:12px">
            <div class="card-header">
                <h3><i class="fas fa-inbox" style="color:var(--primary)"></i> Por Caixa</h3>
            </div>
            <div class="card-body">
                <?php if (empty($inboxes)): ?>
                    <div class="empty-state"><p>Nenhuma caixa disponível.</p></div>
                <?php else: ?>
                    <?php $inboxMax = max($openByInbox) ?: 1; ?>
                    <?php foreach ($inboxes as $ib): $ibCount = $openByInbox[$ib['id']] ?? 0; ?>
                        <div class="dept-bar-row">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
                                <i class="fas fa-inbox" style="color:var(--primary);font-size:12px;width:16px"></i>
                                <span class="dept-bar-name"><?= e($ib['name']) ?></span>
                                <span class="dept-bar-count"><?= $ibCount ?></span>
                            </div>
                            <div class="dept-bar-track">
                                <div class="dept-bar-fill" style="width:<?= ($ibCount / $inboxMax) * 100 ?>%;background:var(--primary)"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Recent conversations -->
    <div class="card" style="border-radius:12px;overflow:hidden;margin-top:20px">
        <div class="card-header">
            <h3><i class="fas fa-bell" style="color:var(--primary)"></i> Conversas Recentes</h3>
            <a href="<?= url('inbox') ?>" class="btn btn-sm btn-outline">Ver todas <i class="fas fa-arrow-right" style="font-size:10px"></i></a>
        </div>
        <div class="card-body p-0" id="recentConversations">
            <?php if (empty($recentConversations)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>Nenhuma conversa ativa no momento.</p>
                </div>
            <?php else: ?>
                <div class="conversation-list">
                    <?php foreach ($recentConversations as $conv): ?>
                        <a href="<?= url('inbox?conv=' . $conv['id']) ?>" class="conversation-item conv-card-hover">
                            <div class="avatar-container">
                                <?php if ($conv['contact_avatar']): ?>
                                    <img class="avatar" src="<?= e(str_starts_with($conv['contact_avatar'], 'http') ? $conv['contact_avatar'] : upload_url($conv['contact_avatar'])) ?>" alt="">
                                <?php else: ?>
                                    <div class="avatar avatar-placeholder-sm">
                                        <?= mb_strtoupper(mb_substr($conv['contact_name'] ?? '?', 0, 1)) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="convo-info">
                                <div class="convo-header">
                                    <span class="convo-name"><?= e($conv['contact_name']) ?></span>
                                    <span class="convo-time"><?= time_elapsed($conv['last_message_at'] ?? $conv['created_at']) ?></span>
                                </div>
                                <p class="convo-preview">
                                    <?= e(truncate($conv['last_message'] ?? 'Sem mensagens', 80)) ?>
                                </p>
                                <div class="convo-meta">
                                    <?php $chColor = ['whatsapp' => '#25D366', 'webchat' => '#4361ee', 'email' => '#f59e0b', 'telegram' => '#0088cc', 'facebook' => '#1877f2', 'instagram' => '#e1306c', 'phone' => '#6c757d'][$conv['channel_type'] ?? ''] ?? '#6c757d'; ?>
                                    <span class="convo-channel" style="background:<?= $chColor ?>">
                                        <i class="<?= channel_icon($conv['channel_type'] ?? 'webchat') ?>"></i>
                                        <?= e($conv['channel_name'] ?? '') ?>
                                    </span>
                                    <?= status_badge($conv['status']) ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Agent performance (admin only) -->
    <?php if ($isAdmin && !empty($agentData)): ?>
    <div class="card" style="border-radius:12px;margin-top:16px">
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
    fetch('/api/dashboard-stats', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d) return;

            // Global stats
            var el = document.getElementById('statGlobalOpen');
            if (el) el.textContent = d.globalOpen || 0;

            el = document.getElementById('statOnline');
            if (el) el.textContent = d.onlineUsers || 0;

            el = document.getElementById('statTodayConvs');
            if (el) el.textContent = d.todayConversations || 0;

            el = document.getElementById('statTodayMsgs');
            if (el) el.textContent = d.todayMessages || 0;

            el = document.getElementById('statTodayResolved');
            if (el) el.textContent = d.todayResolved || 0;

            // My stats badges
            el = document.getElementById('myOpen');
            if (el) el.textContent = 'Abertos: ' + (d.myOpen || 0);

            el = document.getElementById('myWaiting');
            if (el && d.myCounts) el.textContent = 'Agu. cliente: ' + (d.myCounts.waiting_customer || 0);

            el = document.getElementById('myInternal');
            if (el && d.myCounts) el.textContent = 'Agu. interno: ' + (d.myCounts.waiting_internal || 0);

            el = document.getElementById('myResolved');
            if (el && d.myCounts) el.textContent = 'Resolvidos: ' + (d.myCounts.resolved || 0);

            el = document.getElementById('myUnread');
            if (el) el.textContent = 'Não lidas: ' + (d.unread || 0);

            // Status bars (global)
            if (d.counts) {
                var order = ['new', 'open', 'waiting_customer', 'waiting_internal', 'resolved'];
                var openTotal = d.globalOpen || 1;
                order.forEach(function(k) {
                    var id = 'gCount' + k.charAt(0).toUpperCase() + k.slice(1).replace('_customer', '').replace('_internal', '');
                    var countEl = document.getElementById(id);
                    if (countEl) countEl.textContent = d.counts[k] || 0;
                });
            }

            document.getElementById('dashUpdateTime').textContent = 'agora';
        })
        .catch(function() {});
}
dashTimer = setInterval(refreshDashboard, 20000);
</script>