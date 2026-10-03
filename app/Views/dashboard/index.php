<?php
// ---------- Delta (hoje vs ontem) ----------
$deltaPct = function ($today, $prev) {
    if ($prev <= 0) return $today > 0 ? 'new' : null;
    return round(($today - $prev) / $prev * 100);
};
$deltaConv = $deltaPct($todayConversations, $prevDayConversations);
$deltaMsgs = $deltaPct($todayMessages, $prevDayMessages);
$deltaRes  = $deltaPct($todayResolved, $prevDayResolved);

// ---------- Trend chart (SVG) ----------
$chartW = 720; $chartH = 240;
$padL = 40; $padR = 14; $padT = 18; $padB = 30;
$plotW = $chartW - $padL - $padR;
$plotH = $chartH - $padT - $padB;
$chartRawMax = max(array_merge($trendValues, $trendResolvedValues)) ?: 0;
$chartMax = $chartRawMax;
if ($chartMax <= 0) { $chartMax = 1; }
$divStep = $chartMax > 100 ? 50 : ($chartMax > 20 ? 10 : ($chartMax > 5 ? 2 : 1));
$chartMax = max(ceil($chartMax / $divStep) * $divStep, $divStep);
$chartScale = $plotH / $chartMax;

$mkSeries = function (array $vals) use ($padL, $padT, $plotW, $plotH, $chartScale) {
    $n = count($vals);
    $sx = $n > 1 ? $plotW / ($n - 1) : $plotW;
    $pts = [];
    foreach ($vals as $i => $v) {
        $pts[] = [$padL + $i * $sx, $padT + $plotH - $v * $chartScale];
    }
    return $pts;
};
$n = count($trendValues);
$stepX = $n > 1 ? $plotW / ($n - 1) : $plotW;
$convPts = $mkSeries($trendValues);
$resPts  = $mkSeries($trendResolvedValues);
$baseY = $padT + $plotH;
$convLine = implode(' ', array_map(fn($p) => $p[0] . ',' . $p[1], $convPts));
$resLine  = implode(' ', array_map(fn($p) => $p[0] . ',' . $p[1], $resPts));
$convArea = $padL . ',' . $baseY . ' ' . $convLine . ' ' . ($padL + ($n - 1) * $stepX) . ',' . $baseY;
$resArea  = $padL . ',' . $baseY . ' ' . $resLine . ' ' . ($padL + ($n - 1) * $stepX) . ',' . $baseY;

// ---------- Donut (status ativos) ----------
$donutR = 62; $donutC = 2 * M_PI * $donutR;
$donutDefs = [
    ['key' => 'new',               'label' => 'Novos',        'color' => '#2e90fa'],
    ['key' => 'open',              'label' => 'Abertos',      'color' => '#0078d4'],
    ['key' => 'waiting_customer',  'label' => 'Agu. cliente', 'color' => '#12b76a'],
    ['key' => 'waiting_internal',  'label' => 'Agu. interno', 'color' => '#f59e0b'],
];
$donutTotal = $globalOpen > 0 ? $globalOpen : 1;
$donutSegs = [];
$donutCum = 0;
foreach ($donutDefs as $def) {
    $v = $globalCounts[$def['key']] ?? 0;
    if ($v <= 0) continue;
    $len = $v / $donutTotal * $donutC;
    $donutSegs[] = [
        'color'  => $def['color'],
        'dash'   => round($len, 2),
        'offset' => round($donutC - $donutCum, 2),
    ];
    $donutCum += $len;
}
$statusLabels = [
    'new' => 'Novos', 'open' => 'Abertos',
    'waiting_customer' => 'Agu. cliente', 'waiting_internal' => 'Agu. interno',
    'resolved' => 'Resolvidos', 'closed' => 'Fechados', 'spam' => 'Spam',
];

// ---------- Agentes ----------
$maxActive = 1;
foreach ($agentData as $a) { $maxActive = max($maxActive, (int) $a['active_convos']); }

$weekdays = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
$todayName = $weekdays[(int) date('w')];
$todayPt = date('j') . ' de ' . ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'][(int) date('n') - 1];
?>

