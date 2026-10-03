<?php if (empty($conversations)): ?>
    <div class="empty-state-enhanced">
        <div class="empty-icon"><svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11L2 12v6a2 2 0 002 2h16a2 2 0 002-2v-6l-3.45-6.89A2 2 0 0016.76 4H7.24a2 2 0 00-1.79 1.11z"/></svg></div>
        <h3>Nenhuma conversa encontrada</h3>
        <p>As conversas aparecerão aqui quando receberem mensagens.</p>
    </div>
<?php else: ?>
    <?php
    $convParams = [];
    if (!empty($activeInbox)) $convParams['inbox'] = (int)$activeInbox;
    if (!empty($fstatus)) $convParams['fstatus'] = $fstatus;
    $convQs = $convParams ? '?' . http_build_query($convParams) . '&conv=' : '?conv=';
    $convBase = url('inbox') . $convQs;
    $activeConvId = !empty($activeConvId) ? $activeConvId : null;
    $channelColors = [
        'whatsapp' => '#25D366', 'webchat' => '#4361ee', 'email' => '#f59e0b',
        'telegram' => '#0088cc', 'facebook' => '#1877f2', 'instagram'=> '#e1306c', 'phone' => '#6c757d',
    ];
    ?>
    <?php foreach ($conversations as $conv): ?>
        <?php
        $convTagsList = [];
        if (!empty($conv['tags_json'])) {
            $decodedTags = json_decode($conv['tags_json'], true);
            $convTagsList = is_array($decodedTags) ? $decodedTags : [];
        }
        $convInboxId = !empty($conv['inbox_id']) ? (int) $conv['inbox_id'] : null;
        $slaLevel = \App\Services\SlaService::levelFor(
            (int)($conv['waiting_seconds'] ?? 0),
            $conv['status'] ?? null,
            $conv['last_message_direction'] ?? null,
            $convInboxId
        );
        $slaClass = $slaLevel === \App\Services\SlaService::LEVEL_NONE
            ? ''
            : 'conv-sla-' . e($slaLevel);
        ?>
        <a href="<?= $convBase ?><?= $conv['id'] ?>"
           class="conv-item <?= $slaClass ?> <?= $activeConvId == $conv['id'] ? 'active' : '' ?>"
           data-conv-id="<?= $conv['id'] ?>"
           data-msg-count="<?= (int)($conv['message_count'] ?? 0) ?>"
           data-unread="<?= (int)($conv['unread_count'] ?? 0) ?>"
           data-flow="<?= !empty($conv['has_active_flow']) ? '1' : '0' ?>"
           data-tags="<?= e($conv['tags_json'] ?? '') ?>"
           data-sla-status="<?= e($conv['status'] ?? '') ?>"
           data-sla-dir="<?= e($conv['last_message_direction'] ?? '') ?>"
           data-sla-waiting="<?= (int)($conv['waiting_seconds'] ?? 0) ?>"
           data-sla-inbox="<?= $convInboxId ?: '' ?>"
           data-group-id="<?= !empty($conv['group_id']) ? (int)$conv['group_id'] : '' ?>">
            <div class="avatar-wrap">
                <?php if ($conv['contact_avatar']): ?>
                    <div class="avatar"><img src="<?= e(str_starts_with($conv['contact_avatar'], 'http') ? $conv['contact_avatar'] : upload_url($conv['contact_avatar'])) ?>" alt=""></div>
                <?php else: ?>
                    <div class="avatar"><?= mb_strtoupper(mb_substr($conv['contact_name'] ?? '?', 0, 1)) ?></div>
                <?php endif; ?>
                <?php if ($conv['status'] === 'open' || $conv['status'] === 'new'): ?>
                    <div class="status-dot"></div>
                <?php endif; ?>
                <?php $unread = (int)($conv['unread_count'] ?? 0); ?>
                <?php if ($unread > 0): ?>
                    <span class="nav-badge" style="position:absolute;top:-4px;right:-6px;font-size:10px;padding:1px 6px"><?= $unread > 99 ? '99+' : $unread ?></span>
                <?php endif; ?>
            </div>
            <div class="conv-body">
                <div class="conv-top">
                    <span class="conv-name"><?= e($conv['contact_name']) ?><?php if (!empty($conv['protocol'])): ?> <span style="font-weight:400;color:var(--text-muted);font-size:12px;font-variant-numeric:tabular-nums" title="Protocolo do atendimento">#<?= e(format_protocol($conv['protocol'])) ?></span><?php endif; ?></span>
                    <span class="conv-time"><?= time_elapsed($conv['last_message_at'] ?? $conv['created_at']) ?></span>
                </div>
                <div class="conv-preview">
                    <?php if (!empty($conv['subject'])): ?>
                        <strong><?= e($conv['subject']) ?></strong> —
                    <?php endif; ?>
                    <?php
                        $mediaLabels = ['image' => '🖼️ Imagem', 'video' => '🎬 Vídeo', 'audio' => '🎵 Áudio', 'file' => '📎 Arquivo', 'sticker' => '🖼️ Sticker'];
                        $lmType = $conv['last_message_type'] ?? '';
                        if (isset($mediaLabels[$lmType])):
                            echo $mediaLabels[$lmType];
                        else:
                            echo e(truncate($conv['last_message'] ?? 'Sem mensagens', 80));
                        endif;
                    ?>
                    <?php if (!empty($conv['unit'])): ?>
                        <span style="font-size:11px;color:var(--text-muted);margin-left:6px">| <?= e($conv['unit']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="conv-tags">
                    <?php
                    $chColor = $channelColors[$conv['channel_type'] ?? ''] ?? '#6c757d';
                    ?>
                    <span class="chip chip-neutral"><i class="<?= channel_icon($conv['channel_type'] ?? 'webchat') ?>" style="font-size:11px"></i> <?= e($conv['channel_name'] ?? '') ?></span>
                    <?php if (!empty($conv['group_id'])): ?>
                        <span class="chip chip-info" title="Conversa de grupo WhatsApp"><i class="fas fa-users" style="font-size:11px"></i> Grupo</span>
                    <?php endif; ?>
                    <?php foreach ($convTagsList as $tagItem): ?>
                        <span class="chip conv-tag-chip" style="background:<?= e($tagItem['color'] ?? '#6c757d') ?>22;color:<?= e($tagItem['color'] ?? '#6c757d') ?>"><?= e($tagItem['name'] ?? '') ?></span>
                    <?php endforeach; ?>
                    <?php if (($conv['priority'] ?? 'normal') !== 'normal'): ?>
                        <span class="chip <?= $conv['priority'] === 'urgent' ? 'chip-danger' : 'chip-warning' ?>"><?= ucfirst($conv['priority']) ?></span>
                    <?php endif; ?>
                    <span class="chip <?= $conv['status'] === 'open' || $conv['status'] === 'new' ? 'chip-success' : ($conv['status'] === 'resolved' || $conv['status'] === 'closed' ? 'chip-neutral' : 'chip-info') ?>"><?php $slabels = ['new'=>'Novo','open'=>'Aberto','waiting_customer'=>'Em atendimento','waiting_internal'=>'Aguard. Interno','resolved'=>'Resolvido','closed'=>'Fechado','spam'=>'Spam']; echo $slabels[$conv['status']] ?? $conv['status']; ?></span>
                </div>
                <div class="conv-footer">
                    <span class="conv-agent"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> <?= e($conv['assigned_user_name'] ?? 'Não atribuído') ?></span>
                    <span class="conv-count"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg> <?= (int)($conv['message_count'] ?? 0) ?></span>
                </div>
            </div>
        </a>
    <?php endforeach; ?>
<?php endif; ?>
