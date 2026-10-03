/* ============================================================
   app.js — Aplicação principal e inicialização
   Depende de: data.js, render.js, router.js, search.js, ui.js, share.js
   ============================================================ */

/* ─── SIDEBAR MOBILE ─── */
function toggleSidebar() {
  const toggle = document.getElementById('sidebarToggle');
  const inner  = document.getElementById('sidebarInner');
  if (!toggle || !inner) return;
  toggle.classList.toggle('open');
  inner.classList.toggle('open');
}

/* ─── TOAST ─── */
function showToast(message) {
  const toast = document.getElementById('toast');
  document.getElementById('toastMsg').textContent = message;
  toast.classList.add('show');
  clearTimeout(toast._timer);
  toast._timer = setTimeout(() => toast.classList.remove('show'), 3000);
}

/* ─── INIT ─── */
document.addEventListener('DOMContentLoaded', async () => {
  // Mostra loading enquanto busca os dados
  document.body.innerHTML += '<div id="app-loading" style="position:fixed;inset:0;display:flex;align-items:center;justify-content:center;background:#f7fbf7;z-index:9999;font-family:inherit;color:#6b7a6d;font-size:.95rem;gap:10px"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#006400" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="animation:spin 1s linear infinite"><path d="M21 12a9 9 0 1 1-6.22-8.56"/></svg>Carregando...</div><style>@keyframes spin{to{transform:rotate(360deg)}}</style>';

  await loadData();

  document.getElementById('app-loading')?.remove();
  routeFromHash();
});

window.addEventListener('popstate', routeFromHash);
