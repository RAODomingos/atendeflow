/* ============================================================
   config.js — CONFIGURE AQUI o acesso do portal à API do Wiki
   ------------------------------------------------------------
   1. Copie esta pasta (wiki-frontend) para QUALQUER hospedagem
      estática (outro domínio, Vercel, Netlify, S3, cPanel…).
   2. Preencha API_URL e API_KEY abaixo (valores em
      OminiDesk → Base de Conhecimento → Integração).
   3. Abra o index.html. Pronto.

   API_URL: base do OminiDesk, SEM barra no final.
            Ex: "https://atendeflow.suaempresa.com"
                "https://suaempresa.com/atendeflow"
                "http://localhost/atendeflow"
   API_KEY: chave exibida em /wiki/settings (header X-API-Key).
   ============================================================ */
window.WIKI_CONFIG = {
  API_URL: "http://localhost/atendeflow",
  API_KEY: "756fbfcdcb03956fb684436509ae6926f127712fba93617853ca569a95111c3a",
};
