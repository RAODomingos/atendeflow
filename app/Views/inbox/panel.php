<?php
$initial = mb_strtoupper(mb_substr($contact['name'] ?? '?', 0, 1));
$conv = $conversation;
$currentUserId = (int) \App\Core\Auth::id();
$isOwn = function (array $msg) use ($currentUserId) {
    return !empty($msg['user_id']) && (int) $msg['user_id'] === $currentUserId;
};
$avatarFor = function (array $msg) use ($contact, $initial) {
    if (($msg['direction'] ?? '') === 'outbound') {
        $n = $msg['user_name'] ?? 'A';
        return mb_strtoupper(mb_substr($n, 0, 1));
    }
    return $initial;
};
$avatarHtml = function (array $msg) use ($contact, $initial) {
    if (($msg['direction'] ?? '') === 'outbound' && !empty($msg['user_avatar'])) {
        $src = str_starts_with($msg['user_avatar'], 'http') ? $msg['user_avatar'] : upload_url($msg['user_avatar']);
        return '<img src="' . e($src) . '" alt="">';
    }
    if (($msg['direction'] ?? '') === 'inbound' && !empty($contact['avatar'])) {
        $src = str_starts_with($contact['avatar'], 'http') ? $contact['avatar'] : upload_url($contact['avatar']);
        return '<img src="' . e($src) . '" alt="">';
    }
    $n = ($msg['direction'] ?? '') === 'outbound' ? ($msg['user_name'] ?? 'A') : $initial;
    return e(mb_strtoupper(mb_substr($n, 0, 1)));
};
$online = !empty($contact['last_activity_at']) && (time() - strtotime($contact['last_activity_at']) < 600);
$csat = $conversation['csat'] ?? null;

$convSubjects = [];
$closeReasons = [];
try {
    $convSubjects = \App\Core\Database::getInstance()->fetchAll(
        "SELECT * FROM conversation_subjects WHERE is_active = 1 ORDER BY sort_order ASC, name ASC"
    );
} catch (\Throwable $e) {}
try {
    $closeReasons = \App\Models\CloseReason::all();
} catch (\Throwable $e) {}
$currentCloseReason = \App\Models\CloseReason::resolve($conversation['close_reason'] ?? null);
$isClosed = in_array($conv['status'] ?? '', ['resolved', 'closed', 'spam'], true);

/**
 * Renderiza o corpo de uma mensagem (texto, mídia, nota, sistema, etc.).
 * Faz a detecção de JSON, links, line-breaks e mídia embarcada.
 */
$renderMessageContent = function (array $msg) use ($contact, &$renderMessageContent) {
    $type = $msg['type'] ?? 'text';
    $raw = (string) ($msg['content'] ?? '');
    $deleted = !empty($msg['deleted_at']);
    if ($deleted) {
        return '<div class="message-deleted"><i class="fas fa-ban"></i> Mensagem excluída</div>';
    }

    $isFile = in_array($type, ['image', 'audio', 'video', 'file', 'sticker'], true);
    $mediaType = $type;
    if (!$isFile) {
        $inferred = media_type_from_content($raw);
        if ($inferred !== null) {
            $isFile = true;
            $mediaType = $inferred;
        }
    }

    if ($type === 'internal_note') {
        $user = $msg['user_name'] ?? 'Equipe';
        $body = nl2br(e($raw));
        return '<div class="message-note-header"><i class="fas fa-lock"></i> Nota interna &middot; ' . e($user) . '</div>'
             . '<div class="message-content">' . $body . '</div>';
    }

    if ($type === 'csat_request') {
        $data = json_decode($raw, true) ?: [];
        $prompt = $data['prompt'] ?? 'Solicitação de avaliação enviada ao cliente.';
        $url = $data['url'] ?? '';
        $html = '<i class="fas fa-smile"></i> ' . e($prompt);
        if ($url) {
            $html .= ' &middot; <a href="' . e($url) . '" target="_blank" rel="noopener">Abrir avaliação</a>';
        }
        return '<div class="message-content csat-request-note">' . $html . '</div>';
    }

    if ($type === 'reaction') {
        $data = json_decode($raw, true) ?: [];
        $emoji = $data['reaction'] ?? '';
        return '<div class="message-content">'
             . '<span class="reaction-emoji">' . e($emoji) . '</span> '
             . '<span class="reaction-label">reagiu a uma mensagem</span>'
             . '</div>';
    }

    if ($type === 'system') {
        return '<div class="message-content">' . nl2br(e($raw)) . '</div>';
    }

    if ($isFile) {
        $meta = message_file_meta($raw);
        if (!$meta) {
            return '<div class="message-content">' . nl2br(e($raw)) . '</div>';
        }
        $url = $meta['url'] ?? '';
        $name = $meta['name'] ?? 'arquivo';
        $size = !empty($meta['size']) ? format_bytes((int) $meta['size']) : '';
        $caption = (string) ($meta['caption'] ?? '');
        $captionHtml = '';
        if ($caption !== '') {
            // Mesma formatação do texto puro: detecta links e preserva quebras de linha.
            $captionHtml = '<div class="message-caption">' . linkify_br($caption) . '</div>';
        }
        if ($mediaType === 'image' || $mediaType === 'sticker') {
            return '<div class="message-content"><a href="' . e($url) . '" target="_blank" rel="noopener" class="msg-lightbox">'
                 . '<img class="msg-img" src="' . e($url) . '" alt="' . e($name) . '" loading="lazy">'
                 . '</a>' . $captionHtml . '</div>';
        }
        if ($mediaType === 'audio') {
            return '<div class="message-content"><audio controls preload="metadata" src="' . e($url) . '"></audio>' . $captionHtml . '</div>';
        }
        if ($mediaType === 'video') {
            return '<div class="message-content"><a href="' . e($url) . '" target="_blank" rel="noopener" class="msg-lightbox">'
                 . '<video controls preload="metadata" src="' . e($url) . '" class="msg-video" data-alt="' . e($name) . '"></video>'
                 . '</a>' . $captionHtml . '</div>';
        }
        return '<div class="message-content">'
             . '<a class="msg-file" href="' . e($url) . '" target="_blank" rel="noopener" download>'
             . '<i class="fas fa-file-download"></i>'
             . '<span class="msg-file-name">' . e($name) . '</span>'
             . ($size ? '<span class="msg-file-size">' . e($size) . '</span>' : '')
             . '</a>' . $captionHtml . '</div>';
    }

    // Texto puro: detecta JSON escapado, links e formata quebras de linha
    $parsed = json_decode($raw, true);
    if (is_array($parsed) && isset($parsed['text']) && is_string($parsed['text'])) {
        $raw = $parsed['text'];
    }
    $body = linkify_br($raw);
    return '<div class="message-content">' . $body . '</div>';
};

/**
 * Renderiza o "recibo de leitura" (✓ / ✓✓) e a posição do autor para mensagens enviadas.
 */
