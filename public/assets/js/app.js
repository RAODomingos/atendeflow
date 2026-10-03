// OminiDesk - Application Scripts

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
        if (diff < 3600) return Math.floor(diff / 60) + 'm';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h';
        if (diff < 2592000) return Math.floor(diff / 86400) + 'd';
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
// 🚀 Toast System (Vanilla JS)
// ============================
(function() {
    let toasts = [];
    let id = 0;

    const container = document.createElement('div');
    container.className = 'toast-container';
    document.body.appendChild(container);

    function add(msg, type, duration) {
        if (!type) type = 'info';
        if (!duration) duration = 4000;
        const toastId = ++id;

        const el = document.createElement('div');
        el.className = 'toast-el toast-' + type;

        const icon = document.createElement('i');
        icon.className = type === 'success' ? 'fas fa-check-circle'
            : type === 'error' ? 'fas fa-exclamation-circle'
            : type === 'warning' ? 'fas fa-exclamation-triangle'
            : 'fas fa-info-circle';

        const span = document.createElement('span');
        span.textContent = msg;

        const close = document.createElement('button');
        close.className = 'toast-close';
        close.innerHTML = '&times;';
        close.addEventListener('click', function(e) { e.stopPropagation(); remove(toastId); });

        el.appendChild(icon);
        el.appendChild(span);
        el.appendChild(close);
        container.appendChild(el);

        toasts.push({ id: toastId, el: el });

        setTimeout(function() { remove(toastId); }, duration);
    }

    function remove(id) {
        for (var i = 0; i < toasts.length; i++) {
            if (toasts[i].id === id) {
                var el = toasts[i].el;
                el.style.opacity = '0';
                el.style.transform = 'translateX(100%)';
                setTimeout(function() { if (el.parentNode) el.parentNode.removeChild(el); }, 300);
                toasts.splice(i, 1);
                break;
            }
        }
    }

    window.__toast = { add: add, remove: remove };
})();

// ============================
// 🚀 DOM Ready
// ============================
document.addEventListener('DOMContentLoaded', function() {
    // Flash auto-dismiss
    document.querySelectorAll('.flash').forEach(function(el) {
        setTimeout(function() { el.style.opacity = '0'; setTimeout(function() { if (el.parentNode) el.parentNode.removeChild(el); }, 300); }, 5000);
    });

    // Auto-resize textareas
    document.addEventListener('input', function(e) {
        if (e.target.tagName === 'TEXTAREA') {
            e.target.style.height = 'auto';
            e.target.style.height = e.target.scrollHeight + 'px';
        }
    });

    // Form submit guard
    document.addEventListener('submit', function(e) {
        var btn = e.target.querySelector('button[type="submit"]:not([disabled])');
        if (btn && !btn.classList.contains('is-loading')) {
            btn.classList.add('is-loading');
            btn.disabled = true;
        }
    });
});

// Export for use in inline scripts
window.utils = utils;
