// AtendeFlow - Vue 3 Enhanced Application

const { createApp, ref, reactive, computed, watch, onMounted, onUnmounted, nextTick, defineComponent, h, Transition, Teleport } = Vue;

// ============================
// 🚀 Utility Functions
// ============================
const utils = {
    esc(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; },
    nl2br(s) { return (s || '').replace(/\n/g, '<br>'); },
    csrf() { return document.querySelector('input[name=_csrf_token]')?.value || ''; },
    csrfForm() { const f = new FormData(); f.append('_csrf_token', this.csrf()); return f; },
    api(path) {
        const base = document.querySelector('meta[name="base-url"]')?.content || '';
        return base + '/api' + path;
    },
    post(url, body) {
        const fd = this.csrfForm();
        for (const k in (body || {})) fd.append(k, body[k]);
        return fetch(url, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd }).then(r => r.json()).catch(() => ({ success: false }));
    },
    get(url) {
        return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(r => r.json()).catch(() => []);
    },
    toast(msg, type = 'info') {
        window.__toast && window.__toast.add(msg, type);
    },
    timeAgo(dateStr) {
        if (!dateStr) return '';
        const d = new Date(dateStr.replace(' ', 'T'));
        if (isNaN(d)) return dateStr;
        const now = new Date();
        const diff = Math.floor((now - d) / 1000);
        if (diff < 60) return 'agora';
        if (diff < 3600) return `${Math.floor(diff / 60)}m`;
        if (diff < 86400) return `${Math.floor(diff / 3600)}h`;
        if (diff < 2592000) return `${Math.floor(diff / 86400)}d`;
        return d.toLocaleDateString('pt-BR');
    },
    formatDt(s) {
        if (!s) return '';
        const d = new Date(String(s).replace(' ', 'T'));
        if (isNaN(d)) return s;
        return d.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    }
};

// ============================
// 🚀 Toast System
// ============================
const ToastSystem = {
    install(app) {
        const toasts = ref([]);
        let id = 0;

        function add(msg, type = 'info', duration = 4000) {
            const toastId = ++id;
            toasts.value.push({ id: toastId, msg, type });
            setTimeout(() => remove(toastId), duration);
        }

        function remove(id) {
            const idx = toasts.value.findIndex(t => t.id === id);
            if (idx >= 0) toasts.value.splice(idx, 1);
        }

        window.__toast = { add, remove };

        app.component('ToastContainer', {
            setup() { return { toasts }; },
            template: `
                <Teleport to="body">
                    <div class="toast-container">
                        <TransitionGroup name="slide-fade">
                            <div v-for="t in toasts" :key="t.id" :class="'toast-el toast-' + t.type">
                                <i :class="t.type === 'success' ? 'fas fa-check-circle' : t.type === 'error' ? 'fas fa-exclamation-circle' : t.type === 'warning' ? 'fas fa-exclamation-triangle' : 'fas fa-info-circle'"></i>
                                <span>{{ t.msg }}</span>
                                <button class="toast-close" @click="remove(t.id)">&times;</button>
                            </div>
                        </TransitionGroup>
                    </div>
                </Teleport>
            `,
            methods: { remove }
        });

        app.config.globalProperties.$toast = add;
    }
};

