/* ============================================================
   router.js — Sistema de roteamento SPA
   ============================================================ */

/* ─── NAVEGAÇÃO COM HASH ─── */
function navigate(view, param) {
  document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));

  if (view === 'home') {
    document.getElementById('view-home').classList.add('active');
    renderHeroStats();
    renderCategories();
    renderFeatured();
    history.pushState(null, '', '#');
  }
  else if (view === 'search') {
    document.getElementById('view-search').classList.add('active');
    renderSearch(param);
    history.pushState(null, '', '#search/' + encodeURIComponent(param));
  }
  else if (view === 'cat') {
    document.getElementById('view-cat').classList.add('active');
    renderCategory(param);
    history.pushState(null, '', '#cat/' + param);
  }
  else if (view === 'article') {
    document.getElementById('view-article').classList.add('active');
    renderArticle(param);
    history.pushState(null, '', '#article/' + param);
  }

  document.getElementById('navSearch').value = '';
  window.scrollTo({ top: 0, behavior: 'smooth' });
  closeShareMenu();
}

/* ─── LEITURA DO HASH NA ENTRADA / BACK-FORWARD ─── */
function routeFromHash() {
  const hash = decodeURIComponent(window.location.hash.slice(1));

  if (!hash || hash === '/') {
    navigate('home');
  } else if (hash.startsWith('article/')) {
    navigate('article', hash.slice(8));
  } else if (hash.startsWith('cat/')) {
    navigate('cat', hash.slice(4));
  } else if (hash.startsWith('search/')) {
    navigate('search', hash.slice(7));
  } else {
    navigate('home');
  }
}

/* ─── INIT ─── */
window.addEventListener('popstate', routeFromHash);
