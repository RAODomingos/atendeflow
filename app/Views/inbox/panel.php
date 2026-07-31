<?php
$initial = mb_strtoupper(mb_substr($contact['name'] ?? '?', 0, 1));
$conv = $conversation;
$isOwn = function (array $msg) {
    return !empty($msg['user_id']) && (int) $msg['user_id'] === (int) \App\Core\Auth::id();
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
        return '<img src="' . e($src) . '" class="msg-avatar-img" alt="">';
    }
    if (($msg['direction'] ?? '') === 'inbound' && !empty($contact['avatar'])) {
        $src = str_starts_with($contact['avatar'], 'http') ? $contact['avatar'] : upload_url($contact['avatar']);
        return '<img src="' . e($src) . '" class="msg-avatar-img" alt="">';
    }
    $n = ($msg['direction'] ?? '') === 'outbound' ? ($msg['user_name'] ?? 'A') : $initial;
    return e(mb_strtoupper(mb_substr($n, 0, 1)));
};
$online = !empty($contact['last_activity_at']) && (time() - strtotime($contact['last_activity_at']) < 600);
$csat = $conversation['csat'] ?? null;
?>
<div class="conversation-view" id="convView" data-conv="<?= $conv['id'] ?>">
    <div class="chat-header">
        <div class="chat-header-left">
            <button type="button" class="conv-name-btn" onclick="toggleClientDrawer()" title="Ver dados do cliente e histórico" style="display:flex;align-items:center;gap:13px;background:none;border:none;cursor:pointer;padding:0;flex:1">
                <div class="avatar avatar-sm" style="background:<?= e($contact['avatar'] ? '' : ($online ? '#2ecc71' : 'linear-gradient(135deg,#7c5cff,#a78bfa)')) ?>">
                    <?php if (!empty($contact['avatar'])): ?>
                        <img src="<?= e(str_starts_with($contact['avatar'], 'http') ? $contact['avatar'] : upload_url($contact['avatar'])) ?>" alt="">
                    <?php else: ?>
                        <?= e($initial) ?>
                    <?php endif; ?>
                </div>
                <div class="chat-title-group" style="text-align:left">
                    <div class="chat-title">
                        <?= e($contact['name'] ?? 'Contato') ?>
                        <?php if (!empty($contact['company'])): ?>
                            <span style="font-weight:400;color:var(--text-muted)">— <?= e($contact['company']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="chat-subtitle" style="display:flex;flex-wrap:wrap;align-items:center;gap:6px">
                        <span style="display:inline-flex;align-items:center;gap:4px">
                            <i class="<?= channel_icon($conv['channel_type'] ?? 'webchat') ?>" style="font-size:12px"></i>
                            <?= e($conv['channel_name'] ?? '') ?>
                        </span>
                        <?php if ($conv['department_name']): ?>
                            <span style="color:<?= e($conv['department_color'] ?? '#666') ?>;font-weight:700;font-size:12px"><?= e($conv['department_name']) ?></span>
                        <?php endif; ?>
                        <span id="convUnitDisplay" style="font-size:12px;color:var(--text-muted);cursor:pointer" title="Clique para editar unidade" onclick="editUnit(event)">
                            <?php if (!empty($conv['unit'])): ?>| <?= e($conv['unit']) ?><?php else: ?><span style="opacity:.5;font-style:italic">+ unidade</span><?php endif; ?>
                            <i class="fas fa-pen" style="font-size:9px;opacity:.4;margin-left:2px"></i>
                        </span>
                        <input type="text" id="convUnitInput" style="display:none;font-size:12px;padding:2px 8px;border:1px solid var(--brand);border-radius:6px;outline:none;width:160px" value="<?= e($conv['unit'] ?? '') ?>" placeholder="Unidade" onblur="saveUnit(this.value)" onkeydown="if(event.key==='Enter')saveUnit(this.value);if(event.key==='Escape')cancelUnitEdit()">
                    </div>
                    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:4px;margin-top:4px">
                        <?php if (!empty($conv['substatus'])): ?>
                            <span style="font-size:11px;padding:1px 8px;border-radius:10px;background:var(--bg-panel-alt);color:var(--text-muted)"><?= e($conv['substatus']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--text-muted);flex-shrink:0;margin-left:auto"><path d="M9 18l6-6-6-6"/></svg>
            </button>
        </div>
        <div class="chat-header-right">
            <span class="status-chip <?= $conv['status'] === 'open' || $conv['status'] === 'new' ? 'chip-success' : ($conv['status'] === 'waiting_customer' ? 'chip-info' : ($conv['status'] === 'resolved' || $conv['status'] === 'closed' ? 'chip-neutral' : 'chip-warning')) ?>" onclick="openStatusModal()" title="Clique para alterar status">
                <span class="status-dot" style="width:8px;height:8px;position:static;border:none;flex-shrink:0;background:<?= $conv['status'] === 'open' || $conv['status'] === 'new' ? 'var(--success)' : ($conv['status'] === 'waiting_customer' ? 'var(--info)' : ($conv['status'] === 'resolved' || $conv['status'] === 'closed' ? 'var(--text-muted)' : 'var(--warning)')) ?>"></span>
                <?php $slabels = ['new'=>'Novo','open'=>'Aberto','waiting_customer'=>'Em atendimento','waiting_internal'=>'Aguard. Interno','resolved'=>'Resolvido','closed'=>'Fechado','spam'=>'Spam']; echo $slabels[$conv['status']] ?? $conv['status']; ?>
            </span>
            <?php
                try {
                    $convSubjects = \App\Core\Database::getInstance()->fetchAll(
                        "SELECT * FROM conversation_subjects WHERE is_active = 1 ORDER BY sort_order ASC, name ASC"
                    );
                } catch (\Throwable $e) {
                    $convSubjects = [];
                }
            ?>
            <?php if (!empty($convSubjects)): ?>
                <select id="convSubjectSelect" onchange="saveSubjectSelect(this.value)" style="font-size:11px;padding:2px 6px;border:1px solid var(--border-soft);border-radius:6px;background:var(--bg-panel);color:var(--text-secondary);outline:none;cursor:pointer;max-width:150px">
                    <option value="">Assunto</option>
                    <?php foreach ($convSubjects as $s): ?>
                        <option value="<?= e($s['name']) ?>" <?= ($conv['subject'] ?? '') === $s['name'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <span style="font-size:11px;color:var(--text-muted);padding:0 4px"><?= e($conv['subject'] ?? '') ?></span>
            <?php endif; ?>
            <span class="clickable-badge" onclick="openPriorityModal()" title="Clique para alterar prioridade"><?= priority_badge($conv['priority'] ?? 'normal') ?></span>
            <?php
                try {
                    $substatuses = \App\Core\Database::getInstance()->fetchAll(
                        "SELECT * FROM conversation_substatuses WHERE is_active = 1 ORDER BY sort_order ASC, name ASC"
                    );
                } catch (\Throwable $e) {
                    $substatuses = [];
                }
            ?>
            <?php if (!empty($substatuses)): ?>
                <select id="convSubstatusSelect" onchange="saveSubstatus(this.value)" style="font-size:11px;padding:2px 6px;border:1px solid var(--border-soft);border-radius:6px;background:var(--bg-panel);color:var(--text-secondary);outline:none;cursor:pointer;max-width:140px">
                    <option value="">Sub-status</option>
                    <?php foreach ($substatuses as $ss): ?>
                        <option value="<?= e($ss['name']) ?>" <?= ($conv['substatus'] ?? '') === $ss['name'] ? 'selected' : '' ?> style="color:<?= e($ss['color'] ?? '#6c757d') ?>">
                            <?= e($ss['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <div class="icon-divider"></div>
            <button class="icon-btn" onclick="toggleMsgSearch()" title="Buscar na conversa"><i class="fas fa-search"></i></button>
            <button class="icon-btn" id="convSnoozeBtn" onclick="openSnoozeModal()" title="Agendar"><i class="fas fa-clock"></i></button>
            <button class="icon-btn" onclick="openCsatModal()" title="Avaliação"><i class="fas fa-smile"></i></button>
            <button class="icon-btn" onclick="openMergeModal()" title="Mesclar"><i class="fas fa-code-merge"></i></button>
            <button class="icon-btn" onclick="window.print()" title="Imprimir"><i class="fas fa-print"></i></button>
            <a href="<?= url('inbox/') ?><?= $conv['id'] ?>/pdf" class="icon-btn" title="Baixar PDF"><i class="fas fa-file-pdf"></i></a>
            <button class="icon-btn" onclick="openTransferModal()" title="Transferir"><i class="fas fa-exchange-alt"></i></button>
            <?php if (empty($conv['assigned_user_id'])): ?>
                <form action="<?= url('inbox/') ?><?= $conv['id'] ?>/assign" method="POST" class="conv-action-form" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="user_id" value="<?= \App\Core\Auth::id() ?>">
                    <button type="submit" class="icon-btn" title="Atribuir a mim"><i class="fas fa-hand-paper"></i></button>
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

    <div class="conv-msg-search" id="convMsgSearch" style="display:none">
        <input type="text" id="msgSearchInput" class="form-control" placeholder="Buscar mensagens..." oninput="filterMessages()">
        <span id="msgSearchCount" class="msg-search-count"></span>
    </div>

    <div class="chat-body" id="convMessages">
        <button type="button" class="conv-load-older" id="loadOlderBtn" style="display:<?= !empty($hasOlder) ? 'block' : 'none' ?>" onclick="loadOlder()">Carregar mensagens anteriores</button>
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
        ?>
            <?php if ($showDate): ?>
            <div class="date-sep"><span><?= format_date_sep($msg['created_at']) ?></span></div>
            <?php endif; ?>
            <div class="message <?= $msg['direction'] === 'outbound' ? 'message-out' : 'message-in' ?> <?= $msg['type'] === 'internal_note' ? 'message-note' : '' ?> <?= $msg['type'] === 'system' ? 'message-system' : '' ?> <?= $msg['type'] === 'sticker' ? 'message-sticker' : '' ?> <?= $grouped ? 'message-grouped' : '' ?>" data-mid="<?= $msg['id'] ?>" data-text="<?= e(strip_tags($msg['content'])) ?>">
                <?php if (!$grouped): ?>
                <div class="msg-avatar msg-avatar-<?= $sender ?>"><?= $av ?></div>
                <?php endif; ?>
                <div class="message-body <?= $isDeleted ? 'is-deleted' : '' ?>">
                    <?php if ($isDeleted): ?>
                    <div class="message-deleted"><i class="fas fa-ban"></i> Mensagem excluída</div>
                    <?php endif; ?>
                    <?php if (!empty($msg['reply_to_data'])): ?>
                    <div class="msg-quote" onclick="scrollToMessage(<?= (int) $msg['reply_to_data']['id'] ?>)">
                        <div class="msg-quote-content">
                            <div class="msg-quote-name"><?= ($msg['reply_to_data']['direction'] ?? '') === 'outbound' ? 'Você' : e($contact['name'] ?? 'Contato') ?></div>
                            <div class="msg-quote-text"><?= e(mb_substr(strip_tags(str_replace(['[',']'], '', $msg['reply_to_data']['content'] ?? '')), 0, 100)) ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($msg['type'] === 'internal_note'): ?>
                        <div class="message-note-header">
                            <i class="fas fa-lock"></i> Nota interna
                            <?php if ($msg['user_name']): ?> - <?= e($msg['user_name']) ?><?php endif; ?>
                        </div>
                        <div class="message-content"><?= e($msg['content']) ?></div>
                    <?php elseif ($msg['type'] === 'csat_request'):
                        $csatData = json_decode($msg['content'], true) ?: [];
                        $csatPrompt = $csatData['prompt'] ?? 'Solicitação de avaliação enviada ao cliente.';
                        $csatUrl = $csatData['url'] ?? '';
                    ?>
                        <div class="message-content csat-request-note">
                            <i class="fas fa-smile"></i> <?= e($csatPrompt) ?>
                            <?php if ($csatUrl): ?>
                                <a href="<?= e($csatUrl) ?>" target="_blank" rel="noopener">Avaliar</a>
                            <?php endif; ?>
                        </div>
                    <?php elseif ($msg['type'] === 'reaction'): ?>
                        <?php
                            $rData = json_decode($msg['content'], true) ?: [];
                            $rEmoji = $rData['reaction'] ?? '';
                            $rParentId = $rData['parent_message_id'] ?? '';
                        ?>
                        <div class="message-content">
                            <span class="reaction-emoji"><?= e($rEmoji) ?></span>
                            <span class="reaction-label">reagiu a uma mensagem</span>
                        </div>
                    <?php elseif ($msg['type'] === 'system'): ?>
                        <div class="message-content"><?= e($msg['content']) ?></div>
                    <?php elseif ($isFile && $meta): ?>
                        <div class="message-content">
                            <?php if ($mediaType === 'image' || $mediaType === 'sticker'): ?>
                                <a href="<?= e($meta['url']) ?>" target="_blank" rel="noopener">
                                    <img class="msg-img" src="<?= e($meta['url']) ?>" alt="<?= e($meta['name']) ?>">
                                </a>
                            <?php elseif ($mediaType === 'audio'): ?>
                                <audio controls preload="metadata" src="<?= e($meta['url']) ?>"></audio>
                            <?php elseif ($mediaType === 'video'): ?>
                                <video controls preload="metadata" src="<?= e($meta['url']) ?>"></video>
                            <?php else: ?>
                                <a class="msg-file" href="<?= e($meta['url']) ?>" target="_blank" rel="noopener" download>
                                    <i class="fas fa-file-download"></i>
                                    <span class="msg-file-name"><?= e($meta['name']) ?></span>
                                    <?php if ($meta['size']): ?><span class="msg-file-size">(<?= format_bytes($meta['size']) ?>)</span><?php endif; ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="message-content"><?= e($msg['content']) ?></div>
                    <?php endif; ?>
                    <div class="message-time">
                        <?= format_time($msg['created_at']) ?>
                        <?php if ($msg['user_name'] && $msg['direction'] === 'outbound'): ?>
                            - <?= e($msg['user_name']) ?>
                        <?php endif; ?>
                        <?php if (!empty($msg['updated_at']) && $msg['updated_at'] !== $msg['created_at']): ?>
                            <span class="msg-edited" title="Editada"> (editada)</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!$isDeleted && !empty($msg['reactions'])): ?>
                    <?php
                        $rxs = json_decode($msg['reactions'], true) ?: [];
                        $rGroups = [];
                        foreach ($rxs as $rx) {
                            $e = $rx['emoji'] ?? '';
                            if (!$e) continue;
                            if (!isset($rGroups[$e])) $rGroups[$e] = ['emoji' => $e, 'count' => 0, 'senders' => []];
                            $rGroups[$e]['count']++;
                            $rGroups[$e]['senders'][] = $rx['sender_name'] ?? $rx['from'] ?? '';
                        }
                    ?>
                    <div class="reactions-row">
                        <?php foreach ($rGroups as $rg): ?>
                        <span class="reaction-pill <?= in_array($conv['assigned_user_name'] ?? '', $rg['senders']) ? 'me' : '' ?>" title="<?= e(implode(', ', $rg['senders'])) ?>"><?= e($rg['emoji']) ?><?= $rg['count'] > 1 ? '<span class="count">' . $rg['count'] . '</span>' : '' ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!$isDeleted): ?>
                    <div class="msg-actions">
                        <button type="button" class="msg-act" title="Copiar" onclick="copyMessage(<?= $msg['id'] ?>)"><i class="fas fa-copy"></i></button>
                        <button type="button" class="msg-act" title="Citar" onclick="quoteMessage(<?= $msg['id'] ?>)"><i class="fas fa-quote-right"></i></button>
                        <?php if ($own && in_array($msg['type'], ['text', 'internal_note'], true)): ?>
                            <button type="button" class="msg-act" title="Editar" onclick="editMessage(<?= $msg['id'] ?>)"><i class="fas fa-edit"></i></button>
                            <button type="button" class="msg-act msg-act-danger" title="Excluir" onclick="deleteMessage(<?= $msg['id'] ?>)"><i class="fas fa-trash"></i></button>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="msg-reaction-btn" data-mid="<?= $msg['id'] ?>" title="Reagir" onclick="toggleReactionPicker(<?= $msg['id'] ?>, event)"><i class="far fa-smile"></i></button>
                    <div class="reaction-picker" id="rp-<?= $msg['id'] ?>" data-mid="<?= $msg['id'] ?>"><?php foreach (['👍','❤️','😂','😮','😢','🙏'] as $re): ?><span class="rp-emoji" onclick="sendReaction(<?= $msg['id'] ?>, '<?= $re ?>')"><?= $re ?></span><?php endforeach; ?></div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>

    <button type="button" class="new-msg-pill" id="convNewPill" style="display:none" onclick="scrollConvBottom()">Novas mensagens ↓</button>

    <div class="chat-input">
        <form action="<?= url('inbox/') ?><?= $conv['id'] ?>/messages" method="POST" enctype="multipart/form-data" class="composer-form" id="composerForm">
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
                           accept="image/*,audio/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip" hidden>
                </label>
                <button type="button" class="icon-btn" title="Resposta pronta" onclick="openCannedModal()"><i class="fas fa-bookmark"></i></button>
                <button type="button" class="icon-btn" title="Macro" onclick="openMacroModal()"><i class="fas fa-bolt"></i></button>
                <div style="flex:1"></div>
                <button type="button" class="icon-btn" id="emojiToggle" title="Emoji"><i class="fas fa-smile"></i></button>
                <button type="button" class="icon-btn" id="internalToggle"
                        onclick="toggleInternal()" title="Mensagem interna (não enviada ao cliente)">
                    <i class="fas fa-lock"></i>
                </button>
                <?php if (($conv['channel_type'] ?? '') === 'whatsapp'): ?>
                <button type="button" class="icon-btn <?= empty($conv['signature_enabled']) ? '' : 'active' ?>" id="signatureToggle"
                        onclick="toggleSignature(<?= (int) $conv['id'] ?>)" title="Assinatura automática no WhatsApp">
                    <i class="fas fa-signature"></i>
                </button>
                <?php endif; ?>
            </div>
            <div class="input-row">
                <textarea name="content" id="messageInput" class="input-box" rows="1"
                          placeholder="Digite sua mensagem... (Enter para enviar, '/' resposta, ':' macro)"></textarea>
                <input type="hidden" name="reply_to" id="replyToInput" value="">
                <button type="submit" class="send-btn" id="sendBtn" title="Enviar (Enter)">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
                </button>
            </div>
            <div class="composer-file" id="composerFile" style="display:none">
                <i class="fas fa-file"></i> <span id="composerFileName"></span>
                <button type="button" class="composer-file-x" id="composerFileX" title="Remover">&times;</button>
            </div>
            <div class="emoji-popover" id="emojiPopover" style="display:none"></div>
        </form>
    </div>

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
                            <img src="<?= e(str_starts_with($contact['avatar'], 'http') ? $contact['avatar'] : upload_url($contact['avatar'])) ?>" alt="<?= e($contact['name'] ?? '') ?>">
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
                        <?php if (!empty($contact['company'])): ?>
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
                        <?php if (!empty($conv['unit'])): ?>
                            <div class="client-field-item">
                                <span class="field-icon icon-location"><i class="fas fa-map-marker-alt"></i></span>
                                <span><?= e($conv['unit']) ?></span>
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
                <div class="card-header"><h4><i class="fas fa-ticket-alt"></i> Outros Tickets</h4></div>
                <div class="card-body p-0">
                    <?php if (empty($otherConversations)): ?>
                        <div class="empty-state" style="padding: 20px;"><p>Nenhum outro ticket deste cliente.</p></div>
                    <?php else: ?>
                        <div class="other-tickets-list">
                            <?php foreach ($otherConversations as $oc): ?>
                                <a href="<?= url('inbox') ?>?conv=<?= $oc['id'] ?>" class="other-ticket-item">
                                    <div class="other-ticket-head">
                                        <span class="other-ticket-title"><?= e($oc['subject'] ?: $oc['contact_name']) ?></span>
                                        <?= status_badge($oc['status']) ?>
                                    </div>
                                    <div class="other-ticket-meta">
                                        <i class="<?= channel_icon($oc['channel_type'] ?? 'webchat') ?>"></i>
                                        <?= e($oc['channel_name'] ?? '') ?>
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
    <div class="modal-container" style="max-width:460px">
        <div class="modal-header"><h3><i class="fas fa-flag"></i> Alterar Prioridade</h3><button class="modal-close" onclick="closePriorityModal()">&times;</button></div>
        <form action="<?= url('inbox/') ?><?= $conv['id'] ?>/priority" method="POST">
            <?= csrf_field() ?>
            <div class="modal-body">
                <div class="form-group">
                    <label>Nível de prioridade</label>
                    <select name="priority" class="form-control">
                        <option value="low" <?= ($conv['priority'] ?? 'normal') === 'low' ? 'selected' : '' ?>>Baixa</option>
                        <option value="normal" <?= ($conv['priority'] ?? 'normal') === 'normal' ? 'selected' : '' ?>>Normal</option>
                        <option value="high" <?= ($conv['priority'] ?? 'normal') === 'high' ? 'selected' : '' ?>>Alta</option>
                        <option value="urgent" <?= ($conv['priority'] ?? 'normal') === 'urgent' ? 'selected' : '' ?>>Urgente</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closePriorityModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar</button>
            </div>
        </form>
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

<div class="modal-overlay" id="statusModal" style="display:none" onclick="if(event.target===this)closeStatusModal()">
    <div class="modal-container" style="max-width:460px">
        <div class="modal-header"><h3>Alterar Status</h3><button class="modal-close" onclick="closeStatusModal()">&times;</button></div>
        <form action="<?= url('inbox/') ?><?= $conv['id'] ?>/status" method="POST">
            <?= csrf_field() ?>
            <div class="modal-body">
                <div class="form-group">
                    <label>Novo status</label>
                    <select name="status" id="statusSelect" class="form-control" onchange="toggleCloseFields()">
                        <option value="open" <?= $conv['status'] === 'open' ? 'selected' : '' ?>>Aberto</option>
                        <option value="waiting_customer" <?= $conv['status'] === 'waiting_customer' ? 'selected' : '' ?>>Em atendimento</option>
                        <option value="waiting_internal" <?= $conv['status'] === 'waiting_internal' ? 'selected' : '' ?>>Aguardando Interno</option>
                        <option value="resolved" <?= $conv['status'] === 'resolved' ? 'selected' : '' ?>>Resolvido</option>
                        <option value="closed" <?= $conv['status'] === 'closed' ? 'selected' : '' ?>>Fechado</option>
                    </select>
                </div>
                <div id="closeFields" style="display:none">
                    <div class="form-group">
                        <label>Motivo (ao concluir/fechar)</label>
                        <select name="reason" class="form-control">
                            <option value="">—</option>
                            <option value="Resolvido">Resolvido</option>
                            <option value="Duplicado">Duplicado</option>
                            <option value="Não respondeu">Não respondeu</option>
                            <option value="Fora de escopo">Fora de escopo</option>
                            <option value="Solicitação cancelada">Solicitação cancelada</option>
                            <option value="Outro">Outro</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Descrição</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Detalhes do encerramento..."></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeStatusModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary">Alterar</button>
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

<div class="modal-overlay" id="csatModal" style="display:none" onclick="if(event.target===this)closeCsatModal()">
    <div class="modal-container" style="max-width:460px">
        <div class="modal-header"><h3>Avaliação de Satisfação</h3><button class="modal-close" onclick="closeCsatModal()">&times;</button></div>
        <?php if ($csat): ?>
            <div class="modal-body"><p>Esta conversa já foi avaliada.</p></div>
        <?php else: ?>
            <form action="<?= url('inbox/') ?><?= $conv['id'] ?>/csat" method="POST">
                <?= csrf_field() ?>
                <div class="modal-body">
                    <div class="form-group csat-pick" id="csatPick">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="far fa-star csat-star" data-v="<?= $i ?>"></i>
                        <?php endfor; ?>
                        <input type="hidden" name="rating" id="csatRating" value="0">
                    </div>
                    <div class="form-group">
                        <textarea name="comment" class="form-control" rows="3" placeholder="Comentário (opcional)"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" onclick="closeCsatModal()">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Registrar</button>
                </div>
            </form>
        <?php endif; ?>
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
                    <label><i class="fas fa-building"></i> Empresa</label>
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

function toggleClientDrawer() {
    document.querySelector('.conversation-view')?.classList.toggle('drawer-open');
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
    var sel = document.getElementById('statusSelect');
    var box = document.getElementById('closeFields');
    if (!sel || !box) return;
    box.style.display = (sel.value === 'resolved' || sel.value === 'closed') ? 'block' : 'none';
}
function openPriorityModal() { document.getElementById('priorityModal').style.display = 'flex'; }
function closePriorityModal() { document.getElementById('priorityModal').style.display = 'none'; }
function openSnoozeModal() { document.getElementById('snoozeModal').style.display = 'flex'; }
function closeSnoozeModal() { document.getElementById('snoozeModal').style.display = 'none'; }
function openCsatModal() { document.getElementById('csatModal').style.display = 'flex'; }
function closeCsatModal() { document.getElementById('csatModal').style.display = 'none'; }
function openMergeModal() { document.getElementById('mergeModal').style.display = 'flex'; }
function closeMergeModal() { document.getElementById('mergeModal').style.display = 'none'; }
function openCannedModal() { document.getElementById('cannedModal').style.display = 'flex'; loadCanned(); }
function closeCannedModal() { document.getElementById('cannedModal').style.display = 'none'; }
function openMacroModal() { document.getElementById('macroModal').style.display = 'flex'; loadMacros(); }
function closeMacroModal() { document.getElementById('macroModal').style.display = 'none'; }
function closeEditModal() { document.getElementById('editModal').style.display = 'none'; }
function openContactEditModal() { document.getElementById('contactEditModal').style.display = 'flex'; }
function closeContactEditModal() { document.getElementById('contactEditModal').style.display = 'none'; }
function saveSubjectSelect(val) {
    var cv = document.getElementById('convView');
    var id = cv ? cv.dataset.conv : 0;
    if (id) {
        var fd = csrfForm();
        fd.append('subject', val);
        fetch('/inbox/' + id + '/subject', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd }).then(function(r){
            if (!r.ok) console.error('Subject save failed:', r.status);
        }).catch(function(e){ console.error('Subject save error:', e); });
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
function saveUnit(val) {
    var d = document.getElementById('convUnitDisplay');
    var i = document.getElementById('convUnitInput');
    if (!d || !i) return;
    i.style.display = 'none';
    d.style.display = 'inline-block';
    var cv = document.getElementById('convView');
    var id = cv ? cv.dataset.conv : 0;
    var txt = val ? '| ' + val : '<span style="opacity:.5;font-style:italic">+ unidade</span>';
    d.innerHTML = txt + ' <i class="fas fa-pen" style="font-size:9px;opacity:.4;margin-left:2px"></i>';
    if (id) {
        var fd = csrfForm();
        fd.append('unit', val);
        fetch('/inbox/' + id + '/unit', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd }).then(function(r){
            if (!r.ok) console.error('Unit save failed:', r.status);
        }).catch(function(e){ console.error('Unit save error:', e); });
    }
}
function cancelUnitEdit() {
    var d = document.getElementById('convUnitDisplay');
    var i = document.getElementById('convUnitInput');
    if (!d || !i) return;
    i.style.display = 'none';
    d.style.display = 'inline-block';
}
function saveSubstatus(val) {
    var cv = document.getElementById('convView');
    var id = cv ? cv.dataset.conv : 0;
    if (id) {
        var fd = csrfForm();
        fd.append('substatus', val);
        fetch('/inbox/' + id + '/substatus', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd }).then(function(r){
            if (!r.ok) console.error('Substatus save failed:', r.status);
        }).catch(function(e){ console.error('Substatus save error:', e); });
    }
}
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
    }
});

