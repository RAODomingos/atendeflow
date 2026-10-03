/* ============================================================
   data.js — Carrega categorias e artigos da API pública do Wiki
   (OminiDesk). Config em ../config.js:
     window.WIKI_CONFIG = { API_URL, API_KEY }
   A API exige a Key (header X-API-Key ou ?api_key=).
   Imagens já vêm absolutas (cover_image, content_absolute).
   ============================================================ */

let CATEGORIES = {};   // { slug: { title, desc, iconSvg, ... } }
let ARTICLES   = [];   // [{ id, slug, category, title, desc, content, ... }]

function wikiConfig() {
  const cfg = window.WIKI_CONFIG || {};
  const base = String(cfg.API_URL || "").replace(/\/+$/, "");
  const key = String(cfg.API_KEY || "");
  if (!base || !key || key === "COLE_AQUI_A_WIKI_API_KEY") {
    throw new Error(
      "Wiki não configurado: edite config.js (API_URL e API_KEY). " +
      "Valores em OminiDesk → Base de Conhecimento → Integração."
    );
  }
  return { base, key };
}

async function wikiFetch(path) {
  const { base, key } = wikiConfig();
  const sep = path.includes("?") ? "&" : "?";
  const res = await fetch(base + path + sep + "api_key=" + encodeURIComponent(key));
  if (res.status === 401) {
    throw new Error("API Key inválida. Confira WIKI_API_KEY no config.js.");
  }
  if (!res.ok) {
    throw new Error("Falha ao carregar dados do Wiki (HTTP " + res.status + "). Confira WIKI_API_URL no config.js.");
  }
  return res.json();
}

async function loadData() {
  const [catsArr, artsArr] = await Promise.all([
    wikiFetch("/api/wiki/categories"),
    wikiFetch("/api/wiki/articles"),
  ]);

  // Converte array de categorias no formato { slug: {...} }
  // Normaliza nulos (icon_svg/description podem vir NULL do banco).
  CATEGORIES = {};
  catsArr.forEach(c => {
    CATEGORIES[c.slug] = {
      id:           c.id,
      title:        c.title || '',
      desc:         c.description || '',
      iconSvg:      c.icon_svg || '',
      sort_order:   c.sort_order,
      articleCount: Number(c.article_count || 0),
    };
  });

  // Normaliza artigos para o mesmo formato usado pelo render.js.
  // content_absolute e cover_image já são URLs absolutas → funcionam
  // mesmo com o portal em outro domínio.
  ARTICLES = artsArr.map(a => ({
    id:          a.slug,          // o frontend usa slug como id
    category:    a.category_slug,
    title:       a.title || '',
    desc:        a.description || '',
    date:        a.published_at
                   ? new Date(a.published_at).toLocaleDateString('pt-BR', { day:'2-digit', month:'short', year:'numeric' })
                   : '',
    featured:    Number(a.featured) === 1,
    content:     a.content_absolute || a.content || '',
    cover:       a.cover_image || null,
    sort_order:  Number(a.sort_order || 0),
  }));
}