<?php
$greetName = trim(explode(' ', (string) (\App\Core\Session::get('user_name') ?? ''))[0] ?? '');
$dayHour = (int) date('H');
$greeting = $dayHour < 12 ? 'Bom dia' : ($dayHour < 18 ? 'Boa tarde' : 'Boa noite');
$newWaiting = (int) ($globalCounts['new'] ?? 0);
?>
<div class="dashboard" id="dashboardApp">
    <!-- Header -->
    <div class="dash-header" style="justify-content:flex-end">
        <div class="dash-header-actions">
            <span class="dash-updated">
                <i class="fas fa-sync-alt fa-fw"></i> Atualizado <span id="dashUpdateTime">agora</span>
            </span>
            <a href="<?= url('inbox') ?>" class="btn btn-sm btn-primary"><i class="fas fa-inbox"></i> Ir para caixa</a>
            <button class="btn btn-sm btn-outline" onclick="refreshDashboard()" title="Atualizar">
                <i class="fas fa-redo"></i>
            </button>
        </div>
    </div>

    <?php if ($newWaiting > 0): ?>
    <a href="<?= url('inbox') ?>" class="dash-attention">
        <span class="dash-attention-icon"><i class="fas fa-bell"></i></span>
        <span><strong><?= $newWaiting ?> conversa<?= $newWaiting > 1 ? 's novas' : ' nova' ?> aguardando</strong> atendimento. Clique para ver a fila.</span>
        <i class="fas fa-arrow-right dash-attention-go"></i>
    </a>
    <?php endif; ?>

    <!-- Operação agora -->
    <div class="dash-section-title"><span>Operação agora</span></div>
    <div class="stats-grid dash-stats">
        <div class="stat-card stat-hero">
            <div class="stat-icon stat-icon-primary"><i class="fas fa-comments"></i></div>
            <div class="stat-info">
                <span class="stat-value" id="statGlobalOpen" data-count="<?= $globalOpen ?>"><?= $globalOpen ?></span>
                <span class="stat-label">Em aberto</span>
                <span class="stat-sub"><i class="fas fa-circle" style="color:#2e90fa;font-size:7px"></i> <?= $globalCounts['new'] ?> novos &middot; <?= $globalCounts['open'] ?? 0 ?> em atendimento</span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon stat-icon-danger"><i class="fas fa-inbox"></i></div>
            <div class="stat-info">
                <span class="stat-value" id="statTodayNew" data-count="<?= $globalCounts['new'] ?? 0 ?>"><?= $globalCounts['new'] ?? 0 ?></span>
                <span class="stat-label">Novos aguardando</span>
                <span class="stat-sub"><i class="fas fa-bell" style="font-size:9px"></i> precisam de atenção</span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon stat-icon-info"><i class="fas fa-user-check"></i></div>
            <div class="stat-info">
                <span class="stat-value" id="statOnline" data-count="<?= $onlineUsers ?>"><?= $onlineUsers ?></span>
                <span class="stat-label">Atendentes online</span>
                <span class="stat-sub"><i class="fas fa-circle" style="color:var(--success);font-size:7px"></i> disponíveis agora</span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon stat-icon-success"><i class="fas fa-check-double"></i></div>
            <div class="stat-info">
                <span class="stat-value" id="statTodayResolved" data-count="<?= $todayResolved ?>"><?= $todayResolved ?></span>
                <span class="stat-label">Resolvidos hoje</span>
                <?php if ($deltaRes === 'new'): ?>
                    <span class="stat-delta delta-new"><i class="fas fa-sparkles"></i> Sem base ontem</span>
                <?php elseif ($deltaRes !== null): ?>
                    <span class="stat-delta <?= $deltaRes >= 0 ? 'delta-up' : 'delta-down' ?>" id="dTodayResolved">
                        <i class="fas fa-<?= $deltaRes >= 0 ? 'arrow-up' : 'arrow-down' ?>"></i> <?= abs($deltaRes) ?>% vs ontem
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Movimento de hoje -->
    <div class="dash-section-title"><span>Movimento de hoje</span></div>
    <div class="stats-grid dash-stats">
        <div class="stat-card">
            <div class="stat-icon stat-icon-violet"><i class="fas fa-calendar-day"></i></div>
            <div class="stat-info">
                <span class="stat-value" id="statTodayConvs" data-count="<?= $todayConversations ?>"><?= $todayConversations ?></span>
                <span class="stat-label">Conversas hoje</span>
                <?php if ($deltaConv === 'new'): ?>
                    <span class="stat-delta delta-new"><i class="fas fa-sparkles"></i> Sem base ontem</span>
                <?php elseif ($deltaConv !== null): ?>
                    <span class="stat-delta <?= $deltaConv >= 0 ? 'delta-up' : 'delta-down' ?>" id="dTodayConvs">
                        <i class="fas fa-<?= $deltaConv >= 0 ? 'arrow-up' : 'arrow-down' ?>"></i> <?= abs($deltaConv) ?>% vs ontem
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon stat-icon-warning"><i class="fas fa-envelope-open-text"></i></div>
            <div class="stat-info">
                <span class="stat-value" id="statTodayMsgs" data-count="<?= $todayMessages ?>"><?= $todayMessages ?></span>
                <span class="stat-label">Mensagens hoje</span>
                <?php if ($deltaMsgs === 'new'): ?>
                    <span class="stat-delta delta-new"><i class="fas fa-sparkles"></i> Sem base ontem</span>
                <?php elseif ($deltaMsgs !== null): ?>
                    <span class="stat-delta <?= $deltaMsgs >= 0 ? 'delta-up' : 'delta-down' ?>" id="dTodayMsgs">
                        <i class="fas fa-<?= $deltaMsgs >= 0 ? 'arrow-up' : 'arrow-down' ?>"></i> <?= abs($deltaMsgs) ?>% vs ontem
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon stat-icon-neutral"><i class="fas fa-stopwatch"></i></div>
            <div class="stat-info">
                <span class="stat-value" id="statAvgResp" data-count="<?= $avgResponseTime ?? 0 ?>" data-decimals="0" data-empty="<?= $avgResponseTime === null ? '1' : '0' ?>">
                    <?= $avgResponseTime !== null ? $avgResponseTime : '—' ?>
                </span>
                <span class="stat-label">Resposta média</span>
                <?php if ($avgResponseTime !== null): ?>
                    <span class="stat-sub">últimos 30 dias <span class="stat-suffix">min</span></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon stat-icon-amber"><i class="fas fa-star"></i></div>
            <div class="stat-info">
                <span class="stat-value" id="dashCsat" data-count="<?= $csatAvg ?? 0 ?>" data-decimals="1" data-empty="<?= $csatAvg === null ? '1' : '0' ?>">
                    <?= $csatAvg !== null ? number_format($csatAvg, 1, ',', '.') : '—' ?>
                </span>
                <span class="stat-label">CSAT · 30 dias</span>
                <?php if ($csatAvg !== null): ?>
                    <span class="stat-sub"><i class="fas fa-star" style="color:#f59e0b;font-size:9px"></i> <?= $csatCount ?> avaliações</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Personal stats -->
    <div class="dash-section-title"><span>Minha fila</span><a href="<?= url('inbox/mine') ?>" class="dash-section-link">Ver minha caixa <i class="fas fa-arrow-right"></i></a></div>
    <div class="dash-myrow">
        <div class="dash-myhead">
            <span class="dash-myicon"><i class="fas fa-user"></i></span>
            <div>
                <strong>Em foco</strong>
                <small>Meus atendimentos</small>
            </div>
        </div>
        <div class="dash-mystats">
            <div class="my-stat">
                <span class="my-stat-value" id="myOpen"><?= $myOpen ?></span>
                <span class="my-stat-label"><i class="fas fa-inbox"></i> Abertos</span>
            </div>
            <div class="my-stat">
                <span class="my-stat-value" id="myWaiting"><?= $myCounts['waiting_customer'] ?? 0 ?></span>
                <span class="my-stat-label"><i class="fas fa-hourglass-half" style="color:var(--success)"></i> Agu. cliente</span>
            </div>
            <div class="my-stat">
                <span class="my-stat-value" id="myInternal"><?= $myCounts['waiting_internal'] ?? 0 ?></span>
                <span class="my-stat-label"><i class="fas fa-hourglass-half" style="color:var(--warning)"></i> Agu. interno</span>
            </div>
            <div class="my-stat">
                <span class="my-stat-value" id="myResolved"><?= $myCounts['resolved'] ?? 0 ?></span>
                <span class="my-stat-label"><i class="fas fa-check-double" style="color:var(--success)"></i> Resolvidos</span>
            </div>
            <div class="my-stat my-stat-danger">
                <span class="my-stat-value" id="myUnread"><?= $unread ?></span>
                <span class="my-stat-label"><i class="fas fa-envelope"></i> Não lidas</span>
            </div>
        </div>
    </div>

    <!-- Row: trend chart + donut -->
    <div class="dash-section-title" style="margin-top:20px"><span>Tendência e distribuição</span></div>
    <div class="dashboard-grid" style="grid-template-columns:2fr 1fr;margin-top:0">
        <div class="card chart-card">
            <div class="card-header">
                <h3><i class="fas fa-chart-line" style="color:var(--primary)"></i> Conversas <small class="card-header-sub">últimos 7 dias</small></h3>
                <div class="chart-legend">
                    <span class="chart-legend-item"><span class="legend-dot" style="background:var(--primary)"></span> Novas</span>
                    <span class="chart-legend-item"><span class="legend-dot" style="background:var(--success)"></span> Resolvidas</span>
                </div>
            </div>
            <div class="card-body">
                <div class="trend-chart">
                    <svg viewBox="0 0 <?= $chartW ?> <?= $chartH ?>" class="trend-svg" preserveAspectRatio="xMidYMid meet">
                        <defs>
                            <linearGradient id="gradConv" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="#0078d4" stop-opacity="0.28"/>
                                <stop offset="100%" stop-color="#0078d4" stop-opacity="0.02"/>
                            </linearGradient>
                            <linearGradient id="gradRes" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="#12b76a" stop-opacity="0.20"/>
                                <stop offset="100%" stop-color="#12b76a" stop-opacity="0.02"/>
                            </linearGradient>
                        </defs>
                        <?php for ($g = 0; $g <= 4; $g++): $gy = $padT + $plotH - ($chartMax * $g / 4) * $chartScale; $gval = round($chartMax * $g / 4); ?>
                            <line class="trend-gridline" x1="<?= $padL ?>" y1="<?= $gy ?>" x2="<?= $chartW - $padR ?>" y2="<?= $gy ?>"/>
                            <text class="trend-y" x="<?= $padL - 8 ?>" y="<?= $gy + 4 ?>"><?= $gval ?></text>
                        <?php endfor; ?>
                        <polygon class="trend-area" points="<?= $resArea ?>" fill="url(#gradRes)"/>
                        <polygon class="trend-area" points="<?= $convArea ?>" fill="url(#gradConv)"/>
                        <polyline class="trend-line trend-line-res" points="<?= $resLine ?>"/>
                        <polyline class="trend-line trend-line-conv" points="<?= $convLine ?>"/>
                        <?php foreach ($convPts as $i => $p): ?>
                            <circle class="trend-dot trend-dot-conv" cx="<?= $p[0] ?>" cy="<?= $p[1] ?>" r="3.2">
                                <title><?= $trendLabels[$i] ?> — <?= $trendValues[$i] ?> conversa(s)</title>
                            </circle>
                            <circle class="trend-hit" cx="<?= $p[0] ?>" cy="<?= $p[1] ?>" r="10">
                                <title><?= $trendLabels[$i] ?> — <?= $trendValues[$i] ?> conversa(s)</title>
                            </circle>
                        <?php endforeach; ?>
                        <?php foreach ($resPts as $i => $p): ?>
                            <circle class="trend-dot trend-dot-res" cx="<?= $p[0] ?>" cy="<?= $p[1] ?>" r="3.2">
                                <title><?= $trendLabels[$i] ?> — <?= $trendResolvedValues[$i] ?> resolvida(s)</title>
                            </circle>
                            <circle class="trend-hit" cx="<?= $p[0] ?>" cy="<?= $p[1] ?>" r="10">
                                <title><?= $trendLabels[$i] ?> — <?= $trendResolvedValues[$i] ?> resolvida(s)</title>
                            </circle>
                        <?php endforeach; ?>
                        <?php foreach ($trendLabels as $i => $label): $lx = $padL + $i * $stepX; ?>
                            <text class="trend-x" x="<?= $lx ?>" y="<?= $chartH - 10 ?>"><?= $label ?></text>
                        <?php endforeach; ?>
                    </svg>
                </div>
            </div>
        </div>

        <div class="card chart-card">
            <div class="card-header">
                <h3><i class="fas fa-chart-pie" style="color:var(--primary)"></i> Status <small class="card-header-sub">abertos agora</small></h3>
            </div>
            <div class="card-body donut-body">
                <div class="donut-wrap">
                    <svg viewBox="0 0 160 160" class="donut-svg">
                        <g transform="rotate(-90 80 80)">
                            <circle class="donut-track" cx="80" cy="80" r="<?= $donutR ?>"/>
                            <?php foreach ($donutSegs as $seg): ?>
                                <circle class="donut-seg" cx="80" cy="80" r="<?= $donutR ?>"
                                        stroke="<?= $seg['color'] ?>"
                                        stroke-dasharray="<?= $seg['dash'] ?> <?= $donutC ?>"
                                        stroke-dashoffset="<?= $seg['offset'] ?>">
                                    <title></title>
                                </circle>
                            <?php endforeach; ?>
                        </g>
                        <text class="donut-total" x="80" y="76" text-anchor="middle"><?= $globalOpen ?></text>
                        <text class="donut-total-label" x="80" y="96" text-anchor="middle">abertos</text>
                    </svg>
                </div>
                <div class="donut-legend">
                    <?php foreach ($donutDefs as $def): $v = $globalCounts[$def['key']] ?? 0; $pct = $globalOpen > 0 ? round($v / $globalOpen * 100) : 0; ?>
                        <div class="donut-legend-row">
                            <span class="legend-dot" style="background:<?= $def['color'] ?>"></span>
                            <span class="donut-legend-name"><?= $def['label'] ?></span>
                            <span class="donut-legend-count" id="gCount<?= ucfirst(str_replace(['waiting_customer', 'waiting_internal'], ['Waiting', 'Internal'], $def['key'])) ?>"><?= $v ?></span>
                            <span class="donut-legend-pct"><?= $pct ?>%</span>
                        </div>
                    <?php endforeach; ?>
                    <div class="donut-legend-row">
                        <span class="legend-dot" style="background:var(--text-muted)"></span>
                        <span class="donut-legend-name">Resolvidos</span>
                        <span class="donut-legend-count" id="gCountResolved"><?= $globalCounts['resolved'] ?? 0 ?></span>
                        <span class="donut-legend-pct">—</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Row: departments + inboxes -->
    <div class="dash-section-title" style="margin-top:20px"><span>Equipe e caixas</span><a href="<?= url('reports') ?>" class="dash-section-link">Relatórios <i class="fas fa-arrow-right"></i></a></div>
    <div class="dashboard-grid" style="grid-template-columns:1fr 1fr;margin-top:0">
        <div class="card chart-card">
            <div class="card-header">
                <h3><i class="fas fa-layer-group" style="color:var(--primary)"></i> Por departamento <small class="card-header-sub">ativos</small></h3>
            </div>
            <div class="card-body">
                <?php if (empty($deptData)): ?>
                    <div class="empty-state"><p>Nenhum departamento com conversas ativas.</p></div>
                <?php else: ?>
                    <?php $deptMax = max(array_column($deptData, 'total')) ?: 1; ?>
                    <?php foreach ($deptData as $dept): $dpct = round($dept['total'] / $deptMax * 100); ?>
                        <div class="dept-bar-row">
                            <div class="hbar-top">
                                <span class="hbar-dot" style="background:<?= e($dept['color'] ?? '#6c757d') ?>"></span>
                                <span class="dept-bar-name"><?= e($dept['name']) ?></span>
                                <span class="hbar-pct"><?= $dpct ?>%</span>
                                <span class="dept-bar-count"><?= $dept['total'] ?></span>
                            </div>
                            <div class="dept-bar-track">
                                <div class="dept-bar-fill" style="width:<?= $dpct ?>%;background:<?= e($dept['color'] ?? '#6c757d') ?>"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card chart-card">
            <div class="card-header">
                <h3><i class="fas fa-inbox" style="color:var(--primary)"></i> Por caixa <small class="card-header-sub">em aberto</small></h3>
            </div>
            <div class="card-body">
                <?php if (empty($inboxes)): ?>
                    <div class="empty-state"><p>Nenhuma caixa disponível.</p></div>
                <?php else: ?>
                    <?php $inboxMax = max($openByInbox) ?: 1; ?>
                    <?php foreach ($inboxes as $ib): $ibCount = $openByInbox[$ib['id']] ?? 0; $ibpct = round($ibCount / $inboxMax * 100); ?>
                        <div class="dept-bar-row">
                            <div class="hbar-top">
                                <i class="fas fa-inbox hbar-icon"></i>
                                <span class="dept-bar-name"><?= e($ib['name']) ?></span>
                                <span class="hbar-pct"><?= $ibpct ?>%</span>
                                <span class="dept-bar-count"><?= $ibCount ?></span>
                            </div>
                            <div class="dept-bar-track">
                                <div class="dept-bar-fill" style="width:<?= $ibpct ?>%;background:var(--primary)"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Recent conversations -->
    <div class="card chart-card" style="overflow:hidden;margin-top:16px">
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
    <div class="card chart-card" style="margin-top:16px;overflow:hidden">
        <div class="card-header">
            <h3><i class="fas fa-trophy" style="color:var(--primary)"></i> Performance dos Atendentes</h3>
            <a href="<?= url('reports/agents') ?>" class="btn btn-sm btn-outline">Relatório completo <i class="fas fa-arrow-right" style="font-size:10px"></i></a>
        </div>
        <div class="table-wrap">
            <table class="table table-hover agent-table">
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
                        <td>
                            <div class="user-cell">
                                <div class="agent-avatar"><?= mb_strtoupper(mb_substr($a['name'], 0, 1)) ?></div>
                                <strong><?= e($a['name']) ?></strong>
                            </div>
                        </td>
                        <td>
                            <div class="agent-progress">
                                <div class="agent-progress-track">
                                    <div class="agent-progress-fill" style="width:<?= round($a['active_convos'] / $maxActive * 100) ?>%"></div>
                                </div>
                                <span class="agent-progress-num"><?= $a['active_convos'] ?></span>
                            </div>
                        </td>
                        <td><span class="badge badge-secondary"><?= $a['msgs_7d'] ?></span></td>
                        <td><span class="badge badge-success"><?= $a['resolved_30d'] ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