// ============================
// 🚀 Sidebar Component
// ============================
const SidebarComponent = {
    setup() {
        const isOpen = ref(false);
        const inboxCount = ref(0);

        function toggle() { isOpen.value = !isOpen.value; }
        function close() { isOpen.value = false; }

        function pollCounts() {
            fetch(utils.api('/conversations?status=new,open&count=1'))
                .then(r => r.json())
                .then(data => { inboxCount.value = data.total || 0; })
                .catch(() => {});
        }

        let pollTimer;
        onMounted(() => {
            pollCounts();
            pollTimer = setInterval(pollCounts, 15000);
        });
        onUnmounted(() => { if (pollTimer) clearInterval(pollTimer); });

        return { isOpen, inboxCount, toggle, close };
    },
    template: `
        <nav class="sidebar" :class="{ open: isOpen }" id="sidebar">
            <div class="sidebar-inner">
                <div class="nav-section">
                    <span class="nav-section-title">Menu</span>
                    <a href="/" class="sidebar-btn" :class="{ active: pathname === '/' || pathname === '/dashboard' }">
                        <i class="fa-solid fa-th-large"></i>
                        <span>Dashboard</span>
                    </a>
                    <a href="/inbox" class="sidebar-btn" :class="{ active: pathname.startsWith('/inbox') }">
                        <i class="fa-solid fa-inbox"></i>
                        <span>Caixa de Entrada</span>
                        <span class="nav-badge" v-if="inboxCount > 0">{{ inboxCount > 99 ? '99+' : inboxCount }}</span>
                    </a>
                </div>
                <div class="nav-section">
                    <span class="nav-section-title">Gestão</span>
                    <a href="/contacts" class="sidebar-btn" :class="{ active: pathname === '/contacts' || pathname.startsWith('/contacts/') }">
                        <i class="fa-solid fa-address-book"></i>
                        <span>Contatos</span>
                    </a>
                    <a href="/departments" class="sidebar-btn" :class="{ active: pathname.startsWith('/departments') }">
                        <i class="fa-solid fa-layer-group"></i>
                        <span>Departamentos</span>
                    </a>
                    <a href="/flows" class="sidebar-btn" :class="{ active: pathname.startsWith('/flows') }">
                        <i class="fa-solid fa-diagram-project"></i>
                        <span>Fluxos</span>
                    </a>
                    <a href="/library" class="sidebar-btn" :class="{ active: pathname === '/library' }">
                        <i class="fa-solid fa-book"></i>
                        <span>Tags e Respostas</span>
                    </a>
                    <a href="/reports/csat" class="sidebar-btn" :class="{ active: pathname === '/reports/csat' }">
                        <i class="fa-solid fa-chart-bar"></i>
                        <span>Relatório CSAT</span>
                    </a>
                </div>
                <div class="nav-section" v-if="isAdmin">
                    <span class="nav-section-title">Administração</span>
                    <a href="/users" class="sidebar-btn" :class="{ active: pathname.startsWith('/users') }">
                        <i class="fa-solid fa-users-cog"></i>
                        <span>Usuários</span>
                    </a>
                    <a href="/channels" class="sidebar-btn" :class="{ active: pathname === '/channels' }">
                        <i class="fa-solid fa-plug"></i>
                        <span>Canais</span>
                    </a>
                    <a href="/inboxes" class="sidebar-btn" :class="{ active: pathname === '/inboxes' }">
                        <i class="fa-solid fa-inbox"></i>
                        <span>Caixas de Entrada</span>
                    </a>
                    <a href="/settings" class="sidebar-btn" :class="{ active: pathname === '/settings' }">
                        <i class="fa-solid fa-cog"></i>
                        <span>Configurações</span>
                    </a>
                </div>
            </div>
            <div class="sidebar-footer">
                <form method="post" action="/logout" style="margin:0;">
                    <input type="hidden" name="_csrf_token" :value="csrf">
                    <button type="submit" class="sidebar-btn sidebar-logout">
                        <i class="fa-solid fa-sign-out-alt"></i>
                        <span>Sair</span>
                    </button>
                </form>
            </div>
        </nav>
    `,
    data() {
        return {
            pathname: window.location.pathname,
            csrf: utils.csrf(),
            isAdmin: !!document.querySelector('.nav-section-title:contains("Administração")') || window.__isAdmin
        };
    }
};

