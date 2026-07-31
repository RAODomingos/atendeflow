<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ?? 'AtendeFlow') ?> - AtendeFlow</title>
    <meta name="base-url" content="<?= rtrim(base_url(), '/') ?>">
    <meta name="user-name" content="<?= e(\App\Core\Session::get('user_name')) ?>">
    <meta name="user-role" content="<?= e(\App\Core\Session::get('user_role')) ?>">
    <meta name="config-app-name" content="<?= e($config['app_name'] ?? 'AtendeFlow') ?>">
    <meta name="config-chat-widget-enabled" content="<?= $config['chat_widget_enabled'] ?? 'true' ?>">
    <meta name="config-proactive-chat-enabled" content="<?= $config['proactive_chat_enabled'] ?? 'true' ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
</head>
<body>
<?php
$activePage = $activePage ?? '';
$isInbox = str_starts_with($contentView ?? '', 'inbox/');
$userName = \App\Core\Session::get('user_name') ?? 'Admin';
$userRole = \App\Core\Session::get('user_role') ?? 'admin';
$userInitial = mb_strtoupper(mb_substr($userName, 0, 1));
?>
<div class="app<?= $isInbox ? ' app-inbox' : '' ?>" id="app">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-icon"><i class="fas fa-headset" style="color:#fff"></i></div>
            <div class="brand-name">AtendeFlow</div>
        </div>

        <div class="nav-section">
            <div class="nav-label">Principal</div>
            <a href="<?= route('dashboard') ?>" class="nav-item <?= $activePage === 'dashboard' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>
                Dashboard
            </a>
        </div>

        <div class="nav-section">
            <div class="nav-label">Atendimento</div>
            <?php
            $catInboxes = [];
            foreach (\App\Models\Inbox::getUserInboxes(\App\Core\Auth::id()) as $ib) {
                if ($ib['type'] !== 'personal') $catInboxes[] = $ib;
            }
            $personalInboxes = \App\Models\Inbox::getOwnedPersonalInboxes(\App\Core\Auth::id());
            $allInboxIds = array_merge(array_column($catInboxes, 'id'), array_column($personalInboxes, 'id'));
            $openByInbox = \App\Models\Conversation::openCountsByInbox($allInboxIds);
            $totalOpen = 0;
            foreach ($catInboxes as $ib) { $totalOpen += $openByInbox[$ib['id']] ?? 0; }
            $chatbotCount = \App\Models\Conversation::getChatbotConversations(\App\Core\Auth::id());
            $chatbotBadge = count($chatbotCount);
            ?>
            <a href="<?= route('inbox') ?>" class="nav-item <?= in_array($activePage, ['inbox','inbox_mine','inbox_chatbot']) ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11L2 12v6a2 2 0 002 2h16a2 2 0 002-2v-6l-3.45-6.89A2 2 0 0016.76 4H7.24a2 2 0 00-1.79 1.11z"/></svg>
                Caixa de Entrada
                <?php if ($totalOpen > 0): ?><span class="nav-badge"><?= $totalOpen ?></span><?php endif; ?>
            </a>
            <?php foreach ($catInboxes as $ib): ?>
                <a href="<?= url('inbox?inbox=' . $ib['id']) ?>" class="nav-item nav-sub <?= (($_GET['inbox'] ?? '') == $ib['id']) ? 'active' : '' ?>">
                    <i class="fa-solid fa-inbox" style="font-size:14px;width:17px;text-align:center"></i>
                    <span><?= e($ib['name']) ?></span>
                    <?php if (($openByInbox[$ib['id']] ?? 0) > 0): ?><span class="nav-badge"><?= $openByInbox[$ib['id']] ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
            <a href="<?= route('inbox.mine') ?>" class="nav-item <?= $activePage === 'inbox_mine' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21v-1a7 7 0 0114 0v1"/></svg>
                Minha Caixa
            </a>
            <a href="<?= route('inbox.chatbot') ?>" class="nav-item <?= $activePage === 'inbox_chatbot' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="7" width="16" height="13" rx="2"/><path d="M9 7V5a3 3 0 016 0v2"/></svg>
                Chatbot
                <?php if ($chatbotBadge > 0): ?><span class="nav-badge"><?= $chatbotBadge ?></span><?php endif; ?>
            </a>
            <?php foreach ($personalInboxes as $ib): ?>
                <a href="<?= url('inbox?inbox=' . $ib['id']) ?>" class="nav-item nav-sub <?= (($_GET['inbox'] ?? '') == $ib['id']) ? 'active' : '' ?>">
                    <i class="fa-solid fa-lock" style="font-size:14px;width:17px;text-align:center"></i>
                    <span><?= e($ib['name']) ?></span>
                    <?php if (($openByInbox[$ib['id']] ?? 0) > 0): ?><span class="nav-badge"><?= $openByInbox[$ib['id']] ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php $gestaoActive = in_array($activePage, ['contacts', 'departments', 'flows', 'library']); ?>
        <div class="nav-section <?= $gestaoActive ? 'expanded' : '' ?>">
            <div class="nav-section-title" onclick="this.parentElement.classList.toggle('expanded')">
                <span>Gestão</span>
                <i class="fa-solid fa-chevron-down chevron"></i>
            </div>
            <div class="nav-dropdown-menu">
                <a href="<?= route('contacts') ?>" class="nav-item <?= $activePage === 'contacts' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
                    Contatos
                </a>
                <a href="<?= route('departments') ?>" class="nav-item <?= $activePage === 'departments' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="8" height="8" rx="2"/><rect x="14" y="2" width="8" height="8" rx="2"/><rect x="2" y="14" width="8" height="8" rx="2"/><rect x="14" y="14" width="8" height="8" rx="2"/></svg>
                    Departamentos
                </a>
                <a href="<?= route('flows') ?>" class="nav-item <?= $activePage === 'flows' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 01-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
                    Fluxos
                </a>
                <a href="<?= url('library') ?>" class="nav-item <?= $activePage === 'library' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/></svg>
                    Tags e Respostas
                </a>
            </div>
        </div>

        <?php $relatoriosActive = in_array($activePage, ['reports', 'reports_conversations', 'reports_agents', 'reports_csat']); ?>
        <div class="nav-section <?= $relatoriosActive ? 'expanded' : '' ?>">
            <div class="nav-section-title" onclick="this.parentElement.classList.toggle('expanded')">
                <span>Relatórios</span>
                <i class="fa-solid fa-chevron-down chevron"></i>
            </div>
            <div class="nav-dropdown-menu">
                <a href="<?= url('reports') ?>" class="nav-item <?= $activePage === 'reports' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.21 15.89A10 10 0 118 2.83M22 12A10 10 0 0012 2v10z"/></svg>
                    Visão Geral
                </a>
                <a href="<?= url('reports/conversations') ?>" class="nav-item <?= $activePage === 'reports_conversations' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                    Conversas
                </a>
                <a href="<?= url('reports/agents') ?>" class="nav-item <?= $activePage === 'reports_agents' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                    Atendentes
                </a>
                <a href="<?= url('reports/csat') ?>" class="nav-item <?= $activePage === 'reports_csat' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    Satisfação (CSAT)
                </a>
            </div>
        </div>

        <?php if (\App\Core\Auth::isAdmin()): ?>
        <?php $adminActive = in_array($activePage, ['users', 'channels', 'inboxes', 'settings']); ?>
        <div class="nav-section <?= $adminActive ? 'expanded' : '' ?>">
            <div class="nav-section-title" onclick="this.parentElement.classList.toggle('expanded')">
                <span>Administração</span>
                <i class="fa-solid fa-chevron-down chevron"></i>
            </div>
            <div class="nav-dropdown-menu">
                <a href="<?= route('users') ?>" class="nav-item <?= $activePage === 'users' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
                    Usuários
                </a>
                <a href="<?= url('channels') ?>" class="nav-item <?= $activePage === 'channels' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><path d="M6 6h.01M6 18h.01"/></svg>
                    Canais
                </a>
                <a href="<?= url('inboxes') ?>" class="nav-item <?= $activePage === 'inboxes' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11L2 12v6a2 2 0 002 2h16a2 2 0 002-2v-6l-3.45-6.89A2 2 0 0016.76 4H7.24a2 2 0 00-1.79 1.11z"/></svg>
                    Caixas de Entrada
                </a>
                <a href="<?= url('settings') ?>" class="nav-item <?= $activePage === 'settings' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 01-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
                    Configurações
                </a>
            </div>
        </div>
        <?php endif; ?>

        <div class="sidebar-bottom">
            <div class="user-mini">
                <div class="avatar avatar-sm"><?= $userInitial ?></div>
                <div>
                    <div class="user-mini-name"><?= e($userName) ?></div>
                    <div class="user-mini-role"><?= ucfirst($userRole) ?></div>
                </div>
                <div class="notif-wrapper" style="margin-left:auto">
                    <button class="notif-btn" id="notifBtn" title="Notificações">
                        <i class="fa-regular fa-bell"></i>
                        <span class="notif-badge" id="notifBadge" style="display:none">0</span>
                    </button>
                    <div class="notif-dropdown" id="notifDropdown">
                        <div class="notif-header">
                            <strong>Notificações</strong>
                            <button class="notif-mark-read" id="notifMarkAllRead">Marcar todas como lidas</button>
                        </div>
                        <div class="notif-list" id="notifList"></div>
                    </div>
                </div>
            </div>
            <form method="post" action="<?= url('logout') ?>" style="margin:0;">
                <?= csrf_field() ?>
                <button type="submit" class="sidebar-logout">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Sair</span>
                </button>
            </form>
        </div>
    </aside>

    <?php if (!$isInbox): ?>
    <main class="main-content">
        <?php $flashSuccess = \App\Core\Session::getFlash('success'); ?>
        <?php $flashError = \App\Core\Session::getFlash('error'); ?>
        <?php if ($flashSuccess || $flashError): ?>
            <div class="flash-container">
                <?php if ($flashSuccess): ?>
                    <div class="flash flash-success">
                        <i class="fa-solid fa-check-circle"></i>
                        <span><?= e($flashSuccess) ?></span>
                        <button class="flash-close" onclick="this.parentElement.remove()"><i class="fa-solid fa-times"></i></button>
                    </div>
                <?php endif; ?>
                <?php if ($flashError): ?>
                    <div class="flash flash-error">
                        <i class="fa-solid fa-exclamation-circle"></i>
                        <span><?= e($flashError) ?></span>
                        <button class="flash-close" onclick="this.parentElement.remove()"><i class="fa-solid fa-times"></i></button>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="page-content">
            <?php if (isset($contentView)): ?>
                <?php require __DIR__ . '/../' . $contentView . '.php'; ?>
            <?php endif; ?>
        </div>
    </main>
    <?php else: ?>
        <?php if (isset($contentView)): ?>
            <?php require __DIR__ . '/../' . $contentView . '.php'; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="sidebar-overlay" id="sidebarOverlay" style="display:none"></div>