$renderReceipts = function (array $msg) {
    if ($msg['direction'] !== 'outbound') return '';
    if (($msg['delivery_status'] ?? '') === 'failed') {
        return '<span class="msg-receipts failed" title="Não entregue ao WhatsApp"><i class="fas fa-exclamation-circle"></i></span>'
            . '<button type="button" class="msg-retry" title="Tentar de novo" onclick="retrySend(' . (int) $msg['id'] . ', this)"><i class="fas fa-redo"></i> Tentar de novo</button>';
    }
    $isRead = !empty($msg['read_at']);
    $isDelivered = !$isRead && !empty($msg['delivered_at']);
    $cls = $isRead ? 'msg-receipts read' : 'msg-receipts delivered';
    $icon = $isRead ? 'fa-check-double' : 'fa-check';
    return '<span class="' . $cls . '" title="' . ($isRead ? 'Lida' : 'Entregue') . '"><i class="fas ' . $icon . '"></i></span>';
};
?>
<div class="conversation-view" id="convView" data-conv="<?= $conv['id'] ?>">
    <script src="<?= url('assets/js/audio_recorder.js') ?>"></script>
    <script>
    (function(){
        try { window.__viewingConvId = '<?= (int)$conv['id'] ?>'; } catch(e) {}
        if (window.__enhancements && window.__enhancements.GlobalNotifier) {
            window.__enhancements.GlobalNotifier.setViewing(<?= (int)$conv['id'] ?>);
        }
        if (window.__enhancements && window.__enhancements.LiveFeed) {
            window.__enhancements.LiveFeed.setViewing(<?= (int)$conv['id'] ?>);
        }
    })();
    </script>
    <div class="conv-main">
    <div class="chat-header">
        <div class="chat-header-left">
            <button type="button" class="chat-header-back" onclick="window.__convBack && window.__convBack()" title="Voltar para a lista">
                <i class="fa-solid fa-arrow-left"></i>
            </button>
            <button type="button" class="conv-name-btn" onclick="toggleClientDrawer()" title="Ver dados do cliente e histórico">
                <div class="chat-header-avatar">
                    <?php if (!empty($contact['avatar'])): ?>
                        <img src="<?= e(str_starts_with($contact['avatar'], 'http') ? $contact['avatar'] : upload_url($contact['avatar'])) ?>" alt="">
                    <?php else: ?>
                        <?= e($initial) ?>
                    <?php endif; ?>
                    <span class="online-dot <?= $online ? '' : 'offline' ?>" title="<?= $online ? 'Online' : 'Offline' ?>"></span>
                </div>
                <div class="chat-title-group">
                    <div class="chat-title">
                        <?= e($contact['name'] ?? 'Contato') ?>
                    </div>
                    <div class="chat-title-meta">
                        <span class="meta-tag"><i class="<?= channel_icon($conv['channel_type'] ?? 'webchat') ?>" style="font-size:11px"></i> <?= e($conv['channel_name'] ?? '') ?></span>
                        <?php if (!empty($conv['protocol'])): ?>
                            <span class="meta-sep">·</span>
                            <span class="meta-tag" title="Protocolo do atendimento" style="font-weight:700">
                                <i class="fas fa-hashtag" style="font-size:10px"></i> <?= e(format_protocol($conv['protocol'])) ?>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($conv['department_name'])): ?>
                            <span class="meta-sep">·</span>
                            <span class="meta-tag" style="color:<?= e($conv['department_color'] ?? '#666') ?>;font-weight:700">
                                <i class="fas fa-building" style="font-size:10px"></i> <?= e($conv['department_name']) ?>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($conv['assigned_user_name'])): ?>
                            <span class="meta-sep">·</span>
                            <span class="meta-tag" id="convAssigned" title="Atendente responsável">
                                <i class="fas fa-user" style="font-size:10px"></i> <?= e($conv['assigned_user_name']) ?>
                            </span>
                        <?php endif; ?>
                        <?php if (empty($contact['stores'])): ?>
                        <?php if (!empty($conv['unit'])): ?>
                            <span class="meta-sep">·</span>
                            <span class="meta-tag" onclick="editUnit(event)" style="cursor:pointer" title="Clique para editar">
                                <i class="fas fa-tag" style="font-size:10px"></i> <span id="convUnitDisplay"><?= e($conv['unit']) ?></span>
                            </span>
                        <?php else: ?>
                            <span class="meta-tag" onclick="editUnit(event)" style="cursor:pointer;opacity:.6" title="Adicionar unidade">
                                <i class="fas fa-plus" style="font-size:9px"></i> <span id="convUnitDisplay" style="font-style:italic">unidade</span>
                            </span>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--text-muted);flex-shrink:0;margin-left:6px"><path d="M9 18l6-6-6-6"/></svg>
            </button>
            <?php if (empty($contact['stores'])): ?>
            <input type="text" id="convUnitInput" style="font-size:12px;padding:4px 8px;border:1px solid var(--brand);border-radius:6px;outline:none;width:160px" value="<?= e($conv['unit'] ?? '') ?>" placeholder="Unidade" onblur="saveUnit(this.value)" onkeydown="if(event.key==='Enter')saveUnit(this.value);if(event.key==='Escape')cancelUnitEdit()">
            <?php endif; ?>
        </div>
        <div class="chat-header-right">
            <?php
            $slabels = ['new'=>'Novo','open'=>'Aberto','waiting_customer'=>'Em atendimento','waiting_internal'=>'Aguard. Interno','resolved'=>'Resolvido','closed'=>'Fechado','spam'=>'Spam'];
            $scls = $conv['status'] === 'open' || $conv['status'] === 'new' ? 'chip-success' : ($conv['status'] === 'waiting_customer' ? 'chip-info' : ($conv['status'] === 'resolved' || $conv['status'] === 'closed' ? 'chip-neutral' : 'chip-warning'));
            $sdot = $conv['status'] === 'open' || $conv['status'] === 'new' ? 'var(--success)' : ($conv['status'] === 'waiting_customer' ? 'var(--info)' : ($conv['status'] === 'resolved' || $conv['status'] === 'closed' ? 'var(--text-muted)' : 'var(--warning)'));
            $sicon = $conv['status'] === 'waiting_customer' ? 'fa-headset' : ($conv['status'] === 'new' ? 'fa-bell' : ($conv['status'] === 'open' ? 'fa-comments' : ($conv['status'] === 'resolved' ? 'fa-check-circle' : ($conv['status'] === 'closed' ? 'fa-archive' : ($conv['status'] === 'spam' ? 'fa-ban' : 'fa-clock')))));
            ?>
            <span class="status-chip header-chip-status <?= $scls ?>" onclick="openStatusModal()" title="Clique para alterar status">
                <i class="fas <?= $sicon ?>" style="font-size:11px"></i>
                <span class="chip-text"><?= e($slabels[$conv['status']] ?? $conv['status']) ?></span>
            </span>
            <button type="button" class="header-chip header-chip-subject <?= empty($conv['subject']) ? 'is-empty' : '' ?>" onclick="openSubjectModal()" title="Clique para selecionar o assunto">
                <i class="fas fa-tag"></i>
                <span class="chip-text"><?= !empty($conv['subject']) ? e($conv['subject']) : 'Assunto' ?></span>
                <i class="fas fa-chevron-down" style="font-size:9px;opacity:.5;margin-left:auto"></i>
            </button>
            <button type="button" class="header-chip header-chip-priority" onclick="openPriorityModal()" title="Clique para alterar prioridade">
                <i class="fas fa-flag" style="color:<?= ['urgent'=>'#d32f2f','high'=>'#f57c00','low'=>'#1976d2','normal'=>'var(--text-secondary)'][$conv['priority'] ?? 'normal'] ?>"></i>
                <span class="chip-text"><?= ['low'=>'Baixa','normal'=>'Normal','high'=>'Alta','urgent'=>'Urgente'][$conv['priority'] ?? 'normal'] ?></span>
                <i class="fas fa-chevron-down" style="font-size:9px;opacity:.5;margin-left:auto"></i>
            </button>
            <?php
            $unitNets = [];
            foreach (($contact['stores'] ?? []) as $us) { $unitNets[$us['network_name']] = $us['customer_id']; }
            ksort($unitNets);
            ?>
            <?php if ($unitNets): ?>
            <label class="header-chip header-chip-select" title="Loja">
                <i class="fas fa-store"></i>
                <select id="convUnitNetwork" onchange="unitNetworkChanged(this)" aria-label="Loja">
                    <?php foreach ($unitNets as $un => $uc): ?>
                        <option value="<?= e($un) ?>" data-customer="<?= e($uc) ?>"><?= e($uc) ?> - <?= e($un) ?></option>
                    <?php endforeach; ?>
                    <option value="__new">+ Nova loja…</option>
                </select>
                <i class="fas fa-chevron-down" style="font-size:9px;opacity:.5"></i>
            </label>
            <label class="header-chip header-chip-select" title="Unidade">
                <i class="fas fa-tag"></i>
                <select id="convUnitStore" onchange="saveUnit(this.value)" aria-label="Unidade" data-current-unit="<?= e($conv['unit'] ?? '') ?>">
                </select>
                <i class="fas fa-chevron-down" style="font-size:9px;opacity:.5"></i>
            </label>
            <?php endif; ?>
            <div class="modal" id="newStoreModal" style="display:none">
                <div class="modal-overlay" onclick="closeNewStoreModal()"></div>
                <div class="modal-content">
                    <div class="modal-header">
                        <h3><i class="fas fa-store" style="color:var(--brand)"></i> Vincular nova loja</h3>
                        <button class="modal-close" type="button" onclick="closeNewStoreModal()">&times;</button>
                    </div>
                    <div class="modal-body">
                        <div class="form-group">
                            <label>Código da loja (ID do cliente Guild)</label>
                            <div style="display:flex;gap:8px">
                                <input type="text" id="newStoreCode" class="form-control" placeholder="Ex: 123">
                                <button type="button" class="btn btn-sm btn-outline" onclick="newStoreBuscar()">Buscar</button>
                            </div>
                        </div>
                        <div id="newStoreResult" style="margin-top:8px"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline" onclick="closeNewStoreModal()">Cancelar</button>
                        <button type="button" class="btn btn-primary" id="newStoreBindBtn" onclick="newStoreVincular()" disabled>
                            <i class="fas fa-link"></i> Vincular
                        </button>
                    </div>
                </div>
            </div>
            <script>var CONTACT_ID = <?= (int)($contact['id'] ?? 0) ?>;</script>
            <div class="conv-quick-actions">
                <button class="icon-btn" onclick="toggleMsgSearch()" title="Buscar na conversa"><i class="fas fa-search"></i></button>
                <button class="icon-btn" id="convSnoozeBtn" onclick="openSnoozeModal()" title="Agendar"><i class="fas fa-clock"></i></button>
                <button class="icon-btn" onclick="openMergeModal()" title="Mesclar"><i class="fas fa-code-merge"></i></button>
                <a href="<?= url('inbox/') ?><?= $conv['id'] ?>/pdf" class="icon-btn" title="Baixar PDF"><i class="fas fa-file-pdf"></i></a>
                <button class="icon-btn" onclick="openTransferModal()" title="Transferir"><i class="fas fa-exchange-alt"></i></button>
            </div>
            <?php if (empty($conv['assigned_user_id'])): ?>
                <form action="<?= url('inbox/') ?><?= $conv['id'] ?>/assign" method="POST" class="conv-action-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="user_id" value="<?= $currentUserId ?>">
                    <button type="submit" class="icon-btn" title="Atribuir a mim" style="background:var(--brand-soft);color:var(--brand-2)"><i class="fas fa-hand-paper"></i></button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="conv-header-tags" id="convHeaderTags">
        <?php if (!empty($conv['tags'])): ?>
            <?php foreach ($conv['tags'] as $tag): ?>
                <span class="conv-tag" data-tag-id="<?= $tag['id'] ?>" style="background:<?= e($tag['color'] ?? '#e9ecef') ?>;color:<?= e(contrast_color($tag['color'] ?? '#e9ecef')) ?>">
                    <?= e($tag['name']) ?>
                </span>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if ($isClosed): ?>
    <div class="close-info-banner <?= $conv['status'] === 'closed' ? 'is-closed' : ($conv['status'] === 'spam' ? 'is-spam' : '') ?>" id="closeInfoBanner">
        <div class="close-info-icon">
            <i class="fas <?= $conv['status'] === 'resolved' ? 'fa-check-circle' : ($conv['status'] === 'spam' ? 'fa-ban' : 'fa-archive') ?>"></i>
        </div>
        <div class="close-info-body">
            <div class="close-info-row">
                <span class="close-info-label"><?= $conv['status'] === 'spam' ? 'Marcada como spam' : 'Conversa encerrada' ?></span>
                <?php if ($currentCloseReason): ?>
                <span class="close-info-reason" style="--reason-color:<?= e($currentCloseReason['color']) ?>">
                    <i class="fas <?= e($currentCloseReason['icon'] ?: 'fa-tag') ?>"></i>
                    <?= e($currentCloseReason['label']) ?>
                </span>
                <?php elseif (!empty($conv['close_reason'])): ?>
                <span class="close-info-reason" style="--reason-color:var(--text-secondary)">
                    <i class="fas fa-tag"></i>
                    <?= e($conv['close_reason']) ?>
                </span>
                <?php endif; ?>
            </div>
            <?php if (!empty($conv['close_description'])): ?>
            <div class="close-info-description"><?= nl2br(e($conv['close_description'])) ?></div>
            <?php endif; ?>
            <div class="close-info-meta">
                <?php if (!empty($conv['closed_at'])): ?>
                <span><i class="fas fa-calendar-check"></i> <?= format_datetime($conv['closed_at']) ?></span>
                <?php endif; ?>
                <?php
                $closerName = null;
                if (!empty($conv['events'])) {
                    foreach ($conv['events'] as $ev) {
                        if (in_array($ev['event_type'], ['status_changed'], true) && !empty($ev['user_name'])) {
                            $closerName = $ev['user_name'];
                            break;
                        }
                    }
                }
                if ($closerName): ?>
                <span><i class="fas fa-user"></i> <?= e($closerName) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="close-info-actions">
            <button type="button" class="icon-btn" onclick="openStatusModal()" title="Editar encerramento"><i class="fas fa-pen"></i></button>
        </div>
    </div>
    <?php endif; ?>

    <div class="conv-msg-search" id="convMsgSearch" style="display:none">
        <input type="text" id="msgSearchInput" class="form-control" placeholder="Buscar mensagens..." oninput="filterMessages()">
        <span id="msgSearchCount" class="msg-search-count"></span>
    </div>

    <div class="chat-body" id="convMessages">
        <button type="button" class="conv-load-older" id="loadOlderBtn" style="display:<?= !empty($hasOlder) ? 'block' : 'none' ?>" onclick="loadOlder()">Carregar mensagens anteriores</button>
        <div id="typingIndicator" class="typing-indicator" style="display:none;align-self:flex-start">
            <span class="msg-avatar msg-avatar-contact" style="width:32px;height:32px"><?= e($initial) ?></span>
            <div class="typing-dots"><span></span><span></span><span></span></div>
            <span><?= e($contact['name'] ?? 'Cliente') ?> está digitando...</span>
        </div>
        <div id="convMessagesList">
        <?php
        $lastDate = null;
        $lastSender = null;
        foreach ($messages as $msg):
            $isFile = in_array($msg['type'], ['image', 'audio', 'video', 'file', 'sticker'], true);
            $mediaType = $msg['type'];
            if (!$isFile) {
                $inferred = media_type_from_content((string) ($msg['content'] ?? ''));
                if ($inferred !== null) {
                    $isFile = true;
                    $mediaType = $inferred;
                }
            }
            $meta = $isFile ? message_file_meta($msg['content']) : null;
            $own = $isOwn($msg);
            $av = $avatarHtml($msg);
            $isDeleted = !empty($msg['deleted_at']);
            $msgDate = date('Y-m-d', strtotime($msg['created_at']));
            $sender = ($msg['direction'] ?? '') === 'outbound' ? 'agent' : 'contact';
            $showDate = $msgDate !== $lastDate;
            $grouped = $sender === $lastSender && !$showDate;
            $lastDate = $msgDate;
            $lastSender = $sender;
            $msgClasses = 'message ' . ($msg['direction'] === 'outbound' ? 'message-out' : 'message-in');
            if ($msg['type'] === 'internal_note') $msgClasses .= ' message-note';
            if ($msg['type'] === 'system') $msgClasses .= ' message-system';
            if ($msg['type'] === 'sticker') $msgClasses .= ' message-sticker';
            if ($grouped) $msgClasses .= ' message-grouped';
            $textForSearch = strip_tags((string) ($msg['content'] ?? ''));
        ?>
            <?php if ($showDate): ?>
            <div class="date-sep"><span><?= format_date_sep($msg['created_at']) ?></span></div>
            <?php endif; ?>
            <div class="<?= $msgClasses ?>" data-mid="<?= $msg['id'] ?>" data-text="<?= e($textForSearch) ?>">
                <div class="msg-avatar msg-avatar-<?= $sender ?>"><?= $av ?></div>
                <div class="message-body <?= $isDeleted ? 'is-deleted' : '' ?>">
                    <?php if (($msg['direction'] ?? '') === 'inbound' && (!empty($msg['sender_name']) || !empty($msg['sender_phone']))): ?>
                    <div class="msg-group-sender" style="font-size:12px;font-weight:600;color:#0b57d0;margin-bottom:2px"><i class="fas fa-user"></i> <?= e($msg['sender_name'] ?: $msg['sender_phone']) ?><?php if (!empty($msg['sender_name']) && !empty($msg['sender_phone'])): ?> <small style="opacity:.65;font-weight:400"><?= e($msg['sender_phone']) ?></small><?php endif; ?></div>
                    <?php endif; ?>
                    <?php if (!empty($msg['reply_to_data'])): ?>
                    <div class="msg-quote" onclick="scrollToMessage(<?= (int) $msg['reply_to_data']['id'] ?>)">
                        <div class="msg-quote-content">
                            <div class="msg-quote-name"><?= ($msg['reply_to_data']['direction'] ?? '') === 'outbound' ? 'Você' : e($contact['name'] ?? 'Contato') ?></div>
                            <div class="msg-quote-text"><?= e(mb_substr(strip_tags((string) ($msg['reply_to_data']['content'] ?? '')), 0, 200)) ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?= $renderMessageContent($msg) ?>
                    <div class="message-time">
                        <span><?= format_time($msg['created_at']) ?></span>
                        <?php if (!empty($msg['user_name']) && $msg['direction'] === 'outbound'): ?>
                            <span style="opacity:.7">· <?= e($msg['user_name']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($msg['updated_at']) && $msg['updated_at'] !== $msg['created_at']): ?>
                            <span class="msg-edited" title="Editada">editada</span>
                        <?php endif; ?>
                        <?= $renderReceipts($msg) ?>
                    </div>
                    <?php if (!$isDeleted && !empty($msg['reactions'])):
                        $rxs = json_decode($msg['reactions'], true) ?: [];
                        $rGroups = [];
                        foreach ($rxs as $rx) {
                            $emoji = $rx['emoji'] ?? '';
                            if (!$emoji) continue;
                            if (!isset($rGroups[$emoji])) $rGroups[$emoji] = ['emoji' => $emoji, 'count' => 0, 'senders' => []];
                            $rGroups[$emoji]['count']++;
                            $rGroups[$emoji]['senders'][] = $rx['sender_name'] ?? $rx['from'] ?? '';
                        }
                    ?>
                    <div class="reactions-row">
                        <?php foreach ($rGroups as $rg): ?>
                        <span class="reaction-pill <?= in_array($msg['user_name'] ?? '', $rg['senders'], true) ? 'me' : '' ?>" title="<?= e(implode(', ', $rg['senders'])) ?>"><?= e($rg['emoji']) ?><?= $rg['count'] > 1 ? '<span class="count">' . $rg['count'] . '</span>' : '' ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!$isDeleted): ?>
                    <div class="msg-actions">
                        <button type="button" class="msg-act" title="Copiar" onclick="copyMessage(<?= $msg['id'] ?>)"><i class="fas fa-copy"></i></button>
                        <button type="button" class="msg-act" title="Citar" onclick="quoteMessage(<?= $msg['id'] ?>)"><i class="fas fa-quote-right"></i></button>
                        <button type="button" class="msg-act" title="Reagir" onclick="toggleReactionPicker(<?= $msg['id'] ?>, event)"><i class="far fa-smile"></i></button>
                        <?php if ($own && in_array($msg['type'], ['text', 'internal_note'], true)): ?>
                            <button type="button" class="msg-act" title="Editar" onclick="editMessage(<?= $msg['id'] ?>)"><i class="fas fa-edit"></i></button>
                            <button type="button" class="msg-act msg-act-danger" title="Excluir" onclick="deleteMessage(<?= $msg['id'] ?>)"><i class="fas fa-trash"></i></button>
                        <?php endif; ?>
                    </div>
                    <div class="reaction-picker" id="rp-<?= $msg['id'] ?>" data-mid="<?= $msg['id'] ?>"><?php foreach (['👍','❤️','😂','😮','😢','🙏','🔥'] as $re): ?><span class="rp-emoji" onclick="sendReaction(<?= $msg['id'] ?>, '<?= $re ?>')"><?= $re ?></span><?php endforeach; ?></div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>

    <button type="button" class="new-msg-pill" id="convNewPill" style="display:none" onclick="scrollConvBottom()">Novas mensagens ↓</button>

    <?php $panelFinished = in_array($conv['status'] ?? '', ['resolved', 'closed', 'spam']); ?>
    <div class="chat-input">
        <?php if ($panelFinished): ?>
            <div class="composer-locked" style="margin:12px 16px 0">
                <i class="fas fa-lock"></i> Conversa <?= ($conv['status'] ?? '') === 'spam' ? 'marcada como spam' : 'finalizada' ?>. Não é possível enviar mensagens.
            </div>
        <?php endif; ?>
        <form action="<?= $panelFinished ? '#' : url('inbox/') . $conv['id'] . '/messages' ?>" method="POST" enctype="multipart/form-data" class="composer-form" id="composerForm">
            <?= csrf_field() ?>
            <input type="hidden" name="type" id="msgType" value="text">
            <div class="quote-bar" id="quoteBar" style="display:none">
                <i class="fas fa-quote-right" style="color:var(--brand)"></i>
                <span class="quote-text" id="quotePreview"></span>
                <button type="button" class="quote-close" onclick="clearQuote()">&times;</button>
            </div>
            <div class="input-toolbar">
                <label class="icon-btn" title="Anexar arquivo" style="cursor:pointer">
                    <i class="fas fa-paperclip"></i>
                    <input type="file" name="file" id="attachInput"
                           accept="image/*,audio/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip" hidden <?= $panelFinished ? 'disabled' : '' ?>>
                </label>
                <button type="button" class="icon-btn" id="micToggle" title="Gravar áudio" <?= $panelFinished ? 'disabled' : '' ?>>
                    <i class="fas fa-microphone"></i>
                </button>
                <button type="button" class="icon-btn" title="Resposta pronta" onclick="openCannedModal()" <?= $panelFinished ? 'disabled' : '' ?>><i class="fas fa-bookmark"></i></button>
                <button type="button" class="icon-btn" title="Macro" onclick="openMacroModal()" <?= $panelFinished ? 'disabled' : '' ?>><i class="fas fa-bolt"></i></button>
                <button type="button" class="icon-btn" title="Sugerir artigo da Wiki (envia o link do portal do cliente)" onclick="openWikiModal()" <?= $panelFinished ? 'disabled' : '' ?>><i class="fas fa-book-open"></i></button>
                <div style="flex:1"></div>
                <button type="button" class="icon-btn" id="emojiToggle" title="Emoji" <?= $panelFinished ? 'disabled' : '' ?>><i class="fas fa-smile"></i></button>
                <button type="button" class="icon-btn" id="internalToggle"
                        onclick="toggleInternal()" title="Mensagem interna (não enviada ao cliente)" <?= $panelFinished ? 'disabled' : '' ?>>
                    <i class="fas fa-lock"></i>
                </button>
                <?php if (($conv['channel_type'] ?? '') === 'whatsapp'): ?>
                <button type="button" class="icon-btn <?= empty($conv['signature_enabled']) ? '' : 'active' ?>" id="signatureToggle"
                        onclick="toggleSignature(<?= (int) $conv['id'] ?>)" title="Assinatura automática no WhatsApp" <?= $panelFinished ? 'disabled' : '' ?>>
                    <i class="fas fa-signature"></i>
                </button>
                <?php endif; ?>
            </div>
            <div class="input-row">
                <textarea name="content" id="messageInput" class="input-box" rows="1"
                          placeholder="<?= $panelFinished ? 'Conversa finalizada' : "Digite sua mensagem... Enter envia, Shift+Enter quebra linha" ?>"
                          <?= $panelFinished ? 'disabled' : '' ?>></textarea>
                <input type="hidden" name="reply_to" id="replyToInput" value="">
                <button type="submit" class="send-btn" id="sendBtn" title="Enviar (Enter)" <?= $panelFinished ? 'disabled' : '' ?>>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
                </button>
            </div>
            <?php if (!$panelFinished): ?>
            <div class="composer-hint">
                <span><kbd>/</kbd> resposta pronta</span>
                <span><kbd>:</kbd> macro</span>
                <span><kbd>@</kbd> mencionar</span>
                <span class="char-count" id="charCount">0</span>
            </div>
            <?php endif; ?>
            <div class="composer-file" id="composerFile" style="display:none">
                <i class="fas fa-file"></i> <span id="composerFileName"></span>
                <button type="button" class="composer-file-x" id="composerFileX" title="Remover">&times;</button>
            </div>
            <div class="composer-record" id="composerRecord" style="display:none">
                <span class="rec-indicator" id="recIndicator"></span>
                <span class="rec-timer" id="recTimer">00:00</span>
                <button type="button" class="rec-btn rec-cancel" id="recCancel" title="Cancelar">
                    <i class="fas fa-trash"></i>
                </button>
                <button type="button" class="rec-btn rec-stop" id="recStop" title="Parar e enviar">
                    <i class="fas fa-stop"></i>
                </button>
            </div>
            <div class="emoji-popover" id="emojiPopover" style="display:none"></div>
        </form>
    </div>
    </div><!-- /.conv-main -->

    <div class="conv-drawer-backdrop" id="clientDrawerBackdrop" onclick="toggleClientDrawer(false)"></div>
    <div class="conv-drawer" id="clientDrawer">
        <div class="conv-drawer-header">
            <h4><i class="fas fa-user"></i> Cliente</h4>
            <div style="display:flex;align-items:center;gap:4px">
                <button type="button" class="drawer-close" onclick="openContactEditModal()" title="Editar contato" style="font-size:14px;width:28px;height:28px">
                    <i class="fas fa-pen"></i>
                </button>
                <button type="button" class="drawer-close" onclick="toggleClientDrawer()" title="Fechar">&times;</button>
            </div>
        </div>
        <div class="conv-drawer-body">
            <div class="client-profile-card">
                <div class="client-cover">
                    <div class="client-cover-photo">
                        <?php if (!empty($contact['avatar'])): ?>
                            <img src="<?= e(str_starts_with($contact['avatar'], 'http') ? $contact['avatar'] : upload_url($contact['avatar'])) ?>" alt="<?= e($contact['name'] ?? '') ?>" onerror="this.style.display='none';this.parentElement.textContent='<?= e($initial) ?>'">
                        <?php else: ?>
                            <?= e($initial) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="client-info-body">
                    <div class="client-name">
                        <?= e($contact['name'] ?? 'Contato') ?>
                        <span class="conv-online-dot <?= $online ? 'online' : '' ?>"></span>
                    </div>
                    <div class="client-status"><?= $online ? 'Online agora' : 'Offline' ?></div>
                    <div class="client-fields-modern">
                        <?php if (!empty($contact['email'])): ?>
                            <div class="client-field-item">
                                <span class="field-icon icon-email"><i class="fas fa-envelope"></i></span>
                                <span><?= e($contact['email']) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($contact['phone'])): ?>
                            <div class="client-field-item">
                                <span class="field-icon icon-phone"><i class="fas fa-phone"></i></span>
                                <span><?= e($contact['phone']) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php
                        $panelNets = [];
                        foreach (($contact['stores'] ?? []) as $ps) { $panelNets[$ps['network_name']] = true; }
                        ?>
                        <?php if ($panelNets): ?>
                            <?php foreach (array_keys($panelNets) as $pNet): ?>
                                <div class="client-field-item">
                                    <span class="field-icon icon-building"><i class="fas fa-store"></i></span>
                                    <span><?= e($pNet) ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php elseif (!empty($contact['company'])): ?>
                            <div class="client-field-item">
                                <span class="field-icon icon-building"><i class="fas fa-building"></i></span>
                                <span><?= e($contact['company']) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($contact['document'])): ?>
                            <div class="client-field-item">
                                <span class="field-icon icon-document"><i class="fas fa-id-card"></i></span>
                                <span><?= e($contact['document']) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php foreach (($contact['phones'] ?? []) as $ph): ?>
                            <?php if (($ph['phone'] ?? null) === ($contact['phone'] ?? null)) continue; ?>
                            <div class="client-field-item">
                                <span class="field-icon icon-phone"><i class="fas fa-phone"></i></span>
                                <span><?= e($ph['phone']) ?><?= !empty($ph['label']) ? ' <small>(' . e($ph['label']) . ')</small>' : '' ?></span>
                            </div>
                        <?php endforeach; ?>
                        <?php foreach (($contact['emails'] ?? []) as $em): ?>
                            <?php if (($em['email'] ?? null) === ($contact['email'] ?? null)) continue; ?>
                            <div class="client-field-item">
                                <span class="field-icon icon-email"><i class="fas fa-envelope"></i></span>
                                <span><?= e($em['email']) ?><?= !empty($em['label']) ? ' <small>(' . e($em['label']) . ')</small>' : '' ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (!empty($contact['tags'])): ?>
                        <div class="conv-tags" style="margin-top: 12px;">
                            <?php foreach ($contact['tags'] as $tag): ?>
                                <span class="conv-tag" style="background:<?= e($tag['color'] ?? '#e9ecef') ?>;color:<?= e(contrast_color($tag['color'] ?? '#e9ecef')) ?>">
                                    <?= e($tag['name']) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h4><i class="fas fa-lock"></i> Observações Internas <span class="badge badge-tag-count"><?= count($internalNotes ?? []) ?></span></h4></div>
                <div class="card-body">
                    <form onsubmit="return submitInternalNote(event)">
                        <?= csrf_field() ?>
                        <textarea id="internalNoteInput" rows="3" class="form-control" placeholder="Adicionar observação interna (visível só para a equipe)..."></textarea>
                        <button type="submit" class="btn btn-sm btn-outline" id="internalNoteBtn" style="margin-top:8px"><i class="fas fa-save"></i> Salvar observação</button>
                    </form>
                    <div class="history-list-modern" id="internalNotesList" style="margin-top:12px">
                        <?php if (empty($internalNotes)): ?>
                            <p class="tag-empty-msg" id="internalNotesEmpty">Nenhuma observação registrada.</p>
                        <?php else: ?>
                            <?php foreach ($internalNotes as $note): ?>
                                <div class="history-item-modern">
                                    <div class="history-icon-modern" style="color:var(--warning)">
                                        <i class="fas fa-lock"></i>
                                    </div>
                                    <div class="history-content">
                                        <p style="font-size:13px;margin:0"><?= nl2br(e($note['content'])) ?></p>
                                        <span class="history-time" style="font-size:11px">
                                            <?= format_datetime($note['created_at']) ?>
                                            <?php if (!empty($note['user_name'])): ?> &middot; <?= e($note['user_name']) ?><?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h4><i class="fas fa-ticket-alt"></i> Atendimentos</h4></div>
                <div class="card-body p-0">
                    <?php if (empty($otherConversations)): ?>
                        <div class="empty-state" style="padding: 20px;"><p>Nenhum outro atendimento deste cliente.</p></div>
                    <?php else: ?>
                        <div class="other-tickets-list">
                            <?php foreach ($otherConversations as $oc): ?>
                                <a href="<?= url('inbox') ?>?conv=<?= $oc['id'] ?>" class="other-ticket-item">
                                    <div class="other-ticket-head">
                                        <span class="other-ticket-title"><?= e($oc['subject'] ?: $oc['contact_name']) ?><?php if (!empty($oc['unit'])): ?> — <?= e($oc['unit']) ?><?php endif; ?></span>
                                    </div>
                                    <div class="other-ticket-meta">
                                        <i class="<?= channel_icon($oc['channel_type'] ?? 'webchat') ?>"></i>
                                        <?= e($oc['channel_name'] ?? '') ?>
                                        <span class="dot-sep">&middot;</span>
                                        <?= status_badge($oc['status']) ?>
                                        <span class="dot-sep">&middot;</span>
                                        <?= format_datetime($oc['last_message_at'] ?? $oc['created_at']) ?>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h4><i class="fas fa-tags"></i> Etiquetas <span class="badge badge-tag-count" id="convTagCount"><?= count($conv['tags'] ?? []) ?></span></h4></div>
                <div class="card-body p-0">
                    <div class="tag-list" id="convTagList">
                        <?php if (empty($conv['tags'])): ?>
                            <p class="text-muted tag-empty-msg">Nenhuma etiqueta.</p>
                        <?php else: ?>
                            <?php foreach ($conv['tags'] as $tag): ?>
                                <span class="conv-tag-modern applied" data-tag-id="<?= $tag['id'] ?>" style="background:<?= e($tag['color'] ?? '#6c757d') ?>;color:<?= e(contrast_color($tag['color'] ?? '#6c757d')) ?>">
                                    <?= e($tag['name']) ?>
                                    <button type="button" class="tag-remove-btn" data-tag-id="<?= $tag['id'] ?>" title="Remover">&times;</button>
                                </span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div class="tag-picker-section">
                        <div class="tag-picker-header">Todas as etiquetas</div>
                        <div class="tag-grid" id="tagGrid">
                            <?php
                            $convTagIds = array_column($conv['tags'] ?? [], 'id');
                            foreach ($allTags as $t):
                                $applied = in_array($t['id'], $convTagIds);
                            ?>
                                <button type="button"
                                    class="tag-grid-item <?= $applied ? 'applied' : '' ?>"
                                    data-tag-id="<?= $t['id'] ?>"
                                    data-color="<?= e($t['color'] ?? '#6c757d') ?>"
                                    style="--tag-color:<?= e($t['color'] ?? '#6c757d') ?>">
                                    <?= e($t['name']) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <div class="tag-create-row">
                            <input type="text" class="tag-create-input" id="tagCreateInput" placeholder="Nova etiqueta..." maxlength="40">
                            <button type="button" class="btn btn-sm btn-primary tag-create-btn" id="tagCreateBtn" disabled>Criar</button>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($csat): ?>
            <div class="card">
                <div class="card-header"><h4><i class="fas fa-smile"></i> Avaliação</h4></div>
                <div class="card-body" style="text-align:center">
                    <div class="csat-stars">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="fas fa-star <?= $i <= $csat['rating'] ? 'on' : '' ?>"></i>
                        <?php endfor; ?>
                    </div>
                    <?php if (!empty($csat['comment'])): ?>
                        <p class="text-muted" style="margin-top:8px;font-size:13px"><?= e($csat['comment']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header"><h4><i class="fas fa-history"></i> Histórico <span class="badge badge-tag-count"><?= count($events) ?></span></h4></div>
                <div class="card-body p-0">
                    <div class="history-list-modern" id="convHistory">
                        <?php if (empty($events)): ?>
                            <p class="tag-empty-msg">Nenhum evento registrado.</p>
                        <?php else: ?>
                            <?php foreach ($events as $event): ?>
                                <div class="history-item-modern">
                                    <div class="history-icon-modern" style="color:<?= event_color($event['event_type']) ?>">
                                        <i class="fas <?= event_icon($event['event_type']) ?>"></i>
                                    </div>
                                    <div class="history-content">
                                        <p style="font-size:13px;margin:0"><?= e($event['description']) ?></p>
                                        <span class="history-time" style="font-size:11px">
                                            <?= format_datetime($event['created_at']) ?>
                                            <?php if ($event['user_name']): ?> &middot; <?= e($event['user_name']) ?><?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal-overlay" id="transferModal" style="display:none" onclick="if(event.target===this)closeTransferModal()">
    <div class="modal-container" style="max-width:460px">
        <div class="modal-header"><h3>Transferir Atendimento</h3><button class="modal-close" onclick="closeTransferModal()">&times;</button></div>
        <form action="<?= url('inbox/') ?><?= $conv['id'] ?>/transfer" method="POST">
            <?= csrf_field() ?>
            <div class="modal-body">
                <div class="form-group">
                    <label>Departamento</label>
                    <select name="department_id" id="transferDeptSelect" class="form-control" onchange="loadTransferUsers()">
                        <option value="">Manter atual</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['id'] ?>" <?= $dept['id'] == $conv['department_id'] ? 'selected' : '' ?>><?= e($dept['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Atendente</label>
                    <select name="user_id" id="transferUserSelect" class="form-control"><option value="">Fila do departamento</option></select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeTransferModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary">Transferir</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="priorityModal" style="display:none" onclick="if(event.target===this)closePriorityModal()">
    <div class="modal-container" style="max-width:440px">
        <div class="modal-header">
            <h3><i class="fas fa-flag"></i> Selecionar prioridade</h3>
            <button class="modal-close" onclick="closePriorityModal()">&times;</button>
        </div>
        <div class="modal-body" style="padding:14px">
            <p class="text-muted" style="margin:0 0 12px;font-size:12.5px">Escolha a urgência do atendimento desta conversa.</p>
            <div class="option-list">
                <?php
                $priorities = [
                    'low' => ['label' => 'Baixa', 'desc' => 'Sem urgência, pode aguardar.', 'icon' => 'fa-arrow-down', 'color' => '#1976d2'],
                    'normal' => ['label' => 'Normal', 'desc' => 'Padrão da maioria dos atendimentos.', 'icon' => 'fa-equals', 'color' => 'var(--text-secondary)'],
                    'high' => ['label' => 'Alta', 'desc' => 'Requer atenção em breve.', 'icon' => 'fa-arrow-up', 'color' => '#f57c00'],
                    'urgent' => ['label' => 'Urgente', 'desc' => 'Resolver imediatamente.', 'icon' => 'fa-bolt', 'color' => '#d32f2f'],
                ];
                $currentPriority = $conv['priority'] ?? 'normal';
                foreach ($priorities as $pval => $pinfo): ?>
                <button type="button" class="option-item option-priority-<?= $pval ?> <?= $currentPriority === $pval ? 'is-active' : '' ?>" data-priority="<?= $pval ?>" onclick="pickPriority('<?= $pval ?>')">
                    <span class="option-icon" style="background:<?= $pinfo['color'] ?>15;color:<?= $pinfo['color'] ?>">
                        <i class="fas <?= $pinfo['icon'] ?>"></i>
                    </span>
                    <span class="option-content">
                        <span class="option-title"><?= $pinfo['label'] ?></span>
                        <span class="option-desc"><?= $pinfo['desc'] ?></span>
                    </span>
                    <?php if ($currentPriority === $pval): ?>
                    <i class="fas fa-check option-check"></i>
                    <?php endif; ?>
                </button>
                <?php endforeach; ?>
            </div>
            <?php if (empty($conv['subject']) && false): ?>
            <button type="button" class="btn btn-outline" style="width:100%;margin-top:8px" onclick="clearPriority()">
                <i class="fas fa-times"></i> Remover prioridade
            </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="modal-overlay" id="subjectModal" style="display:none" onclick="if(event.target===this)closeSubjectModal()">
    <div class="modal-container" style="max-width:520px">
        <div class="modal-header">
            <h3><i class="fas fa-tag"></i> Selecionar assunto</h3>
            <button class="modal-close" onclick="closeSubjectModal()">&times;</button>
        </div>
        <div class="modal-body" style="padding:14px">
            <input type="text" id="subjectSearchInput" class="form-control" placeholder="Buscar assunto..." oninput="filterSubjects()" style="margin-bottom:10px">
            <div class="option-list" id="subjectList">
                <button type="button" class="option-item <?= empty($conv['subject']) ? 'is-active' : '' ?>" data-subject="" onclick="pickSubject('')">
                    <span class="option-icon" style="background:var(--bg-panel-alt);color:var(--text-muted)"><i class="fas fa-ban"></i></span>
                    <span class="option-content">
                        <span class="option-title">Sem assunto</span>
                        <span class="option-desc">Remover o assunto atual desta conversa.</span>
                    </span>
                    <?php if (empty($conv['subject'])): ?>
                    <i class="fas fa-check option-check"></i>
                    <?php endif; ?>
                </button>
                <?php foreach (($convSubjects ?? []) as $s): ?>
                <button type="button" class="option-item <?= ($conv['subject'] ?? '') === $s['name'] ? 'is-active' : '' ?>" data-subject="<?= e($s['name']) ?>" onclick="pickSubject('<?= e($s['name']) ?>')">
                    <span class="option-icon" style="background:var(--brand-soft);color:var(--brand-2)"><i class="fas fa-tag"></i></span>
                    <span class="option-content">
                        <span class="option-title"><?= e($s['name']) ?></span>
                        <span class="option-desc">Assunto predefinido para classificar a conversa.</span>
                    </span>
                    <?php if (($conv['subject'] ?? '') === $s['name']): ?>
                    <i class="fas fa-check option-check"></i>
                    <?php endif; ?>
                </button>
                <?php endforeach; ?>
                <?php if (empty($convSubjects)): ?>
                <div class="empty-state" style="padding:24px">
                    <i class="fas fa-folder-open" style="font-size:32px;color:var(--text-muted);margin-bottom:8px"></i>
                    <p style="margin:0;font-size:13px">Nenhum assunto cadastrado.</p>
                    <p style="margin:4px 0 0;font-size:12px"><a href="<?= url('settings/subjects') ?>" target="_blank">Cadastrar assuntos</a></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="modal-overlay" id="cannedModal" style="display:none" onclick="if(event.target===this)closeCannedModal()">
    <div class="modal-container" style="max-width:480px">
        <div class="modal-header"><h3>Respostas Prontas</h3><button class="modal-close" onclick="closeCannedModal()">&times;</button></div>
        <div class="modal-body">
            <div class="form-group">
                <input type="text" id="cannedSearch" class="form-control" placeholder="Buscar resposta..." onkeyup="filterCanned()">
            </div>
            <div id="cannedList" class="canned-list"></div>
        </div>
    </div>
</div>

<div class="modal-overlay" id="macroModal" style="display:none" onclick="if(event.target===this)closeMacroModal()">
    <div class="modal-container" style="max-width:480px">
        <div class="modal-header"><h3>Macros</h3><button class="modal-close" onclick="closeMacroModal()">&times;</button></div>
    <div class="modal-body">
        <div class="form-group">
            <input type="text" id="macroSearch" class="form-control" placeholder="Buscar macro..." onkeyup="filterMacros()">
        </div>
        <div id="macroList" class="canned-list"></div>
        <p class="text-muted" style="margin-top:12px"><a href="<?= url('macros') ?>" target="_blank">Gerenciar macros</a></p>
    </div>
</div>
</div>

<div class="modal-overlay" id="wikiModal" style="display:none" onclick="if(event.target===this)closeWikiModal()">
    <div class="modal-container" style="max-width:480px">
        <div class="modal-header"><h3><i class="fas fa-book-open"></i> Sugerir artigo da Wiki</h3><button class="modal-close" onclick="closeWikiModal()">&times;</button></div>
        <div class="modal-body">
            <div class="form-group">
                <input type="text" id="wikiSearch" class="form-control" placeholder="Buscar artigo..." onkeyup="filterWikiSuggest()">
            </div>
            <div id="wikiList" class="canned-list"></div>
            <p class="text-muted" style="margin-top:12px">Insere no campo de mensagem o <strong>link do portal do cliente</strong>.</p>
        </div>
    </div>
</div>

<div class="modal-overlay" id="statusModal" style="display:none" onclick="if(event.target===this)closeStatusModal()">
    <div class="modal-container" style="max-width:520px">
        <div class="modal-header">
            <h3><i class="fas fa-comments"></i> Selecionar status</h3>
            <button class="modal-close" onclick="closeStatusModal()">&times;</button>
        </div>
        <form action="<?= url('inbox/') ?><?= $conv['id'] ?>/status" method="POST" id="statusForm">
            <?= csrf_field() ?>
            <input type="hidden" name="status" id="statusValue" value="<?= e($conv['status']) ?>">
            <div class="modal-body" style="padding:14px">
                <?php $isGroupConv = !empty($conv['group_id']); ?>
                <?php if ($isGroupConv): ?>
                <p class="text-muted" style="margin:0 0 12px;font-size:12.5px"><i class="fas fa-users"></i> Conversa de grupo: fica sempre aberta, não pode ser encerrada.</p>
                <?php else: ?>
                <p class="text-muted" style="margin:0 0 12px;font-size:12.5px">Defina o estado atual desta conversa na fila.</p>
                <?php endif; ?>
                <div class="option-list">
                    <?php
                    $statuses = [
                        'open' => ['label' => 'Aberto', 'desc' => 'Conversa em atendimento ativo.', 'icon' => 'fa-comments', 'cls' => 'option-status-open'],
                        'waiting_customer' => ['label' => 'Em atendimento', 'desc' => 'Aguardando resposta do cliente.', 'icon' => 'fa-headset', 'cls' => 'option-status-waiting_customer'],
                        'waiting_internal' => ['label' => 'Aguardando interno', 'desc' => 'Aguardando outro atendente ou setor.', 'icon' => 'fa-hourglass-half', 'cls' => 'option-status-waiting_internal'],
                        'resolved' => ['label' => 'Resolvido', 'desc' => 'Solicitação concluída com sucesso.', 'icon' => 'fa-check-circle', 'cls' => 'option-status-resolved'],
                        'closed' => ['label' => 'Fechado', 'desc' => 'Conversa encerrada sem solução.', 'icon' => 'fa-archive', 'cls' => 'option-status-closed'],
                        'spam' => ['label' => 'Spam', 'desc' => 'Marcada como lixo eletrônico.', 'icon' => 'fa-ban', 'cls' => 'option-status-spam'],
                    ];
                    if ($isGroupConv) {
                        unset($statuses['resolved'], $statuses['closed'], $statuses['spam']);
                    }
                    foreach ($statuses as $sval => $sinfo): ?>
                    <button type="button" class="option-item <?= $sinfo['cls'] ?> <?= $conv['status'] === $sval ? 'is-active' : '' ?>" data-status="<?= $sval ?>" onclick="pickStatus('<?= $sval ?>')">
                        <span class="option-icon"><i class="fas <?= $sinfo['icon'] ?>"></i></span>
                        <span class="option-content">
                            <span class="option-title"><?= $sinfo['label'] ?></span>
                            <span class="option-desc"><?= $sinfo['desc'] ?></span>
                        </span>
                        <?php if ($conv['status'] === $sval): ?>
                        <i class="fas fa-check option-check"></i>
                        <?php endif; ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <div id="closeFields" class="close-fields" style="display:none">
                    <div class="close-fields-header">
                        <i class="fas fa-clipboard-check"></i>
                        <div class="close-fields-title">
                            <strong>Detalhes do encerramento</strong>
                            <span>Registre o motivo e contexto para histórico e relatórios.</span>
                        </div>
                    </div>

                    <div class="close-fields-section">
                        <label class="close-fields-label">
                            <span>Motivo</span>
                            <span class="close-fields-required">obrigatório</span>
                        </label>
                        <input type="hidden" name="reason" id="closeReasonValue" value="<?= e($conversation['close_reason'] ?? '') ?>">
                        <div class="reason-grid" id="reasonGrid">
                            <?php foreach ($closeReasons as $r): ?>
                            <button type="button" class="reason-chip <?= ($conversation['close_reason'] ?? '') === $r['code'] ? 'is-active' : '' ?>" data-reason="<?= e($r['code']) ?>" data-label="<?= e($r['label']) ?>" onclick="pickReason('<?= e($r['code']) ?>', this)" style="--reason-color:<?= e($r['color']) ?>">
                                <span class="reason-icon"><i class="fas <?= e($r['icon'] ?: 'fa-tag') ?>"></i></span>
                                <span class="reason-label"><?= e($r['label']) ?></span>
                                <?php if (!empty($r['description'])): ?>
                                <span class="reason-desc"><?= e($r['description']) ?></span>
                                <?php endif; ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                        <p class="close-fields-hint" id="closeReasonHint" style="display:none">
                            <i class="fas fa-info-circle"></i>
                            <span>Nenhum motivo selecionado. Selecione um acima para continuar.</span>
                        </p>
                    </div>

                    <div class="close-fields-section">
                        <label class="close-fields-label" for="closeDescription">
                            <span>Descrição / observações</span>
                            <span class="close-fields-optional">opcional</span>
                        </label>
                        <textarea name="description" id="closeDescription" class="close-fields-textarea" rows="3" maxlength="600"
                                  placeholder="Descreva brevemente o que foi feito, a solução aplicada ou o contexto do encerramento..."
                                  oninput="updateCharCount('closeDescription','closeCharCount',600)"><?= e($conversation['close_description'] ?? '') ?></textarea>
                        <div class="close-fields-meta">
                            <span class="close-fields-hint-inline"><i class="fas fa-lock"></i> Visível apenas para a equipe</span>
                            <span class="char-count" id="closeCharCount"><?= mb_strlen($conversation['close_description'] ?? '') ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeStatusModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="statusSubmitBtn">Alterar status</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="snoozeModal" style="display:none" onclick="if(event.target===this)closeSnoozeModal()">
    <div class="modal-container" style="max-width:460px">
        <div class="modal-header"><h3>Agendar Atendimento</h3><button class="modal-close" onclick="closeSnoozeModal()">&times;</button></div>
        <form action="<?= url('inbox/') ?><?= $conv['id'] ?>/snooze" method="POST">
            <?= csrf_field() ?>
            <div class="modal-body">
                <div class="form-group">
                    <label>Retomar em</label>
                    <select name="until" class="form-control">
                        <option value="">Cancelar agendamento</option>
                        <option value="<?= date('Y-m-d\TH:i', strtotime('+1 hour')) ?>">Em 1 hora</option>
                        <option value="<?= date('Y-m-d\TH:i', strtotime('+4 hour')) ?>">Em 4 horas</option>
                        <option value="<?= date('Y-m-d\TH:i', strtotime('+1 day')) ?>">Amanhã</option>
                        <option value="<?= date('Y-m-d\TH:i', strtotime('+3 day')) ?>">Em 3 dias</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeSnoozeModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary">Agendar</button>
            </div>
        </form>
    </div>
</div>


<div class="modal-overlay" id="mergeModal" style="display:none" onclick="if(event.target===this)closeMergeModal()">
    <div class="modal-container" style="max-width:460px">
        <div class="modal-header"><h3>Mesclar Conversa</h3><button class="modal-close" onclick="closeMergeModal()">&times;</button></div>
        <form action="<?= url('inbox/') ?><?= $conv['id'] ?>/merge" method="POST">
            <?= csrf_field() ?>
            <div class="modal-body">
                <p class="text-muted" style="margin-bottom:14px">Mover mensagens e etiquetas desta conversa para outra do mesmo cliente.</p>
                <div class="form-group">
                    <label>Conversa de destino</label>
                    <select name="target_id" class="form-control">
                        <option value="">Selecione...</option>
                        <?php foreach ($otherConversations as $oc): ?>
                            <option value="<?= $oc['id'] ?>">#<?= $oc['id'] ?> - <?= e($oc['subject'] ?: $oc['contact_name']) ?> (<?= e($oc['status']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeMergeModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary">Mesclar</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="editModal" style="display:none" onclick="if(event.target===this)closeEditModal()">
    <div class="modal-container" style="max-width:460px">
        <div class="modal-header"><h3>Editar Mensagem</h3><button class="modal-close" onclick="closeEditModal()">&times;</button></div>
        <div class="modal-body">
            <div class="form-group">
                <textarea id="editContent" class="form-control" rows="4"></textarea>
            </div>
            <div class="form-group" style="margin-bottom:0">
                <button class="btn btn-primary" onclick="saveEdit()">Salvar</button>
            </div>
        </div>
    </div>
</div>

<div class="modal-overlay" id="contactEditModal" style="display:none" onclick="if(event.target===this)closeContactEditModal()">
    <div class="modal-container" style="max-width:480px">
        <div class="modal-header">
            <h3><i class="fas fa-user-edit"></i> Editar Contato</h3>
            <button class="modal-close" onclick="closeContactEditModal()">&times;</button>
        </div>
        <form action="<?= url('contacts/' . ((int)$contact['id'] ?? 0) . '/update') ?>" method="POST" id="contactEditForm" onsubmit="return submitContactEdit(event)">
            <?= csrf_field() ?>
            <div class="modal-body">
                <div class="form-group">
                    <label><i class="fas fa-user"></i> Nome</label>
                    <input type="text" name="name" class="form-control" value="<?= e($contact['name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-envelope"></i> E-mail</label>
                    <input type="email" name="email" class="form-control" value="<?= e($contact['email'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-phone"></i> Telefone</label>
                    <input type="text" name="phone" class="form-control" value="<?= e($contact['phone'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-id-card"></i> Documento</label>
                    <input type="text" name="document" class="form-control" value="<?= e($contact['document'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-building"></i> Loja</label>
                    <input type="text" name="company" class="form-control" value="<?= e($contact['company'] ?? '') ?>">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeContactEditModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="contactEditBtn"><i class="fas fa-save"></i> Salvar</button>
            </div>
        </form>
    </div>
</div>

<script>
var CONV_ID = <?= (int) $conv['id'] ?>;
var DEPT_ID = <?= (int) ($conv['department_id'] ?? 0) ?>;
var CONTACT_INITIAL = '<?= e($initial) ?>';
var CONTACT_AVATAR = '<?= e($contact['avatar'] ?? '') ?>';
var CONTACT_NAME = '<?= e($contact['name'] ?? 'Contato') ?>';
var FIRST_MID = <?= (int) ($firstMid ?? 0) ?>;
var CSRF = document.querySelector('input[name=_csrf_token]')?.value || '';
var API = '<?= rtrim(base_url('api'), '/') ?>';
var BASE = '<?= rtrim(parse_url(base_url('/'), PHP_URL_PATH), '/') ?>';

function csrfForm() { const f = new FormData(); f.append('_csrf_token', CSRF); return f; }
function postJson(url, body) {
    if (typeof url === 'string' && url.charAt(0) === '/') url = BASE + url;
    const fd = csrfForm();
    for (const k in (body || {})) fd.append(k, body[k]);
    return fetch(url, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(r => r.json()).catch(() => ({ success: false }));
}

function toggleClientDrawer(force) {
    var view = document.querySelector('.conversation-view');
    if (!view) return;
    var open = (typeof force === 'boolean') ? force : !view.classList.contains('drawer-open');
    view.classList.toggle('drawer-open', open);
}
function closeClientDrawer() { toggleClientDrawer(false); }

function submitInternalNote(e) {
    if (e) e.preventDefault();
    var ta = document.getElementById('internalNoteInput');
    var btn = document.getElementById('internalNoteBtn');
    var content = ta ? ta.value.trim() : '';
    if (!content) { toast('Digite a observação antes de salvar'); return false; }
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...'; }
    postJson('/inbox/' + CONV_ID + '/notes', { content: content }).then(function(resp) {
        if (resp && resp.success !== false) {
            if (ta) ta.value = '';
            var empty = document.getElementById('internalNotesEmpty');
            if (empty) empty.remove();
            var list = document.getElementById('internalNotesList');
            if (list && resp.note) {
                var div = document.createElement('div');
                div.className = 'history-item-modern';
                var d = document.createElement('div'); d.textContent = resp.note.content || content;
                var html = '<div class="history-icon-modern" style="color:var(--warning)"><i class="fas fa-lock"></i></div>'
                    + '<div class="history-content"><p style="font-size:13px;margin:0"></p>'
                    + '<span class="history-time" style="font-size:11px">agora'
                    + (resp.note.user_name ? ' &middot; ' + resp.note.user_name.replace(/</g, '') : '')
                    + '</span></div>';
                div.innerHTML = html;
                div.querySelector('p').textContent = resp.note.content || content;
                list.insertBefore(div, list.firstChild);
            }
            toast('Observação salva');
        } else {
            toast('Erro ao salvar observação');
        }
    }).finally(function() {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Salvar observação'; }
    });
    return false;
}

/* ---------- Tag Manager ---------- */
function initTagManager(convId) {
    var grid = document.getElementById('tagGrid');
    if (!grid) return;
    var tagList = document.getElementById('convTagList');
    var headerTags = document.getElementById('convHeaderTags');
    var tagCount = document.getElementById('convTagCount');
    var createInput = document.getElementById('tagCreateInput');
    var createBtn = document.getElementById('tagCreateBtn');
    var allTags = <?= json_encode(array_map(function($t) { return ['id' => (int)$t['id'], 'name' => $t['name'], 'color' => $t['color'] ?? '#6c757d']; }, $allTags)) ?>;

    function contrast(c) {
        var h = c.replace('#','');
        if (h.length===3) h = h[0]+h[0]+h[1]+h[1]+h[2]+h[2];
        var r=parseInt(h.substr(0,2),16), g=parseInt(h.substr(2,2),16), b=parseInt(h.substr(4,2),16);
        return (0.299*r+0.587*g+0.114*b)/255 > 0.5 ? '#1a1a2e' : '#fff';
    }

    function tagHtml(t) {
        return '<span class="conv-tag-modern applied" data-tag-id="'+t.id+'" style="background:'+t.color+';color:'+contrast(t.color)+'">' +
            t.name + ' <button type="button" class="tag-remove-btn" data-tag-id="'+t.id+'" title="Remover">&times;</button></span>';
    }

    function headerTagHtml(t) {
        return '<span class="conv-tag" data-tag-id="'+t.id+'" style="background:'+t.color+';color:'+contrast(t.color)+'">'+t.name+'</span>';
    }

    function gridItemHtml(t, applied) {
        return '<button type="button" class="tag-grid-item'+(applied?' applied':'')+'" data-tag-id="'+t.id+'" data-color="'+t.color+'" style="--tag-color:'+t.color+'">'+t.name+'</button>';
    }

    function updateAll(convTags) {
        var ids = convTags.map(function(t){return t.id});
        tagList.innerHTML = convTags.length
            ? convTags.map(tagHtml).join('')
            : '<p class="text-muted tag-empty-msg">Nenhuma etiqueta.</p>';
        headerTags.innerHTML = convTags.map(headerTagHtml).join('');
        grid.innerHTML = allTags.map(function(t){return gridItemHtml(t, ids.indexOf(t.id) !== -1)}).join('');
        tagCount.textContent = convTags.length;
        bindGridEvents(convId);
        bindRemoveEvents(convId);
    }

    function bindGridEvents(cid) {
        grid.querySelectorAll('.tag-grid-item').forEach(function(el) {
            el.addEventListener('click', function() {
                var tid = parseInt(el.dataset.tagId, 10);
                var adding = !el.classList.contains('applied');
                el.disabled = true;
                (adding
                    ? postJson('/inbox/'+cid+'/tags', { tag_id: tid })
                    : postJson('/inbox/'+cid+'/untag', { tag_id: tid })
                ).then(function(resp) {
                    if (resp.success !== false) {
                        var t = allTags.find(function(x){return x.id===tid});
                        if (!t) return;
                        if (adding) {
                            el.classList.add('applied');
                            var cur = getCurrentTags();
                            cur.push(t);
                            updateAll(cur);
                        } else {
                            el.classList.remove('applied');
                            var cur = getCurrentTags().filter(function(x){return x.id!==tid});
                            updateAll(cur);
                        }
                    }
                }).finally(function() { el.disabled = false; });
            });
        });
    }

    function bindRemoveEvents(cid) {
        tagList.querySelectorAll('.tag-remove-btn').forEach(function(el) {
            el.addEventListener('click', function(e) {
                e.stopPropagation();
                var tid = parseInt(el.dataset.tagId, 10);
                el.disabled = true;
                postJson('/inbox/'+cid+'/untag', { tag_id: tid }).then(function(resp) {
                    if (resp.success !== false) {
                        var cur = getCurrentTags().filter(function(x){return x.id!==tid});
                        updateAll(cur);
                    }
                }).finally(function() { el.disabled = false; });
            });
        });
    }

    function getCurrentTags() {
        var items = tagList.querySelectorAll('.conv-tag-modern.applied');
        return Array.from(items).map(function(el) {
            var id = parseInt(el.dataset.tagId, 10);
            return allTags.find(function(t){return t.id===id}) || {id:id, name:'?', color:'#6c757d'};
        }).filter(Boolean);
    }

    if (createInput) {
        createInput.addEventListener('input', function() {
            createBtn.disabled = createInput.value.trim().length < 2;
        });
        createInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !createBtn.disabled) createBtn.click();
        });
    }
    if (createBtn) {
        createBtn.addEventListener('click', function() {
            var name = createInput.value.trim();
            if (name.length < 2) return;
            createBtn.disabled = true;
            createBtn.textContent = '...';
            postJson('/inbox/'+convId+'/tags', { tag_name: name }).then(function(resp) {
                if (resp.success !== false && resp.tag_id) {
                    var newTag = { id: resp.tag_id, name: name, color: resp.color || '#6c757d' };
                    allTags.push(newTag);
                    var cur = getCurrentTags();
                    cur.push(newTag);
                    updateAll(cur);
                    createInput.value = '';
                }
            }).finally(function() { createBtn.disabled = true; createBtn.textContent = 'Criar'; });
        });
    }

    bindGridEvents(convId);
    bindRemoveEvents(convId);
}