// ============================
// 🚀 Global Search Component
// ============================
const GlobalSearchComponent = {
    setup() {
        const query = ref('');
        const results = ref([]);
        const loading = ref(false);
        const show = ref(false);
        const activeIdx = ref(-1);
        let timer;

        function doSearch() {
            const q = query.value.trim();
            if (q.length < 2) { results.value = []; show.value = false; return; }
            loading.value = true;
            fetch(utils.api('/contacts/search?q=' + encodeURIComponent(q)))
                .then(r => r.json())
                .then(data => {
                    results.value = data || [];
                    show.value = true;
                    activeIdx.value = -1;
                })
                .catch(() => { results.value = []; })
                .finally(() => loading.value = false);
        }

        function onInput() {
            clearTimeout(timer);
            timer = setTimeout(doSearch, 300);
        }

        function onKeydown(e) {
            if (!show.value || !results.value.length) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); activeIdx.value = Math.min(activeIdx.value + 1, results.value.length - 1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); activeIdx.value = Math.max(activeIdx.value - 1, 0); }
            else if (e.key === 'Enter' && activeIdx.value >= 0) { e.preventDefault(); window.location = '/contacts/' + results.value[activeIdx.value].id; }
            else if (e.key === 'Escape') { close(); }
        }

        function close() { show.value = false; results.value = []; activeIdx.value = -1; }
        function selectContact(id) { window.location = '/contacts/' + id; }
        function onBlur() { setTimeout(close, 200); }

        return { query, results, loading, show, activeIdx, onInput, onKeydown, close, selectContact, onBlur };
    },
    template: `
        <div class="search-bar" style="position:relative">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" v-model="query" @input="onInput" @keydown="onKeydown" @blur="onBlur" @focus="doSearch"
                   placeholder="Buscar contatos, conversas..." autocomplete="off" aria-label="Buscar">
            <Transition name="scale">
                <div class="search-results" v-if="show" style="display:block;position:absolute;top:calc(100% + 8px);left:0;right:0;background:#fff;border:1px solid #e4e7ec;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,0.12);max-height:360px;overflow-y:auto;z-index:200">
                    <div v-if="!results.length" class="sr-empty" style="padding:16px;text-align:center;color:#667085;font-size:13px">Nenhum contato encontrado.</div>
                    <a v-for="(c, i) in results" :key="c.id" :class="'sr-item ' + (i === activeIdx ? 'active' : '')"
                       @mousedown.prevent="selectContact(c.id)" style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid #f2f4f7;cursor:pointer;color:#1d2939;text-decoration:none;transition:all 0.15s">
                        <div class="sr-avatar" style="width:32px;height:32px;border-radius:50%;background:#4A90D9;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:13px;flex-shrink:0">
                            {{ (c.name || '?').charAt(0).toUpperCase() }}
                        </div>
                        <div class="sr-info" style="min-width:0">
                            <div class="sr-name" style="font-weight:600;font-size:13px">{{ c.name }}</div>
                            <div class="sr-meta" style="font-size:12px;color:#667085;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ c.email || c.phone || c.company || '' }}</div>
                        </div>
                    </a>
                </div>
            </Transition>
        </div>
    `
};

// ============================
// 🚀 Header Component
// ============================
const HeaderComponent = {
    setup() {
        const darkMode = ref(localStorage.getItem('atendeflow-dark') === 'true');

        watch(darkMode, (val) => {
            document.body.classList.toggle('dark-mode', val);
            localStorage.setItem('atendeflow-dark', val);
        });

        onMounted(() => {
            if (darkMode.value) document.body.classList.add('dark-mode');
        });

        function toggleDark() { darkMode.value = !darkMode.value; }

        const userName = document.querySelector('meta[name="user-name"]')?.content || 'Usuário';
        const userRole = document.querySelector('meta[name="user-role"]')?.content || '';
        const userInitial = (userName || 'U').charAt(0).toUpperCase();

        return { darkMode, toggleDark, userName, userRole, userInitial };
    },
    template: `
        <header class="app-header">
            <div class="brand">
                <i class="fa-solid fa-headset"></i>
                <span>AtendeFlow</span>
            </div>
            <GlobalSearchComponent />
            <div class="header-actions">
                <button class="dark-mode-toggle" @click="toggleDark" :title="darkMode ? 'Modo Claro' : 'Modo Escuro'">
                    <i :class="darkMode ? 'fas fa-sun' : 'fas fa-moon'"></i>
                </button>
                <button class="icon-btn" title="Atalhos do teclado" @click="$emit('show-shortcuts')">
                    <i class="fas fa-keyboard"></i>
                </button>
                <div class="user-profile-header" style="display:flex;align-items:center;gap:10px;font-size:14px;padding:4px 12px 4px 4px;border-radius:8px;transition:all 0.2s;cursor:pointer">
                    <div class="avatar-sm" style="width:36px;height:36px;border-radius:50%;background:#4A90D9;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;flex-shrink:0">
                        {{ userInitial }}
                    </div>
                    <div class="info">
                        <strong>{{ userName }}</strong>
                        <span style="display:block;font-size:12px;color:#98a2b3">{{ userRole }}</span>
                    </div>
                </div>
                <button class="icon-btn" @click="toggleDark" style="display:none">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
        </header>
    `,
    emits: ['show-shortcuts']
};

