/* ============================================================
   ui.js — Interações da interface do usuário
   ============================================================ */

/* ─── MENU MOBILE ─── */
function toggleMobileMenu() {
  const btn  = document.getElementById('navHamburger');
  const menu = document.getElementById('navMobileMenu');
  
  if (btn && menu) {
    const isOpen = btn.classList.contains('open');
    btn.classList.toggle('open');
    menu.classList.toggle('open');
    
    // Atualizar ARIA
    btn.setAttribute('aria-expanded', !isOpen);
  }
}

/* ─── DROPDOWN CONTATO ─── */
function toggleContactDropdown() {
  const dropdown = document.getElementById('contactDropdown');
  const menu = document.getElementById('contactDropdownMenu');
  const isOpen = dropdown.classList.contains('open');
  
  if (dropdown && menu) {
    dropdown.classList.toggle('open');
    menu.classList.toggle('open');
    
    // Atualizar ARIA
    dropdown.setAttribute('aria-expanded', !isOpen);
    
    // Focar no primeiro item do menu se abriu
    if (!isOpen) {
      const firstItem = menu.querySelector('.nav-dropdown-item');
      if (firstItem) {
        setTimeout(() => firstItem.focus(), 100);
      }
    }
  }
}

function toggleMobileContactDropdown() {
  const dropdown = document.getElementById('mobileContactDropdown');
  const menu = document.getElementById('mobileContactDropdownMenu');
  
  if (dropdown && menu) {
    const isOpen = dropdown.classList.contains('open');
    dropdown.classList.toggle('open');
    menu.classList.toggle('open');
    
    // Atualizar ARIA
    dropdown.setAttribute('aria-expanded', !isOpen);
    
    // Focar no primeiro item do menu se abriu
    if (!isOpen) {
      const firstItem = menu.querySelector('.nav-mobile-dropdown-item');
      if (firstItem) {
        setTimeout(() => firstItem.focus(), 100);
      }
    }
  }
}

/* ─── NAVEGAÇÃO POR TECLADO ─── */
function handleDropdownKeydown(e, dropdownId, menuId) {
  const dropdown = document.getElementById(dropdownId);
  const menu = document.getElementById(menuId);
  const items = menu.querySelectorAll('a');
  const currentIndex = Array.from(items).findIndex(item => item === document.activeElement);
  
  switch (e.key) {
    case 'ArrowDown':
      e.preventDefault();
      const nextIndex = currentIndex < items.length - 1 ? currentIndex + 1 : 0;
      items[nextIndex].focus();
      break;
    case 'ArrowUp':
      e.preventDefault();
      const prevIndex = currentIndex > 0 ? currentIndex - 1 : items.length - 1;
      items[prevIndex].focus();
      break;
    case 'Escape':
      e.preventDefault();
      if (dropdownId === 'contactDropdown') {
        toggleContactDropdown();
      } else {
        toggleMobileContactDropdown();
      }
      // Retornar foco para o botão principal
      dropdown.focus();
      break;
  }
}

/* ─── EVENT LISTENERS ─── */
function initUIEventListeners() {
  // Adicionar event listeners para navegação por teclado
  const desktopDropdown = document.getElementById('contactDropdown');
  const mobileDropdown = document.getElementById('mobileContactDropdown');
  
  if (desktopDropdown) {
    desktopDropdown.addEventListener('keydown', (e) => handleDropdownKeydown(e, 'contactDropdown', 'contactDropdownMenu'));
  }
  
  if (mobileDropdown) {
    mobileDropdown.addEventListener('keydown', (e) => handleDropdownKeydown(e, 'mobileContactDropdown', 'mobileContactDropdownMenu'));
  }

  // Fechar dropdowns ao clicar fora
  document.addEventListener('click', function(e) {
    // Desktop dropdown
    if (desktopDropdown && !desktopDropdown.contains(e.target)) {
      desktopDropdown.classList.remove('open');
      document.getElementById('contactDropdownMenu').classList.remove('open');
      desktopDropdown.setAttribute('aria-expanded', 'false');
    }
    
    // Mobile dropdown
    if (mobileDropdown && !mobileDropdown.contains(e.target)) {
      mobileDropdown.classList.remove('open');
      document.getElementById('mobileContactDropdownMenu').classList.remove('open');
      mobileDropdown.setAttribute('aria-expanded', 'false');
    }
  });

  // Fechar dropdowns com ESC global
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      if (desktopDropdown && desktopDropdown.classList.contains('open')) {
        toggleContactDropdown();
      }
      
      if (mobileDropdown && mobileDropdown.classList.contains('open')) {
        toggleMobileContactDropdown();
      }
    }
  });
}

/* ─── INIT ─── */
document.addEventListener('DOMContentLoaded', initUIEventListeners);