initTagManager(CONV_ID);
function openTransferModal() { document.getElementById('transferModal').style.display = 'flex'; }
function closeTransferModal() { document.getElementById('transferModal').style.display = 'none'; }
function openStatusModal() { document.getElementById('statusModal').style.display = 'flex'; toggleCloseFields(); }
function closeStatusModal() { document.getElementById('statusModal').style.display = 'none'; }
function toggleCloseFields() {
    var val = document.getElementById('statusValue')?.value || '';
    var box = document.getElementById('closeFields');
    if (!box) return;
    box.style.display = (val === 'resolved' || val === 'closed') ? 'block' : 'none';
}
function pickStatus(val) {
    var hidden = document.getElementById('statusValue');
    if (hidden) hidden.value = val;
    document.querySelectorAll('#statusModal .option-item').forEach(function(el) {
        el.classList.toggle('is-active', el.dataset.status === val);
        var hasCheck = el.querySelector('.option-check');
        if (el.dataset.status === val && !hasCheck) {
            var ic = document.createElement('i');
            ic.className = 'fas fa-check option-check';
            el.appendChild(ic);
        } else if (el.dataset.status !== val && hasCheck) {
            hasCheck.remove();
        }
    });
    toggleCloseFields();
}
function pickReason(code, btn) {
    var hidden = document.getElementById('closeReasonValue');
    if (hidden) hidden.value = code;
    document.querySelectorAll('#reasonGrid .reason-chip').forEach(function(el) {
        el.classList.toggle('is-active', el.dataset.reason === code);
    });
    var hint = document.getElementById('closeReasonHint');
    if (hint) hint.style.display = 'none';
}
function updateCharCount(inputId, countId, max) {
    var ta = document.getElementById(inputId);
    var cc = document.getElementById(countId);
    if (!ta || !cc) return;
    var n = ta.value.length;
    cc.textContent = n + (n >= max ? ' / ' + max : '');
    cc.classList.toggle('warn', n > max * 0.8 && n < max);
    cc.classList.toggle('danger', n >= max);
}
function openPriorityModal() { document.getElementById('priorityModal').style.display = 'flex'; }
function closePriorityModal() { document.getElementById('priorityModal').style.display = 'none'; }
function openSnoozeModal() { document.getElementById('snoozeModal').style.display = 'flex'; }
function closeSnoozeModal() { document.getElementById('snoozeModal').style.display = 'none'; }
function openMergeModal() { document.getElementById('mergeModal').style.display = 'flex'; }
function closeMergeModal() { document.getElementById('mergeModal').style.display = 'none'; }
function openCannedModal() { document.getElementById('cannedModal').style.display = 'flex'; loadCanned(); }
function closeCannedModal() { document.getElementById('cannedModal').style.display = 'none'; }
function openMacroModal() { document.getElementById('macroModal').style.display = 'flex'; loadMacros(); }
function closeMacroModal() { document.getElementById('macroModal').style.display = 'none'; }
function closeEditModal() { document.getElementById('editModal').style.display = 'none'; }
function openContactEditModal() { document.getElementById('contactEditModal').style.display = 'flex'; }
function closeContactEditModal() { document.getElementById('contactEditModal').style.display = 'none'; }