// ============================
// 🚀 Keyboard Shortcuts Modal
// ============================
const ShortcutsModal = {
    props: ['show'],
    emits: ['close'],
    setup(props, { emit }) {
        const shortcuts = [
            { key: '?', desc: 'Abrir ajuda de atalhos' },
            { key: 'I', desc: 'Alternar nota interna' },
            { key: 'A', desc: 'Atribuir conversa a mim' },
            { key: 'R', desc: 'Alterar status' },
            { key: 'C', desc: 'Abrir respostas prontas' },
            { key: 'M', desc: 'Abrir macros' },
            { key: 'S', desc: 'Agendar / Snooze' },
            { key: 'Enter', desc: 'Enviar mensagem' },
            { key: 'Shift+Enter', desc: 'Nova linha' },
            { key: 'Esc', desc: 'Fechar modal / pesquisa' },
            { key: '/', desc: 'Buscar resposta pronta' },
            { key: ':', desc: 'Abrir macros (campo vazio)' },
        ];

        function onKeydown(e) {
            if (e.key === 'Escape') emit('close');
        }

        onMounted(() => document.addEventListener('keydown', onKeydown));
        onUnmounted(() => document.removeEventListener('keydown', onKeydown));

        return { shortcuts };
    },
    template: `
        <Teleport to="body">
            <Transition name="fade">
                <div class="modal" v-if="show" style="display:flex;align-items:center;justify-content:center;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999" @click.self="$emit('close')">
                    <div class="modal-content" style="max-width:520px;width:100%;background:var(--bg-card);border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,0.12);overflow:hidden">
                        <div class="modal-header" style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--border-color)">
                            <h3 style="font-size:16px;margin:0;display:flex;align-items:center;gap:8px"><i class="fas fa-keyboard"></i> Atalhos do Teclado</h3>
                            <button class="modal-close" @click="$emit('close')" style="background:none;border:none;font-size:24px;cursor:pointer;color:var(--text-muted);line-height:1">&times;</button>
                        </div>
                        <div class="modal-body" style="padding:20px">
                            <div class="shortcuts-grid">
                                <div class="shortcut-row" v-for="s in shortcuts" :key="s.key">
                                    <span class="shortcut-key">{{ s.key }}</span>
                                    <span class="shortcut-desc">{{ s.desc }}</span>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer" style="padding:12px 20px;border-top:1px solid var(--border-color);text-align:center">
                            <button class="btn btn-sm btn-outline" @click="$emit('close')">Fechar</button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    `
};

