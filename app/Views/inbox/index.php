<div class="inbox-page inbox-3col" id="inboxApp">
    <div class="inbox-col-list">
        <div class="pane-header">
            <div style="display:flex;align-items:center;justify-content:space-between;">
                <h1 class="pane-title" style="margin-bottom:0;">
                    <i class="fas fa-inbox" style="color:var(--primary);font-size:22px"></i>
                    Caixa de Entrada
                </h1>
                <a href="<?= url('inbox/new') ?>" class="btn btn-primary btn-sm" style="white-space:nowrap;">
                    <i class="fas fa-plus"></i> Novo
                </a>
            </div>
            <?php $iq = !empty($activeInbox) ? 'inbox=' . (int)$activeInbox . '&' : ''; ?>
            <div class="pane-search">
                <form method="GET" action="<?= url('inbox') ?><?= $iq ? '?' . rtrim($iq, '&') : '' ?>" id="inboxSearchForm" data-fstatus="<?= e($fstatus ?? 'new') ?>">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" name="search" class="pane-search-input" placeholder="Buscar conversas..."
                           value="<?= e($search ?? '') ?>"
                           oninput="debounceSearch(this)" autocomplete="off">
                    <input type="hidden" name="fstatus" value="<?= e($fstatus ?? 'new') ?>">
                    <?php if (!empty($activeInbox)): ?>
                        <input type="hidden" name="inbox" value="<?= (int)$activeInbox ?>">
                    <?php endif; ?>
                    <button type="submit" style="display:none"></button>
                </form>
            </div>
            <div class="tabs" id="inboxTabs">
                <a href="<?= url('inbox') ?>?<?= $iq ?>fstatus=new" class="tab <?= ($fstatus ?? 'new') === 'new' ? 'active' : '' ?>">
                    <i class="fas fa-star"></i> Novas
                </a>
                <a href="<?= url('inbox') ?>?<?= $iq ?>fstatus=open" class="tab <?= ($fstatus ?? '') === 'open' ? 'active' : '' ?>">
                    <i class="fas fa-headset"></i> Em atendimento
                </a>
                <a href="<?= url('inbox') ?>?<?= $iq ?>fstatus=resolved_closed" class="tab <?= ($fstatus ?? '') === 'resolved_closed' ? 'active' : '' ?>">
                    <i class="fas fa-check"></i> Concluído
                </a>
            </div>
        </div>

        <div class="conversations" id="conversationsList">
            <?php if (empty($conversations)): ?>
                <div class="empty-state-enhanced">
                    <div class="empty-icon"><i class="fas fa-inbox"></i></div>
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
                $activeConvId = !empty($detail) ? $detail['conversation']['id'] : null;
                $channelColors = [
                    'whatsapp' => '#25D366',
                    'webchat'  => '#4361ee',
                    'email'    => '#f59e0b',
                    'telegram' => '#0088cc',
                    'facebook' => '#1877f2',
                    'instagram'=> '#e1306c',
                    'phone'    => '#6c757d',
                ];
                ?>
                <?php foreach ($conversations as $conv): ?>
                    <a href="<?= $convBase ?><?= $conv['id'] ?>"
                       class="conversation-item <?= $activeConvId == $conv['id'] ? 'active' : '' ?> conv-card-hover"
                       data-conv-id="<?= $conv['id'] ?>"
                       data-msg-count="<?= (int)($conv['message_count'] ?? 0) ?>"
                       data-unread="<?= (int)($conv['unread_count'] ?? 0) ?>">
                        <div class="avatar-container">
                            <?php if ($conv['contact_avatar']): ?>
                                <img class="avatar" src="<?= e(str_starts_with($conv['contact_avatar'], 'http') ? $conv['contact_avatar'] : upload_url($conv['contact_avatar'])) ?>" alt="">
                            <?php else: ?>
                                <div class="avatar avatar-placeholder-sm">
                                    <?= mb_strtoupper(mb_substr($conv['contact_name'] ?? '?', 0, 1)) ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($conv['status'] === 'open' || $conv['status'] === 'new'): ?>
                                <div class="status-badge-dot"></div>
                            <?php endif; ?>
                            <?php $unread = (int)($conv['unread_count'] ?? 0); ?>
                            <?php if ($unread > 0): ?>
                                <span class="unread-badge"><?= $unread > 99 ? '99+' : $unread ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="convo-info">
                            <div class="convo-header">
                                <span class="convo-name"><?= e($conv['contact_name']) ?></span>
                                <span class="convo-time">
                                    <?= time_elapsed($conv['last_message_at'] ?? $conv['created_at']) ?>
                                </span>
                            </div>
                            <?php if (!empty($conv['subject'])): ?>
                                <div class="convo-subject"><?= e($conv['subject']) ?></div>
                            <?php endif; ?>
                            <p class="convo-preview">
                                <?= e(truncate($conv['last_message'] ?? 'Sem mensagens', 80)) ?>
                            </p>
                            <div class="convo-meta">
                                <?php $chColor = $channelColors[$conv['channel_type'] ?? ''] ?? '#6c757d'; ?>
                                <span class="convo-channel" style="background: <?= e($chColor) ?>">
                                    <i class="<?= channel_icon($conv['channel_type'] ?? 'webchat') ?>"></i>
                                    <?= e($conv['channel_name'] ?? '') ?>
                                </span>
                                <?= priority_badge($conv['priority'] ?? 'normal') ?>
                                <?= status_badge($conv['status']) ?>
                            </div>
                            <div class="convo-footer">
                                <span><i class="fas fa-user"></i> <?= e($conv['assigned_user_name'] ?? 'Não atribuído') ?></span>
                                <span><i class="fas fa-comment-dots"></i> <?= (int)($conv['message_count'] ?? 0) ?></span>
                                <?php if ($conv['department_name']): ?>
                                    <span><i class="fas fa-layer-group"></i> <?= e($conv['department_name']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="inbox-col-detail" id="conversationDetail">
        <?php if (!empty($detail)): ?>
            <?php extract($detail); ?>
            <?php include __DIR__ . '/panel.php'; ?>
        <?php else: ?>
            <div class="detail-empty">
                <i class="fas fa-comments fa-3x" style="opacity:0.3"></i>
                <p style="font-size:15px;color:var(--text-muted);margin-top:12px">Selecione uma conversa para visualizar o conteúdo da mensagem</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Inbox search debounce
var searchTimer;
function debounceSearch(input) {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function() { input.closest('form').submit(); }, 400);
}

