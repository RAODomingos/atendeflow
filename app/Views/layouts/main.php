<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ?? 'OminiDesk') ?> - OminiDesk</title>
    <meta name="base-url" content="<?= rtrim(base_url(), '/') ?>">
    <meta name="user-name" content="<?= e(\App\Core\Session::get('user_name')) ?>">
    <meta name="user-role" content="<?= e(\App\Core\Session::get('user_role')) ?>">
    <meta name="config-app-name" content="<?= e($config['app_name'] ?? 'OminiDesk') ?>">
    <meta name="config-chat-widget-enabled" content="<?= $config['chat_widget_enabled'] ?? 'true' ?>">
    <meta name="config-proactive-chat-enabled" content="<?= $config['proactive_chat_enabled'] ?? 'true' ?>">
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="icon" type="image/png" href="<?= asset('assets/img/favicon.png') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
    <script>
    (function(){try{var t=localStorage.getItem('omini-theme');if(!t)t=window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';if(t==='dark')document.documentElement.setAttribute('data-theme','dark');}catch(e){}})();
    </script>
</head>
<body>
<?php
$activePage = $activePage ?? '';
$isInbox = str_starts_with($contentView ?? '', 'inbox/');
$userName = \App\Core\Session::get('user_name') ?? 'Admin';
$userRole = \App\Core\Session::get('user_role') ?? 'admin';
$userInitial = mb_strtoupper(mb_substr($userName, 0, 1));
?>
<?php
try {
    $waDown = \App\Models\WhatsAppConnection::disconnected();
} catch (\Throwable $e) {
    $waDown = [];
}
?>
<?php if (!empty($waDown)): ?>
<div class="wa-alert-bar" id="waAlertBar" role="alert">
    <i class="fa-brands fa-whatsapp"></i>
    <span>
        <strong>WhatsApp desconectado<?= count($waDown) > 1 ? ' (' . count($waDown) . ' canais)' : '' ?>:</strong>
        <?= e(implode(', ', array_column($waDown, 'channel_name'))) ?>.
        As respostas não estão chegando ao cliente.
    </span>
    <?php if (\App\Core\Auth::isAdmin()): ?>
        <a href="<?= url('channels') ?>" class="wa-alert-link">Reconectar <i class="fa-solid fa-arrow-right"></i></a>
    <?php endif; ?>
</div>
<?php endif; ?>
<div class="app<?= $isInbox ? ' app-inbox' : '' ?>" id="app">
    <header class="topbar">
        <div class="topbar-left">
            <button class="topbar-toggle" id="sidebarToggle" title="Ocultar / exibir menu" aria-label="Ocultar / exibir menu" aria-expanded="true" aria-controls="sidebarNav">
                <i class="fa-solid fa-bars"></i>
            </button>
            <a href="<?= route('dashboard') ?>" class="topbar-brand" title="OminiDesk - Início">
                <img class="topbar-brand-img" src="<?= asset('assets/img/ominidesk-mark.png') ?>" alt="Ominidesk" width="28" height="28">
                <span class="topbar-brand-name">Ominidesk</span>
            </a>
            <div class="topbar-title-wrap">
                <h1 class="topbar-title"><?= e($title ?? 'Dashboard') ?></h1>
            </div>
        </div>
        <div class="m365-search">
            <i class="fa-solid fa-magnifying-glass m365-search-icon"></i>
            <input type="text" id="globalSearch" placeholder="Pesquisar" autocomplete="off" aria-label="Pesquisar">
            <span class="m365-search-hint">Ctrl K</span>
        </div>
        <div class="topbar-right">
            <div class="notif-wrapper">
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
            <button class="notif-btn" id="soundToggle" title="Som de notificações">
                <i class="fa-solid fa-volume-high"></i>
            </button>
            <button class="notif-btn" id="themeToggle" title="Alternar tema claro/escuro" aria-label="Alternar tema">
                <i class="fa-regular fa-moon" id="themeToggleIcon"></i>
            </button>
            <div class="topbar-user" id="topbarUser">
                <a href="<?= url('profile') ?>" class="topbar-profile-link" title="Meu Perfil">
                    <div class="avatar avatar-sm"><?= $userInitial ?></div>
                    <div class="topbar-user-info">
                        <div class="topbar-user-name"><?= e($userName) ?></div>
                        <div class="topbar-user-role"><?= ucfirst($userRole) ?></div>
                    </div>
                </a>
                <form method="post" action="<?= url('logout') ?>" style="margin:0;display:flex">
                    <?= csrf_field() ?>
                    <button type="submit" class="topbar-logout" title="Sair">
                        <i class="fas fa-sign-out-alt"></i>
                    </button>
                </form>
            </div>
        </div>
    </header>
    <aside class="sidebar" id="sidebarNav">
        <div class="nav-section" style="margin-top:0">
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
            // Caixa de Entrada (todas) só fica ativa quando nenhuma caixa
            // específica está selecionada; senão só a caixa visível destaca.
            $selectedInbox = (string) ($_GET['inbox'] ?? '');
            ?>
            <a href="<?= route('inbox') ?>" class="nav-item <?= ($activePage === 'inbox' && $selectedInbox === '') ? 'active' : '' ?>" data-inbox-link="all">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11L2 12v6a2 2 0 002 2h16a2 2 0 002-2v-6l-3.45-6.89A2 2 0 0016.76 4H7.24a2 2 0 00-1.79 1.11z"/></svg>
                Caixa de Entrada
                <?php if ($totalOpen > 0): ?><span class="nav-badge" data-inbox-badge="all"><?= $totalOpen ?></span><?php endif; ?>
            </a>
            <?php foreach ($catInboxes as $ib): ?>
                <a href="<?= url('inbox?inbox=' . $ib['id']) ?>" class="nav-item nav-sub <?= ($activePage === 'inbox' && $selectedInbox === (string) $ib['id']) ? 'active' : '' ?>" data-inbox-link="<?= (int) $ib['id'] ?>">
                    <i class="fa-solid fa-inbox" style="font-size:14px;width:17px;text-align:center"></i>
                    <span><?= e($ib['name']) ?></span>
                    <?php if (($openByInbox[$ib['id']] ?? 0) > 0): ?><span class="nav-badge" data-inbox-badge="<?= (int) $ib['id'] ?>"><?= $openByInbox[$ib['id']] ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
            <a href="<?= route('inbox.mine') ?>" class="nav-item <?= $activePage === 'inbox_mine' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21v-1a7 7 0 0114 0v1"/></svg>
                Minha Caixa
            </a>

            <?php foreach ($personalInboxes as $ib): ?>
                <a href="<?= url('inbox?inbox=' . $ib['id']) ?>" class="nav-item nav-sub <?= ($activePage === 'inbox' && $selectedInbox === (string) $ib['id']) ? 'active' : '' ?>" data-inbox-link="<?= (int) $ib['id'] ?>">
                    <i class="fa-solid fa-lock" style="font-size:14px;width:17px;text-align:center"></i>
                    <span><?= e($ib['name']) ?></span>
                    <?php if (($openByInbox[$ib['id']] ?? 0) > 0): ?><span class="nav-badge" data-inbox-badge="<?= (int) $ib['id'] ?>"><?= $openByInbox[$ib['id']] ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php $gestaoActive = in_array($activePage, ['contacts', 'departments', 'flows', 'library', 'macros', 'wiki']); ?>
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
                <a href="<?= url('macros') ?>" class="nav-item <?= $activePage === 'macros' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                    Macros
                </a>
                <a href="<?= url('wiki') ?>" class="nav-item <?= $activePage === 'wiki' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/><path d="M9 7h6M9 11h6"/></svg>
                    Base de Conhecimento
                </a>
                <a href="<?= url('whatsapp/groups') ?>" class="nav-item <?= $activePage === 'wa_groups' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
                    Grupos WhatsApp
                </a>
            </div>
        </div>

        <?php $relatoriosActive = in_array($activePage, ['reports', 'reports_timeline', 'reports_conversations', 'reports_agents', 'reports_csat']); ?>
        <?php if (\App\Core\Auth::isManager()): ?>
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
                <a href="<?= url('reports/timeline') ?>" class="nav-item <?= $activePage === 'reports_timeline' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 6l-9.5 9.5-5-5L1 18"/><path d="M17 6h6v6"/></svg>
                    Linha do Tempo
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
        <?php endif; ?>

        <?php if (\App\Core\Auth::isAdmin()): ?>
        <?php $adminActive = in_array($activePage, ['users', 'settings', 'settings_notifications']); ?>
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
                <a href="<?= url('settings') ?>" class="nav-item <?= $activePage === 'settings' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 01-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
                    Configurações
                </a>
            </div>
        </div>
        <?php endif; ?>

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
<div id="globalToastWrap" aria-live="polite" style="position:fixed;bottom:18px;right:18px;z-index:9999;display:flex;flex-direction:column;gap:8px"></div>
<div id="confirmModalOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:10000;align-items:center;justify-content:center">
    <div role="dialog" aria-modal="true" aria-labelledby="confirmModalTitle" id="confirmModalBox" style="background:var(--bg-panel,#fff);border-radius:12px;max-width:420px;width:calc(100% - 32px);padding:20px;box-shadow:0 20px 60px rgba(0,0,0,.3)">
        <h3 id="confirmModalTitle" style="margin:0 0 8px;font-size:16px">Confirmar</h3>
        <p id="confirmModalMsg" style="margin:0 0 16px;font-size:13.5px;color:var(--text-secondary,#555)"></p>
        <div style="display:flex;gap:8px;justify-content:flex-end">
            <button type="button" class="btn btn-outline" id="confirmModalCancel">Cancelar</button>
            <button type="button" class="btn btn-primary" id="confirmModalOk">Confirmar</button>
        </div>
    </div>
</div>

<script src="<?= asset('assets/js/app.js') ?>"></script>
<script src="<?= asset('assets/js/app-enhancements.js') ?>"></script>
<script>
// Toast + confirm acessíveis globais (substituem alert()/confirm() nativos).
window.toast = window.toast || function(msg) {
    var wrap = document.getElementById('globalToastWrap');
    if (!wrap) { alert(msg); return; }
    var t = document.createElement('div');
    t.className = 'toast-msg';
    t.textContent = String(msg == null ? '' : msg);
    t.style.cssText = 'background:#1f2937;color:#fff;padding:10px 14px;border-radius:8px;font-size:13px;box-shadow:0 8px 24px rgba(0,0,0,.25);max-width:320px';
    wrap.appendChild(t);
    setTimeout(function(){ t.style.opacity = '0'; t.style.transition = 'opacity .3s'; }, 2600);
    setTimeout(function(){ t.remove(); }, 3000);
};
window.confirmModal = window.confirmModal || function(msg, title) {
    return new Promise(function(resolve) {
        var overlay = document.getElementById('confirmModalOverlay');
        var msgEl = document.getElementById('confirmModalMsg');
        var titleEl = document.getElementById('confirmModalTitle');
        var ok = document.getElementById('confirmModalOk');
        var cancel = document.getElementById('confirmModalCancel');
        if (!overlay) { resolve(confirm(msg)); return; }
        titleEl.textContent = title || 'Confirmar';
        msgEl.textContent = msg;
        overlay.style.display = 'flex';
        var prevFocus = document.activeElement;
        ok.focus();
        function close(val) {
            overlay.style.display = 'none';
            ok.removeEventListener('click', onOk);
            cancel.removeEventListener('click', onCancel);
            overlay.removeEventListener('click', onOverlay);
            document.removeEventListener('keydown', onKey);
            if (prevFocus && prevFocus.focus) prevFocus.focus();
            resolve(val);
        }
        function onOk() { close(true); }
        function onCancel() { close(false); }
        function onOverlay(e) { if (e.target === overlay) close(false); }
        function onKey(e) { if (e.key === 'Escape') close(false); }
        ok.addEventListener('click', onOk);
        cancel.addEventListener('click', onCancel);
        overlay.addEventListener('click', onOverlay);
        document.addEventListener('keydown', onKey);
    });
};
// CSRF global: todo fetch() POST/PUT/DELETE same-origin leva X-CSRF-TOKEN automaticamente.
(function(){
    var tok = document.querySelector('meta[name="csrf-token"]')?.content || '';
    if (!tok || window.__csrfPatched) return;
    window.__csrfPatched = true;
    var origFetch = window.fetch.bind(window);
    window.fetch = function(input, init) {
        init = init || {};
        var method = (init.method || 'GET').toUpperCase();
        var url = typeof input === 'string' ? input : (input && input.url) || '';
        var sameOrigin = !/^https?:\/\//i.test(url) || url.indexOf(location.origin) === 0;
        if (method !== 'GET' && method !== 'HEAD' && sameOrigin) {
            init.headers = init.headers || {};
            if (init.headers instanceof Headers) {
                if (!init.headers.has('X-CSRF-TOKEN')) init.headers.set('X-CSRF-TOKEN', tok);
            } else if (Array.isArray(init.headers)) {
                var has = init.headers.some(function(h){ return String(h[0]).toLowerCase() === 'x-csrf-token'; });
                if (!has) init.headers.push(['X-CSRF-TOKEN', tok]);
            } else {
                var found = Object.keys(init.headers).some(function(k){ return k.toLowerCase() === 'x-csrf-token'; });
                if (!found) init.headers['X-CSRF-TOKEN'] = tok;
            }
        }
        return origFetch(input, init);
    };
})();
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
    var KEY='omini-theme';
    var btn=document.getElementById('themeToggle'),icon=document.getElementById('themeToggleIcon');
    function apply(t){
        if(t==='dark')document.documentElement.setAttribute('data-theme','dark');
        else document.documentElement.removeAttribute('data-theme');
        if(icon)icon.className=t==='dark'?'fa-regular fa-sun':'fa-regular fa-moon';
        if(btn)btn.title=t==='dark'?'Tema claro':'Tema escuro';
    }
    var saved=null;
    try{saved=localStorage.getItem(KEY);}catch(e){}
    if(!saved)saved=window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';
    apply(saved);
    if(btn)btn.addEventListener('click',function(){
        var next=document.documentElement.getAttribute('data-theme')==='dark'?'light':'dark';
        apply(next);
        try{localStorage.setItem(KEY,next);}catch(e){}
    });
})();
(function(){
    var notifBtn=document.getElementById('notifBtn'),notifDropdown=document.getElementById('notifDropdown'),
        notifBadge=document.getElementById('notifBadge'),notifList=document.getElementById('notifList'),
        markAllBtn=document.getElementById('notifMarkAllRead'),
        soundBtn=document.getElementById('soundToggle');
    if(!notifBtn)return;
    var baseUrl=document.querySelector('meta[name="base-url"]')?.content||'';
    var channelIcons={whatsapp:'fab fa-whatsapp',webchat:'fas fa-comment-dots',email:'fas fa-envelope',telegram:'fab fa-telegram',facebook:'fab fa-facebook',instagram:'fab fa-instagram',phone:'fas fa-phone'};
    var typeIcons={mention:'fa-at',group_mention:'fa-users',assignment:'fa-user-plus',new_message:'fa-comment',new_conversation:'fa-comments',transfer:'fa-share',status_change:'fa-circle-info',system:'fa-gear'};
    function esc(s){if(s==null)return'';var d=document.createElement('div');d.textContent=String(s);return d.innerHTML;}
    function timeAgo(dt){if(!dt)return'';var d=new Date(String(dt).replace(' ','T'));if(isNaN(d))return'';var n=new Date(),s=Math.floor((n-d)/1000);if(s<60)return'agora';var m=Math.floor(s/60);if(m<60)return m+'m';var h=Math.floor(m/60);if(h<24)return h+'h';var dy=Math.floor(h/24);if(dy<30)return dy+'d';return d.toLocaleDateString('pt-BR');}
    function trunc(s,l){if(!s)return'Sem mensagens';if(s.length<=l)return s;return s.substring(0,l)+'...';}
    function fetchUnreadConversations(){return fetch(baseUrl+'/api/unread-conversations',{headers:{'X-Requested-With':'XMLHttpRequest'}}).then(function(r){return r.json()}).catch(function(){return{conversations:[],total_unread:0}});}
    function fetchNotifications(){return fetch(baseUrl+'/api/notifications/dropdown',{headers:{'X-Requested-With':'XMLHttpRequest'}}).then(function(r){return r.json()}).catch(function(){return{notifications:[],unread_count:0}});}
    function renderConvItem(c){
        var ci=channelIcons[c.channel_type]||'fas fa-comment-dots',cc=c.channel_type==='whatsapp'?'#25D366':c.channel_type==='webchat'?'#4361ee':c.channel_type==='email'?'#f59e0b':'#6c757d';
        var init=(c.contact_name||'?').charAt(0).toUpperCase();
        var av=c.contact_avatar?'<img class="notif-msg-avatar" src="'+(c.contact_avatar.indexOf('http')===0?'':baseUrl+'/uploads/')+esc(c.contact_avatar)+'" alt="">':'<div class="notif-msg-avatar">'+esc(init)+'</div>';
        return '<a href="'+baseUrl+'/inbox?conv='+c.id+'" class="notif-msg-item">'+av+'<div class="notif-msg-content"><div class="notif-msg-header"><span class="notif-msg-name">'+esc(c.contact_name||'Cliente')+'</span><span class="notif-msg-channel" style="color:'+cc+'"><i class="'+ci+'"></i></span><span class="notif-msg-time">'+timeAgo(c.last_message_at||c.created_at)+'</span></div><div class="notif-msg-preview">'+esc(trunc(c.last_message,100))+'</div>'+(c.unread_count>1?'<span class="notif-msg-count">'+c.unread_count+' mensagens</span>':'')+'</div></a>';
    }
    function renderNotifItem(n){
        var icon=typeIcons[n.notification_type]||'fa-bell';
        var link=n.conversation_id?baseUrl+'/inbox?conv='+n.conversation_id:'#';
        try{
            if(n.notification_type==='group_mention'&&n.metadata){
                var meta=typeof n.metadata==='string'?JSON.parse(n.metadata):n.metadata;
                // Prefere abrir a conversa na caixa (tem o histórico); sem
                // conversa, cai na página do grupo (lista de menções).
                if(meta&&(meta.conversation_id||n.conversation_id))link=baseUrl+'/inbox?conv='+(meta.conversation_id||n.conversation_id);
                else if(meta&&meta.group_id)link=baseUrl+'/whatsapp/groups/'+meta.group_id;
            }
        }catch(e){}
        return '<a href="'+link+'" class="notif-msg-item notif-struct"><div class="notif-msg-avatar notif-msg-icon"><i class="fa-solid '+icon+'"></i></div><div class="notif-msg-content"><div class="notif-msg-header"><span class="notif-msg-name">'+esc(n.title||'Notificação')+'</span><span class="notif-msg-time">'+timeAgo(n.created_at)+'</span></div>'+(n.body?'<div class="notif-msg-preview">'+esc(n.body)+'</div>':'')+'</div></a>';
    }
    function renderDropdown(){
        notifList.innerHTML='<div class="notif-loading"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>';
        var promises=[fetchUnreadConversations(), fetchNotifications()];
        Promise.all(promises).then(function(results){
            var convs=(results[0]&&results[0].conversations)||[];
            var notifs=(results[1]&&results[1].notifications)||[];
            var html='';
            if(notifs.length){
                html+='<div class="notif-section-label"><i class="fa-solid fa-bell"></i> Menções e atribuições</div>';
                notifs.forEach(function(n){ html+=renderNotifItem(n); });
            }
            if(convs.length){
                html+='<div class="notif-section-label"><i class="fas fa-comment-dots"></i> Conversas não lidas</div>';
                convs.forEach(function(c){ html+=renderConvItem(c); });
            }
            if(!html){
                html='<div class="notif-empty">Nenhuma notificação no momento</div>';
            }
            notifList.innerHTML=html;
        }).catch(function(){notifList.innerHTML='<div class="notif-empty">Erro ao carregar</div>';});
    }
    notifBtn.addEventListener('click',function(e){
        e.stopPropagation();var o=notifDropdown.classList.contains('open');
        document.querySelectorAll('.notif-dropdown.open').forEach(function(el){el.classList.remove('open');});
        if(!o){notifDropdown.classList.add('open');renderDropdown();}
    });
    if(markAllBtn)markAllBtn.addEventListener('click',function(){
        var fd=new FormData();fd.append('_csrf_token',document.querySelector('meta[name="csrf-token"]')?.content||'');
        fetch(baseUrl+'/api/messages/read-all',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd}).then(function(){
            notifList.innerHTML='<div class="notif-empty">Nenhuma notificação no momento</div>';
            if(window.__enhancements&&window.__enhancements.GlobalNotifier){
                window.__enhancements.GlobalNotifier.lastUnreadConversations=0;
                window.__enhancements.GlobalNotifier.lastUnreadNotifications=0;
                window.__enhancements.GlobalNotifier._updateBadge(0);
            }
        }).catch(function(){});
    });
    if(soundBtn){
        var refreshIcon=function(){
            var on=window.__enhancements&&window.__enhancements.SoundManager&&window.__enhancements.SoundManager.isEnabled();
            soundBtn.innerHTML=on?'<i class="fa-solid fa-volume-high"></i>':'<i class="fa-solid fa-volume-xmark"></i>';
            soundBtn.style.opacity=on?'1':'0.55';
        };
        refreshIcon();
        soundBtn.addEventListener('click',function(e){
            e.stopPropagation();
            var sm=window.__enhancements&&window.__enhancements.SoundManager;
            var gn=window.__enhancements&&window.__enhancements.GlobalNotifier;
            if(!sm)return;
            var willEnable=!sm.isEnabled();
            sm.toggle();
            if(gn){gn.savePreferences({sound_enabled:willEnable});}
            refreshIcon();
        });
    }
    document.addEventListener('click',function(){notifDropdown.classList.remove('open');});
    notifDropdown.addEventListener('click',function(e){e.stopPropagation();});
})();
(function(){
    // Barra de alerta WhatsApp: atualiza sozinha a cada 60s (a conexão
    // pode cair no meio da sessão, ex.: auto-disconnect do provedor).
    var baseUrl=document.querySelector('meta[name="base-url"]')?.content||'';
    function esc(s){var d=document.createElement('div');d.textContent=String(s==null?'':s);return d.innerHTML;}
    function renderWaBar(list, canManage){
        var old=document.getElementById('waAlertBar');
        if(!list.length){if(old)old.remove();return;}
        var names=list.map(function(c){return esc(c.channel);}).join(', ');
        var label=list.length>1?' ('+list.length+' canais)':'';
        var link=canManage?' <a href="'+baseUrl+'/channels" class="wa-alert-link">Reconectar <i class="fa-solid fa-arrow-right"></i></a>':'';
        var html='<i class="fa-brands fa-whatsapp"></i> <span><strong>WhatsApp desconectado'+label+':</strong> '
            +names+'. As respostas não estão chegando ao cliente.</span>'+link;
        if(old){old.innerHTML=html;return;}
        var bar=document.createElement('div');
        bar.className='wa-alert-bar';bar.id='waAlertBar';bar.setAttribute('role','alert');
        bar.innerHTML=html;
        var app=document.getElementById('app');
        if(app&&app.parentNode)app.parentNode.insertBefore(bar,app);
        else document.body.insertBefore(bar,document.body.firstChild);
    }
    function pollWa(){
        fetch(baseUrl+'/api/whatsapp-status',{headers:{'X-Requested-With':'XMLHttpRequest'}})
            .then(function(r){return r.json();})
            .then(function(d){renderWaBar(d.disconnected||[],!!d.can_manage);})
            .catch(function(){});
    }
    setInterval(pollWa,60000);
})();
(function(){
    var toggle=document.getElementById('sidebarToggle');
    var app=document.getElementById('app');
    var sidebar=document.querySelector('.sidebar');
    var overlay=document.getElementById('sidebarOverlay');
    function isMobile(){return window.innerWidth<=768;}
    function syncToggle(){
        if(!toggle||!app)return;
        var hidden=app.classList.contains('sidebar-hidden');
        toggle.setAttribute('aria-expanded',hidden?'false':'true');
        toggle.title=hidden?'Exibir menu':'Ocultar menu';
    }
    if(toggle&&sidebar&&overlay){
        // Migra estado antigo (collapsed = só ícones) para oculto total.
        // A logo agora vive na topbar, então ocultar o sidebar não remove a marca.
        try{
            var legacy=null;
            try{legacy=localStorage.getItem('af_sidebar_collapsed');}catch(e){}
            var hidden=localStorage.getItem('af_sidebar_hidden');
            if(hidden===null&&legacy==='1'){hidden='1';}
            if(hidden==='1'&&!isMobile())app?.classList.add('sidebar-hidden');
            // Limpa chave legada
            try{localStorage.removeItem('af_sidebar_collapsed');}catch(e){}
        }catch(e){}
        syncToggle();
        toggle.addEventListener('click',function(){
            if(isMobile()){
                sidebar.classList.toggle('open');overlay.classList.toggle('open');
                overlay.style.display=sidebar.classList.contains('open')?'block':'none';
            }else if(app){
                app.classList.toggle('sidebar-hidden');
                try{localStorage.setItem('af_sidebar_hidden',app.classList.contains('sidebar-hidden')?'1':'0');}catch(e){}
                syncToggle();
            }
        });
        overlay.addEventListener('click',function(){sidebar.classList.remove('open');overlay.classList.remove('open');overlay.style.display='none';});
        window.addEventListener('resize',function(){
            if(!isMobile()){sidebar.classList.remove('open');overlay.classList.remove('open');overlay.style.display='none';}
            syncToggle();
        });
    }
})();
(function(){
    var sidebarEl=document.querySelector('.sidebar');
    var overlayEl=document.getElementById('sidebarOverlay');
    if(!overlayEl)return;
    function closeSidebar(){sidebarEl?.classList.remove('open');overlayEl.style.display='none';overlayEl.classList.remove('open');}
    document.addEventListener('click',function(e){
        if(window.innerWidth<=768&&!e.target.closest('.sidebar')&&!e.target.closest('.topbar-toggle')&&!e.target.closest('.notif-btn')&&!e.target.closest('.notif-dropdown')){
            closeSidebar();
        }
    });
    function checkMobile(){if(window.innerWidth>768)closeSidebar();}
    window.addEventListener('resize',checkMobile);
})();
(function(){
    var input=document.getElementById('globalSearch');
    if(!input)return;
    var sidebar=document.querySelector('.sidebar');
    if(!sidebar)return;
    var items=Array.prototype.slice.call(sidebar.querySelectorAll('.nav-item'));
    var sections=Array.prototype.slice.call(sidebar.querySelectorAll('.nav-section'));
    var initialExpanded=sections.map(function(s){return s.classList.contains('expanded');});
    function normalize(s){
        return (s||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').trim();
    }
    var initialText=items.map(function(it){return normalize(it.textContent);});
    function applyFilter(){
        var q=normalize(input.value);
        items.forEach(function(it,i){
            var match=!q||initialText[i].indexOf(q)!==-1;
            it.style.display=match?'':'none';
            if(match&&q){
                var sec=it.closest('.nav-section');
                if(sec&&sec.querySelector('.nav-section-title'))sec.classList.add('expanded');
            }
        });
        sections.forEach(function(sec,si){
            if(!q){ if(initialExpanded[si])sec.classList.add('expanded'); return; }
            var visible=sec.querySelectorAll('.nav-item:not([style*="display: none"])').length;
            sec.style.display=visible?'':'none';
        });
        if(!q)sections.forEach(function(sec){sec.style.display='';});
    }
    input.addEventListener('input',applyFilter);
    input.addEventListener('keydown',function(e){
        if(e.key==='Escape'){input.value='';applyFilter();input.blur();}
        if(e.key==='Enter'){
            e.preventDefault();
            var first=items.find(function(it){return it.style.display!=='none';});
            if(first&&first.href)window.location.href=first.href;
        }
    });
    document.addEventListener('keydown',function(e){
        if((e.ctrlKey||e.metaKey)&&(e.key==='k'||e.key==='K')){
            e.preventDefault();input.focus();input.select();
        }
    });
})();
</script>
</body>
</html>
