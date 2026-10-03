/* ============================================================
   search.js — Funcionalidades de busca
   ============================================================ */

/* ─── BUSCA ─── */
function doSearch(query) {
  if (!query.trim()) return;
  navigate('search', query.trim());
}

function handleNavSearch(value) {
  if (value.length > 2) navigate('search', value);
}

/* ─── LAZY LOADING PARA IMAGENS ─── */
function initLazyLoading() {
  const lazyImages = document.querySelectorAll('img[data-src]');
  
  if ('IntersectionObserver' in window) {
    const imageObserver = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          const img = entry.target;
          img.src = img.dataset.src;
          img.classList.remove('lazy');
          imageObserver.unobserve(img);
        }
      });
    }, {
      rootMargin: '50px 0px',
      threshold: 0.01
    });

    lazyImages.forEach(img => imageObserver.observe(img));
  } else {
    // Fallback para browsers sem IntersectionObserver
    lazyImages.forEach(img => {
      img.src = img.dataset.src;
      img.classList.remove('lazy');
    });
  }
}

/* ─── INIT ─── */
document.addEventListener('DOMContentLoaded', initLazyLoading);