<div class="top-progress" id="topProgress"></div>

<script src="<?= asset('assets/js/app.js') ?>"></script>
<script src="<?= asset('assets/js/app-enhancements.js') ?>"></script>
<script>
(function(){
    var bar = document.getElementById('topProgress');
    if(bar){
        document.addEventListener('submit',function(){bar.classList.add('active');});
        window.addEventListener('beforeunload',function(){
            bar.classList.remove('active');bar.classList.add('done');
            setTimeout(function(){bar.classList.remove('done');},500);
        });
    }
})();
(function(){
    var notifBtn=document.getElementById('notifBtn'),notifDropdown=document.getElementById('notifDropdown'),
        notifBadge=document.getElementById('notifBadge'),notifList=document.getElementById('notifList'),
        markAllBtn=document.getElementById('notifMarkAllRead');
    if(!notifBtn)return;
    var baseUrl=document.querySelector('meta[name="base-url"]')?.content||'';
    var channelIcons={whatsapp:'fab fa-whatsapp',webchat:'fas fa-comment-dots',email:'fas fa-envelope',telegram:'fab fa-telegram',facebook:'fab fa-facebook',instagram:'fab fa-instagram',phone:'fas fa-phone'};
    function esc(s){if(s==null)return'';var d=document.createElement('div');d.textContent=String(s);return d.innerHTML;}
    function timeAgo(dt){if(!dt)return'';var d=new Date(String(dt).replace(' ','T'));if(isNaN(d))return'';var n=new Date(),s=Math.floor((n-d)/1000);if(s<60)return'agora';var m=Math.floor(s/60);if(m<60)return m+'m';var h=Math.floor(m/60);if(h<24)return h+'h';var dy=Math.floor(h/24);if(dy<30)return dy+'d';return d.toLocaleDateString('pt-BR');}
    function trunc(s,l){if(!s)return'Sem mensagens';if(s.length<=l)return s;return s.substring(0,l)+'...';}
    function fetchUnreadConversations(){return fetch(baseUrl+'/api/unread-conversations',{headers:{'X-Requested-With':'XMLHttpRequest'}}).then(function(r){return r.json()}).catch(function(){return{conversations:[],total_unread:0}});}
    function fetchNotifications(){return fetch(baseUrl+'/notifications?limit=10',{headers:{'X-Requested-With':'XMLHttpRequest'}}).then(function(r){return r.json()}).catch(function(){return{notifications:[],unread_count:0}});}
    function renderDropdown(){
        notifList.innerHTML='<div class="notif-loading"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>';
        Promise.all([fetchUnreadConversations(),fetchNotifications()]).then(function(r){
            var convs=r[0].conversations||[],notifs=r[1].notifications||[],html='';
            if(convs.length){
                html+='<div class="notif-section-label"><i class="fas fa-comment-dots"></i> Mensagens não lidas</div>';
                convs.forEach(function(c){
                    var ci=channelIcons[c.channel_type]||'fas fa-comment-dots',cc=c.channel_type==='whatsapp'?'#25D366':c.channel_type==='webchat'?'#4361ee':c.channel_type==='email'?'#f59e0b':'#6c757d';
                    var init=(c.contact_name||'?').charAt(0).toUpperCase();
                    var av=c.contact_avatar?'<img class="notif-msg-avatar" src="'+(c.contact_avatar.indexOf('http')===0?'':baseUrl+'/uploads/')+esc(c.contact_avatar)+'" alt="">':'<div class="notif-msg-avatar">'+esc(init)+'</div>';
                    html+='<a href="'+baseUrl+'/inbox/'+c.id+'" class="notif-msg-item">'+av+'<div class="notif-msg-content"><div class="notif-msg-header"><span class="notif-msg-name">'+esc(c.contact_name||'Cliente')+'</span><span class="notif-msg-channel" style="color:'+cc+'"><i class="'+ci+'"></i></span><span class="notif-msg-time">'+timeAgo(c.last_message_at||c.created_at)+'</span></div><div class="notif-msg-preview">'+esc(trunc(c.last_message,100))+'</div>'+(c.unread_count>1?'<span class="notif-msg-count">'+c.unread_count+' mensagens</span>':'')+'</div></a>';
                });
                html+='<div class="notif-section-divider"></div>';
            }
            if(notifs.length){
                html+='<div class="notif-section-label"><i class="fas fa-bell"></i> Notificações</div>';
                notifs.forEach(function(n){
                    var u=!n.is_read;
                    html+='<div class="notif-item'+(u?' notif-unread':'')+'" data-id="'+n.id+'"><div class="notif-icon"><i class="fa-solid fa-'+(n.notification_type==='mention'?'at':'user-plus')+'"></i></div><div class="notif-content"><div class="notif-title">'+esc(n.title||'')+'</div><div class="notif-body">'+esc(n.body||'')+'</div><div class="notif-time">'+timeAgo(n.created_at)+'</div></div>'+(u?'<button class="notif-mark-one" data-id="'+n.id+'"><i class="fa-solid fa-check"></i></button>':'')+'</div>';
                });
            }
            if(!convs.length&&!notifs.length)html='<div class="notif-empty">Nenhuma notificação</div>';
            notifList.innerHTML=html;
            notifList.querySelectorAll('.notif-mark-one').forEach(function(b){b.addEventListener('click',function(e){e.stopPropagation();var id=b.dataset.id;fetch(baseUrl+'/notifications/'+id+'/read',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:new URLSearchParams({_csrf_token:window.utils?.csrf()||''})}).then(function(){var i=b.closest('.notif-item');if(i){i.classList.remove('notif-unread');b.remove();}}).catch(function(){})});});
        }).catch(function(){notifList.innerHTML='<div class="notif-empty">Erro ao carregar notificações</div>';});
    }
    notifBtn.addEventListener('click',function(e){
        e.stopPropagation();var o=notifDropdown.classList.contains('open');
        document.querySelectorAll('.notif-dropdown.open').forEach(function(el){el.classList.remove('open');});
        if(!o){notifDropdown.classList.add('open');renderDropdown();}
    });
    if(markAllBtn)markAllBtn.addEventListener('click',function(){
        fetch(baseUrl+'/notifications/read-all',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:new URLSearchParams({_csrf_token:window.utils?.csrf()||''})}).then(function(){notifList.querySelectorAll('.notif-item.notif-unread').forEach(function(i){i.classList.remove('notif-unread');var m=i.querySelector('.notif-mark-one');if(m)m.remove();});}).catch(function(){});
    });
    document.addEventListener('click',function(){notifDropdown.classList.remove('open');});
    notifDropdown.addEventListener('click',function(e){e.stopPropagation();});
})();
(function(){
    var overlay=document.getElementById('sidebarOverlay');
    if(!overlay)return;
    var sidebar=document.querySelector('.sidebar');
    function closeSidebar(){sidebar?.classList.remove('open');overlay.style.display='none';overlay.classList.remove('open');}
    overlay.addEventListener('click',closeSidebar);
    document.addEventListener('click',function(e){
        if(window.innerWidth<=768&&!e.target.closest('.sidebar')&&!e.target.closest('.notif-btn')&&!e.target.closest('.notif-dropdown')){
            closeSidebar();
        }
    });
    function checkMobile(){if(window.innerWidth>768)closeSidebar();}
    window.addEventListener('resize',checkMobile);
})();
</script>
</body>
</html>