// ============================
// 🚀 Dashboard App
// ============================
const DashboardApp = {
    setup() {
        const stats = reactive({
            myOpen: 0,
            waiting: 0,
            resolved: 0,
            online: 0,
            totalUsers: 0,
            totalDepts: 0,
            totalAll: 0
        });
        const statusCounts = reactive({ new: 0, open: 0, waiting_customer: 0, waiting_internal: 0 });
        const myConversations = ref([]);
        const loading = ref(true);

        const statusLabels = { new: 'Novos', open: 'Abertos', waiting_customer: 'Em atendimento', waiting_internal: 'Agu. Interno' };
        const statusColors = { new: '#3b82f6', open: '#4A90D9', waiting_customer: '#f59e0b', waiting_internal: '#6c757d' };

        function fetchData() {
            fetch(utils.api('/conversations?status=new,open,waiting_customer,waiting_internal,resolved'))
                .then(r => r.json())
                .then(data => {
                    if (!data) return;
                    stats.myOpen = data.myOpen || data.total || 0;
                    stats.waiting = data.waiting_customer || 0;
                    stats.resolved = data.resolved || 0;
                    if (data.counts) Object.assign(statusCounts, data.counts);
                })
                .catch(() => {})
                .finally(() => loading.value = false);
        }

        let timer;
        onMounted(() => {
            fetchData();
            timer = setInterval(fetchData, 20000);
        });
        onUnmounted(() => { if (timer) clearInterval(timer); });

        const totalOpen = computed(() => statusCounts.new + statusCounts.open + statusCounts.waiting_customer + statusCounts.waiting_internal);

        return { stats, statusCounts, myConversations, loading, statusLabels, statusColors, totalOpen };
    },
    template: `
        <div class="dashboard">
            <div class="stats-grid">
                <div v-if="loading" v-for="n in 7" :key="n" class="stat-skeleton">
                    <div class="stat-skeleton-icon skeleton"></div>
                    <div class="stat-skeleton-lines"><div class="skeleton skeleton-text w-40"></div><div class="skeleton skeleton-text w-60"></div></div>
                </div>
                <template v-if="!loading">
                    <div class="stat-card fade-enter-active">
                        <div class="stat-icon stat-icon-primary"><i class="fas fa-comments"></i></div>
                        <div class="stat-info"><span class="stat-value">{{ stats.myOpen }}</span><span class="stat-label">Meus Atendimentos</span></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon stat-icon-warning"><i class="fas fa-clock"></i></div>
                        <div class="stat-info"><span class="stat-value">{{ stats.waiting }}</span><span class="stat-label">Em atendimento</span></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon stat-icon-success"><i class="fas fa-check-circle"></i></div>
                        <div class="stat-info"><span class="stat-value">{{ stats.resolved }}</span><span class="stat-label">Resolvidos</span></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon stat-icon-info"><i class="fas fa-user-check"></i></div>
                        <div class="stat-info"><span class="stat-value">{{ stats.online }}</span><span class="stat-label">Atendentes Online</span></div>
                    </div>
                </template>
            </div>

            <div class="dashboard-grid">
                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-bell"></i> Meus Atendimentos Recentes</h3><a href="/inbox/mine" class="btn btn-sm btn-outline">Ver todos</a></div>
                    <div class="card-body p-0">
                        <div v-if="loading">
                            <div v-for="n in 4" :key="n" class="conv-skeleton">
                                <div class="skeleton skeleton-avatar"></div>
                                <div class="conv-skeleton-content"><div class="skeleton skeleton-text"></div><div class="skeleton skeleton-text w-60"></div></div>
                            </div>
                        </div>
                        <div v-else-if="!myConversations.length" class="empty-state"><i class="fas fa-inbox"></i><p>Nenhum atendimento no momento.</p></div>
                        <div v-else class="conversation-list">
                            <a v-for="conv in myConversations.slice(0, 8)" :key="conv.id" :href="'/inbox/' + conv.id" class="conversation-item">
                                <div class="conv-avatar"><i class="fas fa-user-circle fa-2x"></i></div>
                                <div class="conv-info">
                                    <div class="conv-header"><span class="conv-name">{{ conv.contact_name }}</span><span class="conv-status" v-html="statusBadge(conv.status)"></span></div>
                                    <div class="conv-meta"><span class="conv-channel"><i :class="channelIcon(conv.channel_type)"></i></span><span class="conv-time">{{ utils.timeAgo(conv.last_message_at || conv.created_at) }}</span></div>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-chart-pie"></i> Status dos Atendimentos</h3></div>
                    <div class="card-body">
                        <div v-if="loading">
                            <div v-for="n in 4" :key="n" class="skeleton skeleton-text" style="margin-bottom:16px"></div>
                        </div>
                        <div v-else class="status-chart">
                            <div class="status-bar-item" v-for="(count, key) in statusCounts" :key="key">
                                <span class="status-label">{{ statusLabels[key] }}</span>
                                <div class="status-bar-track">
                                    <div class="status-bar-fill" :style="{ width: totalOpen > 0 ? (count / totalOpen * 100) + '%' : '0%', background: statusColors[key] }"></div>
                                </div>
                                <span class="status-count">{{ count }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `,
    methods: {
        statusBadge(s) {
            const map = { new: 'Novo', open: 'Aberto', waiting_customer: 'Em atendimento', waiting_internal: 'Agu. Interno', resolved: 'Resolvido', closed: 'Fechado', spam: 'Spam' };
            return '<span class="badge badge-' + (s === 'new' ? 'info' : s === 'open' ? 'primary' : s === 'waiting_customer' ? 'warning' : s === 'resolved' ? 'success' : 'secondary') + '">' + (map[s] || s) + '</span>';
        },
        channelIcon(type) {
            const icons = { whatsapp: 'fab fa-whatsapp', webchat: 'fas fa-comment-dots', email: 'fas fa-envelope', telegram: 'fab fa-telegram', facebook: 'fab fa-facebook-messenger', instagram: 'fab fa-instagram', phone: 'fas fa-phone' };
            return icons[type] || 'fas fa-comment';
        }
    }
};

