<?php $flashSuccess = \App\Core\Session::getFlash('success'); $flashError = \App\Core\Session::getFlash('error'); ?>
<div class="groups-page">
    <div class="page-actions">
        <form method="get" action="<?= url('whatsapp/groups') ?>" class="toolbar-filter">
            <select name="connection" class="form-control" onchange="this.form.submit()">
                <option value="">Todas as conexões</option>
                <?php foreach ($connections as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= ((string) $filterConnection === (string) $c['id']) ? 'selected' : '' ?>>
                        <?= e($c['channel_name']) ?> (<?= e($c['phone_number'] ?? 'sem número') ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if ($flashSuccess): ?><div class="alert alert-success"><?= e($flashSuccess) ?></div><?php endif; ?>
    <?php if ($flashError): ?><div class="alert alert-danger"><?= e($flashError) ?></div><?php endif; ?>

    <p class="form-hint">Grupos aparecem aqui automaticamente quando chega a primeira mensagem de cada grupo. Escolha em <strong>qual caixa as conversas do grupo aparecem</strong> — o sino avisa os membros dessa caixa quando alguém <strong>marcar o número da conexão ou @todos</strong>. Ative/desative o alerta por grupo.</p>

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
                        <tr><th>Grupo</th><th>Conexão</th><th>Caixa das conversas</th><th>Menções ao número</th><th>Alerta</th><th>Última msg</th><th>Ações</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($groups as $g): ?>
                            <tr>
                                <td>
                                    <a href="<?= url('whatsapp/groups/') ?><?= (int) $g['id'] ?>"><strong><?= e($g['name'] ?: '(sem nome)') ?></strong></a>
                                    <br><small class="form-hint"><?= e($g['group_jid']) ?><?= $g['participant_count'] ? ' · ' . (int) $g['participant_count'] . ' participantes' : '' ?></small>
                                    <?php if (!empty($g['conversation_id'])): ?>
                                        <br><a href="<?= url('inbox') ?>?conv=<?= (int) $g['conversation_id'] ?>" class="btn btn-xs btn-outline" title="Abrir conversa na caixa"><i class="fas fa-inbox"></i> Abrir na caixa</a>
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
                                    <?php if ((int) $g['unread_mentions'] > 0): ?>
                                        <span class="badge badge-danger" title="Vezes em que marcaram o número da conexão"><?= (int) $g['unread_mentions'] ?> nova(s)</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">0</span>
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
.groups-page .toolbar-filter .form-control { min-width: 260px; }
.groups-page .form-hint { font-size: 12px; color: #6c757d; }
.groups-page .empty-state { text-align: center; padding: 40px 20px; color: #6c757d; }
.groups-page .action-cell { white-space: nowrap; }
.groups-page .inbox-inline-form .form-control-sm { min-width: 200px; font-size: 13px; }
.groups-page .btn-xs { font-size: 12px; padding: 2px 8px; margin-top: 4px; display: inline-block; }
.groups-page .alert { padding: 10px 14px; border-radius: 8px; margin-bottom: 12px; }
.groups-page .alert-success { background: #e6f4ea; color: #1b5e20; }
.groups-page .alert-danger { background: #fdecea; color: #8e1414; }
</style>