/* CSAT star picker */
var csatPick = document.getElementById('csatPick');
if (csatPick) {
    csatPick.querySelectorAll('.csat-star').forEach(function(st) {
        st.addEventListener('click', function() {
            var v = parseInt(st.dataset.v, 10);
            document.getElementById('csatRating').value = v;
            csatPick.querySelectorAll('.csat-star').forEach(function(s) {
                s.classList.toggle('fas', parseInt(s.dataset.v, 10) <= v);
            });
        });
    });
}

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

var macros = [];
function loadMacros() {
    fetch(API + '/macros?department_id=' + DEPT_ID).then(function(r) { return r.json(); }).then(function(list) {
        macros = list || []; renderMacros('');
    }).catch(function() { macros = []; renderMacros(''); });
}
function renderMacros(q) {
    var box = document.getElementById('macroList'); q = (q || '').toLowerCase(); var html = '';
    macros.forEach(function(m) {
        if (q && (m.title + ' ' + (m.content || '')).toLowerCase().indexOf(q) === -1) return;
        html += '<div class="canned-item" onclick="applyMacro(' + m.id + ')"><div class="canned-title"><i class="fas fa-bolt"></i> ' + (m.title || '').replace(/</g, '') + '</div><div class="canned-content">' + ((m.content || '').replace(/</g, '')).substring(0, 120) + '</div></div>';
    });
    if (!html) html = '<p class="text-muted">Nenhuma macro encontrada.</p>';
    box.innerHTML = html;
}
function filterMacros() { renderMacros(document.getElementById('macroSearch').value); }
function applyMacro(id) {
    postJson('/inbox/' + CONV_ID + '/macro', { macro_id: id }).then(function() { location.reload(); });
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
    if (!confirm('Excluir esta mensagem?')) return;
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
        if (r.success !== false) toast('Reação enviada');
        else toast('Erro ao enviar reação');
    });
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
function nl2br(s) { return esc(s); }
function fmtBytes(b) { var u = ['B','KB','MB','GB'], i = 0; b = b || 0; while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; } return Math.round(b * 10) / 10 + ' ' + u[i]; }
function fmtDt(s) { if (!s) return ''; var d = new Date(String(s).replace(' ', 'T')); if (isNaN(d)) return s; var p = function(n) { return (n < 10 ? '0' : '') + n; }; return p(d.getDate()) + '/' + p(d.getMonth() + 1) + '/' + p(d.getFullYear()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()); }
function fileMeta(content) { try { var m = JSON.parse(content); if (m && m.url) return m; } catch (e) {} return { url: content }; }

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
function fileContentHtml(type, content, uploadsBase) {
    var m = fileMeta(content); var url = isAbsoluteUrl(m.url) ? m.url : (uploadsBase + '/' + m.url);
    if (type === 'image' || type === 'sticker') return '<a href="' + esc(url) + '" target="_blank" rel="noopener"><img class="msg-img" src="' + esc(url) + '" alt="' + esc(m.name || 'imagem') + '"></a>';
    if (type === 'audio') return '<audio controls preload="metadata" src="' + esc(url) + '"></audio>';
    if (type === 'video') return '<video controls preload="metadata" src="' + esc(url) + '"></video>';
    var name = m.name || 'arquivo'; var size = m.size ? ' (' + fmtBytes(m.size) + ')' : '';
    return '<a class="msg-file" href="' + esc(url) + '" target="_blank" rel="noopener" download><i class="fas fa-file-download"></i><span class="msg-file-name">' + esc(name) + '</span><span class="msg-file-size">' + esc(size) + '</span></a>';
}
function renderMessageHtml(m, uploadsBase) {
    var cls = 'message ' + (m.direction === 'outbound' ? 'message-out' : 'message-in');
    if (m.type === 'internal_note') cls += ' message-note';
    if (m.type === 'system') cls += ' message-system';
    if (m.type === 'sticker') cls += ' message-sticker';
    var avHtml;
    if (m.direction === 'outbound' && m.user_avatar) {
        avHtml = '<img src="' + esc(avatarSrc(m.user_avatar)) + '" class="msg-avatar-img" alt="">';
    } else if (m.direction === 'inbound' && CONTACT_AVATAR) {
        avHtml = '<img src="' + esc(avatarSrc(CONTACT_AVATAR)) + '" class="msg-avatar-img" alt="">';
    } else {
        avHtml = esc(m.direction === 'outbound' ? (m.user_name ? m.user_name.charAt(0).toUpperCase() : 'A') : CONTACT_INITIAL);
    }
    var deleted = !!(m.deleted_at);
    var body = '';
    var mtype = mediaTypeOf(m);
    if (deleted) {
        body += '<div class="message-deleted"><i class="fas fa-ban"></i> Mensagem excluída</div>';
    }
    if (m.reply_to_data) {
        var qname = m.reply_to_data.direction === 'outbound' ? 'Você' : (CONTACT_NAME || 'Contato');
        var qtext = (m.reply_to_data.content || '').replace(/<[^>]+>/g, '').substring(0, 100);
        body += '<div class="msg-quote" onclick="scrollToMessage(' + m.reply_to_data.id + ')">' +
            '<div class="msg-quote-content"><div class="msg-quote-name">' + esc(qname) + '</div>' +
            '<div class="msg-quote-text">' + esc(qtext) + '</div></div></div>';
    }
    if (m.type === 'internal_note') {
        body += '<div class="message-note-header"><i class="fas fa-lock"></i> Nota interna' + (m.user_name ? ' - ' + esc(m.user_name) : '') + '</div>';
        body += '<div class="message-content">' + nl2br(m.content) + '</div>';
    } else if (m.type === 'csat_request') {
        var cdat = {}; try { cdat = JSON.parse(m.content); } catch (e) {}
        body += '<div class="message-content csat-request-note"><i class="fas fa-smile"></i> ' + nl2br(cdat.prompt || 'Solicitação de avaliação enviada ao cliente.') +
            (cdat.url ? ' <a href="' + esc(cdat.url) + '" target="_blank" rel="noopener">Avaliar</a>' : '') + '</div>';
    } else if (m.type === 'system') {
        body += '<div class="message-content">' + esc(m.content) + '</div>';
    } else if (mtype) {
        body += '<div class="message-content">' + fileContentHtml(mtype, m.content, uploadsBase) + '</div>';
    } else {
        body += '<div class="message-content">' + nl2br(m.content) + '</div>';
    }
    var time = fmtDt(m.created_at);
    if (m.user_name && m.direction === 'outbound') time += ' - ' + esc(m.user_name);
    if (m.updated_at && m.updated_at !== m.created_at) time += ' <span class="msg-edited">(editada)</span>';
    var actions = '';
    var reactionPicker = '';
    if (!deleted) {
        actions = '<button type="button" class="msg-act" title="Copiar" onclick="copyMessage(' + m.id + ')"><i class="fas fa-copy"></i></button>' +
                  '<button type="button" class="msg-act" title="Citar" onclick="quoteMessage(' + m.id + ')"><i class="fas fa-quote-right"></i></button>';
        if (m.user_id && m.user_id == <?= \App\Core\Auth::id() ?> && ['text','internal_note'].indexOf(m.type) >= 0) {
            actions += '<button type="button" class="msg-act" title="Editar" onclick="editMessage(' + m.id + ')"><i class="fas fa-edit"></i></button>' +
                       '<button type="button" class="msg-act msg-act-danger" title="Excluir" onclick="deleteMessage(' + m.id + ')"><i class="fas fa-trash"></i></button>';
        }
        reactionPicker = '<button type="button" class="msg-reaction-btn" data-mid="' + m.id + '" title="Reagir" onclick="toggleReactionPicker(' + m.id + ', event)"><i class="far fa-smile"></i></button>' +
            '<div class="reaction-picker" id="rp-' + m.id + '" data-mid="' + m.id + '">' +
            ['👍','❤️','😂','😮','😢','🙏'].map(function(e){return '<span class="rp-emoji" onclick="sendReaction(' + m.id + ', \'' + e + '\')">' + e + '</span>';}).join('') + '</div>';
    }
    var reactionsHtml = renderReactions(m.reactions);
    return '<div class="' + cls + '" data-mid="' + m.id + '" data-text="' + esc((m.content || '').replace(/<[^>]+>/g, '')) + '">' +
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
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var ta = document.getElementById('messageInput');
        var fileInput = document.getElementById('attachInput');
        var btn = document.getElementById('sendBtn');
        if (!ta.value.trim() && !(fileInput && fileInput.files.length)) return;
        if (btn) btn.disabled = true;
        var fd = new FormData(form);
        if (quoteTarget) fd.set('reply_to', quoteTarget);
        fetch(form.action, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        }).then(function(r) { return r.json(); }).then(function(resp) {
            if (resp && resp.ok && resp.messages) {
                (resp.messages || []).forEach(function(m) { appendMessage(m, uploadsBase); });
                decorateDates();
                scrollConvBottom();
                ta.value = '';
                clearQuote();
                closeSuggest();
                if (internalOn) toggleInternal();
                if (fileInput) fileInput.value = '';
                var fb = document.getElementById('composerFile');
                if (fb) fb.style.display = 'none';
            } else if (resp && resp.error) {
                alert(resp.error);
            }
        }).catch(function() {
            alert('Erro ao enviar mensagem. Tente novamente.');
        }).finally(function() {
            if (btn) btn.disabled = false;
            doneProgress();
        });
    });
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
    if ('Notification' in window && Notification.permission === 'granted') {
        new Notification('Nova mensagem de ' + '<?= e($contact['name'] ?? 'cliente') ?>');
    }
    if (document.title !== origTitle) document.title = origTitle;
    document.title = '(' + notifyCount + ') ' + origTitle;
    setTimeout(function() { document.title = origTitle; }, 5000);
    if (typeof window.utils !== 'undefined' && window.utils.toast) {
        window.utils.toast('Nova mensagem de <?= e($contact['name'] ?? 'cliente') ?>', 'info');
    }
}

