<?php $flashSuccess = \App\Core\Session::getFlash('success'); $flashError = \App\Core\Session::getFlash('error'); ?>
<div class="group-show-page">
    <div class="page-actions">
        <a href="<?= url('whatsapp/groups') ?>" class="btn btn-sm btn-outline" title="Voltar"><i class="fas fa-arrow-left"></i> Voltar</a>
        <?php if ($isManager): ?>
            <form action="<?= url('whatsapp/groups/') ?><?= (int) $group['id'] ?>/alert" method="POST" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="mention_alert" value="<?= $group['mention_alert'] ? '0' : '1' ?>">
                <button type="submit" class="btn btn-sm <?= $group['mention_alert'] ? 'btn-outline' : 'btn-secondary' ?>">
                    <i class="fas fa-bell<?= $group['mention_alert'] ? '' : '-slash' ?>"></i>
                    <?= $group['mention_alert'] ? 'Alerta ativo' : 'Alerta inativo' ?>
                </button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($flashSuccess): ?><div class="alert alert-success"><?= e($flashSuccess) ?></div><?php endif; ?>
    <?php if ($flashError): ?><div class="alert alert-danger"><?= e($flashError) ?></div><?php endif; ?>

    <div class="group-meta card">
        <div class="card-body">
            <div><strong>JID:</strong> <code><?= e($group['group_jid']) ?></code></div>
            <div><strong>Conexão:</strong> <?= e($connection['phone_number'] ?? '-') ?> (<?= e($connection['provider'] ?? '') ?>)</div>
            <?php if (!empty($conversationId)): ?>
                <div><a href="<?= url('inbox') ?>?conv=<?= (int) $conversationId ?>" class="btn btn-sm btn-outline"><i class="fas fa-inbox"></i> Abrir conversa na caixa</a></div>
            <?php endif; ?>
            <?php if ($isManager): ?>
                <form action="<?= url('whatsapp/groups/') ?><?= (int) $group['id'] ?>/inbox" method="POST" class="inbox-form">
                    <?= csrf_field() ?>
                    <label><strong>Caixa onde aparecem as conversas deste grupo:</strong></label>
                    <select name="inbox_id" class="form-control" onchange="this.form.submit()" title="As mensagens do grupo aparecem como conversa nesta caixa">
                        <option value="0">Caixa do canal (padrão)</option>
                        <?php foreach ($inboxes as $ib): ?>
                            <option value="<?= (int) $ib['id'] ?>" <?= ((int) ($group['inbox_id'] ?? 0) === (int) $ib['id']) ? 'selected' : '' ?>><?= e($ib['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-hint">Quando alguém <strong>marcar o número da conexão ou @todos</strong>, o sino avisa os membros desta caixa.</small>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mt-2">
        <div class="card-header"><h3><i class="fas fa-at"></i> Menções ao número da conexão</h3></div>
        <div class="card-body p-0">
            <?php if (empty($mentions)): ?>
                <div class="empty-state"><p>Nenhuma menção registrada neste grupo.</p></div>
            <?php else: ?>
                <table class="table">
                    <thead><tr><th>Quando</th><th>Quem</th><th>Mensagem</th></tr></thead>
                    <tbody>
                        <?php foreach ($mentions as $m): ?>
                            <tr>
                                <td style="white-space:nowrap"><?= format_datetime($m['created_at']) ?></td>
                                <td><strong><?= e($m['sender_name'] ?: $m['sender_phone'] ?: '?') ?></strong><br><small class="form-hint"><?= e($m['sender_phone'] ?? '') ?></small></td>
                                <td><?= e($m['content'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($isManager): ?>
        <div class="card mt-2">
            <div class="card-header"><h3><i class="fas fa-paper-plane"></i> Enviar mensagem ao grupo</h3></div>
            <div class="card-body">
                <form action="<?= url('whatsapp/groups/') ?><?= (int) $group['id'] ?>/send" method="POST" class="send-form">
                    <?= csrf_field() ?>
                    <textarea name="message" class="form-control" rows="2" maxlength="4000" placeholder="Digite a mensagem enviada pelo número conectado..." required></textarea>
                    <button type="submit" class="btn btn-primary btn-sm mt-1"><i class="fas fa-paper-plane"></i> Enviar</button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
.group-show-page .group-meta .card-body { display: flex; flex-direction: column; gap: 8px; }
.group-show-page .inbox-form { display: flex; align-items: center; gap: 8px; }
.group-show-page .inbox-form .form-control { max-width: 320px; }
.group-show-page .form-hint { font-size: 12px; color: #6c757d; }
.group-show-page .empty-state { text-align: center; padding: 32px 20px; color: #6c757d; }
.group-show-page .send-form { display: flex; flex-direction: column; gap: 8px; }
.group-show-page .mt-2 { margin-top: 16px; }
.group-show-page .mt-1 { margin-top: 8px; }
.alert { padding: 10px 14px; border-radius: 8px; margin-bottom: 12px; }
.alert-success { background: #e6f4ea; color: #1b5e20; }
.alert-danger { background: #fdecea; color: #8e1414; }
</style>