// Mobile: show detail on conversation click
document.querySelectorAll('.conversation-item').forEach(function(el) {
    el.addEventListener('click', function(e) {
        if (window.innerWidth <= 768) {
            document.querySelector('.inbox-3col')?.classList.add('show-detail');
        }
    });
});

// Mobile: back button
(function() {
    var detail = document.getElementById('conversationDetail');
    if (detail && window.innerWidth <= 768) {
        var backBtn = document.createElement('button');
        backBtn.className = 'btn btn-sm btn-outline';
        backBtn.innerHTML = '<i class="fas fa-arrow-left"></i> Voltar';
        backBtn.style.cssText = 'position:absolute;top:12px;left:12px;z-index:5';
        backBtn.addEventListener('click', function() {
            document.querySelector('.inbox-3col')?.classList.remove('show-detail');
        });
        var header = detail.querySelector('.conv-detail-header');
        if (header) header.appendChild(backBtn);
    }
})();

// Live conversation count badges
(function pollBadges() {
    fetch('/api/conversations?status=new&count=1')
        .then(r => r.json())
        .then(data => {
            var count = data.total || 0;
            document.querySelectorAll('.nav-badge:first-of-type').forEach(function(el) {
                if (count > 0) {
                    el.textContent = count > 99 ? '99+' : count;
                    el.style.display = 'inline';
                } else {
                    el.style.display = 'none';
                }
            });
        })
        .catch(function() {});
    setTimeout(pollBadges, 15000);
})();
</script>
