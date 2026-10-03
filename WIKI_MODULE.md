# Módulo Wiki / Base de Conhecimento — OminiDesk

A pasta legada `wiki/` (admin + `api/*.php` + `index.html`) foi integrada ao
OminiDesk como **módulo**:

- **Admin (criação de categorias e posts)** → dentro do OminiDesk:
  `Base de Conhecimento` no menu (rotas `/wiki/*`), com o mesmo visual do
  sistema, permissões por cargo e upload para `public/uploads/wiki/`.
- **Front-end público** → pasta estática `public/wiki-frontend/` que pode ser
  **hospedada em qualquer lugar**; só precisa configurar **URL + Key**
  (arquivo `config.js`).
- **API pública com Key** → `/api/wiki/*` (CORS aberto, imagens em URL
  absoluta para funcionar em outro domínio).
- A pasta `wiki/` original foi mantida como referência, mas **não é mais
  servida** (só arquivos dentro de `public/` são acessíveis via web).

## 1. Banco de dados

### 1.1. Criar as tabelas

Migration: `database/migrations/2026_09_14_wiki_module.sql`

```sql
wiki_categories     -- categorias (slug, title, description, icon_svg, sort_order)
wiki_articles       -- artigos (slug, category_id, title, description, content, cover_image, featured, sort_order, published_at, view_count)
wiki_article_views  -- log de visualizações
settings wiki_api_key / wiki_enabled  -- chave da API pública
```

Rode pelo script (credenciais padrão Laragon `root`/`''`, banco `atendeflow`):

```bash
php database/migrate.php
```

Ou importe o arquivo SQL manualmente no seu banco.

### 1.2. Importar os dados legados (opcional)

Se você já tem o banco `wiki` do sistema antigo (tabelas `articles`,
`categories` do `wiki/BD.sql`), importe para as tabelas novas. Com as tabelas
legadas no **mesmo banco** do OminiDesk:

```sql
INSERT INTO wiki_categories (id, slug, title, description, icon_svg, sort_order, created_at, updated_at)
SELECT id, slug, title, description, icon_svg, sort_order, created_at, updated_at FROM categories;

INSERT INTO wiki_articles (id, slug, category_id, title, description, content, cover_image, featured, sort_order, published_at, created_at, updated_at, view_count)
SELECT id, slug, category_id, title, description, content, NULLIF(cover_image,''), featured, sort_order, published_at, created_at, updated_at, view_count FROM articles;
```

Imagens antigas: copie `wiki/uploads/*` → `public/uploads/` (os nomes são
únicos por timestamp). Conteúdos com `src="/uploads/..."` continuam valendo,
pois a API reescreve para URL absoluta.

## 2. Admin — categorias e artigos

| Página | Rota | Permissão |
|---|---|---|
| Dashboard (contadores + URL/Key) | `GET /wiki` | autenticado |
| Categorias | `GET /wiki/categories` | autenticado |
| Nova categoria | `GET+POST /wiki/categories/create` | gerente+ |
| Editar categoria | `GET+POST /wiki/categories/{id}/edit` | gerente+ |
| Excluir categoria | `POST /wiki/categories/{id}/delete` | gerente+ |
| Reordenar categorias (drag-and-drop) | `POST /wiki/categories/reorder` | gerente+ |
| Artigos (+ filtros q/cat/destaque) | `GET /wiki/articles` | autenticado |
| Novo artigo (editor Quill) | `GET+POST /wiki/articles/create` | gerente+ |
| Editar artigo | `GET+POST /wiki/articles/{id}/edit` | gerente+ |
| Excluir artigo | `POST /wiki/articles/{id}/delete` | gerente+ |
| Reordenar artigos | `POST /wiki/articles/reorder` | gerente+ |
| Integração (URL + Key + endpoints) | `GET /wiki/settings` | admin |
| Regenerar Key | `POST /wiki/settings/regenerate-key` | admin |
| Salvar ChatWeb do portal (on/off + chave) | `POST /wiki/settings/chat` | admin |
| Salvar URL pública do portal (link do cliente) | `POST /wiki/settings/portal` | admin |
| Upload de imagem (capa/editor) | `POST /api/wiki/upload` (form `file`) | gerente+ |

Upload aceita JPG/PNG/GIF/WEBP (máx. 10 MB, validação de MIME real) e devolve:

```json
{ "url": "https://atendeflow.suaempresa.com/uploads/wiki/abc123.png" }
```

## 3. API pública (front externo) — autenticação por Key

Base: `{API_URL}/api/wiki/*` · CORS `*` · auth via header
`X-API-Key: <key>` **ou** query `?api_key=<key>`.

