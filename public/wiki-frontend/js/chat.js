/* ============================================================
   chat.js — Injeta o ChatWeb (OminiDesk) no portal, se ativo.
   Lê a config de /wiki/settings (API wiki/chat-config) e carrega
   o widget sozinho: nada para editar aqui ao trocar a chave —
   basta salvar em OminiDesk → Base de Conhecimento → Integração.
   Depende de: config.js, data.js (wikiFetch).
   ============================================================ */

async function initWikiChat() {
  try {
    const cfg = await wikiFetch('/api/wiki/chat-config');
    if (!cfg || !cfg.enabled || !cfg.widget_key) return;

    const apiUrl = String(cfg.api_url || '').replace(/\/+$/, '');
    if (!apiUrl) return;

    window.ATENDIMENTO_CONFIG = {
      widgetId: cfg.widget_key,
      title: cfg.title || 'Atendimento',
      color: cfg.color || '#2f6fed',
      position: cfg.position === 'left' ? 'left' : 'right',
      apiUrl: apiUrl,
      avatarUrl: cfg.avatar_url || '',
      fields: cfg.fields || undefined,
    };

    const s = document.createElement('script');
    s.async = true;
    // timestamp: o .htaccess serve chat.js com cache imutável de 1 ano,
    // então o versionamento garante que o cliente sempre rode a versão atual.
    s.src = apiUrl + '/widget/chat.js?v=' + Date.now();
    document.body.appendChild(s);
  } catch (err) {
    // Chat é opcional: nunca quebra o portal.
    console.warn('[Wiki] ChatWeb indisponível:', (err && err.message) || err);
  }
}
