<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 20mm 15mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #1a1a2e; line-height: 1.5; }
    .header { text-align: center; border-bottom: 2px solid #4A90D9; padding-bottom: 10px; margin-bottom: 20px; }
    .header h1 { font-size: 16pt; color: #4A90D9; margin: 0 0 4px; }
    .header .sub { font-size: 9pt; color: #888; }
    .section { margin-bottom: 18px; }
    .section-title { font-size: 11pt; font-weight: bold; color: #4A90D9; border-bottom: 1px solid #ddd; padding-bottom: 4px; margin-bottom: 8px; }
    .info-grid { width: 100%; }
    .info-grid td { padding: 3px 8px; vertical-align: top; }
    .info-grid td:first-child { font-weight: bold; width: 130px; color: #555; }
    .contact-card { background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 6px; padding: 10px; margin-bottom: 10px; }
    .contact-card td { padding: 2px 8px; vertical-align: top; }
    .contact-card td:first-child { font-weight: bold; width: 100px; color: #555; }
    .msg { padding: 6px 10px; margin-bottom: 4px; border-radius: 4px; }
    .msg-out { background: #e3f2fd; border-left: 3px solid #4A90D9; }
    .msg-in { background: #f8f9fa; border-left: 3px solid #28A745; }
    .msg-note { background: #fff3cd; border-left: 3px solid #ffc107; font-style: italic; }
    .msg-system { background: #f1f0f0; border-left: 3px solid #888; text-align: center; font-size: 9pt; color: #666; }
    .msg-header { font-size: 8pt; color: #888; margin-bottom: 2px; }
    .msg-header strong { color: #333; }
    .msg-content { font-size: 10pt; word-wrap: break-word; }
    .msg-time { font-size: 7pt; color: #aaa; text-align: right; margin-top: 2px; }
    .msg-file { color: #4A90D9; text-decoration: none; }
    .msg-date-sep { text-align: center; font-size: 8pt; color: #888; margin: 10px 0 6px; border-bottom: 1px dashed #ddd; padding-bottom: 4px; }
    .event { padding: 3px 8px; margin-bottom: 2px; font-size: 9pt; color: #666; border-left: 2px solid #ddd; }
    .event strong { color: #333; }
    .event-time { font-size: 7pt; color: #aaa; }
    .csat-box { background: #fef9e7; border: 1px solid #f9e79f; border-radius: 6px; padding: 8px; text-align: center; font-size: 10pt; }
    .csat-stars { color: #f39c12; font-size: 14pt; }
    .footer { text-align: center; font-size: 8pt; color: #aaa; border-top: 1px solid #ddd; padding-top: 8px; margin-top: 20px; }
    .tag { display: inline-block; padding: 1px 6px; border-radius: 3px; font-size: 8pt; margin: 1px; }
</style>
</head>
<body>

<div class="header">
    <h1>Relatório de Conversa</h1>
    <div class="sub">AtendeFlow &mdash; <?= format_datetime(date('Y-m-d H:i:s')) ?></div>
</div>

<div class="section">
    <div class="section-title">Informações da Conversa</div>
    <table class="info-grid">
        <tr><td>ID</td><td><?= $conversation['id'] ?></td></tr>
        <tr><td>Status</td><td><?= ['new'=>'Novo','open'=>'Aberto','waiting_customer'=>'Em atendimento','waiting_internal'=>'Aguardando Interno','resolved'=>'Resolvido','closed'=>'Fechado','spam'=>'Spam'][$conversation['status']] ?? $conversation['status'] ?></td></tr>
        <tr><td>Prioridade</td><td><?= ['low'=>'Baixa','normal'=>'Normal','high'=>'Alta','urgent'=>'Urgente'][$conversation['priority'] ?? 'normal'] ?? $conversation['priority'] ?></td></tr>
        <tr><td>Canal</td><td><?= e($conversation['channel_name'] ?? '-') ?> (<?= e($conversation['channel_type'] ?? '-') ?>)</td></tr>
        <?php if (!empty($conversation['department_name'])): ?>
        <tr><td>Departamento</td><td><?= e($conversation['department_name']) ?></td></tr>
        <?php endif; ?>
        <tr><td>Responsável</td><td><?= e($conversation['assigned_user_name'] ?? 'Sem responsável') ?></td></tr>
        <tr><td>Assunto</td><td><?= e($conversation['subject'] ?? '-') ?></td></tr>
        <?php if (!empty($conversation['unit'])): ?>
        <tr><td>Unidade</td><td><?= e($conversation['unit']) ?></td></tr>
        <?php endif; ?>
        <tr><td>Criada em</td><td><?= format_datetime($conversation['created_at']) ?></td></tr>
        <?php if (!empty($conversation['closed_at'])): ?>
        <tr><td>Fechada em</td><td><?= format_datetime($conversation['closed_at']) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($conversation['close_reason'])): ?>
        <tr><td>Motivo</td><td><?= e($conversation['close_reason']) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($conversation['close_description'])): ?>
        <tr><td>Descrição</td><td><?= e($conversation['close_description']) ?></td></tr>
        <?php endif; ?>
    </table>
</div>

<div class="section">
    <div class="section-title">Contato</div>
    <table class="contact-card">
        <tr><td>Nome</td><td><?= e($contact['name'] ?? '-') ?></td></tr>
        <tr><td>Telefone</td><td><?= e($contact['phone'] ?? '-') ?></td></tr>
        <tr><td>E-mail</td><td><?= e($contact['email'] ?? '-') ?></td></tr>
        <?php if (!empty($contact['company'])): ?>
        <tr><td>Empresa</td><td><?= e($contact['company']) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($contact['document'])): ?>
        <tr><td>Documento</td><td><?= e($contact['document']) ?></td></tr>
        <?php endif; ?>
    </table>
</div>

<?php if (!empty($allTags)): ?>
<div class="section">
    <div class="section-title">Etiquetas</div>
    <div>
        <?php foreach ($allTags as $tag): ?>
            <?php if (in_array($tag['id'], array_column($conversation['tags'] ?? [], 'id'))): ?>
            <span class="tag" style="background:<?= e($tag['color'] ?? '#6c757d') ?>;color:<?= e(contrast_color($tag['color'] ?? '#6c757d')) ?>"><?= e($tag['name']) ?></span>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($csat)): ?>
<div class="section">
    <div class="section-title">Avaliação de Satisfação</div>
    <div class="csat-box">
        <div class="csat-stars"><?= str_repeat('★', (int) $csat['rating']) ?><?= str_repeat('☆', 5 - (int) $csat['rating']) ?></div>
        <div><?= (int) $csat['rating'] ?>/5</div>
        <?php if (!empty($csat['comment'])): ?>
            <div style="margin-top:4px;font-size:9pt"><?= e($csat['comment']) ?></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="section">
    <div class="section-title">Mensagens (<?= count($messages) ?>)</div>
    <?php
    $lastDate = null;
    foreach ($messages as $msg):
        $isFile = in_array($msg['type'], ['image', 'audio', 'video', 'file', 'sticker'], true);
        if (!$isFile) {
            $inferred = media_type_from_content((string) ($msg['content'] ?? ''));
            if ($inferred !== null) $isFile = true;
        }
        $meta = $isFile ? message_file_meta($msg['content']) : null;
        $msgDate = date('Y-m-d', strtotime($msg['created_at']));
        $showDate = $msgDate !== $lastDate;
        $lastDate = $msgDate;
        $senderName = $msg['direction'] === 'outbound' ? ($msg['user_name'] ?? 'Agente') : ($contact['name'] ?? 'Contato');
    ?>
        <?php if ($showDate): ?>
        <div class="msg-date-sep"><?= format_date_sep($msg['created_at']) ?></div>
        <?php endif; ?>
        <div class="msg msg-<?= $msg['direction'] === 'outbound' ? 'out' : 'in' ?> <?= $msg['type'] === 'internal_note' ? 'msg-note' : '' ?> <?= $msg['type'] === 'system' ? 'msg-system' : '' ?>">
            <div class="msg-header"><strong><?= e($senderName) ?></strong> &mdash; <?= $msg['direction'] === 'outbound' ? 'Enviado' : 'Recebido' ?></div>
            <?php if ($msg['type'] === 'internal_note'): ?>
                <div class="msg-content"><em>[Nota interna]</em> <?= e($msg['content']) ?></div>
            <?php elseif ($msg['type'] === 'system'): ?>
                <div class="msg-content"><?= e($msg['content']) ?></div>
            <?php elseif ($msg['type'] === 'csat_request'): ?>
                <?php $csatReq = json_decode($msg['content'], true) ?: []; ?>
                <div class="msg-content"><em>[Solicitação de avaliação]</em> <?= e($csatReq['prompt'] ?? '') ?></div>
            <?php elseif ($msg['type'] === 'reaction'): ?>
                <?php $rData = json_decode($msg['content'], true) ?: []; ?>
                <div class="msg-content"><em>[Reação: <?= e($rData['reaction'] ?? '') ?>]</em></div>
            <?php elseif ($isFile && $meta): ?>
                <div class="msg-content">
                    <span class="msg-file"><?= e($meta['name']) ?><?= !empty($meta['size']) ? ' (' . format_bytes($meta['size']) . ')' : '' ?></span>
                </div>
            <?php else: ?>
                <div class="msg-content"><?= nl2br(e($msg['content'])) ?></div>
            <?php endif; ?>
            <div class="msg-time"><?= format_datetime($msg['created_at']) ?></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="section">
    <div class="section-title">Eventos (<?= count($events) ?>)</div>
    <?php if (empty($events)): ?>
        <div style="color:#888;font-size:9pt">Nenhum evento registrado.</div>
    <?php else: ?>
        <?php foreach ($events as $ev): ?>
        <div class="event">
            <strong><?= e($ev['description'] ?? $ev['event_type']) ?></strong>
            <?php if (!empty($ev['user_name'])): ?> &mdash; <?= e($ev['user_name']) ?><?php endif; ?>
            <span class="event-time"><?= format_datetime($ev['created_at']) ?></span>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="footer">
    Relatório gerado em <?= format_datetime(date('Y-m-d H:i:s')) ?> &mdash; AtendeFlow
</div>

</body>
</html>