/* ---------- Modais de Assunto, Prioridade e Sub-status (chips) ---------- */
function openSubjectModal() {
    var m = document.getElementById('subjectModal');
    if (!m) return;
    m.style.display = 'flex';
    setTimeout(function() {
        var input = document.getElementById('subjectSearchInput');
        if (input) { input.value = ''; input.focus(); filterSubjects(); }
    }, 50);
}
function closeSubjectModal() { var m = document.getElementById('subjectModal'); if (m) m.style.display = 'none'; }
function filterSubjects() {
    var q = (document.getElementById('subjectSearchInput')?.value || '').toLowerCase();
    var items = document.querySelectorAll('#subjectList .option-item');
    var any = false;
    items.forEach(function(el) {
        var txt = (el.textContent || '').toLowerCase();
        var match = !q || txt.indexOf(q) !== -1;
        el.style.display = match ? '' : 'none';
        if (match) any = true;
    });
    var empty = document.getElementById('subjectListEmpty');
    if (empty) empty.style.display = any ? 'none' : 'block';
}
function pickSubject(val) {
    var fd = csrfForm();
    fd.append('subject', val);
    var btn = document.querySelector('#subjectModal .option-item[data-subject="' + (val || '').replace(/"/g, '\\"') + '"]');
    if (btn) { btn.disabled = true; btn.style.opacity = '0.6'; }
    fetch(BASE + '/inbox/' + CONV_ID + '/subject', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(function(r){ return r.json(); })
        .then(function(resp) {
            if (resp && resp.ok) {
                updateHeaderChip('subject', val);
                closeSubjectModal();
                toast(val ? 'Assunto atualizado' : 'Assunto removido');
            } else {
                if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
                toast('Erro ao atualizar assunto');
            }
        }).catch(function(){ if (btn) { btn.disabled = false; btn.style.opacity = '1'; } });
}

function pickPriority(val) {
    var fd = csrfForm();
    fd.append('priority', val);
    var btn = document.querySelector('#priorityModal .option-item[data-priority="' + val + '"]');
    if (btn) { btn.disabled = true; btn.style.opacity = '0.6'; }
    fetch(BASE + '/inbox/' + CONV_ID + '/priority', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(function(r){ return r.json(); })
        .then(function(resp) {
            if (resp && resp.ok) {
                updateHeaderChip('priority', val);
                closePriorityModal();
                toast('Prioridade atualizada');
            } else {
                if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
                toast('Erro ao atualizar prioridade');
            }
        }).catch(function(){ if (btn) { btn.disabled = false; btn.style.opacity = '1'; } });
}

/* Atualiza visualmente o chip do header após salvar */
function updateHeaderChip(kind, val) {
    if (kind === 'subject') {
        var btn = document.querySelector('.header-chip-subject');
        if (!btn) return;
        var txt = btn.querySelector('.chip-text');
        if (txt) txt.textContent = val || 'Assunto';
        btn.classList.toggle('is-empty', !val);
    } else if (kind === 'priority') {
        var btn = document.querySelector('.header-chip-priority');
        if (!btn) return;
        var labels = {low:'Baixa',normal:'Normal',high:'Alta',urgent:'Urgente'};
        var colors = {urgent:'#d32f2f',high:'#f57c00',low:'#1976d2',normal:'var(--text-secondary)'};
        btn.querySelector('.chip-text').textContent = labels[val] || val;
        var icon = btn.querySelector('i.fa-flag');
        if (icon) icon.style.color = colors[val] || colors.normal;
    }
}
function editUnit(e) {
    if (e) e.stopPropagation();
    var d = document.getElementById('convUnitDisplay');
    var i = document.getElementById('convUnitInput');
    if (!d || !i) return;
    d.style.display = 'none';
    i.style.display = 'inline-block';
    i.focus();
    i.select();
}
function unitLoadStores(searchSaved) {
    if (searchSaved === undefined) searchSaved = true;
    var net = document.getElementById('convUnitNetwork');
    var st = document.getElementById('convUnitStore');
    if (!net || !st) return;
    var current = st.dataset.currentUnit || '';
    var base = (typeof BASE !== 'undefined' && BASE) ? BASE : (document.querySelector('meta[name="base-url"]')?.content || '');
    var opts = [];
    Array.prototype.forEach.call(net.options, function(o){
        if (o.value && o.value !== '__new') opts.push(o);
    });
    st.innerHTML = '';
    var loading = document.createElement('option');
    loading.textContent = 'Buscando unidades...';
    st.appendChild(loading);
    st.disabled = true;
    if (!opts.length) { st.disabled = false; return; }
    function fill(names, selectName) {
        st.innerHTML = '';
        names.forEach(function(name){
            var o = document.createElement('option');
            o.value = name; o.textContent = name;
            if (name === selectName) o.selected = true;
            st.appendChild(o);
        });
        st.disabled = false;
    }
    function fetchUnits(code) {
        return fetch(base + '/api/guild/stores?customer_id=' + encodeURIComponent(code), {headers:{'X-Requested-With':'XMLHttpRequest'}})
            .then(function(r){ return r.json(); })
            .then(function(j){
                if (!j.success || !j.stores || !j.stores.length) throw new Error(j.error || 'empty');
                return j.stores.map(function(s){ return s.name; });
            });
    }
    function fail() {
        st.disabled = false;
        toast('Falha ao buscar unidades. Tente novamente.');
    }
    function selectOpt(opt) {
        net.selectedIndex = opt.index;
        net.dataset.prev = net.value;
    }
    if (!searchSaved || !current) {
        // Troca manual ou nada salvo: usa a loja selecionada.
        var selOpt = net.options[net.selectedIndex];
        if (!selOpt || !selOpt.value || selOpt.value === '__new') selOpt = opts[0];
        selectOpt(selOpt);
        fetchUnits(selOpt.dataset.customer || '').then(function(names){
            fill(names, current);
            if (!current && names.length === 1) saveUnit(names[0], true);
        }).catch(fail);
        return;
    }
    // Restaura: procura a unidade salva em todas as lojas.
    var i = 0, firstNames = null;
    function attempt() {
        if (i >= opts.length) {
            if (firstNames) {
                net.selectedIndex = opts[0].index;
                net.dataset.prev = net.value;
                fill(firstNames, '');
            } else { fail(); }
            return;
        }
        var opt = opts[i];
        fetchUnits(opt.dataset.customer || '').then(function(names){
            if (i === 0) firstNames = names;
            if (names.indexOf(current) !== -1) {
                net.selectedIndex = opt.index;
                net.dataset.prev = net.value;
                fill(names, current);
            } else { i++; attempt(); }
        }).catch(function(){
            if (i === 0) { i++; attempt(); }
            else if (firstNames) {
                net.selectedIndex = opts[0].index;
                net.dataset.prev = net.value;
                fill(firstNames, '');
            } else { fail(); }
        });
    }
    attempt();
}
function unitNetworkChanged(sel) {
    sel = sel || document.getElementById('convUnitNetwork');
    if (!sel) return;
    if (sel.value === '__new') { openNewStoreModal(); return; }
    sel.dataset.prev = sel.value;
    var st = document.getElementById('convUnitStore');
    if (st) st.dataset.currentUnit = '';
    unitLoadStores(false);
}
var NEW_STORE = null;
var PREV_NETWORK = '';
function openNewStoreModal(){
    var net = document.getElementById('convUnitNetwork');
    if (net) PREV_NETWORK = net.dataset.prev || net.value;
    var m = document.getElementById('newStoreModal');
    if (m) { m.style.display = 'flex'; }
    var box = document.getElementById('newStoreResult');
    if (box) box.innerHTML = '';
    var btn = document.getElementById('newStoreBindBtn');
    if (btn) btn.disabled = true;
    NEW_STORE = null;
    var c = document.getElementById('newStoreCode');
    if (c) { c.value = ''; setTimeout(function(){ c.focus(); }, 50); }
}
function closeNewStoreModal(restore){
    var m = document.getElementById('newStoreModal');
    if (m) { m.style.display = 'none'; }
    if (restore !== false) {
        var net = document.getElementById('convUnitNetwork');
        if (net && PREV_NETWORK) net.value = PREV_NETWORK;
    }
}
function newStoreBuscar(){
    var codeEl = document.getElementById('newStoreCode');
    var box = document.getElementById('newStoreResult');
    var btn = document.getElementById('newStoreBindBtn');
    var code = (codeEl.value || '').trim();
    if (!code || !box) return;
    box.textContent = '';
    if (btn) btn.disabled = true;
    NEW_STORE = null;
    var base = (typeof BASE !== 'undefined' && BASE) ? BASE : (document.querySelector('meta[name="base-url"]')?.content || '');
    fetch(base + '/api/guild/stores?customer_id=' + encodeURIComponent(code), {headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(function(r){ return r.json(); })
        .then(function(j){
            if (!j.success) throw new Error(j.error || 'Falha ao consultar o painel Guild.');
            NEW_STORE = {customer_id: code, network_name: j.network_name, units: j.stores.map(function(s){ return s.name; })};
            var title = document.createElement('div');
            title.style.fontWeight = '700';
            title.textContent = j.network_name + ' (' + code + ')';
            var sub = document.createElement('div');
            sub.className = 'text-muted';
            sub.style.fontSize = '12.5px';
            sub.textContent = j.stores.length + ' unidades: ' + NEW_STORE.units.join(', ');
            box.appendChild(title);
            box.appendChild(sub);
            if (btn) btn.disabled = false;
        })
        .catch(function(e){
            var p = document.createElement('p');
            p.style.color = 'var(--danger)';
            p.style.fontSize = '12.5px';
            p.textContent = (e && e.message) || 'Falha ao consultar o painel Guild. Tente novamente.';
            box.appendChild(p);
        });
}
function newStoreVincular(){
    if (!NEW_STORE || !CONTACT_ID) return;
    var btn = document.getElementById('newStoreBindBtn');
    if (btn) btn.disabled = true;
    var net = document.getElementById('convUnitNetwork');
    var stores = [], nets = [];
    if (net) {
        Array.prototype.forEach.call(net.options, function(o){
            if (o.value && o.value !== '__new') {
                nets.push(o.value);
                stores.push({customer_id: o.dataset.customer, network_name: o.value});
            }
        });
    }
    nets.push(NEW_STORE.network_name);
    stores.push({customer_id: NEW_STORE.customer_id, network_name: NEW_STORE.network_name});
    var base = (typeof BASE !== 'undefined' && BASE) ? BASE : (document.querySelector('meta[name="base-url"]')?.content || '');
    var fd = csrfForm();
    fd.append('guild_stores_json', JSON.stringify(stores));
    fd.append('guild_networks_json', JSON.stringify(nets));
    fetch(base + '/contacts/' + CONTACT_ID + '/stores', {method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}, body: fd})
        .then(function(r){ return r.json(); })
        .then(function(j){
            if (!j.success) throw new Error(j.error || 'Falha ao vincular.');
            if (net) {
                var o = document.createElement('option');
                o.value = NEW_STORE.network_name;
                o.dataset.customer = NEW_STORE.customer_id;
                o.textContent = NEW_STORE.customer_id + ' - ' + NEW_STORE.network_name;
                net.insertBefore(o, net.querySelector('option[value="__new"]'));
                net.value = NEW_STORE.network_name;
                net.dataset.prev = NEW_STORE.network_name;
            }
            var st = document.getElementById('convUnitStore');
            if (st) st.dataset.currentUnit = '';
            closeNewStoreModal(false);
            unitLoadStores();
            toast('Loja vinculada com sucesso.');
        })
        .catch(function(e){
            toast((e && e.message) || 'Falha ao vincular a loja.');
        })
        .finally(function(){ if (btn) btn.disabled = false; });
}
function saveUnit(val, quiet) {
    var d = document.getElementById('convUnitDisplay');
    if (d) {
        d.textContent = '';
        var label = document.createElement('span');
        if (val) {
            label.textContent = '| ' + val;
        } else {
            label.style.opacity = '.5';
            label.style.fontStyle = 'italic';
            label.textContent = '+ unidade';
        }
        d.appendChild(label);
        var pen = document.createElement('i');
        pen.className = 'fas fa-pen';
        pen.setAttribute('style', 'font-size:9px;opacity:.4;margin-left:2px');
        d.appendChild(document.createTextNode(' '));
        d.appendChild(pen);
        var i = document.getElementById('convUnitInput');
        if (i) i.style.display = 'none';
        d.style.display = 'inline-block';
    }
    var cv = document.getElementById('convView');
    var id = cv ? cv.dataset.conv : 0;
    if (!id) { toast('Falha ao salvar a unidade: conversa não identificada.'); return; }
    var fd = csrfForm();
    fd.append('unit', val);
    fetch(BASE + '/inbox/' + id + '/unit', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd }).then(function(r){
        if (!r.ok) throw new Error('http ' + r.status);
        return r.json();
    }).then(function(j){
        if (j && (j.ok === true || j.success === true)) { if (!quiet) toast('Unidade salva com sucesso.'); }
        else throw new Error('nok');
    }).catch(function(){ toast('Falha ao salvar a unidade. Tente novamente.'); });
}
function cancelUnitEdit() {
    var d = document.getElementById('convUnitDisplay');
    var i = document.getElementById('convUnitInput');
    if (!d || !i) return;
    i.style.display = 'none';
    d.style.display = 'inline-block';
}
if (document.getElementById('convUnitNetwork')) unitLoadStores();
function submitContactEdit(e) {
    e.preventDefault();
    var form = document.getElementById('contactEditForm');
    var btn = document.getElementById('contactEditBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
    var fd = new FormData(form);
    fetch(form.action, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    }).then(function(r) { return r.json(); }).then(function(resp) {
        if (resp && resp.success !== false) {
            closeContactEditModal();
            toast('Contato atualizado com sucesso');
            setTimeout(function() { location.reload(); }, 800);
        } else {
            alert(resp.error || 'Erro ao atualizar contato');
        }
    }).catch(function() {
        alert('Erro de conexão. Tente novamente.');
    }).finally(function() {
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Salvar';
    });
    return false;
}
document.querySelectorAll('.modal-overlay').forEach(function(m) {
    m.addEventListener('click', function(e) { if (e.target === this) this.style.display = 'none'; });
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay').forEach(function(m) { m.style.display = 'none'; });
        toggleClientDrawer(false);
    }
});

/* ---------- Transfer / Canned / Macros ---------- */
function loadTransferUsers() {
    var dept = document.getElementById('transferDeptSelect').value;
    var sel = document.getElementById('transferUserSelect');
    if (!dept) { sel.innerHTML = '<option value="">Fila do departamento</option>'; return; }
    fetch(API + '/departments/' + dept + '/users').then(function(r) { return r.json(); }).then(function(users) {
        var html = '<option value="">Fila do departamento</option>';
        (users || []).forEach(function(u) { html += '<option value="' + u.id + '">' + (u.name || '').replace(/</g, '') + '</option>'; });
        sel.innerHTML = html;
    }).catch(function() { sel.innerHTML = '<option value="">Fila do departamento</option>'; });
}

var cannedResponses = [];
function loadCanned() {
    fetch(API + '/canned-responses?department_id=' + DEPT_ID).then(function(r) { return r.json(); }).then(function(list) {
        cannedResponses = list || []; renderCanned('');
    }).catch(function() { cannedResponses = []; renderCanned(''); });
}
function renderCanned(q) {
    var box = document.getElementById('cannedList'); q = (q || '').toLowerCase(); var html = '';
    cannedResponses.forEach(function(c) {
        if (q && (c.title + ' ' + c.content).toLowerCase().indexOf(q) === -1) return;
        html += '<div class="canned-item" onclick="insertCanned(' + c.id + ')"><div class="canned-title">' + (c.title || '').replace(/</g, '') + '</div><div class="canned-content">' + (c.content || '').replace(/</g, '').substring(0, 120) + '</div></div>';
    });
    if (!html) html = '<p class="text-muted">Nenhuma resposta encontrada.</p>';
    box.innerHTML = html;
}
function filterCanned() { renderCanned(document.getElementById('cannedSearch').value); }
function insertCanned(id) {
    var c = cannedResponses.find(function(x) { return x.id == id; }); if (!c) return;
    var ta = document.getElementById('messageInput');
    if (ta) { ta.value = (ta.value ? ta.value + '\n\n' : '') + c.content; ta.focus(); }
    closeCannedModal();
}

var wikiSuggest = [];
var wikiSuggestTimer = null;
function openWikiModal() { document.getElementById('wikiModal').style.display = 'flex'; document.getElementById('wikiSearch').value = ''; loadWikiSuggest(''); setTimeout(function() { document.getElementById('wikiSearch').focus(); }, 50); }
function closeWikiModal() { document.getElementById('wikiModal').style.display = 'none'; }
function filterWikiSuggest() {
    clearTimeout(wikiSuggestTimer);
    wikiSuggestTimer = setTimeout(function() { loadWikiSuggest(document.getElementById('wikiSearch').value); }, 250);
}
function loadWikiSuggest(q) {
    var box = document.getElementById('wikiList');
    box.innerHTML = '<p class="text-muted">Buscando...</p>';
    fetch(API + '/wiki/suggest?q=' + encodeURIComponent(q || '')).then(function(r) { return r.json(); }).then(function(list) {
        wikiSuggest = list || []; renderWikiSuggest();
    }).catch(function() { wikiSuggest = []; box.innerHTML = '<p class="text-muted">Falha ao buscar artigos.</p>'; });
}
function renderWikiSuggest() {
    var box = document.getElementById('wikiList'); var html = '';
    wikiSuggest.forEach(function(a, i) {
        html += '<div class="canned-item" onclick="insertWikiArticle(' + i + ')"><div class="canned-title"><i class="fas fa-book-open"></i> ' + (a.title || '').replace(/</g, '') + '</div><div class="canned-content">' + ((a.description || a.url || '').replace(/</g, '')).substring(0, 120) + '</div></div>';
    });
    if (!html) html = '<p class="text-muted">Nenhum artigo encontrado.</p>';
    box.innerHTML = html;
}
function insertWikiArticle(i) {
    var a = wikiSuggest[i]; if (!a) return;
    var ta = document.getElementById('messageInput');
    if (ta) { ta.value = (ta.value ? ta.value + '\n\n' : '') + a.title + '\n' + a.url; ta.focus(); }
    closeWikiModal();
}

var macros = [];
function loadMacros() {
    fetch(API + '/macros?department_id=' + DEPT_ID).then(function(r) { return r.json(); }).then(function(list) {
        macros = list || []; renderMacros('');
    }).catch(function() { macros = []; renderMacros(''); });
}
function macroPreview(m) {
    if (m.items_summary) return m.items_summary;
    var items = m.items || [];
    if (!items.length) return (m.content || '').substring(0, 120);
    var icons = { text: '💬', image: '🖼️', video: '🎬', audio: '🎙️', file: '📎' };
    return items.map(function(it) {
        var t = it.type || 'text';
        var label = icons[t] || '💬';
        if (t === 'text') return label + ' ' + (it.content || '').substring(0, 60);
        return label + ' ' + ((it.content || '') ? (it.content || '').substring(0, 30) + ' + ' : '') + (it.media_name || t);
    }).join(' • ').substring(0, 160);
}
function renderMacros(q) {
    var box = document.getElementById('macroList'); q = (q || '').toLowerCase(); var html = '';
    macros.forEach(function(m) {
        var hay = (m.title + ' ' + (m.content || '') + ' ' + macroPreview(m)).toLowerCase();
        if (q && hay.indexOf(q) === -1) return;
        html += '<div class="canned-item" onclick="applyMacro(' + m.id + ')"><div class="canned-title"><i class="fas fa-bolt"></i> ' + (m.title || '').replace(/</g, '') + '</div><div class="canned-content">' + macroPreview(m).replace(/</g, '') + '</div></div>';
    });
    if (!html) html = '<p class="text-muted">Nenhuma macro encontrada.</p>';
    box.innerHTML = html;
}
function filterMacros() { renderMacros(document.getElementById('macroSearch').value); }
function applyMacro(id) {
    var m = null;
    for (var i = 0; i < macros.length; i++) { if (macros[i].id == id) { m = macros[i]; break; } }
    var label = m ? macroPreview(m) : '';
    var msg = 'Aplicar macro' + (m ? ' "' + (m.title || '') + '"' : '') + (label ? '\n\n' + label : '') + '?';
    if (!confirm(msg)) return;
    postJson('/inbox/' + CONV_ID + '/macro', { macro_id: id }).then(function(r) {
        if (r && r.success === false) { alert('Falha ao aplicar macro.'); return; }
        location.reload();
    }).catch(function() { location.reload(); });
}

/* ---------- Composer ---------- */
var internalOn = false;
function toggleInternal() {
    internalOn = !internalOn;
    var btn = document.getElementById('internalToggle');
    var ta = document.getElementById('messageInput');
    var typeInput = document.getElementById('msgType');
    var sendBtn = document.getElementById('sendBtn');
    if (internalOn) {
        btn.classList.add('active'); ta.placeholder = 'Mensagem interna (visível apenas à equipe)...'; typeInput.value = 'internal';
        if (sendBtn) { sendBtn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>'; sendBtn.title = 'Enviar nota interna'; }
    } else {
        btn.classList.remove('active'); ta.placeholder = 'Digite sua mensagem... (Enter para enviar, \'/\' resposta, \':\' macro)'; typeInput.value = 'text';
        if (sendBtn) { sendBtn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>'; sendBtn.title = 'Enviar'; }
    }
}
function toggleSignature(id) {
    var btn = document.getElementById('signatureToggle');
    var next = btn.classList.contains('active') ? 0 : 1;
    btn.classList.toggle('active', next === 1);
    postJson('/inbox/' + id + '/settings', { field: 'signature_enabled', value: next });
}
var EMOJIS = ['😀','😁','😂','🤣','😊','😍','😎','🤔','🙄','😅','👍','👎','👏','🙏','💪','🔥','✅','❌','⚠️','💡','📌','📎','☎️','✉️','⏰','💬'];
(function() {
    var pop = document.getElementById('emojiPopover');
    pop.innerHTML = EMOJIS.map(function(e) { return '<span class="emoji-opt" onclick="insertEmoji(\'' + e + '\')">' + e + '</span>'; }).join('');
    document.getElementById('emojiToggle').addEventListener('click', function(e) {
        e.stopPropagation(); pop.style.display = pop.style.display === 'none' ? 'grid' : 'none';
    });
    document.addEventListener('click', function() { pop.style.display = 'none'; });
})();
function insertEmoji(e) { var ta = document.getElementById('messageInput'); ta.value += e; ta.focus(); }

function scrollConvBottom() { var c = document.getElementById('convMessages'); c.scrollTop = c.scrollHeight; document.getElementById('convNewPill').style.display = 'none'; }

function loadOlder() {
    if (!FIRST_MID) return;
    fetch(API + '/conversations/' + CONV_ID + '/messages?before=' + FIRST_MID)
        .then(function(r) { return r.json(); })
        .then(function(msgs) {
            msgs = msgs || [];
            if (!msgs.length) { document.getElementById('loadOlderBtn').style.display = 'none'; return; }
            var firstMsg = msgContainer.querySelector('.message');
            msgs.forEach(function(m) {
                var wrap = document.createElement('div'); wrap.innerHTML = renderMessageHtml(m, uploadsBase);
                msgContainer.insertBefore(wrap.firstChild, firstMsg);
            });
            FIRST_MID = parseInt(msgs[0].id, 10);
            if (msgs.length < 50) document.getElementById('loadOlderBtn').style.display = 'none';
            decorateDates();
        }).catch(function() {});
}

/* ---------- Mensagens: copiar / citar / editar / excluir ---------- */
function msgText(id) { var el = document.querySelector('.message[data-mid="' + id + '"]'); return el ? (el.getAttribute('data-text') || '') : ''; }
function copyMessage(id) {
    var t = msgText(id); if (navigator.clipboard) navigator.clipboard.writeText(t);
    toast('Mensagem copiada');
}
var quoteTarget = null;
function quoteMessage(id) {
    var el = document.querySelector('.message[data-mid="' + id + '"]');
    var text = el ? (el.getAttribute('data-text') || '') : '';
    quoteTarget = id;
    var bar = document.getElementById('quoteBar');
    var preview = document.getElementById('quotePreview');
    if (bar) bar.style.display = 'flex';
    if (preview) preview.textContent = text.substring(0, 80);
    document.getElementById('messageInput').focus();
}
function clearQuote() {
    quoteTarget = null;
    var bar = document.getElementById('quoteBar');
    if (bar) bar.style.display = 'none';
}
var editId = null;
function editMessage(id) { editId = id; document.getElementById('editContent').value = msgText(id); document.getElementById('editModal').style.display = 'flex'; }
function saveEdit() {
    var content = document.getElementById('editContent').value.trim(); if (!content) return;
    postJson('/inbox/' + CONV_ID + '/messages/' + editId + '/edit', { content: content }).then(function(r) {
        if (r.success) {
            var el = document.querySelector('.message[data-mid="' + editId + '"]');
            var c = el ? el.querySelector('.message-content') : null;
            if (c) c.innerHTML = content.replace(/</g, '&lt;').replace(/\n/g, '<br>');
            if (el) el.setAttribute('data-text', content.replace(/<[^>]+>/g, ''));
            closeEditModal();
        }
    });
}
function deleteMessage(id) {
    OminiConfirm('Excluir esta mensagem?').then(function(ok) {
        if (!ok) return;
        deleteMessageConfirmed(id);
    });
}
function deleteMessageConfirmed(id) {
    postJson('/inbox/' + CONV_ID + '/messages/' + id + '/delete', {}).then(function(r) {
        if (r.success) {
            var el = document.querySelector('.message[data-mid="' + id + '"]');
            if (!el) return;
            var bd = el.querySelector('.message-body');
            if (bd) {
                bd.classList.add('is-deleted');
                if (!bd.querySelector('.message-deleted')) {
                    var lab = document.createElement('div');
                    lab.className = 'message-deleted';
                    lab.innerHTML = '<i class="fas fa-ban"></i> Mensagem excluída';
                    bd.insertBefore(lab, bd.firstChild);
                }
            }
            var act = el.querySelector('.msg-actions'); if (act) act.remove();
        }
    });
}

/* ---------- Reações ---------- */
var activeRp = null;
function toggleReactionPicker(mid, event) {
    if (event) event.stopPropagation();
    var picker = document.getElementById('rp-' + mid);
    if (!picker) return;
    if (picker.classList.contains('open')) {
        picker.classList.remove('open');
        activeRp = null;
    } else {
        if (activeRp) activeRp.classList.remove('open');
        picker.classList.add('open');
        activeRp = picker;
    }
}
document.addEventListener('click', function() {
    if (activeRp) { activeRp.classList.remove('open'); activeRp = null; }
});
function sendReaction(mid, emoji) {
    var picker = document.getElementById('rp-' + mid);
    if (picker) picker.classList.remove('open');
    activeRp = null;
    postJson('/inbox/' + CONV_ID + '/messages/' + mid + '/reaction', { reaction: emoji }).then(function(r) {
        if (r.success !== false) {
            refreshMessagePills(mid, r.reactions);
            toast('Reação enviada');
        }
        else toast('Erro ao enviar reação');
    });
}
// Re-renderiza as pílulas de reação de um balão sem reload.
function refreshMessagePills(mid, reactionsJson) {
    var el = document.querySelector('.message[data-mid="' + mid + '"]');
    if (!el) return;
    var body = el.querySelector('.message-body');
    if (!body) return;
    var old = body.querySelector('.reactions-row');
    if (old) old.remove();
    var html = (typeof renderReactions === 'function') ? renderReactions(reactionsJson) : '';
    if (!html) return;
    var tmp = document.createElement('div');
    tmp.innerHTML = html;
    var node = tmp.firstChild;
    var anchor = body.querySelector('.msg-actions') || body.querySelector('.message-time');
    if (node) body.insertBefore(node, anchor ? anchor.nextSibling : null);
}
function scrollToMessage(mid) {
    var el = document.querySelector('.message[data-mid="' + mid + '"]');
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

/* ---------- Busca dentro da conversa ---------- */
function toggleMsgSearch() {
    var box = document.getElementById('convMsgSearch');
    box.style.display = box.style.display === 'none' ? 'flex' : 'none';
    if (box.style.display === 'flex') document.getElementById('msgSearchInput').focus();
    else filterMessages();
}
function filterMessages() {
    var q = document.getElementById('msgSearchInput').value.toLowerCase();
    var total = 0, shown = 0;
    document.querySelectorAll('#convMessages .message').forEach(function(el) {
        total++;
        var match = !q || (el.getAttribute('data-text') || '').toLowerCase().indexOf(q) !== -1;
        el.style.display = match ? '' : 'none'; if (match) shown++;
    });
    document.getElementById('msgSearchCount').textContent = q ? (shown + '/' + total) : '';
}

/* ---------- Polling: mensagens + meta + notificação ---------- */
var msgContainer = document.getElementById('convMessagesList');
function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
function avatarSrc(p) { return (typeof p === 'string' && p.indexOf('http') === 0) ? p : (uploadsBase + '/' + p); }
function nl2br(s) { return esc(s).replace(/\n/g, '<br>'); }
function fmtBytes(b) { var u = ['B','KB','MB','GB'], i = 0; b = b || 0; while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; } return Math.round(b * 10) / 10 + ' ' + u[i]; }
function fmtDt(s) { if (!s) return ''; var d = new Date(String(s).replace(' ', 'T')); if (isNaN(d)) return s; var p = function(n) { return (n < 10 ? '0' : '') + n; }; return p(d.getDate()) + '/' + p(d.getMonth() + 1) + '/' + p(d.getFullYear()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()); }
function fileMeta(content) {
    if (!content) return { url: '' };
    try {
        var m = JSON.parse(content);
        if (m && typeof m === 'object' && m.url) return m;
    } catch (e) {}
    return { url: content, name: (content || '').split('/').pop() || 'arquivo' };
}

function mediaTypeOf(m) {
    var mt = m.type;
    if (['image','audio','video','file','sticker'].indexOf(mt) >= 0) return mt;
    try {
        var c = JSON.parse(m.content);
        if (c && c.url) {
            if (c.title || c.prompt) return null;
            var mime = c.mime || '';
            if (mime.indexOf('image/') === 0) return (mime === 'image/webp') ? 'sticker' : 'image';
            if (mime.indexOf('audio/') === 0) return 'audio';
            if (mime.indexOf('video/') === 0) return 'video';
            if (mime.indexOf('application/') === 0 || mime.indexOf('text/') === 0) return 'file';
            var ext = (c.url.split('?')[0].split('.').pop() || '').toLowerCase();
            if (['jpg','jpeg','png','gif','webp','bmp','heic','heif'].indexOf(ext) >= 0) return 'image';
            if (['mp3','ogg','m4a','aac','wav','amr'].indexOf(ext) >= 0) return 'audio';
            if (['mp4','mov','avi','mkv','webm','3gp'].indexOf(ext) >= 0) return 'video';
            return 'file';
        }
    } catch (e) {}
    return null;
}
function linkify(text) {
    return text.replace(/@?(https?:\/\/[^\s<]+)/g, function (m) {
        return '<a href="' + m + '" target="_blank" rel="noopener">' + m + '</a>';
    });
}
function renderReactions(reactionsJson) {
    if (!reactionsJson) return '';
    var rxs;
    try { rxs = JSON.parse(reactionsJson); } catch (e) { return ''; }
    if (!rxs || !rxs.length) return '';
    var groups = {};
    rxs.forEach(function(r) {
        if (!r.emoji) return;
        if (!groups[r.emoji]) groups[r.emoji] = {emoji: r.emoji, count: 0, senders: []};
        groups[r.emoji].count++;
        groups[r.emoji].senders.push(r.sender_name || r.from || '');
    });
    var html = '<div class="reactions-row">';
    for (var emo in groups) {
        var g = groups[emo];
        html += '<span class="reaction-pill" title="' + esc(g.senders.join(', ')) + '">' + esc(emo) + (g.count > 1 ? '<span class="count">' + g.count + '</span>' : '') + '</span>';
    }
    html += '</div>';
    return html;
}
function isAbsoluteUrl(u) { return /^https?:\/\//i.test(u); }
function captionHtml(content) {
    var m = fileMeta(content);
    var cap = (m && m.caption) ? String(m.caption) : '';
    if (!cap) return '';
    return '<div class="message-caption">' + linkify(nl2br(cap)) + '</div>';
}
function fileContentHtml(type, content, uploadsBase) {
    var m = fileMeta(content); var url = isAbsoluteUrl(m.url) ? m.url : (uploadsBase + '/' + m.url);
    var cap = captionHtml(content);
    if (type === 'image' || type === 'sticker') return '<a href="' + esc(url) + '" target="_blank" rel="noopener" class="msg-lightbox"><img class="msg-img" src="' + esc(url) + '" alt="' + esc(m.name || 'imagem') + '" loading="lazy"></a>' + cap;
    if (type === 'audio') return '<audio controls preload="metadata" src="' + esc(url) + '"></audio>' + cap;
    if (type === 'video') return '<a href="' + esc(url) + '" target="_blank" rel="noopener" class="msg-lightbox"><video controls preload="metadata" src="' + esc(url) + '" class="msg-video" data-alt="' + esc(m.name || 'vídeo') + '"></video></a>' + cap;
    var name = m.name || 'arquivo'; var size = m.size ? ' (' + fmtBytes(m.size) + ')' : '';
    return '<a class="msg-file" href="' + esc(url) + '" target="_blank" rel="noopener" download><i class="fas fa-file-download"></i><span class="msg-file-name">' + esc(name) + '</span><span class="msg-file-size">' + esc(size) + '</span></a>' + cap;
}
function renderReceiptsHtml(m) {
    if (m.direction !== 'outbound') return '';
    if (m.delivery_status === 'failed' || m.delivered === false) {
        return '<span class="msg-receipts failed" title="Não entregue ao WhatsApp"><i class="fas fa-exclamation-circle"></i></span>' +
            '<button type="button" class="msg-retry" title="Tentar de novo" onclick="retrySend(' + m.id + ', this)"><i class="fas fa-redo"></i> Tentar de novo</button>';
    }
    var isRead = !!m.read_at;
    var icon = isRead ? 'fa-check-double' : 'fa-check';
    var cls = isRead ? 'msg-receipts read' : 'msg-receipts delivered';
    var title = isRead ? 'Lida' : 'Entregue';
    return '<span class="' + cls + '" title="' + title + '"><i class="fas ' + icon + '"></i></span>';
}
function retrySend(mid, btn) {
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando…'; }
    postJson('/inbox/' + CONV_ID + '/messages/' + mid + '/retry', {})
        .then(function (resp) {
            if (resp && resp.success && resp.message) {
                var node = document.querySelector('.message[data-mid="' + mid + '"]');
                if (node) {
                    var tmp = document.createElement('div');
                    tmp.innerHTML = renderMessageHtml(resp.message, uploadsBase);
                    node.replaceWith(tmp.firstChild);
                    decorateDates();
                }
                toast('Mensagem entregue ✓');
            } else {
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-redo"></i> Tentar de novo'; }
                toast(resp && resp.error ? resp.error : 'Ainda sem entregar. Tente de novo.');
            }
        })
        .catch(function () {
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-redo"></i> Tentar de novo'; }
            toast('Erro de rede ao reenviar.');
        });
}
function renderMessageBody(m) {
    var body = '';
    var mtype = mediaTypeOf(m);
    if (m.reply_to_data) {
        var qname = m.reply_to_data.direction === 'outbound' ? 'Você' : (CONTACT_NAME || 'Contato');
        var qtext = (m.reply_to_data.content || '').replace(/<[^>]+>/g, '').substring(0, 200);
        body += '<div class="msg-quote" onclick="scrollToMessage(' + m.reply_to_data.id + ')">' +
            '<div class="msg-quote-content"><div class="msg-quote-name">' + esc(qname) + '</div>' +
            '<div class="msg-quote-text">' + esc(qtext) + '</div></div></div>';
    }
    if (m.type === 'internal_note') {
        body += '<div class="message-note-header"><i class="fas fa-lock"></i> Nota interna · ' + esc(m.user_name || 'Equipe') + '</div>';
        body += '<div class="message-content">' + nl2br(m.content) + '</div>';
    } else if (m.type === 'csat_request') {
        var cdat = {}; try { cdat = JSON.parse(m.content); } catch (e) {}
        var prompt = cdat.prompt || 'Solicitação de avaliação enviada ao cliente.';
        var link = cdat.url ? ' · <a href="' + esc(cdat.url) + '" target="_blank" rel="noopener">Abrir avaliação</a>' : '';
        body += '<div class="message-content csat-request-note"><i class="fas fa-smile"></i> ' + esc(prompt) + link + '</div>';
    } else if (m.type === 'reaction') {
        try {
            var rd = JSON.parse(m.content);
            body += '<div class="message-content"><span class="reaction-emoji">' + esc(rd.reaction || '') + '</span> <span class="reaction-label">reagiu a uma mensagem</span></div>';
        } catch (e) {}
    } else if (m.type === 'system') {
        body += '<div class="message-content">' + nl2br(m.content) + '</div>';
    } else if (mtype) {
        body += '<div class="message-content">' + fileContentHtml(mtype, m.content, uploadsBase) + '</div>';
    } else {
        // Texto puro: detecta JSON, linkifica e formata
        var raw = m.content || '';
        try { var parsed = JSON.parse(raw); if (parsed && typeof parsed.text === 'string') raw = parsed.text; } catch (e) {}
        body += '<div class="message-content">' + linkify(nl2br(raw)) + '</div>';
    }
    return body;
}
function renderMessageHtml(m, uploadsBase) {
    var cls = 'message ' + (m.direction === 'outbound' ? 'message-out' : 'message-in');
    if (m.type === 'internal_note') cls += ' message-note';
    if (m.type === 'system') cls += ' message-system';
    if (m.type === 'sticker') cls += ' message-sticker';
    var avHtml;
    if (m.direction === 'outbound' && m.user_avatar) {
        avHtml = '<img src="' + esc(avatarSrc(m.user_avatar)) + '" alt="">';
    } else if (m.direction === 'inbound' && CONTACT_AVATAR) {
        avHtml = '<img src="' + esc(avatarSrc(CONTACT_AVATAR)) + '" alt="">';
    } else {
        avHtml = esc(m.direction === 'outbound' ? (m.user_name ? m.user_name.charAt(0).toUpperCase() : 'A') : CONTACT_INITIAL);
    }
    var deleted = !!(m.deleted_at);
    var body = deleted
        ? '<div class="message-deleted"><i class="fas fa-ban"></i> Mensagem excluída</div>'
        : renderMessageBody(m);
    var time = '<span>' + esc(fmtDt(m.created_at)) + '</span>';
    if (m.user_name && m.direction === 'outbound') time += ' <span style="opacity:.7">· ' + esc(m.user_name) + '</span>';
    if (m.updated_at && m.updated_at !== m.created_at) time += ' <span class="msg-edited" title="Editada">editada</span>';
    time += renderReceiptsHtml(m);

    var actions = '';
    var reactionPicker = '';
    if (!deleted) {
        actions = '<button type="button" class="msg-act" title="Copiar" onclick="copyMessage(' + m.id + ')"><i class="fas fa-copy"></i></button>' +
                  '<button type="button" class="msg-act" title="Citar" onclick="quoteMessage(' + m.id + ')"><i class="fas fa-quote-right"></i></button>' +
                  '<button type="button" class="msg-act" title="Reagir" onclick="toggleReactionPicker(' + m.id + ', event)"><i class="far fa-smile"></i></button>';
        if (m.user_id && m.user_id == <?= $currentUserId ?> && ['text','internal_note'].indexOf(m.type) >= 0) {
            actions += '<button type="button" class="msg-act" title="Editar" onclick="editMessage(' + m.id + ')"><i class="fas fa-edit"></i></button>' +
                       '<button type="button" class="msg-act msg-act-danger" title="Excluir" onclick="deleteMessage(' + m.id + ')"><i class="fas fa-trash"></i></button>';
        }
        reactionPicker = '<div class="reaction-picker" id="rp-' + m.id + '" data-mid="' + m.id + '">' +
            ['👍','❤️','😂','😮','😢','🙏','🔥'].map(function(e){return '<span class="rp-emoji" onclick="sendReaction(' + m.id + ', \'' + e + '\')">' + e + '</span>';}).join('') + '</div>';
    }
    var reactionsHtml = renderReactions(m.reactions);
    var text = (m.content || '').replace(/<[^>]+>/g, '').replace(/[\u0000-\u001f]/g, ' ').substring(0, 1000);
    return '<div class="' + cls + '" data-mid="' + m.id + '" data-text="' + esc(text) + '">' +
        '<div class="msg-avatar msg-avatar-' + (m.direction === 'outbound' ? 'agent' : 'contact') + '">' + avHtml + '</div>' +
        '<div class="message-body' + (deleted ? ' is-deleted' : '') + '">' + body +
        '<div class="message-time">' + time + '</div>' +
        '<div class="msg-actions">' + actions + '</div>' +
        reactionPicker +
        reactionsHtml +
        '</div></div>';
}
function appendMessage(m, uploadsBase) {
    var wrap = document.createElement('div'); wrap.innerHTML = renderMessageHtml(m, uploadsBase);
    msgContainer.appendChild(wrap.firstChild);
    var mid = parseInt(m.id, 10);
    if (!isNaN(mid)) lastMid = Math.max(lastMid, mid);
}
function nearBottom() { return msgContainer.scrollHeight - msgContainer.scrollTop - msgContainer.clientHeight < 80; }

function dayKey(s) { var d = new Date(String(s).replace(' ', 'T')); if (isNaN(d)) return ''; var p = function(n){return (n<10?'0':'')+n;}; return d.getFullYear()+'-'+(p(d.getMonth()+1))+'-'+p(d.getDate()); }
function decorateDates() {
    document.querySelectorAll('#convMessages .date-sep').forEach(function(el) { el.remove(); });
    var cur = '';
    msgContainer.querySelectorAll('.message[data-mid]').forEach(function(el) {
        var t = el.querySelector('.message-time')?.textContent || '';
        var m = t.match(/\d{2}\/\d{2}\/\d{4}/); if (!m) return;
        var dk = dayKey(m[0].split('/').reverse().join('-'));
        if (dk && dk !== cur) {
            cur = dk;
            var sep = document.createElement('div'); sep.className = 'date-sep';
            var label = m[0]; if (dk === dayKey(new Date().toISOString())) label = 'Hoje';
            sep.innerHTML = '<span>' + label + '</span>';
            el.parentNode.insertBefore(sep, el);
        }
    });
}

var lastMid = 0;
msgContainer.querySelectorAll('.message[data-mid]').forEach(function(el) { var id = parseInt(el.getAttribute('data-mid'), 10); if (id > lastMid) lastMid = id; });

/* ---------- Lightbox de imagens e vídeos ---------- */
var lightboxItems = [];
var lightboxIndex = -1;
function openLightbox(src, caption) {
    var all = [];
    document.querySelectorAll('.msg-lightbox img').forEach(function(img) {
        all.push({ src: img.src, alt: img.alt || '', type: 'image' });
    });
    document.querySelectorAll('.msg-lightbox video').forEach(function(vid) {
        all.push({ src: vid.currentSrc || vid.src, alt: vid.getAttribute('data-alt') || '', type: 'video' });
    });
    lightboxItems = all.length ? all : [{ src: src, alt: caption || '', type: 'image' }];
    lightboxIndex = Math.max(0, lightboxItems.findIndex(function(x) { return x.src === src; }));
    renderLightbox();
    var lb = document.getElementById('msgLightbox');
    if (lb) lb.classList.add('open');
    document.body.style.overflow = 'hidden';
}
function renderLightbox() {
    if (lightboxIndex < 0 || !lightboxItems[lightboxIndex]) return;
    var item = lightboxItems[lightboxIndex];
    var content = document.getElementById('lightboxContent');
    var info = document.getElementById('lightboxInfo');
    if (content) {
        if (item.type === 'video') {
            content.innerHTML = '<video controls autoplay preload="auto" src="' + esc(item.src) + '"></video>';
        } else {
            content.innerHTML = '<img src="' + esc(item.src) + '" alt="' + esc(item.alt) + '">';
        }
    }
    if (info) info.innerHTML = '<span>' + esc(item.alt || '') + '</span><span style="opacity:.6">' + (lightboxIndex + 1) + ' / ' + lightboxItems.length + '</span>';
    var prev = document.getElementById('lightboxPrev');
    var next = document.getElementById('lightboxNext');
    if (prev) prev.style.display = lightboxItems.length > 1 ? '' : 'none';
    if (next) next.style.display = lightboxItems.length > 1 ? '' : 'none';
}
function lightboxNav(e, dir) {
    if (e) e.stopPropagation();
    if (!lightboxItems.length) return;
    lightboxIndex = (lightboxIndex + dir + lightboxItems.length) % lightboxItems.length;
    renderLightbox();
}
function closeLightbox(e, force) {
    if (e && e.target && e.target.closest('.lightbox-nav')) return;
    if (e && e.target && e.target.closest('.lightbox-close')) force = true;
    if (e && e.target && e.target.id !== 'msgLightbox' && !force) return;
    var lb = document.getElementById('msgLightbox');
    if (lb) lb.classList.remove('open');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e) {
    var lb = document.getElementById('msgLightbox');
    if (!lb || !lb.classList.contains('open')) return;
    if (e.key === 'Escape') closeLightbox({target: lb}, true);
    else if (e.key === 'ArrowLeft') lightboxNav(e, -1);
    else if (e.key === 'ArrowRight') lightboxNav(e, 1);
});
document.addEventListener('click', function(e) {
    var a = e.target.closest && e.target.closest('.msg-lightbox');
    if (a) {
        e.preventDefault();
        var img = a.querySelector('img');
        var vid = a.querySelector('video');
        if (img) openLightbox(img.src, img.alt);
        else if (vid) openLightbox(vid.currentSrc || vid.src, vid.getAttribute('data-alt') || '');
    }
});

/* ---------- Char counter no composer ---------- */
(function() {
    var ta = document.getElementById('messageInput');
    var cc = document.getElementById('charCount');
    if (!ta || !cc) return;
    var max = 4096;
    var update = function() {
        var n = ta.value.length;
        cc.textContent = n + (n >= max ? ' (limite)' : '');
        cc.classList.toggle('warn', n > max * 0.8 && n < max);
        cc.classList.toggle('danger', n >= max);
    };
    ta.addEventListener('input', update);
    update();
})();

/* ---------- Voltar para a lista (mobile) ---------- */
window.__convBack = function() {
    var listPanel = document.getElementById('listPanel');
    var panel = document.getElementById('conversationDetail');
    if (listPanel) listPanel.style.display = '';
    if (panel) panel.style.display = 'none';
};

/* ---------- Indicador de "está digitando" ---------- */
var typingTimer = null;
function showTyping(convId) {
    if (convId !== CONV_ID) return;
    var el = document.getElementById('typingIndicator');
    if (el) el.style.display = 'flex';
    clearTimeout(typingTimer);
    typingTimer = setTimeout(hideTyping, 5500);
}
function hideTyping() {
    var el = document.getElementById('typingIndicator');
    if (el) el.style.display = 'none';
}
document.addEventListener('atendeflow:typing', function(e) {
    var d = e && e.detail;
    if (d && d.conversation_id) showTyping(d.conversation_id);
});

/* ---------- Polling de typing (fallback caso SSE caia) ---------- */
var lastTypingCheck = 0;
function pollTyping() {
    var now = Date.now();
    if (now - lastTypingCheck < 4000) return;
    lastTypingCheck = now;
    fetch(API + '/conversations/' + CONV_ID, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r){ return r.json(); })
        .then(function(c){
            if (!c || !c.id) return;
            if (c.last_typing_at) {
                var t = new Date(String(c.last_typing_at).replace(' ', 'T')).getTime();
                if (!isNaN(t) && (Date.now() - t) < 6000) showTyping(c.id);
                else hideTyping();
            } else {
                hideTyping();
            }
        }).catch(function(){});
}
(function() {
    var fi = document.getElementById('attachInput'); var box = document.getElementById('composerFile');
    var nameEl = document.getElementById('composerFileName'); var x = document.getElementById('composerFileX');
    if (!fi) return;
    fi.addEventListener('change', function() { if (fi.files && fi.files[0]) { nameEl.textContent = fi.files[0].name; box.style.display = 'flex'; } else { box.style.display = 'none'; } });
    x.addEventListener('click', function() { fi.value = ''; box.style.display = 'none'; });
})();

/* ---------- Autocomplete inline de respostas prontas (/) ---------- */
if (!document.getElementById('composerSuggestStyle')) {
    var __cs = document.createElement('style');
    __cs.id = 'composerSuggestStyle';
    __cs.textContent = '.composer-suggest{position:absolute;z-index:50;background:#fff;border:1px solid #d0d7de;border-radius:8px;box-shadow:0 6px 20px rgba(0,0,0,.15);max-height:260px;overflow:auto;min-width:240px;display:none}.composer-suggest-item{padding:8px 10px;cursor:pointer;border-bottom:1px solid #f0f0f0}.composer-suggest-item:last-child{border-bottom:none}.composer-suggest-item.active,.composer-suggest-item:hover{background:#f1f5fb}.composer-suggest-item .cs-title{font-weight:600;font-size:13px;color:#222}.composer-suggest-item .cs-preview{font-size:12px;color:#6c757d;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}';
    document.head.appendChild(__cs);
}
var suggestBox = null, suggestItems = [], suggestIndex = -1, suggestTokenStart = -1;
function ensureCannedData() {
    if (cannedResponses.length === 0 && typeof loadCanned === 'function') { loadCanned(); }
}
function getSlashToken() {
    var ta = document.getElementById('messageInput');
    if (!ta) return null;
    var pos = ta.selectionStart;
    var text = ta.value.slice(0, pos);
    var m = text.match(/(?:^|\s)\/([\p{L}\p{N}_]*)$/u);
    if (!m) return null;
    return { query: m[1], start: pos - m[1].length - 1 };
}
function renderSuggest(token) {
    ensureCannedData();
    var q = (token.query || '').toLowerCase();
    suggestItems = cannedResponses.filter(function(c) {
        if (!q) return true;
        return ((c.title || '') + ' ' + (c.content || '')).toLowerCase().indexOf(q) !== -1;
    }).slice(0, 8);
    suggestIndex = suggestItems.length ? 0 : -1;
    if (!suggestBox) {
        suggestBox = document.createElement('div');
        suggestBox.id = 'composerSuggest';
        suggestBox.className = 'composer-suggest';
        var composer = document.querySelector('.chat-input');
        if (composer) { composer.style.position = 'relative'; composer.appendChild(suggestBox); }
    }
    if (!suggestItems.length) { suggestBox.style.display = 'none'; return; }
    suggestBox.innerHTML = suggestItems.map(function(c, i) {
        var title = (c.title || '').replace(/</g, '');
        var preview = (c.content || '').replace(/</g, '').substring(0, 80).replace(/\n/g, ' ');
        return '<div class="composer-suggest-item" data-i="' + i + '"><div class="cs-title">' + title + '</div><div class="cs-preview">' + preview + '</div></div>';
    }).join('');
    suggestBox.querySelectorAll('.composer-suggest-item').forEach(function(el) {
        el.addEventListener('mousedown', function(e) { e.preventDefault(); acceptSuggest(parseInt(el.dataset.i, 10)); });
    });
    var ta = document.getElementById('messageInput');
    suggestBox.style.display = 'block';
    suggestBox.style.bottom = (ta.offsetHeight + 6) + 'px';
    suggestBox.style.left = '0px';
    highlightSuggest();
}
function highlightSuggest() {
    if (!suggestBox) return;
    suggestBox.querySelectorAll('.composer-suggest-item').forEach(function(el, i) {
        el.classList.toggle('active', i === suggestIndex);
    });
    var active = suggestBox.querySelector('.composer-suggest-item.active');
    if (active) active.scrollIntoView({ block: 'nearest' });
}
function acceptSuggest(i) {
    if (i < 0 || i >= suggestItems.length) return;
    var c = suggestItems[i];
    var ta = document.getElementById('messageInput');
    var pos = ta.selectionStart;
    var before = ta.value.slice(0, suggestTokenStart);
    var after = ta.value.slice(pos);
    ta.value = before + (c.content || '') + after;
    var caret = (before + (c.content || '')).length;
    ta.setSelectionRange(caret, caret);
    ta.focus();
    suggestBox.style.display = 'none';
    suggestTokenStart = -1;
}
function closeSuggest() { if (suggestBox) suggestBox.style.display = 'none'; suggestIndex = -1; }

document.getElementById('messageInput')?.addEventListener('input', function() {
    var token = getSlashToken();
    if (token) { suggestTokenStart = token.start; renderSuggest(token); }
    else { closeSuggest(); }
});
document.getElementById('messageInput')?.addEventListener('keydown', function(e) {
    if (suggestBox && suggestBox.style.display === 'block' && suggestItems.length) {
        if (e.key === 'ArrowDown') { e.preventDefault(); suggestIndex = (suggestIndex + 1) % suggestItems.length; highlightSuggest(); return; }
        if (e.key === 'ArrowUp') { e.preventDefault(); suggestIndex = (suggestIndex - 1 + suggestItems.length) % suggestItems.length; highlightSuggest(); return; }
        if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); acceptSuggest(suggestIndex); return; }
        if (e.key === 'Escape') { e.preventDefault(); closeSuggest(); return; }
    }
    if (this.selectionStart === 0 && this.value === '' && e.key === ':') { setTimeout(openMacroModal, 0); return; }
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); this.closest('form').requestSubmit(); }
});

