# Portal Wiki — front-end público (hospede em qualquer lugar)

Portal estático da Base de Conhecimento. Lê tudo da API do OminiDesk.
Nenhum PHP/banco aqui: só configure **URL + Key**.

## Configuração (2 minutos)

1. Copie **esta pasta inteira** (`wiki-frontend/`) para qualquer hospedagem
   estática: outro domínio, Vercel, Netlify, S3, cPanel, etc.
2. Abra o arquivo **`config.js`** e preencha:
   ```js
   window.WIKI_CONFIG = {
     API_URL: "https://atendeflow.suaempresa.com", // SEM barra no final
     API_KEY: "chave-de-/wiki/settings",
   };
   ```
   Os valores estão em **OminiDesk → Base de Conhecimento → Integração**
   (`/wiki/settings`).
3. Acesse o `index.html` hospedado. Pronto.

## Como funciona

- Categorias: `GET {API_URL}/api/wiki/categories` (header `X-API-Key: {KEY}`)
- Artigos: `GET {API_URL}/api/wiki/articles` (header `X-API-Key: {KEY}`)
- As imagens vêm em **URL absoluta** (`cover_image`, `content_absolute`),
  então funcionam mesmo em outro domínio — sem copiar `uploads/`.
- **ChatWeb**: o portal busca `GET {API_URL}/api/wiki/chat-config` e injeta
  o balão de atendimento sozinho quando ativo. Liga/desliga e troca a chave
  em **OminiDesk → Base de Conhecimento → Integração** (seção 4 · ChatWeb),
  sem republicar arquivos.

## Problemas comuns

| Sintoma | Causa | Solução |
|---|---|---|
| Tela "Portal indisponível: Wiki não configurado" | `config.js` com valores de exemplo | Preencha `API_URL` e `API_KEY` |
| "API Key inválida" (401) | Key errada ou regenerada | Copie a Key atual de `/wiki/settings` |
| "Falha ao carregar (HTTP 404)" | `API_URL` errada (subpasta?) | Inclua a subpasta: `https://site.com/atendeflow`, sem `/` no final |
| Imagens quebradas | Conteúdo antigo com `src="/uploads/..."` relativo | A API já reescreve para absoluta; se quebrou, confira `API_URL` |
| CORS | Navegador bloqueou | A API envia `Access-Control-Allow-Origin: *`; confira se há proxy/WAF removendo headers |
