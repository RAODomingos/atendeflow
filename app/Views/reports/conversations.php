<div class="reports-page">
    <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px">
        <div>
            <h1 style="font-size:24px;font-weight:700;margin:0;display:flex;align-items:center;gap:10px">
                <i class="fas fa-comments" style="color:var(--primary)"></i> Conversas
            </h1>
            <p style="margin:4px 0 0;font-size:14px;color:var(--text-muted)">Volume, tendências e distribuição</p>
        </div>
        <form method="GET" style="display:flex;gap:8px;align-items:center">
            <select name="period" class="form-control form-control-sm" style="width:auto" onchange="this.form.submit()">
                <option value="7" <?= $period === '7' ? 'selected' : '' ?>>Últimos 7 dias</option>
                <option value="30" <?= $period === '30' ? 'selected' : '' ?>>Últimos 30 dias</option>
                <option value="90" <?= $period === '90' ? 'selected' : '' ?>>Últimos 90 dias</option>
                <option value="all" <?= $period === 'all' ? 'selected' : '' ?>>Todo período</option>
            </select>
        </form>
    </div>

    <!-- Tendência -->
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-chart-line" style="color:var(--primary)"></i> Tendência</h3></div>
        <div class="card-body">
            <?php if (empty($trendValues)): ?>
                <div class="empty-state"><p>Nenhum dado no período.</p></div>
            <?php else: ?>
                <?php $maxVal = max($trendValues) ?: 1; ?>
                <div class="trend-chart" style="display:flex;align-items:flex-end;justify-content:space-around;gap:4px;min-height:200px;padding:8px 0">
                    <?php foreach ($trendValues as $i => $val): ?>
                        <div class="trend-bar-item" style="flex:1;display:flex;flex-direction:column;align-items:center">
                            <span style="font-size:11px;color:var(--text-muted);margin-bottom:4px"><?= $val ?></span>
                            <div style="width:100%;max-width:36px;display:flex;justify-content:center">
                                <div style="height:<?= ($val / $maxVal) * 160 ?>px;width:100%;background:linear-gradient(to top,var(--primary),#60a5fa);border-radius:4px 4px 0 0;transition:height 0.5s ease;min-height:4px"></div>
                            </div>
                            <span style="font-size:10px;color:var(--text-muted);margin-top:4px"><?= $trendLabels[$i] ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="dashboard-grid" style="grid-template-columns:1fr 1fr;margin-top:0">
        <!-- Por Status -->
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-tasks" style="color:var(--primary)"></i> Por Status</h3></div>
            <div class="card-body">
                <?php if (empty($statusData)): ?>
                    <div class="empty-state"><p>Nenhum dado.</p></div>
                <?php else: ?>
                    <?php $stTotal = array_sum(array_column($statusData, 'total')); ?>
                    <?php
                    $colors = ['new'=>'var(--info)','open'=>'var(--primary)','waiting_customer'=>'var(--warning)','waiting_internal'=>'var(--secondary)','resolved'=>'var(--success)','closed'=>'#6c757d','spam'=>'var(--danger)'];
                    ?>
                    <?php foreach ($statusData as $st): ?>
                        <?php $pct = $stTotal > 0 ? round($st['total'] / $stTotal * 100) : 0; ?>
                        <div style="margin-bottom:10px">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
                                <span style="width:8px;height:8px;border-radius:50%;background:<?= $colors[$st['status']] ?? '#6c757d' ?>;flex-shrink:0"></span>
                                <span style="font-size:13px;font-weight:500;flex:1;text-transform:capitalize"><?= e(str_replace('_', ' ', $st['status'])) ?></span>
                                <span style="font-size:13px;font-weight:700;color:var(--text-muted)"><?= $st['total'] ?> (<?= $pct ?>%)</span>
                            </div>
                            <div style="height:8px;background:var(--bg-content);border-radius:4px;overflow:hidden">
                                <div style="height:100%;width:<?= $pct ?>%;background:<?= $colors[$st['status']] ?? '#6c757d' ?>;border-radius:4px;transition:width 0.5s ease"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Por Canal -->
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-share-alt" style="color:var(--primary)"></i> Por Canal</h3></div>
            <div class="card-body">
                <?php if (empty($channelData)): ?>
                    <div class="empty-state"><p>Nenhum dado.</p></div>
                <?php else: ?>
                    <?php $chTotal = array_sum(array_column($channelData, 'total')); ?>
                    <?php foreach ($channelData as $ch): ?>
                        <?php $pct = $chTotal > 0 ? round($ch['total'] / $chTotal * 100) : 0; ?>
                        <div style="margin-bottom:10px">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
                                <i class="<?= channel_icon($ch['type']) ?>" style="width:16px;color:var(--primary)"></i>
                                <span style="font-size:13px;font-weight:500;flex:1;text-transform:capitalize"><?= e($ch['type']) ?></span>
                                <span style="font-size:13px;font-weight:700;color:var(--text-muted)"><?= $ch['total'] ?> (<?= $pct ?>%)</span>
                            </div>
                            <div style="height:8px;background:var(--bg-content);border-radius:4px;overflow:hidden">
                                <div style="height:100%;width:<?= $pct ?>%;background:var(--primary);border-radius:4px;transition:width 0.5s ease"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="dashboard-grid" style="grid-template-columns:1fr 1fr;margin-top:0">
        <!-- Por Departamento -->
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-layer-group" style="color:var(--primary)"></i> Por Departamento</h3></div>
            <div class="card-body">
                <?php if (empty($deptData)): ?>
                    <div class="empty-state"><p>Nenhum dado.</p></div>
                <?php else: ?>
                    <?php $dpMax = max(array_column($deptData, 'total')) ?: 1; ?>
                    <?php foreach ($deptData as $dp): ?>
                        <div style="margin-bottom:10px">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
                                <span style="width:8px;height:8px;border-radius:50%;background:<?= e($dp['color'] ?? '#6c757d') ?>;flex-shrink:0"></span>
                                <span style="font-size:13px;font-weight:500;flex:1"><?= e($dp['name']) ?></span>
                                <span style="font-size:13px;font-weight:700;color:var(--text-muted)"><?= $dp['total'] ?></span>
                            </div>
                            <div style="height:8px;background:var(--bg-content);border-radius:4px;overflow:hidden">
                                <div style="height:100%;width:<?= ($dp['total'] / $dpMax) * 100 ?>%;background:<?= e($dp['color'] ?? '#6c757d') ?>;border-radius:4px;transition:width 0.5s ease"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Top Contatos -->
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-address-book" style="color:var(--primary)"></i> Top Contatos</h3></div>
            <div class="card-body p-0">
                <?php if (empty($topContacts)): ?>
                    <div class="empty-state"><p>Nenhum contato com múltiplas conversas.</p></div>
                <?php else: ?>
                    <table class="table table-hover">
                        <thead><tr><th>Nome</th><th>Conversas</th></tr></thead>
                        <tbody>
                            <?php foreach ($topContacts as $ct): ?>
                            <tr>
                                <td><?= e($ct['name']) ?><br><small style="color:var(--text-muted)"><?= e($ct['email'] ?: $ct['phone'] ?: '') ?></small></td>
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
