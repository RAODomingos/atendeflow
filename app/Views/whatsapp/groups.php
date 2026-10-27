<?php $flashSuccess = \App\Core\Session::getFlash('success'); $flashError = \App\Core\Session::getFlash('error'); ?>
<div class="groups-page">
    <div class="page-actions">
        <form method="get" action="<?= url('whatsapp/groups') ?>" class="toolbar-filter">
            <select name="connection" class="form-control" onchange="this.form.submit()" aria-label="Conexão">
                <option value="">Todas as conexões</option>
                <?php foreach ($connections as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= ((string) $filterConnection === (string) $c['id']) ? 'selected' : '' ?>>
                        <?= e($c['channel_name']) ?> (<?= e($c['phone_number'] ?? 'sem número') ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="search" name="search" class="form-control" placeholder="Buscar grupo por nome ou JID"
                   value="<?= e($filterSearch ?? '') ?>" aria-label="Buscar grupo">
            <button type="submit" class="btn btn-outline btn-sm"><i class="fas fa-search"></i> Buscar</button>
            <?php if (!empty($filterSearch) || !empty($filterConnection)): ?>
                <a href="<?= url('whatsapp/groups') ?>" class="btn btn-link btn-sm">Limpar</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($flashSuccess): ?><div class="alert alert-success"><?= e($flashSuccess) ?></div><?php endif; ?>
    <?php if ($flashError): ?><div class="alert alert-danger"><?= e($flashError) ?></div><?php endif; ?>

    <p class="form-hint">
        Grupos aparecem aqui automaticamente quando chega a primeira mensagem de cada grupo.
        Escolha em <strong>qual caixa as conversas do grupo aparecem</strong> — o sino avisa os membros
        dessa caixa <strong>somente quando marcam o número da conexão ou @todos</strong>; o resto do
        histórico fica visível na caixa, sem alerta.
    </p>

    <div class="card">
        <div class="card-body p-0">
            <?php if (empty($groups)): ?>
                <div class="empty-state">
                    <p><i class="fas fa-users fa-2x"></i></p>
                    <p>Nenhum grupo registrado ainda. Grupos entram na lista ao receber mensagens.</p>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Grupo</th><th>Conexão</th><th>Caixa das conversas</th>
                            <th>Menções</th><th>Alerta</th><th>Monitorado</th><th>Última msg</th><th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($groups as $g): ?>
                        <?php
                        $monitored = \App\Models\WhatsAppGroup::isMonitored($g);
                        $participants = (int) ($g['participant_count'] ?? 0);
                        ?>
                        <tr class="<?= $monitored ? '' : 'group-row-off' ?>">
                            <td>
                                <div class="group-cell">
                                    <span class="group-avatar">
                                        <?php if (!empty($g['avatar_url'])): ?>
                                            <img src="<?= e($g['avatar_url']) ?>" alt="" loading="lazy" onerror="this.remove()">
                                        <?php else: ?>
                                            <?= e(mb_strtoupper(mb_substr($g['name'] ?: '?', 0, 1))) ?>
                                        <?php endif; ?>
                                    </span>
                                    <span class="group-cell-text">
                                        <a href="<?= url('whatsapp/groups/') ?><?= (int) $g['id'] ?>" class="group-name-link"><strong><?= e($g['name'] ?: '(sem nome)') ?></strong></a>
                                        <small class="form-hint">
                                            <?= e($g['group_jid']) ?>
                                            <?= $participants > 0 ? ' · ' . $participants . ' participantes' : '' ?>
                                        </small>
                                    </span>
                                </div>
                                <?php if (!empty($g['conversation_id'])): ?>
                                    <a href="<?= url('inbox') ?>?conv=<?= (int) $g['conversation_id'] ?>" class="btn btn-xs btn-outline" title="Abrir conversa na caixa"><i class="fas fa-inbox"></i> Abrir na caixa</a>
                                <?php endif; ?>
                            </td>
                            <td><?= e($g['channel_name']) ?><br><small class="form-hint"><?= e($g['connection_phone'] ?? '-') ?></small></td>
                            <td>
                                <?php if ($isManager): ?>
                                    <form action="<?= url('whatsapp/groups/') ?><?= (int) $g['id'] ?>/inbox" method="POST" class="inbox-inline-form">
                                        <?= csrf_field() ?>
                                        <select name="inbox_id" class="form-control form-control-sm" onchange="this.form.submit()" title="Caixa onde aparecem as conversas deste grupo">
                                            <option value="0">Caixa do canal (padrão)</option>
                                            <?php foreach ($inboxes as $ib): ?>
                                                <option value="<?= (int) $ib['id'] ?>" <?= ((int) ($g['inbox_id'] ?? 0) === (int) $ib['id']) ? 'selected' : '' ?>><?= e($ib['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                <?php else: ?>
                                    <?= e($g['inbox_name'] ?? 'Caixa do canal') ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $totalMentions = (int) ($g['total_mentions'] ?? 0);
                                $unreadMentions = (int) ($g['unread_mentions'] ?? 0);
                                ?>
                                <?php if ($totalMentions === 0): ?>
                                    <span class="badge badge-secondary" title="Ninguém marcou o número da conexão neste grupo">0</span>
                                <?php elseif ($unreadMentions > 0): ?>
                                    <span class="badge badge-danger" title="Menções ainda não vistas"><?= $unreadMentions ?> nova(s)</span>
                                    <?php if ($totalMentions > $unreadMentions): ?>
                                        <small class="form-hint">de <?= $totalMentions ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge badge-secondary" title="Total de menções já vistas"><?= $totalMentions ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isManager): ?>
                                    <form action="<?= url('whatsapp/groups/') ?><?= (int) $g['id'] ?>/alert" method="POST" style="display:inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="mention_alert" value="<?= $g['mention_alert'] ? '0' : '1' ?>">
                                        <button type="submit" class="btn btn-sm <?= $g['mention_alert'] ? 'btn-outline' : 'btn-secondary' ?>" title="<?= $g['mention_alert'] ? 'Desativar alerta de menção' : 'Ativar alerta de menção' ?>">
                                            <i class="fas fa-bell<?= $g['mention_alert'] ? '' : '-slash' ?>"></i>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="badge <?= $g['mention_alert'] ? 'badge-success' : 'badge-secondary' ?>"><?= $g['mention_alert'] ? 'Ativo' : 'Inativo' ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isManager): ?>
                                    <form action="<?= url('whatsapp/groups/') ?><?= (int) $g['id'] ?>/monitor" method="POST" style="display:inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="monitored" value="<?= $monitored ? '0' : '1' ?>">
                                        <button type="submit" class="btn btn-sm <?= $monitored ? 'btn-outline' : 'btn-secondary' ?>"
                                                title="<?= $monitored ? 'Arquivar grupo (para de espelhar e de avisar)' : 'Monitorar grupo' ?>">
                                            <i class="fas fa-<?= $monitored ? 'eye' : 'eye-slash' ?>"></i>
                                            <?= $monitored ? 'Monitorado' : 'Arquivado' ?>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="badge <?= $monitored ? 'badge-success' : 'badge-secondary' ?>"><?= $monitored ? 'Monitorado' : 'Arquivado' ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= $g['last_message_at'] ? format_datetime($g['last_message_at']) : '-' ?></td>
                            <td class="action-cell">
                                <a href="<?= url('whatsapp/groups/') ?><?= (int) $g['id'] ?>" class="btn btn-sm btn-outline" title="Abrir"><i class="fas fa-eye"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.groups-page .toolbar-filter{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.groups-page .page-actions{justify-content:flex-start;margin-bottom:12px;}
.groups-page .toolbar-filter .form-control{width:auto;max-width:260px;}
.groups-page .form-hint{font-size:12px;color:var(--text-muted);}
.groups-page .empty-state{text-align:center;padding:40px 20px;color:var(--text-secondary);}
.groups-page .action-cell{white-space:nowrap;}
.groups-page .group-cell{display:flex;align-items:center;gap:10px;}
.groups-page .group-cell-text{display:flex;flex-direction:column;gap:2px;min-width:0;}
.groups-page .group-name-link{display:inline-block;padding:2px 0;min-height:22px;color:var(--text-primary);}
.groups-page .group-name-link:hover{color:var(--brand-dark);}
.groups-page .group-avatar{
    width:34px;height:34px;border-radius:50%;flex-shrink:0;overflow:hidden;
    background:var(--brand-soft);color:var(--brand-text);
    display:flex;align-items:center;justify-content:center;
    font-size:13px;font-weight:700;
}
.groups-page .group-avatar img{width:100%;height:100%;object-fit:cover;}
.groups-page .inbox-inline-form .form-control-sm{min-width:180px;font-size:13px;}
.groups-page .btn-xs{font-size:12px;padding:2px 8px;margin-top:4px;display:inline-block;}
.groups-page .group-row-off{opacity:.62;}
.groups-page .group-row-off .group-name-link{text-decoration:line-through;}
.groups-page .alert{padding:10px 14px;border-radius:8px;margin-bottom:12px;}
.groups-page .alert-success{background:var(--success-soft);color:var(--success-text);}
.groups-page .alert-danger{background:var(--danger-soft);color:var(--danger-text);}
</style>