.dash-header{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:6px;flex-wrap:wrap;gap:12px}
.dash-eyebrow{font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:var(--brand-2)}
.dash-title{font-size:24px;font-weight:800;margin:2px 0 0;letter-spacing:-.02em}
.dash-subtitle{margin:4px 0 0;font-size:13px;color:var(--text-muted)}
.dash-subtitle i{font-size:11px}
.dash-header-actions{display:flex;gap:8px;align-items:center}
.dash-updated{font-size:12px;color:var(--text-muted);background:var(--bg-panel);border:1px solid var(--border-soft);padding:7px 12px;border-radius:999px;display:inline-flex;align-items:center;gap:6px}
.dash-attention{display:flex;align-items:center;gap:10px;background:#fff8eb;border:1px solid #f5dfae;color:#8a5a00;border-radius:12px;padding:10px 14px;font-size:13px;margin:12px 0 4px}
.dash-attention strong{font-weight:800}
.dash-attention-icon{width:30px;height:30px;border-radius:9px;background:#f59e0b;color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0}
.dash-attention-go{margin-left:auto;font-size:12px}
.dash-section-title{display:flex;align-items:center;justify-content:space-between;margin:18px 0 10px;font-size:11px;font-weight:800;letter-spacing:.09em;text-transform:uppercase;color:var(--text-muted)}
.dash-section-link{font-size:12px;font-weight:700;color:var(--brand-2);text-transform:none;letter-spacing:0;display:inline-flex;align-items:center;gap:5px}
.dash-section-link i{font-size:10px}
.card-header-sub{font-size:11px;font-weight:600;color:var(--text-muted);margin-left:2px}
.dash-stats{grid-template-columns:repeat(auto-fill,minmax(210px,1fr))}
.stat-card{position:relative;overflow:hidden;transition:transform .18s ease,box-shadow .18s ease;min-height:112px;align-items:center}
.stat-card .stat-info{flex:1;min-width:0}
.stat-card:hover{transform:none;box-shadow:none;border-color:var(--border-strong)}
.stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:var(--brand);opacity:0;transition:opacity .18s}
.stat-card:hover::before{opacity:1}
.stat-sub{font-size:11px;color:var(--text-muted);display:flex;align-items:center;gap:5px;margin-top:1px}
.stat-suffix{font-weight:700;color:var(--text-secondary)}
.stat-delta{font-size:11px;font-weight:700;display:inline-flex;align-items:center;gap:4px;margin-top:3px}
.delta-up{color:var(--success)}
.delta-down{color:var(--danger)}
.delta-new{color:var(--info)}
.stat-icon-violet{background:#efedfd;color:#4c1d95}
.stat-icon-amber{background:var(--warning-soft);color:#b45309}
.stat-icon-success{background:var(--success-soft);color:var(--success)}
.stat-icon-danger{background:var(--danger-soft);color:var(--danger)}
.stat-icon-neutral{background:#eef1f6;color:#4b5162}
.stat-icon-primary{background:var(--brand-soft);color:var(--brand-2)}
.stat-icon-info{background:var(--info-soft);color:var(--info)}
.stat-icon-warning{background:var(--warning-soft);color:#b45309}
.stat-hero{border-color:#d9d6fa;box-shadow:0 1px 3px rgba(67,56,202,.12)}
.stat-value{font-variant-numeric:tabular-nums}
.stat-value.stat-pulse{animation:statPulse .5s ease}
@keyframes statPulse{0%{transform:scale(1)}40%{transform:scale(1.18)}100%{transform:scale(1)}}

.dash-myrow{display:flex;align-items:center;gap:18px;flex-wrap:wrap;background:var(--bg-panel);border:1px solid var(--border-soft);border-radius:var(--radius-lg);padding:12px 16px;margin-top:0;box-shadow:var(--shadow-sm)}
.dash-myhead{display:flex;align-items:center;gap:10px}
.dash-myicon{width:38px;height:38px;border-radius:11px;background:var(--brand-soft);color:var(--brand-2);display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}
.dash-myhead strong{font-size:14px;display:block}
.dash-myhead small{font-size:11.5px;color:var(--text-muted)}
.dash-mystats{display:flex;gap:8px;flex-wrap:wrap;margin-left:auto}
.my-stat{display:flex;align-items:center;gap:8px;background:var(--bg-panel-alt);border:1px solid var(--border-soft);border-radius:12px;padding:8px 14px;transition:.15s}
.my-stat:hover{transform:translateY(-1px)}
.my-stat-value{font-size:18px;font-weight:800;font-variant-numeric:tabular-nums}
.my-stat-label{font-size:11.5px;color:var(--text-muted);display:flex;align-items:center;gap:4px}
.my-stat-danger .my-stat-value{color:var(--danger)}

.chart-card{background:var(--bg-panel);border:1px solid var(--border-soft);border-radius:var(--radius-lg);box-shadow:var(--shadow-sm)}
.chart-card .card-header{display:flex;align-items:center;justify-content:space-between;gap:10px;border-bottom:1px solid var(--border-soft);padding:14px 18px}
.chart-card .card-header h3{font-size:14px;font-weight:700;margin:0;display:flex;align-items:center;gap:8px}
.chart-card .card-body{padding:16px 18px}
.chart-legend{display:flex;gap:14px;font-size:11.5px;color:var(--text-muted);font-weight:600;align-items:center;flex-shrink:0}
.chart-legend-item{display:inline-flex;align-items:center;gap:6px}
.legend-dot{width:9px;height:9px;border-radius:50%;display:inline-block;flex-shrink:0}

.trend-chart{width:100%}
.trend-svg{width:100%;height:auto;display:block}
.trend-gridline{stroke:var(--border-soft);stroke-width:1;stroke-dasharray:3 5}
.trend-y{font-size:10px;fill:var(--text-muted);text-anchor:end}
.trend-x{font-size:10px;fill:var(--text-muted);text-anchor:middle}
.trend-area{pointer-events:none}
.trend-line{fill:none;stroke-width:2.4;stroke-linecap:round;stroke-linejoin:round}
.trend-line-conv{stroke:var(--primary)}
.trend-line-res{stroke:var(--success)}
.trend-dot{stroke:#fff;stroke-width:1.6}
.trend-dot-conv{fill:var(--primary)}
.trend-dot-res{fill:var(--success)}
.trend-hit{fill:transparent;cursor:pointer}

.donut-body{display:flex;flex-direction:column;align-items:center;gap:16px}
.donut-wrap{position:relative;width:170px}
.donut-svg{width:100%;height:auto}
.donut-track{fill:none;stroke:var(--bg-panel-alt);stroke-width:20}
.donut-seg{fill:none;stroke-width:20;transition:stroke-dashoffset .8s ease}
.donut-total{font-size:26px;font-weight:800;fill:var(--text-primary);font-variant-numeric:tabular-nums}
.donut-total-label{font-size:10px;fill:var(--text-muted);letter-spacing:.06em}
.donut-legend{width:100%;display:flex;flex-direction:column;gap:6px}
.donut-legend-row{display:flex;align-items:center;gap:8px;font-size:12.5px}
.donut-legend-name{flex:1;font-weight:600;color:var(--text-secondary)}
.donut-legend-count{font-weight:800;font-variant-numeric:tabular-nums;min-width:22px;text-align:right}
.donut-legend-pct{font-size:11px;color:var(--text-muted);font-weight:600;min-width:38px;text-align:right}

.hbar-top{display:flex;align-items:center;gap:8px;margin-bottom:5px}
.hbar-dot{width:9px;height:9px;border-radius:50%;flex-shrink:0}
.hbar-icon{color:var(--primary);font-size:12px;width:16px;text-align:center}
.hbar-pct{font-size:11px;font-weight:700;color:var(--text-muted);min-width:34px;text-align:right}
.dept-bar-count{font-size:13px;font-weight:800;color:var(--text-primary);min-width:22px;text-align:right;font-variant-numeric:tabular-nums}
.dept-bar-track{height:9px;background:var(--bg-panel-alt);border-radius:5px;overflow:hidden}
.dept-bar-fill{height:100%;border-radius:5px;transition:width .6s ease}

.agent-avatar{width:30px;height:30px;border-radius:50%;background:var(--brand);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:12px;flex-shrink:0}
.agent-table td{vertical-align:middle}
.agent-progress{display:flex;align-items:center;gap:8px;max-width:200px}
.agent-progress-track{flex:1;height:7px;background:var(--bg-panel-alt);border-radius:4px;overflow:hidden}
.agent-progress-fill{height:100%;border-radius:4px;background:linear-gradient(90deg,var(--brand),var(--brand-2));transition:width .6s ease}
.agent-progress-num{font-size:12px;font-weight:800;min-width:18px;text-align:right;font-variant-numeric:tabular-nums}

@media(max-width:1100px){.dashboard-grid{grid-template-columns:1fr !important}.dash-mystats{margin-left:0}}
@media(max-width:768px){.dash-title{font-size:20px}.dash-subtitle{margin-left:0}.dash-stats{grid-template-columns:repeat(auto-fill,minmax(150px,1fr))}}
</style>

<script>
(function () {
    var dashTimer;
    function fmtInt(n) { return String(Math.round(n)); }
    function fmtDecimal(n, d) { return n.toLocaleString('pt-BR', { minimumFractionDigits: d, maximumFractionDigits: d }); }

    function animateValue(el, end, decimals, empty) {
        if (empty) return;
        var start = 0, dur = 750, t0 = null;
        function step(ts) {
            if (!t0) t0 = ts;
            var p = Math.min((ts - t0) / dur, 1);
            var cur = start + (end - start) * p;
            el.textContent = decimals > 0 ? fmtDecimal(cur, decimals) : fmtInt(cur);
            if (p < 1) requestAnimationFrame(step);
            else el.textContent = decimals > 0 ? fmtDecimal(end, decimals) : fmtInt(end);
        }
        requestAnimationFrame(step);
    }

    function pulse(el) {
        if (!el) return;
        el.classList.remove('stat-pulse');
        void el.offsetWidth;
        el.classList.add('stat-pulse');
    }

    function refreshDashboard() {
        fetch('<?= base_url('api/dashboard-stats') ?>', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d) return;
                var i, el, ids;

                // Global stats
                var map = {
                    statGlobalOpen: d.globalOpen || 0,
                    statOnline: d.onlineUsers || 0,
                    statTodayConvs: d.todayConversations || 0,
                    statTodayMsgs: d.todayMessages || 0,
                    statTodayResolved: d.todayResolved || 0,
                    statTodayNew: (d.counts && (d.counts.new || 0)) || 0
                };
                for (var key in map) {
                    el = document.getElementById(key);
                    if (el) { el.textContent = map[key]; pulse(el); }
                }

                // CSAT
                el = document.getElementById('dashCsat');
                if (el) {
                    el.textContent = d.csatAvg !== null && d.csatAvg !== undefined ? fmtDecimal(Number(d.csatAvg), 1) : '—';
                    pulse(el);
                }

                // Deltas
                var deltas = [
                    ['dTodayConvs', d.todayConversations || 0, d.prevDayConversations || 0],
                    ['dTodayMsgs', d.todayMessages || 0, d.prevDayMessages || 0],
                    ['dTodayResolved', d.todayResolved || 0, d.prevDayResolved || 0]
                ];
                deltas.forEach(function (t) {
                    el = document.getElementById(t[0]);
                    if (!el) return;
                    var today = t[1], prev = t[2], html;
                    if (prev <= 0) {
                        html = '<i class="fas fa-sparkles"></i> Sem base ontem';
                        el.className = 'stat-delta delta-new';
                    } else {
                        var pct = Math.round((today - prev) / prev * 100);
                        var up = pct >= 0;
                        html = '<i class="fas fa-' + (up ? 'arrow-up' : 'arrow-down') + '"></i> ' + Math.abs(pct) + '% vs ontem';
                        el.className = 'stat-delta ' + (up ? 'delta-up' : 'delta-down');
                    }
                    el.innerHTML = html;
                });

                // My stats
                el = document.getElementById('myOpen'); if (el) { el.textContent = d.myOpen || 0; pulse(el); }
                el = document.getElementById('myWaiting'); if (el && d.myCounts) { el.textContent = d.myCounts.waiting_customer || 0; pulse(el); }
                el = document.getElementById('myInternal'); if (el && d.myCounts) { el.textContent = d.myCounts.waiting_internal || 0; pulse(el); }
                el = document.getElementById('myResolved'); if (el && d.myCounts) { el.textContent = d.myCounts.resolved || 0; pulse(el); }
                el = document.getElementById('myUnread'); if (el) { el.textContent = d.unread || 0; pulse(el); }

                // Status counts (donut legend)
                if (d.counts) {
                    var statusIds = {
                        new: 'gCountNew',
                        open: 'gCountOpen',
                        waiting_customer: 'gCountWaiting',
                        waiting_internal: 'gCountInternal',
                        resolved: 'gCountResolved'
                    };
                    Object.keys(statusIds).forEach(function (k) {
                        el = document.getElementById(statusIds[k]);
                        if (el) el.textContent = d.counts[k] || 0;
                    });
                }

                el = document.getElementById('dashUpdateTime');
                if (el) el.textContent = 'agora';
            })
            .catch(function () {});
    }

    // Initial count-up animations
    document.querySelectorAll('.stat-value[data-count]').forEach(function (el) {
        var end = parseFloat(el.getAttribute('data-count')) || 0;
        var dec = parseInt(el.getAttribute('data-decimals') || '0', 10);
        var empty = el.getAttribute('data-empty') === '1';
        animateValue(el, end, dec, empty);
    });

    window.refreshDashboard = refreshDashboard;
    dashTimer = setInterval(refreshDashboard, 20000);
})();
</script>
