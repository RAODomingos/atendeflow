/* ============================================================
   share.js — Funcionalidades de compartilhamento
   ============================================================ */

/* ─── MENU DE COMPARTILHAMENTO ─── */
function toggleShareMenu(articleId) {
  const existing = document.getElementById('shareMenu');
  if (existing) { closeShareMenu(); return; }

  const article  = ARTICLES.find(a => a.id === articleId);
  if (!article) return;

  const shareUrl   = window.location.origin + window.location.pathname + '#article/' + articleId;
  const shareTitle = article.title;
  const shareDesc  = article.desc;

  const menu = document.createElement('div');
  menu.id = 'shareMenu';
  menu.className = 'share-menu';
  menu.innerHTML = `
    <div class="share-menu-header">
      <span>Compartilhar artigo</span>
      <button class="share-menu-close" onclick="closeShareMenu()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
      </button>
    </div>

    <p class="share-article-title">${shareTitle}</p>

    <div class="share-url-box">
      <span class="share-url-text">${shareUrl}</span>
      <button class="share-copy-btn" onclick="copyShareLink('${shareUrl}')">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
        Copiar
      </button>
    </div>

    <div class="share-divider">ou compartilhe via</div>

    <div class="share-options">
      <button class="share-opt share-opt-whatsapp" onclick="shareWhatsApp('${encodeURIComponent(shareTitle)}','${encodeURIComponent(shareUrl)}')">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.124.556 4.118 1.528 5.845L.057 23.428a.5.5 0 00.515.572l5.736-1.504A11.95 11.95 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.891 0-3.667-.507-5.197-1.392l-.373-.22-3.863 1.013 1.033-3.76-.242-.389A9.956 9.956 0 012 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/></svg>
        WhatsApp
      </button>

      <button class="share-opt share-opt-email" onclick="shareEmail('${encodeURIComponent(shareTitle)}','${encodeURIComponent(shareDesc)}','${encodeURIComponent(shareUrl)}')">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
        E-mail
      </button>

      <button class="share-opt share-opt-native" id="shareNativeBtn" style="display:none"
              onclick="shareNative('${encodeURIComponent(shareTitle)}','${encodeURIComponent(shareDesc)}','${shareUrl}')">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
        Mais opções
      </button>
    </div>`;

  if (navigator.share) {
    menu.querySelector('#shareNativeBtn').style.display = 'flex';
  }

  document.body.appendChild(menu);
  setTimeout(() => document.addEventListener('click', outsideShareClick), 10);
  requestAnimationFrame(() => menu.classList.add('open'));
}

function outsideShareClick(e) {
  const menu = document.getElementById('shareMenu');
  if (menu && !menu.contains(e.target) && !e.target.closest('.share-trigger')) {
    closeShareMenu();
  }
}

function closeShareMenu() {
  const menu = document.getElementById('shareMenu');
  if (!menu) return;
  menu.classList.remove('open');
  document.removeEventListener('click', outsideShareClick);
  setTimeout(() => menu.remove(), 220);
}

function copyShareLink(url) {
  const fallback = async () => {
    try {
      await navigator.clipboard.writeText(url);
      showToast('Link copiado!');
      closeShareMenu();
    } catch (err) {
      // Fallback for older browsers
      const el = document.createElement('textarea');
      el.value = url;
      el.style.position = 'fixed';
      el.style.opacity = '0';
      document.body.appendChild(el);
      el.select();
      try {
        document.execCommand('copy');
        showToast('Link copiado!');
        closeShareMenu();
      } catch (fallbackErr) {
        showToast('Erro ao copiar link');
      }
      el.remove();
    }
  };
  if (navigator.clipboard) {
    navigator.clipboard.writeText(url).then(() => {
      showToast('✅ Link copiado para a área de transferência!');
      closeShareMenu();
    }).catch(fallback);
  } else { fallback(); }
}

function shareWhatsApp(title, url) {
  const text = 'Confira este artigo: ' + decodeURIComponent(title) + '\n' + decodeURIComponent(url);
  window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank');
  closeShareMenu();
}

function shareEmail(title, desc, url) {
  const subject = 'Artigo: ' + decodeURIComponent(title);
  const body    = decodeURIComponent(desc) + '\n\nLeia o artigo completo:\n' + decodeURIComponent(url);
  window.location.href = 'mailto:?subject=' + encodeURIComponent(subject) + '&body=' + encodeURIComponent(body);
  closeShareMenu();
}

function shareNative(title, text, url) {
  navigator.share({ title: decodeURIComponent(title), text: decodeURIComponent(text), url })
    .catch(() => {});
}
