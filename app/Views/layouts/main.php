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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
</head>
<body>
    <div class="app-container" id="app">
        <!-- Header -->
        <header class="app-header">
            <div class="brand">
                <i class="fa-solid fa-headset"></i>
                <span>AtendeFlow</span>
            </div>
            <div class="header-actions">
                <button class="icon-btn" id="sidebarToggle" title="Abrir menu">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="notif-wrapper">
                    <div class="notif-btn" id="notifBtn" title="Notificações">
                        <i class="fa-regular fa-bell"></i>
                        <span class="notif-badge" id="notifBadge" style="display:none">0</span>
                    </div>
                    <div class="notif-dropdown" id="notifDropdown">
                        <div class="notif-header">
                            <strong>Notificações</strong>
                            <button class="notif-mark-read" id="notifMarkAllRead">Marcar todas como lidas</button>
                        </div>
                        <div class="notif-list" id="notifList"></div>
                    </div>
                </div>
                <a href="<?= route('profile') ?>" class="user-profile-header">
                    <div class="avatar-sm">
                        <?= mb_strtoupper(mb_substr(\App\Core\Session::get('user_name') ?? 'U', 0, 1)) ?>
                    </div>
                    <div class="info">
                        <strong><?= e(\App\Core\Session::get('user_name')) ?></strong>
                        <span><?= ucfirst(\App\Core\Session::get('user_role')) ?></span>
                    </div>
                </a>
            </div>
        </header>

        <div class="app-body">
            <!-- Sidebar -->
            <nav class="sidebar" id="sidebar">
                <div class="sidebar-inner">
                    <div class="nav-section">
                        <span class="nav-section-title">Menu</span>
                        <a href="<?= route('dashboard') ?>" class="sidebar-btn <?= $activePage === 'dashboard' ? 'active' : '' ?>">
                            <i class="fa-solid fa-th-large"></i>
                            <span>Dashboard</span>
                        </a>
                        <?php
                        $catInboxes = [];
                        foreach (\App\Models\Inbox::getUserInboxes(\App\Core\Auth::id()) as $ib) {
                            if ($ib['type'] !== 'personal') {
                                $catInboxes[] = $ib;
                            }
                        }
                        $personalInboxes = \App\Models\Inbox::getOwnedPersonalInboxes(\App\Core\Auth::id());
                        $allInboxIds = array_merge(array_column($catInboxes, 'id'), array_column($personalInboxes, 'id'));
                        $openByInbox = \App\Models\Conversation::openCountsByInbox($allInboxIds);
                        $totalOpen = 0;
                        foreach ($catInboxes as $ib) {
                            $totalOpen += $openByInbox[$ib['id']] ?? 0;
                        }
                        ?>
                        <a href="<?= route('inbox') ?>" class="sidebar-btn <?= in_array($activePage ?? '', ['inbox']) ? 'active' : '' ?>">
                            <i class="fa-solid fa-inbox"></i>
                            <span>Caixa de Entrada</span>
                            <?php if ($totalOpen > 0): ?>
                                <span class="nav-badge"><?= $totalOpen ?></span>
                            <?php endif; ?>
                        </a>
                        <?php foreach ($catInboxes as $ib): ?>
                            <a href="<?= url('inbox?inbox=' . $ib['id']) ?>" class="sidebar-btn sidebar-sub <?= (($_GET['inbox'] ?? '') == $ib['id']) ? 'active' : '' ?>">
                                <i class="fa-solid fa-inbox"></i>
                                <span><?= e($ib['name']) ?></span>
                                <?php if (($openByInbox[$ib['id']] ?? 0) > 0): ?>
                                    <span class="nav-badge"><?= $openByInbox[$ib['id']] ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                        <a href="<?= route('inbox.mine') ?>" class="sidebar-btn">
                            <i class="fa-solid fa-user"></i>
                            <span>Minha Caixa</span>
                        </a>
                        <?php foreach ($personalInboxes as $ib): ?>
                            <a href="<?= url('inbox?inbox=' . $ib['id']) ?>" class="sidebar-btn sidebar-sub <?= (($_GET['inbox'] ?? '') == $ib['id']) ? 'active' : '' ?>">
                                <i class="fa-solid fa-lock"></i>
                                <span><?= e($ib['name']) ?></span>
                                <?php if (($openByInbox[$ib['id']] ?? 0) > 0): ?>
                                    <span class="nav-badge"><?= $openByInbox[$ib['id']] ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <div class="nav-section">
                        <span class="nav-section-title">Gestão</span>
                        <a href="<?= route('contacts') ?>" class="sidebar-btn <?= $activePage === 'contacts' ? 'active' : '' ?>">
                            <i class="fa-solid fa-address-book"></i>
                            <span>Contatos</span>
                        </a>
                        <a href="<?= route('departments') ?>" class="sidebar-btn <?= $activePage === 'departments' ? 'active' : '' ?>">
                            <i class="fa-solid fa-layer-group"></i>
                            <span>Departamentos</span>
                        </a>
                        <a href="<?= route('flows') ?>" class="sidebar-btn <?= $activePage === 'flows' ? 'active' : '' ?>">
                            <i class="fa-solid fa-diagram-project"></i>
                            <span>Fluxos</span>
                        </a>
                        <a href="<?= url('library') ?>" class="sidebar-btn <?= ($activePage ?? '') === 'library' ? 'active' : '' ?>">
                            <i class="fa-solid fa-book"></i>
                            <span>Tags e Respostas</span>
                        </a>
                    </div>
                    <div class="nav-section">
                        <span class="nav-section-title">Relatórios</span>
                        <a href="<?= url('reports') ?>" class="sidebar-btn <?= ($activePage ?? '') === 'reports' ? 'active' : '' ?>">
                            <i class="fa-solid fa-chart-pie"></i>
                            <span>Visão Geral</span>
                        </a>
                        <a href="<?= url('reports/conversations') ?>" class="sidebar-btn <?= ($activePage ?? '') === 'reports_conversations' ? 'active' : '' ?>">
                            <i class="fa-solid fa-comments"></i>
                            <span>Conversas</span>
                        </a>
                        <a href="<?= url('reports/agents') ?>" class="sidebar-btn <?= ($activePage ?? '') === 'reports_agents' ? 'active' : '' ?>">
                            <i class="fa-solid fa-users"></i>
                            <span>Atendentes</span>
                        </a>
                        <a href="<?= url('reports/csat') ?>" class="sidebar-btn <?= ($activePage ?? '') === 'reports_csat' ? 'active' : '' ?>">
                            <i class="fa-solid fa-star"></i>
                            <span>Satisfação (CSAT)</span>
                        </a>
                    </div>
                    <?php if (\App\Core\Auth::isAdmin()): ?>
                    <div class="nav-section">
                        <span class="nav-section-title">Administração</span>
                        <a href="<?= route('users') ?>" class="sidebar-btn <?= $activePage === 'users' ? 'active' : '' ?>">
                            <i class="fa-solid fa-users-cog"></i>
                            <span>Usuários</span>
                        </a>
                        <a href="<?= url('channels') ?>" class="sidebar-btn <?= ($activePage ?? '') === 'channels' ? 'active' : '' ?>">
                            <i class="fa-solid fa-plug"></i>
                            <span>Canais</span>
                        </a>
                        <a href="<?= url('inboxes') ?>" class="sidebar-btn <?= ($activePage ?? '') === 'inboxes' ? 'active' : '' ?>">
                            <i class="fa-solid fa-inbox"></i>
                            <span>Caixas de Entrada</span>
                        </a>
                        <a href="<?= url('settings') ?>" class="sidebar-btn <?= ($activePage ?? '') === 'settings' ? 'active' : '' ?>">
                            <i class="fa-solid fa-cog"></i>
                            <span>Configurações</span>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="sidebar-footer">
                    <form method="post" action="<?= url('logout') ?>" style="margin:0;">
                        <?= csrf_field() ?>
                        <button type="submit" class="sidebar-btn sidebar-logout">
                            <i class="fa-solid fa-sign-out-alt"></i>
                            <span>Sair</span>
                        </button>
                    </form>
                </div>
            </nav>

            <!-- Main Content -->
            <main class="main-content">
                <?php $flashSuccess = \App\Core\Session::getFlash('success'); ?>
                <?php $flashError = \App\Core\Session::getFlash('error'); ?>
                <?php if ($flashSuccess || $flashError): ?>
                    <div class="flash-container">
                        <?php if ($flashSuccess): ?>
                            <div class="flash flash-success">
                                <i class="fa-solid fa-check-circle"></i>
                                <span><?= e($flashSuccess) ?></span>
                                <button class="flash-close"><i class="fa-solid fa-times"></i></button>
                            </div>
                        <?php endif; ?>
                        <?php if ($flashError): ?>
                            <div class="flash flash-error">
                                <i class="fa-solid fa-exclamation-circle"></i>
                                <span><?= e($flashError) ?></span>
                                <button class="flash-close"><i class="fa-solid fa-times"></i></button>
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
        </div>
        <!-- Sidebar overlay - mobile/tablet only -->
        <div class="sidebar-overlay" id="sidebarOverlay" style="display:none; opacity:0; transition:opacity 0.3s;"></div>

        <div id="vue-toasts"></div>

        <div class="top-progress" id="topProgress"></div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
    <script src="<?= asset('assets/js/app.js') ?>"></script>
    <script src="<?= asset('assets/js/app-enhancements.js') ?>"></script>
    <script>
    // Sidebar toggle (fixed for both mobile and desktop)
    (function() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const toggleBtn = document.getElementById('sidebarToggle');
        
        if (toggleBtn && sidebar) {
            toggleBtn.addEventListener('click', function(e) {
                if (window.innerWidth <= 768) {
                    sidebar.classList.toggle('open');
                    overlay.classList.toggle('open');
                    if (overlay) overlay.style.display = 'block';
                } else {
                    sidebar.classList.toggle('sidebar-collapsed');
                }
            });
        }
        
        if (overlay) {
            overlay.addEventListener('click', function() {
                sidebar.classList.remove('open');
                overlay.classList.remove('open');
            });
            overlay.style.display = 'none';
        }
        
        let resizeTimeout;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(function() {
                if (window.innerWidth > 768) {
                    sidebar.classList.remove('open');
                    if (overlay) overlay.classList.remove('open');
                }
            }, 250);
        });
    })();

    // Top progress bar
    (function() {
        const bar = document.getElementById('topProgress');
        if (!bar) return;
        let timer;
        document.addEventListener('submit', function() {
            bar.classList.add('active');
            clearTimeout(timer);
        });
        window.addEventListener('beforeunload', function() {
            bar.classList.remove('active');
            bar.classList.add('done');
            timer = setTimeout(function() { bar.classList.remove('done'); }, 500);
        });
    })();

    // Notifications system
    (function() {
        const notifBtn = document.getElementById('notifBtn');
        const notifDropdown = document.getElementById('notifDropdown');
        const notifBadge = document.getElementById('notifBadge');
        const notifList = document.getElementById('notifList');
        const markAllBtn = document.getElementById('notifMarkAllRead');
        if (!notifBtn) return;

        const baseUrl = document.querySelector('meta[name="base-url"]')?.content || '';

        function fetchUnreadCount() {
            fetch(baseUrl + '/notifications/unread-count')
                .then(r => r.json())
                .then(data => {
                    const count = data.count || 0;
                    if (count > 0) {
                        notifBadge.textContent = count > 99 ? '99+' : count;
                        notifBadge.style.display = '';
                    } else {
                        notifBadge.style.display = 'none';
                    }
                })
                .catch(() => {});
        }

        function fetchNotifications() {
            fetch(baseUrl + '/notifications?limit=20')
                .then(r => r.json())
                .then(data => {
                    const notifications = data.notifications || [];
                    notifList.innerHTML = '';
                    if (notifications.length === 0) {
                        notifList.innerHTML = '<div class="notif-empty">Nenhuma notificação</div>';
                        return;
                    }
                    notifications.forEach(function(n) {
                        const item = document.createElement('div');
                        item.className = 'notif-item' + (n.is_read ? '' : ' notif-unread');
                        item.dataset.id = n.id;
                        item.innerHTML = [
                            '<div class="notif-icon"><i class="fa-solid fa-',
                            n.notification_type === 'mention' ? 'at' : 'user-plus',
                            '"></i></div>',
                            '<div class="notif-content">',
                            '<div class="notif-title">', utils.esc(n.title || ''), '</div>',
                            '<div class="notif-body">', utils.esc(n.body || ''), '</div>',
                            '<div class="notif-time">', utils.timeAgo(n.created_at), '</div>',
                            '</div>',
                            n.is_read ? '' : '<button class="notif-mark-one" data-id="' + n.id + '"><i class="fa-solid fa-check"></i></button>'
                        ].join('');
                        item.addEventListener('click', function(e) {
                            if (e.target.closest('.notif-mark-one')) return;
                            if (n.conversation_id) {
                                window.location = baseUrl + '/inbox/' + n.conversation_id;
                            }
                        });
                        const markOne = item.querySelector('.notif-mark-one');
                        if (markOne) {
                            markOne.addEventListener('click', function(e) {
                                e.stopPropagation();
                                fetch(baseUrl + '/notifications/' + n.id + '/read', {
                                    method: 'POST',
                                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                                    body: new URLSearchParams({ _csrf_token: utils.csrf() })
                                }).then(function() {
                                    item.classList.remove('notif-unread');
                                    markOne.remove();
                                    fetchUnreadCount();
                                }).catch(function() {});
                            });
                        }
                        notifList.appendChild(item);
                    });
                })
                .catch(function() {
                    notifList.innerHTML = '<div class="notif-empty">Erro ao carregar notificações</div>';
                });
        }

        notifBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            const isOpen = notifDropdown.classList.contains('open');
            document.querySelectorAll('.notif-dropdown.open').forEach(function(el) { el.classList.remove('open'); });
            if (!isOpen) {
                notifDropdown.classList.add('open');
                if (!notifDropdown.dataset.loaded) {
                    fetchNotifications();
                    notifDropdown.dataset.loaded = '1';
                }
            }
        });

        if (markAllBtn) {
            markAllBtn.addEventListener('click', function() {
                fetch(baseUrl + '/notifications/read-all', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: new URLSearchParams({ _csrf_token: utils.csrf() })
                }).then(function() {
                    notifList.querySelectorAll('.notif-item').forEach(function(item) {
                        item.classList.remove('notif-unread');
                        var mark = item.querySelector('.notif-mark-one');
                        if (mark) mark.remove();
                    });
                    fetchUnreadCount();
                }).catch(function() {});
            });
        }

        document.addEventListener('click', function() {
            notifDropdown.classList.remove('open');
        });
        notifDropdown.addEventListener('click', function(e) {
            e.stopPropagation();
        });

        fetchUnreadCount();
        setInterval(fetchUnreadCount, 30000);
    })();
    </script>
</body>
</html>