/* ---------- Envio via AJAX (sem recarregar a página) ---------- */
(function() {
    var form = document.getElementById('composerForm');
    if (!form) return;
    function doneProgress() {
        var bar = document.getElementById('topProgress');
        if (bar) { bar.classList.remove('active'); bar.classList.add('done'); setTimeout(function() { bar.classList.remove('done'); }, 500); }
    }
    // Envio otimista: o balão aparece na hora e a API confirma em
    // segundo plano (o provedor pode levar segundos). Em falha, o balão
    // temporário sai e entra o real com "Tentar de novo".
    function tempFileKind(file) {
        var mime = file.type || '';
        if (mime.indexOf('image/') === 0) return 'image';
        if (mime.indexOf('audio/') === 0) return 'audio';
        if (mime.indexOf('video/') === 0) return 'video';
        var ext = (file.name.split('.').pop() || '').toLowerCase();
        if (['mp3', 'ogg', 'm4a', 'aac', 'wav', 'amr', 'opus'].indexOf(ext) >= 0) return 'audio';
        if (['mp4', 'webm', 'mov', '3gp'].indexOf(ext) >= 0) return 'video';
        if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'].indexOf(ext) >= 0) return 'image';
        return 'file';
    }
    function appendTempMessage(opts) {
        var wrap = document.createElement('div');
        wrap.className = 'message message-out';
        wrap.setAttribute('data-temp', '1');
        var quote = opts.replyText
            ? '<div class="msg-quote"><div class="msg-quote-content"><div class="msg-quote-name">Você</div>' +
              '<div class="msg-quote-text">' + esc(opts.replyText.substring(0, 200)) + '</div></div></div>'
            : '';
        var body = '';
        if (opts.file) {
            var kind = tempFileKind(opts.file);
            var url = URL.createObjectURL(opts.file);
            if (kind === 'image') body = '<a class="msg-lightbox"><img class="msg-img" src="' + url + '" alt=""></a>';
            else if (kind === 'audio') body = '<audio controls preload="metadata" src="' + url + '"></audio>';
            else if (kind === 'video') body = '<video controls preload="metadata" src="' + url + '" class="msg-video"></video>';
            else body = '<span class="msg-file"><i class="fas fa-file-download"></i><span class="msg-file-name">' + esc(opts.file.name) + '</span></span>';
            if (opts.text) body += '<div class="message-content">' + linkify(nl2br(opts.text)) + '</div>';
        } else {
            body = '<div class="message-content">' + linkify(nl2br(opts.text)) + '</div>';
        }
        wrap.innerHTML = '<div class="msg-avatar msg-avatar-agent">A</div>' +
            '<div class="message-body">' + quote + body +
            '<div class="message-time"><span>agora</span> ' +
            '<span class="msg-receipts" title="Enviando…"><i class="fas fa-clock"></i></span></div></div>';
        msgContainer.appendChild(wrap);
        return wrap;
    }
    function clearTempMessages() {
        document.querySelectorAll('#convMessagesList .message[data-temp]').forEach(function(n) { n.remove(); });
    }
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var ta = document.getElementById('messageInput');
        var fileInput = document.getElementById('attachInput');
        var btn = document.getElementById('sendBtn');
        // Fallback: se o input file estiver vazio mas o recorder tiver um arquivo
        // gravado (caso o DataTransfer não tenha funcionado no navegador), usa-o.
        var recordedFile = (window.AudioRecorder && window.AudioRecorder.getActiveFile)
            ? window.AudioRecorder.getActiveFile('composerForm') : null;
        var fileToSend = (fileInput && fileInput.files && fileInput.files[0]) || recordedFile || null;
        var hasFile = !!fileToSend;
        var textToSend = ta.value;
        if (!textToSend.trim() && !hasFile) return;
        if (btn) btn.disabled = true;
        var fd = new FormData(form);
        var qid = quoteTarget;
        if (qid) fd.set('reply_to', qid);
        if (recordedFile && !(fileInput && fileInput.files && fileInput.files.length)) {
            // Substitui o input file vazio pelo arquivo do recorder
            fd.delete('file');
            fd.append('file', recordedFile, recordedFile.name);
        }
        // Mostra na hora: texto e/ou arquivo como balões temporários.
        var qText = '';
        if (qid) {
            var qel = document.querySelector('.message[data-mid="' + qid + '"]');
            qText = qel ? (qel.getAttribute('data-text') || '') : '';
        }
        if (textToSend.trim()) appendTempMessage({ text: textToSend, replyText: qText });
        if (fileToSend) appendTempMessage({ file: fileToSend, text: textToSend.trim(), replyText: textToSend.trim() ? '' : qText });
        decorateDates();
        scrollConvBottom();
        ta.value = '';
        clearQuote();
        closeSuggest();
        if (internalOn) toggleInternal();
        if (fileInput) fileInput.value = '';
        var fb = document.getElementById('composerFile');
        if (fb) fb.style.display = 'none';
        if (window.AudioRecorder && window.AudioRecorder.getActive) {
            var rec = window.AudioRecorder.getActive('composerForm');
            if (rec) rec.cancel();
        }
        fetch(form.action, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        }).then(function(r) { return r.json(); }).then(function(resp) {
            clearTempMessages();
            if (resp && resp.ok && resp.messages) {
                (resp.messages || []).forEach(function(m) { appendMessage(m, uploadsBase); });
                decorateDates();
                scrollConvBottom();
                if (resp.delivery_failed) {
                    toast(resp.delivery_error || 'Mensagem salva, mas NÃO entregue ao WhatsApp. Use "Tentar de novo".');
                }
            } else if (resp && resp.error) {
                alert(resp.error);
            }
        }).catch(function() {
            clearTempMessages();
            ta.value = textToSend;
            alert('Erro ao enviar mensagem. Seu texto foi restaurado — tente novamente.');
        }).finally(function() {
            if (btn) btn.disabled = false;
            doneProgress();
        });
    });
})();