// ============================
// 🚀 Dashboard Init (if dashboard page)
// ============================
function initDashboard() {
    const el = document.querySelector('.dashboard');
    if (!el) return;
    const app = createApp(DashboardApp);
    app.use(ToastSystem);
    app.mount(el);
}

// ============================
// 🚀 Initialize based on page
// ============================
document.addEventListener('DOMContentLoaded', function() {
    // Mount Toast system
    const toastEl = document.getElementById('vue-toasts');
    if (toastEl) {
        const toastApp = createApp({
            template: '<ToastContainer />'
        });
        toastApp.use(ToastSystem);
        toastApp.mount(toastEl);
    }

    // Mount Search component
    const searchEl = document.getElementById('vue-search');
    if (searchEl) {
        const searchApp = createApp(GlobalSearchComponent);
        searchApp.mount(searchEl);
    }

    // Init Dashboard (if on dashboard page)
    initDashboard();

    // Flash auto-dismiss
    document.querySelectorAll('.flash').forEach(function(el) {
        setTimeout(function() { el.style.opacity = '0'; setTimeout(() => el.remove(), 300); }, 5000);
    });

    // Auto-resize textareas
    document.addEventListener('input', function(e) {
        if (e.target.tagName === 'TEXTAREA') {
            e.target.style.height = 'auto';
            e.target.style.height = e.target.scrollHeight + 'px';
        }
    });

    // Form submit guard (prevent double)
    document.addEventListener('submit', function(e) {
        const btn = e.target.querySelector('button[type="submit"]:not([disabled])');
        if (btn && !btn.classList.contains('is-loading')) {
            btn.classList.add('is-loading');
            btn.disabled = true;
        }
    });

    // Ripple effect on buttons
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.btn:not(.is-loading)');
        if (!btn) return;
        const ripple = document.createElement('span');
        const rect = btn.getBoundingClientRect();
        const size = Math.max(rect.width, rect.height);
        ripple.style.cssText = 'position:absolute;border-radius:50%;background:rgba(255,255,255,0.3);width:' + size + 'px;height:' + size + 'px;left:' + (e.clientX - rect.left - size/2) + 'px;top:' + (e.clientY - rect.top - size/2) + 'px;transform:scale(0);animation:rippleAnim 0.6s ease-out;pointer-events:none';
        btn.style.position = 'relative';
        btn.style.overflow = 'hidden';
        btn.appendChild(ripple);
        setTimeout(() => ripple.remove(), 600);
    });
});

// Export for use in inline scripts
window.utils = utils;