| Endpoint | Descrição |
|---|---|
| `GET /api/wiki/categories` | Todas as categorias (+ `article_count`) |
| `GET /api/wiki/articles` | Todos os artigos |
| `GET /api/wiki/articles?category=slug` | Artigos de uma categoria |
| `GET /api/wiki/articles?featured=1` | Destaques (máx. 6) |
| `GET /api/wiki/articles?search=texto` | Busca título/descrição/conteúdo |
| `GET /api/wiki/articles?slug=x` ou `/api/wiki/articles/x` | 1 artigo (+1 visualização) |
| `GET /api/wiki/config` | Contadores + base de uploads |
| `GET /api/wiki/chat-config` | Config do ChatWeb no portal (`enabled`, `widget_key`, `title`, `color`, `position`, `api_url`, `avatar_url`, `fields` ask/required) — o portal injeta o widget sozinho se ativo |
| `GET /api/wiki/suggest?q=` | Busca de artigos para o atendente (rota interna autenticada) — retorna `title` + `url` do **portal do cliente** (`{portal_url}#article/{slug}`) |

Exemplos:

```bash
curl -H "X-API-Key: SUA_KEY" https://atendeflow.suaempresa.com/api/wiki/categories
curl "https://atendeflow.suaempresa.com/api/wiki/articles?category=comece-aqui&api_key=SUA_KEY"
```

Artigo retornado (resumo):

```json
{
  "slug": "kit-guild-control",
  "title": "Kit Guild Control",
  "category_slug": "comece-aqui",
  "cover_image": "https://atendeflow.suaempresa.com/uploads/wiki/x.png",
  "content": "<h1>…</h1><img src=\"/uploads/…\">",
  "content_absolute": "<h1>…</h1><img src=\"https://atendeflow.suaempresa.com/uploads/…\">"
}
```

Use **`cover_image`** e **`content_absolute`** no front: já são absolutas,
então as imagens funcionam com o portal em outro domínio.

## 4. Front-end externo — configuração detalhada

A pasta **`public/wiki-frontend/`** é o portal pronto (mesmo visual do
`wiki/index.html` original). Hospede onde quiser:

### 4.1. Passo a passo

1. **Copie a pasta** `public/wiki-frontend/` para a hospedagem
   (Vercel, Netlify, S3+CloudFront, cPanel, outro VPS…).
   Só arquivos estáticos — não precisa de PHP/MySQL lá.
2. **Edite `config.js`** (na raiz da pasta copiada):
   ```js
   window.WIKI_CONFIG = {
     API_URL: "https://atendeflow.suaempresa.com", // SEM "/" no final; inclua subpasta se houver (ex: https://site.com/atendeflow)
     API_KEY: "cole_a_key_de_/wiki/settings",
   };
   ```
   Pegue os valores em **OminiDesk → Base de Conhecimento → Integração**
   (`/wiki/settings`, só admin vê a Key).
3. **Abra o `index.html`** no domínio hospedado. Categorias, artigos, busca
   e imagens carregam da API.

### 4.2. Prévia local (sem hospedar)

O próprio OminiDesk serve o portal (arquivo estático):

- `http(s)://seu-atendeflow/wiki-frontend/index.html`

Para a prévia funcionar, edite `public/wiki-frontend/config.js` com a URL
local (ex: `http://localhost/atendeflow`) e a Key atual.

### 4.3. Personalização

- Marca/textos/WhatsApp: edite `index.html` (mesma estrutura do original).
- Estilo: `css/style.css`.
- Lógica: `js/*.js` (`data.js` é o que fala com a API; os demais são
  renderização idêntica ao original).

### 4.4. Troubleshooting

| Sintoma | Causa provável | Solução |
|---|---|---|
| "Wiki não configurado" | `config.js` com valores de exemplo | Preencha `API_URL` e `API_KEY` |
| 401 "Invalid API key" | Key errada / regenerada | Recopie a Key de `/wiki/settings` |
| 404 ao carregar | `API_URL` sem subpasta ou com `/` no final | Ex: `https://site.com/atendeflow`, sem barra final |
| Imagens quebradas | `API_URL` errada (absolutização usa ela) | Corrija `API_URL`; use `content_absolute` |
| Mudou e não atualizou | Cache agressivo do `.htaccess` (1 ano p/ assets) | Hard-refresh (Ctrl+F5) ou versione o arquivo |

> **Segurança:** a Key é pública por natureza (vai no JS do portal).
> Ela só permite **leitura** do Wiki. Se vazar/precisar trocar:
> `/wiki/settings` → **Regenerar chave** → atualize o `config.js` do portal.

## 5. Arquivos criados/alterados

```
database/migrations/2026_09_14_wiki_module.sql
app/Models/WikiCategory.php
app/Models/WikiArticle.php
app/Controllers/WikiController.php          (admin)
app/Controllers/Api/WikiApiController.php   (API pública com Key)
app/Views/wiki/{index,categories,category_form,articles,article_form,settings}.php
app/Views/layouts/main.php                  (+ item "Base de Conhecimento")
routes/web.php                              (rotas /wiki/* + /api/wiki/* + /api/wiki/upload)
public/wiki-frontend/{index.html,config.js,js/*,css/style.css,README.md}
WIKI_MODULE.md                              (este arquivo)
```