/* ---------- Gravação de áudio (MediaRecorder) ---------- */
(function() {
    var micBtn = document.getElementById('micToggle');
    var recBox = document.getElementById('composerRecord');
    if (!micBtn || !recBox || !window.AudioRecorder) return;

    var recorder = AudioRecorder.create('composerForm', {
        startBtn: micBtn,
        stopBtn: document.getElementById('recStop'),
        cancelBtn: document.getElementById('recCancel'),
        timer: document.getElementById('recTimer'),
        indicator: document.getElementById('recIndicator'),
        fileInput: document.getElementById('attachInput'),
        fileChip: document.getElementById('composerFile'),
        fileName: document.getElementById('composerFileName'),
        fileRemove: document.getElementById('composerFileX'),
        onState: function(state) {
            if (state === 'recording') {
                recBox.style.display = 'flex';
                micBtn.style.display = 'none';
            } else if (state === 'ready' || state === 'idle' || state === 'denied' || state === 'error') {
                recBox.style.display = 'none';
                micBtn.style.display = '';
                micBtn.innerHTML = '<i class="fas fa-microphone"></i>';
            }
        }
    });
    recorder.bind();
})();

/* ---------- Form de status (submit via AJAX) ---------- */
(function() {
    var form = document.getElementById('statusForm');
    if (!form) return;
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var statusVal = document.getElementById('statusValue')?.value || '';
        var reasonVal = document.getElementById('closeReasonValue')?.value || '';
        if ((statusVal === 'resolved' || statusVal === 'closed') && !reasonVal) {
            var hint = document.getElementById('closeReasonHint');
            if (hint) {
                hint.style.display = 'flex';
                hint.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            document.getElementById('reasonGrid')?.animate(
                [{ transform: 'translateX(-3px)' }, { transform: 'translateX(3px)' }, { transform: 'translateX(0)' }],
                { duration: 300, easing: 'ease-out' }
            );
            return;
        }
        var btn = document.getElementById('statusSubmitBtn');
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...'; }
        var fd = new FormData(form);
        fetch(form.action, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        }).then(function(r){ return r.json(); })
          .then(function(resp) {
            if (resp && resp.ok) {
                var statusEl = document.querySelector('.header-chip-status');
                if (statusEl) statusEl.outerHTML = statusBadgeHtml(resp.status || statusVal);
                closeStatusModal();
                toast('Status atualizado');
                // Recarrega a página para refletir o banner de fechamento
                setTimeout(function(){ location.reload(); }, 600);
            } else {
                toast('Erro ao atualizar status');
            }
        }).catch(function(){ toast('Erro de rede'); })
          .finally(function(){
            if (btn) { btn.disabled = false; btn.innerHTML = 'Alterar status'; }
          });
    });
})();

