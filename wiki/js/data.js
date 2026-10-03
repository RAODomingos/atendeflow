/* ============================================================
   data.js — Carrega categorias e artigos via API PHP
   A fonte de dados agora é o banco MySQL, não mais este arquivo.
   ============================================================ */

let CATEGORIES = {};   // { slug: { title, desc, iconSvg, ... } }
let ARTICLES   = [];   // [{ id, slug, category, title, desc, content, ... }]

async function loadData() {
  const [catsRes, artsRes] = await Promise.all([
    fetch('api/categories.php'),
    fetch('api/articles.php')
  ]);

  const catsArr = await catsRes.json();
  const artsArr = await artsRes.json();

  // Converte array de categorias no formato { slug: {...} }
  CATEGORIES = {};
  catsArr.forEach(c => {
    CATEGORIES[c.slug] = {
      id:           c.id,
      title:        c.title,
      desc:         c.description,
      iconSvg:      c.icon_svg,
      sort_order:   c.sort_order,
      articleCount: Number(c.article_count || 0),
    };
  });

  // Normaliza artigos para o mesmo formato usado pelo render.js
  ARTICLES = artsArr.map(a => ({
    id:          a.slug,          // o frontend usa slug como id
    category:    a.category_slug,
    title:       a.title,
    desc:        a.description,
    date:        a.published_at
                   ? new Date(a.published_at).toLocaleDateString('pt-BR', { day:'2-digit', month:'short', year:'numeric' })
                   : '',
    featured:    Boolean(Number(a.featured)),
    content:     a.content || '',
    cover:       a.cover_image || null,
    sort_order:  Number(a.sort_order || 0),
  }));
}