function pollMessages() {
    fetch(API + '/conversations/' + CONV_ID + '/messages').then(function(r) { return r.json(); }).then(function(msgs) {
        var added = 0, inboundNew = false;
        (msgs || []).forEach(function(m) {
            var id = parseInt(m.id, 10);
            if (id > lastMid) { appendMessage(m, uploadsBase); lastMid = id; added++; if (m.direction === 'inbound') inboundNew = true; }
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
        var badge = document.querySelector('.chat-header .status-chip');
        if (badge && c.status) badge.outerHTML = statusBadgeHtml(c.status);
        var pb = document.querySelector('.chat-header .priority-badge');
        if (pb && c.priority) pb.outerHTML = priorityBadgeHtml(c.priority);
        var asg = document.getElementById('convAssigned');
        if (asg) asg.textContent = c.assigned_user_name || 'Sem responsável';
    }).catch(function() {});
}
function statusBadgeHtml(s) {
    var map = {new:'Novo',open:'Aberto',waiting_customer:'Em atendimento',waiting_internal:'Aguardando Interno',resolved:'Resolvido',closed:'Fechado',spam:'Spam'};
    var cls = s === 'open' || s === 'new' ? 'chip-success' : s === 'waiting_customer' ? 'chip-info' : s === 'resolved' || s === 'closed' ? 'chip-neutral' : 'chip-warning';
    var dot = {'new':'var(--success)','open':'var(--success)','waiting_customer':'var(--info)','waiting_internal':'var(--warning)','resolved':'var(--text-muted)','closed':'var(--text-muted)','spam':'var(--danger)'}[s] || 'var(--success)';
    return '<span class="status-chip ' + cls + '" onclick="openStatusModal()" title="Clique para alterar status"><span class="status-dot" style="width:8px;height:8px;position:static;border:none;flex-shrink:0;background:' + dot + '"></span>' + (map[s] || s) + '</span>';
}
function priorityBadgeHtml(p) { var map = {low:'Baixa',normal:'Normal',high:'Alta',urgent:'Urgente'}; var cls = 'priority-' + p; return '<span class="priority-badge ' + cls + '" onclick="openPriorityModal()" title="Clique para alterar prioridade">' + (map[p] || p) + '</span>'; }

if (window.__convPollInterval) clearInterval(window.__convPollInterval);
window.__convPollInterval = setInterval(pollMessages, 3000);
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