/* Inicializa contador de caracteres do closeDescription (caso esteja pré-preenchido) */
(function() {
    var ta = document.getElementById('closeDescription');
    if (ta) updateCharCount('closeDescription', 'closeCharCount', 600);
})();

ensureCannedData();

var uploadsBase = '<?= rtrim(base_url('uploads'), '/') ?>';
var notifyCount = 0, origTitle = document.title;

function playNotifySound() {
    try {
        var ctx = new (window.AudioContext || window.webkitAudioContext)();
        var o = ctx.createOscillator(), g = ctx.createGain();
        o.type = 'sine';
        o.connect(g); g.connect(ctx.destination);
        o.frequency.value = 520; g.gain.value = 0.08;
        o.start();
        o.frequency.linearRampToValueAtTime(780, ctx.currentTime + 0.12);
        g.gain.linearRampToValueAtTime(0, ctx.currentTime + 0.25);
        setTimeout(function() { o.stop(); }, 250);
    } catch (e) {}
}

function notifyInbound() {
    notifyCount++;
    playNotifySound();
    if (window.__enhancements?.SoundManager) { window.__enhancements.SoundManager.play('message'); }
    var browserOn = !!(window.__enhancements?.GlobalNotifier && window.__enhancements.GlobalNotifier._browserNotifEnabled);
    if (browserOn && 'Notification' in window && Notification.permission === 'granted') {
        new Notification('Nova mensagem de ' + '<?= e($contact['name'] ?? 'cliente') ?>');
    }
    if (document.title !== origTitle) document.title = origTitle;
    document.title = '(' + notifyCount + ') ' + origTitle;
    setTimeout(function() { document.title = origTitle; }, 5000);
    if (typeof window.utils !== 'undefined' && window.utils.toast) {
        window.utils.toast('Nova mensagem de <?= e($contact['name'] ?? 'cliente') ?>', 'info');
    }
}

