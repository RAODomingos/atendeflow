<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 18mm 14mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #1a1a2e; line-height: 1.5; }
    .header { text-align: center; border-bottom: 2px solid #7c5cff; padding-bottom: 12px; margin-bottom: 20px; }
    .header h1 { font-size: 16pt; color: #7c5cff; margin: 0 0 4px; }
    .header .sub { font-size: 8pt; color: #888; }
    .section { margin-bottom: 16px; }
    .section-title { font-size: 11pt; font-weight: bold; color: #7c5cff; border-bottom: 1px solid #e0e0e0; padding-bottom: 4px; margin-bottom: 8px; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data td { padding: 4px 8px; vertical-align: top; }
    table.data td:first-child { font-weight: bold; width: 110px; color: #555; }
    table.data tr:nth-child(even) { background: #f8f6ff; }
    .tag { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 8pt; margin: 1px 2px; }
    .conv { border: 1px solid #eee; border-radius: 5px; padding: 8px 10px; margin-bottom: 6px; }
    .conv-header { display: flex; justify-content: space-between; font-size: 8pt; color: #888; margin-bottom: 3px; }
    .conv-subject { font-size: 10pt; font-weight: bold; margin: 2px 0; }
    .conv-meta { font-size: 8pt; color: #666; display: flex; gap: 10px; }
    .conv-footer { font-size: 8pt; color: #999; margin-top: 4px; padding-top: 4px; border-top: 1px solid #f0f0f0; display: flex; gap: 12px; }
    .badge { display: inline-block; padding: 1px 6px; border-radius: 3px; font-size: 7.5pt; font-weight: bold; }
    .badge-success { background: #e6f9ef; color: #12b76a; }
    .badge-info { background: #eaf4ff; color: #2e90fa; }
    .badge-warning { background: #fef3e2; color: #b45309; }
    .badge-danger { background: #fee4e2; color: #f04438; }
    .badge-neutral { background: #f1f2f5; color: #6b7280; }
    .notes-box { background: #fef9e7; border: 1px solid #f9e79f; border-radius: 5px; padding: 8px 10px; font-size: 9pt; }
    .extra-list { margin: 0; padding: 0; list-style: none; }
    .extra-list li { padding: 2px 0; font-size: 9pt; }
    .extra-list li .label { color: #999; font-size: 8pt; }
    .footer { text-align: center; font-size: 7.5pt; color: #aaa; border-top: 1px solid #ddd; padding-top: 8px; margin-top: 20px; }
</style>
</head>
<body>

<div class="header">
    <h1>Ficha do Contato</h1>
    <div class="sub">AtendeFlow &mdash; <?= format_datetime(date('Y-m-d H:i:s')) ?></div>
</div>

<div class="section">
    <div class="section-title">Informações Principais</div>
    <table class="data">
        <tr><td>Nome</td><td><?= e($contact['name'] ?? '-') ?></td></tr>
        <tr><td>E-mail</td><td><?= e($contact['email'] ?? '-') ?></td></tr>
        <tr><td>Telefone</td><td><?= e($contact['phone'] ?? '-') ?></td></tr>
        <tr><td>Empresa</td><td><?= e($contact['company'] ?? '-') ?></td></tr>
        <tr><td>CPF/CNPJ</td><td><?= e($contact['document'] ?? '-') ?></td></tr>
        <tr><td>Cadastrado em</td><td><?= format_datetime($contact['created_at'] ?? '') ?></td></tr>
        <tr><td>Última atividade</td><td><?= !empty($contact['last_activity_at']) ? format_datetime($contact['last_activity_at']) : '-' ?></td></tr>
    </table>
</div>

<?php if (!empty($contact['tags'])): ?>
<div class="section">
    <div class="section-title">Etiquetas</div>
    <div>
        <?php foreach ($contact['tags'] as $tag): ?>
            <span class="tag" style="background:<?= e($tag['color'] ?? '#6c757d') ?>;color:<?= e(contrast_color($tag['color'] ?? '#6c757d')) ?>"><?= e($tag['name']) ?></span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($contact['phones']) || !empty($contact['emails'])): ?>
<div class="section">
    <div class="section-title">Informações Adicionais</div>
    <?php if (!empty($contact['phones'])): ?>
        <strong style="font-size:9pt">Telefones</strong>
        <ul class="extra-list">
            <?php foreach ($contact['phones'] as $ph): ?>
                <li><?= e($ph['phone']) ?> <?= !empty($ph['label']) ? '<span class="label">(' . e($ph['label']) . ')</span>' : '' ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if (!empty($contact['emails'])): ?>
        <strong style="font-size:9pt">E-mails</strong>
        <ul class="extra-list">
            <?php foreach ($contact['emails'] as $em): ?>
                <li><?= e($em['email']) ?> <?= !empty($em['label']) ? '<span class="label">(' . e($em['label']) . ')</span>' : '' ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($contact['notes'])): ?>
<div class="section">
    <div class="section-title">Observações</div>
    <div class="notes-box"><?= nl2br(e($contact['notes'])) ?></div>
</div>
<?php endif; ?>

<div class="section">
    <div class="section-title">Conversas (<?= count($conversations) ?>)</div>
    <?php if (empty($conversations)): ?>
        <p style="color:#888;font-size:9pt">Nenhuma conversa registrada.</p>
    <?php else: ?>
        <?php
        $statusLabels = ['new'=>'Novo','open'=>'Aberto','waiting_customer'=>'Em Atendimento','waiting_internal'=>'Aguard. Interno','resolved'=>'Resolvido','closed'=>'Fechado','spam'=>'Spam'];
        $priorityLabels = ['low'=>'Baixa','normal'=>'Normal','high'=>'Alta','urgent'=>'Urgente'];
        $statusClasses = ['new'=>'badge-info','open'=>'badge-info','waiting_customer'=>'badge-warning','waiting_internal'=>'badge-neutral','resolved'=>'badge-success','closed'=>'badge-neutral','spam'=>'badge-danger'];
        ?>
        <?php foreach ($conversations as $conv): ?>
            <div class="conv">
                <div class="conv-header">
                    <span><strong>#<?= $conv['id'] ?></strong> &mdash; <?= e($conv['channel_name'] ?? $conv['channel_type'] ?? '-') ?></span>
                    <span><?= format_datetime($conv['created_at']) ?></span>
                </div>
                <div class="conv-subject"><?= e($conv['subject'] ?: truncate($conv['last_message'] ?? 'Sem mensagens', 120)) ?></div>
                <div class="conv-meta">
                    <span class="badge <?= $statusClasses[$conv['status']] ?? 'badge-neutral' ?>"><?= $statusLabels[$conv['status']] ?? $conv['status'] ?></span>
                    <span>Prioridade: <?= $priorityLabels[$conv['priority'] ?? 'normal'] ?? $conv['priority'] ?></span>
                    <?php if ($conv['department_name']): ?>
                        <span>Setor: <?= e($conv['department_name']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="conv-footer">
                    <span>Atendente: <?= e($conv['assigned_user_name'] ?? 'Não atribuído') ?></span>
                    <span>Mensagens: <?= (int)($conv['message_count'] ?? 0) ?></span>
                    <?php if (!empty($conv['unit'])): ?>
                        <span>Unidade: <?= e($conv['unit']) ?></span>
                    <?php endif; ?>
                </div>
                <?php if (in_array($conv['status'], ['resolved', 'closed']) && (!empty($conv['close_reason']) || !empty($conv['close_description']))): ?>
                    <div style="margin-top:4px;font-size:8pt;color:#888;border-top:1px dashed #eee;padding-top:4px">
                        <strong>Encerramento:</strong>
                        <?php if (!empty($conv['close_reason'])): ?><?= e($conv['close_reason']) ?><?php endif; ?>
                        <?php if (!empty($conv['close_description'])): ?> &mdash; <?= e($conv['close_description']) ?><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="footer">
    Relatório gerado em <?= format_datetime(date('Y-m-d H:i:s')) ?> &mdash; AtendeFlow
</div>

</body>
</html>