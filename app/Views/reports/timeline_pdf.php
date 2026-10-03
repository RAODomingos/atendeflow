<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<?php require __DIR__ . '/../_pdf_style.php'; ?>
<style>
    @page { margin: 16mm 12mm; }
    body { font-size: 9pt; }
    .scope-bar { background: #eff6fc; border: 1px solid #deecf9; border-radius: 6px; padding: 6px 10px; font-size: 8.5pt; color: #005a9e; font-weight: 600; margin: 10px 0 12px; }
</style>
</head>
<body>

<?php
$statusLabels = ['new'=>'Novo','open'=>'Aberto','waiting_customer'=>'Em atendimento','waiting_internal'=>'Aguardando Interno','resolved'=>'Resolvido','closed'=>'Fechado','spam'=>'Spam'];
$priorityLabels = ['low'=>'Baixa','normal'=>'Normal','high'=>'Alta','urgent'=>'Urgente'];
$statusClasses = ['new'=>'badge-info','open'=>'badge-info','waiting_customer'=>'badge-warning','waiting_internal'=>'badge-neutral','resolved'=>'badge-success','closed'=>'badge-neutral','spam'=>'badge-danger'];

$tl = $timeline;
$total = count($conversationsData);
$totalMsgs = array_sum(array_map(fn($c) => count($c['messages'] ?? []), $conversationsData));
$totalEvents = array_sum(array_map(fn($c) => count($c['events'] ?? []), $conversationsData));
?>
<table class="doc-header">
    <tr>
        <td>
            <span class="doc-logo">O</span>
            <span class="doc-brand">
                <div class="doc-brand-name">OminiDesk</div>
                <div class="doc-brand-title">Relat&oacute;rio de Conversas</div>
            </span>
        </td>
        <td class="r">
            <div class="doc-meta">Emitido em<br><?= format_datetime(date('Y-m-d H:i:s')) ?></div>
        </td>
    </tr>
</table>

<div class="scope-bar">
    Per&iacute;odo: <?= format_date($tl['from']) ?> &rarr; <?= format_date($tl['to']) ?>
    <?php if ($tl['status']): ?>
        &nbsp;&middot;&nbsp; Status: <?= $statusLabels[$tl['status']] ?? e($tl['status']) ?>
    <?php endif; ?>
    <?php if (!empty($isSelection)): ?>
        &nbsp;&middot;&nbsp; <?= (int) $selectedCount ?> conversa(s) selecionada(s)
    <?php endif; ?>
    &nbsp;&middot;&nbsp; <?= $total ?> conversa(s) &nbsp;&middot;&nbsp; <?= $totalMsgs ?> mensagem(ns)
</div>

<table class="stat-grid">
    <tr>
        <td><span class="val"><?= $total ?></span><span class="lbl">Conversas</span></td>
        <td><span class="val"><?= $totalMsgs ?></span><span class="lbl">Mensagens</span></td>
        <td><span class="val"><?= $totalEvents ?></span><span class="lbl">Eventos</span></td>
        <td><span class="val"><?= $tl['granularity'] === 'day' ? 'Di&aacute;rio' : ($tl['granularity'] === 'week' ? 'Semanal' : ($tl['granularity'] === 'month' ? 'Mensal' : 'Anual')) ?></span><span class="lbl">Agrupamento</span></td>
    </tr>
</table>

<div class="section-title">Sum&aacute;rio de Conversas</div>
<table class="data">
    <thead>
        <tr>
            <th style="width:24px">#</th>
            <th>Protocolo</th>
            <th>Assunto</th>
            <th>Contato</th>
            <th>Status</th>
            <th>Canal</th>
            <th>Setor</th>
            <th>Respons&aacute;vel</th>
            <th style="text-align:center">Mens.</th>
            <th>Criada em</th>
        </tr>
    </thead>
    <tbody>
    <?php $i = 1; foreach ($conversationsData as $c):
        $conv = $c['conversation'];
    ?>
        <tr>
            <td class="num"><?= $i++ ?></td>
            <td><span class="proto-inline">#<?= e(format_protocol($conv['protocol'] ?? '')) ?></span></td>
            <td><?= e(truncate($conv['subject'] ?: '(sem assunto)', 34)) ?></td>
            <td><?= e($conv['contact_name'] ?? '-') ?></td>
            <td><span class="badge <?= $statusClasses[$conv['status']] ?? 'badge-neutral' ?>"><?= $statusLabels[$conv['status']] ?? e($conv['status']) ?></span></td>
            <td><?= e($conv['channel_name'] ?? $conv['channel_type'] ?? '-') ?></td>
            <td><?= e($conv['department_name'] ?? '-') ?></td>
            <td><?= e($conv['assigned_user_name'] ?? '-') ?></td>
            <td class="num"><?= count($c['messages'] ?? []) ?></td>
            <td><?= format_date($conv['created_at']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php foreach ($conversationsData as $ci => $c):
    $conversation = $c['conversation'];
    $messages = $c['messages'] ?? [];
    $events = $c['events'] ?? [];
    $csat = $c['csat'] ?? null;
    $contactTags = $conversation['tags'] ?? [];
    $proto = !empty($conversation['protocol']) ? format_protocol($conversation['protocol']) : '';
?>
<div class="conv">
    <table class="head-table">
        <tr>
            <td>
                <div class="head-title">Conversa #<?= (int) $conversation['id'] ?><?= $conversation['subject'] ? ' &mdash; ' . e($conversation['subject']) : '' ?></div>
                <div class="head-sub">
                    <?= format_datetime($conversation['created_at']) ?>
                    &nbsp;&middot;&nbsp;
                    <?= $statusLabels[$conversation['status']] ?? e($conversation['status']) ?>
                    &nbsp;&middot;&nbsp;
                    <?= count($messages) ?> mensagens
                    <?php if (!empty($conversation['contact_name'])): ?>
                        &nbsp;&middot;&nbsp; <?= e($conversation['contact_name']) ?>
                    <?php endif; ?>
                </div>
            </td>
            <td class="r">
                <?php if ($proto !== ''): ?>
                    <div class="proto-label">Protocolo</div>
                    <span class="proto">#<?= e($proto) ?></span>
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <div class="section-title">Informa&ccedil;&otilde;es</div>
    <table class="kv">
        <?php if ($proto !== ''): ?>
        <tr class="proto-row">
            <td class="k">Protocolo</td>
            <td class="v"><span class="proto-inline">#<?= e($proto) ?></span></td>
        </tr>
        <?php endif; ?>
        <tr><td class="k">ID</td><td class="v"><?= (int) $conversation['id'] ?></td></tr>
        <tr><td class="k">Status</td><td class="v"><span class="badge <?= $statusClasses[$conversation['status']] ?? 'badge-neutral' ?>"><?= $statusLabels[$conversation['status']] ?? e($conversation['status']) ?></span></td></tr>
        <tr><td class="k">Prioridade</td><td class="v"><?= $priorityLabels[$conversation['priority'] ?? 'normal'] ?? e($conversation['priority']) ?></td></tr>
        <tr><td class="k">Canal</td><td class="v"><?= e($conversation['channel_name'] ?? '-') ?> (<?= e($conversation['channel_type'] ?? '-') ?>)</td></tr>
        <?php if (!empty($conversation['department_name'])): ?>
        <tr><td class="k">Departamento</td><td class="v"><?= e($conversation['department_name']) ?></td></tr>
        <?php endif; ?>
        <tr><td class="k">Respons&aacute;vel</td><td class="v"><?= e($conversation['assigned_user_name'] ?? 'Sem responsável') ?></td></tr>
        <tr><td class="k">Assunto</td><td class="v"><?= e($conversation['subject'] ?? '-') ?></td></tr>
        <?php if (!empty($conversation['unit'])): ?>
        <tr><td class="k">Unidade</td><td class="v"><?= e($conversation['unit']) ?></td></tr>
        <?php endif; ?>
        <tr><td class="k">Criada em</td><td class="v"><?= format_datetime($conversation['created_at']) ?></td></tr>
        <?php if (!empty($conversation['closed_at'])): ?>
        <tr><td class="k">Fechada em</td><td class="v"><?= format_datetime($conversation['closed_at']) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($conversation['close_reason'])): ?>
        <tr><td class="k">Motivo</td><td class="v"><?= e($conversation['close_reason']) ?></td></tr>
        <?php endif; ?>
    </table>

    <?php if (!empty($contactTags)): ?>
    <div class="section-title">Etiquetas</div>
    <div>
        <?php foreach ($allTags as $tag): ?>
            <?php if (in_array($tag['id'], array_column($contactTags, 'id'))): ?>
            <span class="tag" style="background:<?= e($tag['color'] ?? '#6c757d') ?>;color:<?= e(contrast_color($tag['color'] ?? '#6c757d')) ?>"><?= e($tag['name']) ?></span>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($csat)): ?>
    <div class="section-title">Avalia&ccedil;&atilde;o</div>
    <div class="csat-box">
        <div class="csat-stars"><?= str_repeat('&#9733;', (int) $csat['rating']) ?><?= str_repeat('&#9734;', 5 - (int) $csat['rating']) ?></div>
        <div><?= (int) $csat['rating'] ?>/5</div>
        <?php if (!empty($csat['comment'])): ?>
            <div style="margin-top:3px;font-size:8.5pt"><?= e($csat['comment']) ?></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="section-title">Mensagens (<?= count($messages) ?>)</div>
    <?php if (empty($messages)): ?>
        <div class="muted" style="font-size:8.5pt">Nenhuma mensagem registrada.</div>
    <?php else:
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
            $senderName = $msg['direction'] === 'outbound' ? ($msg['user_name'] ?? 'Agente') : ($conversation['contact_name'] ?? 'Contato');
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
                    <div class="msg-content"><em>[CSAT]</em> <?= e($csatReq['prompt'] ?? '') ?></div>
                <?php elseif ($msg['type'] === 'reaction'): ?>
                    <?php $rData = json_decode($msg['content'], true) ?: []; ?>
                    <div class="msg-content"><em>[Rea&ccedil;&atilde;o: <?= e($rData['reaction'] ?? '') ?>]</em></div>
                <?php elseif ($isFile && $meta): ?>
                    <div class="msg-content">
                        <span class="msg-file"><?= e($meta['name']) ?><?= !empty($meta['size']) ? ' (' . format_bytes($meta['size']) . ')' : '' ?></span>
                        <?php if (!empty($meta['caption'])): ?>
                            <div class="msg-caption"><?= nl2br(e($meta['caption'])) ?></div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="msg-content"><?= nl2br(e($msg['content'])) ?></div>
                <?php endif; ?>
                <div class="msg-time"><?= format_datetime($msg['created_at']) ?></div>
            </div>
        <?php endforeach;
        endif; ?>

    <div class="section-title">Eventos (<?= count($events) ?>)</div>
    <?php if (empty($events)): ?>
        <div class="muted" style="font-size:8.5pt">Nenhum evento registrado.</div>
    <?php else: ?>
        <?php foreach ($events as $ev): ?>
        <div class="event">
            <strong><?= e($ev['description'] ?? $ev['event_type']) ?></strong>
            <?php if (!empty($ev['user_name'])): ?> &mdash; <?= e($ev['user_name']) ?><?php endif; ?>
            <span class="event-time"><?= format_datetime($ev['created_at']) ?></span>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="footer">
        <?php if ($proto !== ''): ?>
            Protocolo <span class="proto-inline">#<?= e($proto) ?></span> &mdash;
        <?php endif; ?>
        Conversa #<?= (int) $conversation['id'] ?> &mdash; OminiDesk
    </div>
</div>
<?php endforeach; ?>

<div style="text-align:center;font-size:7.5pt;color:#8a8886;border-top:1px solid #edebe9;padding-top:8px;margin-top:18px">
    Relat&oacute;rio gerado em <?= format_datetime(date('Y-m-d H:i:s')) ?> &mdash; OminiDesk
</div>

</body>
</html>
