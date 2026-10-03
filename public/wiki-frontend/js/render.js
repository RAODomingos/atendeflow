/* ============================================================
   render.js — Funções que geram o HTML de cada view
   Depende de: data.js (CATEGORIES, ARTICLES)
   ============================================================ */

/* ─── HELPERS: texto seguro + ícone padrão (banco pode ter NULL) ─── */
function escText(s) {
  if (s == null) return '';
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

const DEFAULT_CAT_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/></svg>';

function catIconSvg(cat) {
  const raw = (cat && cat.iconSvg) || DEFAULT_CAT_ICON;
  return String(raw).replace(/stroke="white"/g, 'stroke="currentColor"');
}

function catOf(article) {
  return CATEGORIES[article.category] || { title: 'Sem categoria', desc: '', iconSvg: '' };
}

/* ─── HELPER: card de artigo na listagem ─── */
function articleItemHTML(article) {
  const cat = catOf(article);
  return `
    <div class="article-item fade-up" onclick="navigate('article','${escText(article.id)}')">
      <div class="article-item-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round">
          <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
          <polyline points="14 2 14 8 20 8"/>
          <line x1="16" y1="13" x2="8" y2="13"/>
          <line x1="16" y1="17" x2="8" y2="17"/>
          <polyline points="10 9 9 9 8 9"/>
        </svg>
      </div>
      <div class="article-item-body">
        <div class="article-item-cat">${escText(cat.title)}</div>
        <div class="article-item-title">${escText(article.title)}</div>
        <div class="article-item-desc">${escText(article.desc)}</div>
      </div>
      <svg class="article-item-arrow" width="18" height="18" viewBox="0 0 24 24"
           fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round">
        <path d="M9 18l6-6-6-6"/>
      </svg>
    </div>`;
}

/* ─── HOME: estatísticas do hero ─── */
function renderHeroStats() {
  const totalArticles = ARTICLES.length;
  const totalCats     = Object.keys(CATEGORIES).length;
  document.getElementById('hero-stats').innerHTML = `
    <div class="hero-stat"><strong>${totalArticles}</strong> artigos disponíveis</div>
    <div class="hero-stat"><strong>${totalCats}</strong> categorias</div>`;
}

/* ─── HOME: cards de categoria (gerado automaticamente do data.js) ─── */
function renderCategories() {
  const arrowSvg = `
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
         stroke-linecap="round" stroke-linejoin="round">
      <path d="M5 12h14M12 5l7 7-7 7"/>
    </svg>`;

  const cards = Object.entries(CATEGORIES).map(([id, cat], index) => {
    const count    = ARTICLES.filter(a => a.category === id).length;
    const singular = count === 1 ? 'artigo' : 'artigos';
    // Substitui stroke="white" pelo stroke="currentColor" para o ícone do card
    const iconSvg  = catIconSvg(cat);
    const delay    = ['fade-up', 'fade-up-2', 'fade-up-3', 'fade-up-4'][index] || 'fade-up';

    return `
      <div class="cat-card ${delay}" onclick="navigate('cat','${escText(id)}')">
        <span class="cat-count">${count} ${singular}</span>
        <div class="cat-icon">${iconSvg}</div>
        <h3>${escText(cat.title)}</h3>
        <p>${escText(cat.desc)}</p>
        <span class="cat-link">Ver artigos ${arrowSvg}</span>
      </div>`;
  }).join('');

  document.getElementById('home-categories').innerHTML = cards;
}

/* ─── HOME: artigos em destaque (cai para os mais recentes se não houver) ─── */
function renderFeatured() {
  let featured = ARTICLES.filter(a => a.featured);
  if (featured.length === 0) featured = ARTICLES.slice(0, 6);
  document.getElementById('featured-articles').innerHTML =
    featured.map(articleItemHTML).join('');
}

/* ─── BUSCA ─── */
function renderSearch(query) {
  const q = String(query || '').toLowerCase();
  const results = ARTICLES.filter(a =>
    String(a.title || '').toLowerCase().includes(q) ||
    String(a.desc || '').toLowerCase().includes(q) ||
    (a.content && String(a.content).toLowerCase().includes(q))
  );

  document.getElementById('searchResultsTitle').textContent =
    `Resultados para "${query}"`;
  document.getElementById('searchResultsCount').textContent =
    `${results.length} artigo${results.length !== 1 ? 's' : ''} encontrado${results.length !== 1 ? 's' : ''}`;

  const list = document.getElementById('searchResultsList');
  if (results.length === 0) {
    list.innerHTML = `
      <div class="no-results">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <circle cx="11" cy="11" r="8"/>
          <path d="m21 21-4.35-4.35"/>
          <path d="M8 11h6M11 8v6"/>
        </svg>
        <h3>Nenhum resultado encontrado</h3>
        <p>Tente buscar por outras palavras ou explore as categorias abaixo.</p>
      </div>`;
  } else {
    list.innerHTML = results.map(articleItemHTML).join('');
  }
}

/* ─── PÁGINA DE CATEGORIA ─── */
function renderCategory(catId) {
  const cat = CATEGORIES[catId];
  if (!cat) return;

  document.getElementById('catBreadcrumb').textContent  = cat.title || '';
  document.getElementById('catPageTitle').textContent   = cat.title || '';
  document.getElementById('catPageDesc').textContent    = cat.desc || '';
  document.getElementById('catPageIcon').innerHTML      = catIconSvg(cat);

  const arts = ARTICLES.filter(a => a.category === catId);
  document.getElementById('catArticleList').innerHTML =
    arts.map(articleItemHTML).join('');
}

/* ─── PÁGINA DE ARTIGO ─── */
function renderArticle(articleId) {
  const article = ARTICLES.find(a => a.id === articleId);
  if (!article) return;

  const cat      = catOf(article);
  const siblings = ARTICLES.filter(a => a.category === article.category);
  const idx      = siblings.indexOf(article);
  const prev     = siblings[idx - 1];
  const next     = siblings[idx + 1];

  /* -- Sidebar -- */
  const sidebarLinks = siblings.map(a => `
    <a class="sidebar-link ${a.id === articleId ? 'active' : ''}"
       onclick="navigate('article','${escText(a.id)}')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round">
        <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
        <polyline points="14 2 14 8 20 8"/>
      </svg>
      ${escText(a.title)}
    </a>`).join('');

  const moreCatsLinks = Object.entries(CATEGORIES).map(([id, c]) => `
    <a class="sidebar-link ${id === article.category ? 'active' : ''}"
       onclick="navigate('cat','${escText(id)}')">
      ${escText(c.title)}
    </a>`).join('');

  document.getElementById('articleSidebar').innerHTML = `
    <button class="sidebar-mobile-toggle" id="sidebarToggle" onclick="toggleSidebar()">
      <span style="display:flex;align-items:center;gap:8px;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <line x1="3" y1="12" x2="21" y2="12"/>
          <line x1="3" y1="6"  x2="21" y2="6"/>
          <line x1="3" y1="18" x2="21" y2="18"/>
        </svg>
        Navegar nesta categoria
      </span>
      <svg class="toggle-icon" width="18" height="18" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M6 9l6 6 6-6"/>
      </svg>
    </button>
    <div class="sidebar-inner" id="sidebarInner">
      <div class="sidebar-section">
        <div class="sidebar-title">${escText(cat.title)}</div>
        ${sidebarLinks}
      </div>
      <div class="sidebar-section">
        <div class="sidebar-title">Mais categorias</div>
        ${moreCatsLinks}
      </div>
    </div>`;

  /* -- Botões prev / next -- */
  const navBtns = `
    <div class="nav-articles">
      ${prev ? `
        <button class="nav-article-btn" onclick="navigate('article','${escText(prev.id)}')">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 12H5M12 19l-7-7 7-7"/>
          </svg>
          Anterior
        </button>` : ''}
      ${next ? `
        <button class="nav-article-btn" onclick="navigate('article','${escText(next.id)}')">
          Próximo
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M5 12h14M12 5l7 7-7 7"/>
          </svg>
        </button>` : ''}
    </div>`;

  /* -- Conteúdo principal -- */
  const coverHTML = article.cover
    ? `<div class="article-cover"><img src="${escText(article.cover)}" alt="${escText(article.title)}"/></div>`
    : '';

  document.getElementById('articleContent').innerHTML = `
    <nav class="breadcrumb">
      <a onclick="navigate('home')">Início</a>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round" width="14" height="14">
        <path d="M9 18l6-6-6-6"/>
      </svg>
      <a onclick="navigate('cat','${escText(article.category)}')">${escText(cat.title)}</a>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round" width="14" height="14">
        <path d="M9 18l6-6-6-6"/>
      </svg>
      <span>${escText(article.title)}</span>
    </nav>

    <div class="article-header">
      <div class="article-meta">
        <span class="article-tag">${escText(cat.title)}</span>
        <span class="article-date">Atualizado em ${escText(article.date)}</span>
        <button class="share-trigger share-btn-inline"
                onclick="toggleShareMenu('${escText(article.id)}')"
                title="Compartilhar artigo">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/>
            <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/>
            <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>
          </svg>
          Compartilhar
        </button>
      </div>
      <h1>${escText(article.title)}</h1>
      <p class="lead">${escText(article.desc)}</p>
    </div>

    ${coverHTML}

    <div class="article-body ql-content">${article.content}</div>

    <div class="article-footer">
      <div class="helpful">
        <p>Este artigo foi útil?</p>
        <button class="helpful-btn" onclick="showToast('Obrigado pelo seu feedback! 😊')">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M14 9V5a3 3 0 00-3-3l-4 9v11h11.28a2 2 0 002-1.7l1.38-9a2 2 0 00-2-2.3H14z"/>
            <path d="M7 22H4a2 2 0 01-2-2v-7a2 2 0 012-2h3"/>
          </svg>
          Sim, ajudou
        </button>
        <button class="helpful-btn" onclick="showToast('Obrigado! Vamos melhorar este artigo.')">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M10 15v4a3 3 0 003 3l4-9V2H5.72a2 2 0 00-2 1.7l-1.38 9a2 2 0 002 2.3H10z"/>
            <path d="M17 2h2.67A2.31 2.31 0 0122 4v7a2.31 2.31 0 01-2.33 2H17"/>
          </svg>
          Não ajudou
        </button>
      </div>
      ${navBtns}
    </div>`;
}