var msgState = {};
function msgSig(m) {
    return (m.reactions || '') + '|' + (m.updated_at || '') + '|' + (m.deleted_at || '') + '|' +
        String(m.content || '').length + ':' + String(m.content || '').substring(0, 64);
}
function pollMessages() {
    fetch(API + '/conversations/' + CONV_ID + '/messages').then(function(r) { return r.json(); }).then(function(msgs) {
        var added = 0, inboundNew = false;
        (msgs || []).forEach(function(m) {
            var id = parseInt(m.id, 10);
            if (id > lastMid) {
                appendMessage(m, uploadsBase);
                msgState[id] = msgSig(m);
                lastMid = id; added++;
                if (m.direction === 'inbound') inboundNew = true;
                return;
            }
            // Mensagem já na tela: reações, edições e exclusões chegam sem
            // criar linha nova — atualiza o balão no lugar quando mudar.
            var sig = msgSig(m);
            if (msgState[id] === undefined) { msgState[id] = sig; return; }
            if (msgState[id] !== sig) {
                msgState[id] = sig;
                var node = document.querySelector('.message[data-mid="' + id + '"]');
                if (node) {
                    var tmp = document.createElement('div');
                    tmp.innerHTML = renderMessageHtml(m, uploadsBase);
                    if (tmp.firstChild) node.replaceWith(tmp.firstChild);
                }
            }
        });
        if (added) {
            scrollConvBottom();
            if (inboundNew) notifyInbound();
            decorateDates();
        }
    }).catch(function() {});
}
if ('Notification' in window && Notification.permission === 'default') { Notification.requestPermission(); }

function pollMeta() {
    fetch(API + '/conversations/' + CONV_ID).then(function(r) { return r.json(); }).then(function(c) {
        if (!c || !c.id) return;
        var badge = document.querySelector('.header-chip-status');
        if (badge && c.status) badge.outerHTML = statusBadgeHtml(c.status);
        var pb = document.querySelector('.header-chip-priority');
        if (pb && c.priority) pb.outerHTML = priorityBadgeHtml(c.priority);
        if (c.assigned_user_name) {
            var a = document.getElementById('convAssigned');
            if (a) a.innerHTML = '<i class="fas fa-user" style="font-size:10px"></i> ' + c.assigned_user_name;
        }
        var subj = document.querySelector('.header-chip-subject');
        if (subj) {
            var txt2 = subj.querySelector('.chip-text');
            if (txt2) txt2.textContent = c.subject || 'Assunto';
            subj.classList.toggle('is-empty', !c.subject);
        }
        var asg = document.getElementById('convAssigned');
        if (asg) asg.textContent = c.assigned_user_name || 'Sem responsável';
    }).catch(function() {});
}
function statusBadgeHtml(s) {
    var map = {new:'Novo',open:'Aberto',waiting_customer:'Em atendimento',waiting_internal:'Aguard. Interno',resolved:'Resolvido',closed:'Fechado',spam:'Spam'};
    var icon = s === 'waiting_customer' ? 'fa-headset' : s === 'new' ? 'fa-bell' : s === 'open' ? 'fa-comments' : s === 'resolved' ? 'fa-check-circle' : s === 'closed' ? 'fa-archive' : s === 'spam' ? 'fa-ban' : 'fa-clock';
    var cls = s === 'open' || s === 'new' ? 'chip-success' : s === 'waiting_customer' ? 'chip-info' : s === 'resolved' || s === 'closed' ? 'chip-neutral' : 'chip-warning';
    return '<span class="status-chip header-chip-status ' + cls + '" onclick="openStatusModal()" title="Clique para alterar status"><i class="fas ' + icon + '" style="font-size:11px"></i><span class="chip-text">' + (map[s] || s) + '</span></span>';
}
function priorityBadgeHtml(p) {
    var map = {low:'Baixa',normal:'Normal',high:'Alta',urgent:'Urgente'};
    var colors = {urgent:'#d32f2f',high:'#f57c00',low:'#1976d2',normal:'var(--text-secondary)'};
    return '<button type="button" class="header-chip header-chip-priority" onclick="openPriorityModal()" title="Clique para alterar prioridade"><i class="fas fa-flag" style="color:' + (colors[p] || colors.normal) + '"></i><span class="chip-text">' + (map[p] || p) + '</span><i class="fas fa-chevron-down" style="font-size:9px;opacity:.5;margin-left:auto"></i></button>';
}

if (window.__convPollInterval) clearInterval(window.__convPollInterval);
window.__convPollInterval = setInterval(function() { pollMessages(); pollTyping(); }, 3000);
window.__metaPollInterval = setInterval(pollMeta, 10000);
pollMessages();

/* ---------- Atalhos de teclado ---------- */
document.addEventListener('keydown', function(e) {
    if (/^(INPUT|TEXTAREA|SELECT)$/.test((e.target.tagName || ''))) return;
    if (e.metaKey || e.ctrlKey || e.altKey) return;
    var k = e.key.toLowerCase();
    if (k === 'i') { toggleInternal(); e.preventDefault(); }
    else if (k === 'a' && document.querySelector('.conv-action-form')) { document.querySelector('.conv-action-form').requestSubmit(); e.preventDefault(); }
    else if (k === 'r') { openStatusModal(); e.preventDefault(); }
    else if (k === 'c') { openCannedModal(); e.preventDefault(); }
    else if (k === 'm') { openMacroModal(); e.preventDefault(); }
    else if (k === 's') { openSnoozeModal(); e.preventDefault(); }
});

/* ---------- Toast ---------- */
function toast(msg) {
    var t = document.createElement('div'); t.className = 'toast-msg'; t.textContent = msg;
    document.body.appendChild(t); setTimeout(function() { t.remove(); }, 1800);
}

requestAnimationFrame(function() {
    scrollConvBottom();
    decorateDates();
    requestAnimationFrame(scrollConvBottom);
});
</script>

<div class="lightbox" id="msgLightbox" onclick="closeLightbox(event)">
    <button type="button" class="lightbox-close" onclick="closeLightbox(event, true)">&times;</button>
    <button type="button" class="lightbox-nav lightbox-prev" id="lightboxPrev" onclick="lightboxNav(event, -1)"><i class="fas fa-chevron-left"></i></button>
    <button type="button" class="lightbox-nav lightbox-next" id="lightboxNext" onclick="lightboxNav(event, 1)"><i class="fas fa-chevron-right"></i></button>
    <div id="lightboxContent"></div>
    <div class="lightbox-info" id="lightboxInfo"></div>
</div>
