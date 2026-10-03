/* ============================================================
   admin/js/theme-toggle.js - Dark mode functionality
   ============================================================ */

// Theme management
class ThemeManager {
  constructor() {
    this.storageKey = 'admin-theme';
    this.init();
  }

  init() {
    // Load saved theme or use system preference
    const savedTheme = localStorage.getItem(this.storageKey);
    const systemTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    const theme = savedTheme || systemTheme;
    
    this.setTheme(theme);
    this.setupEventListeners();
  }

  setupEventListeners() {
    // Theme toggle button
    const toggleBtn = document.getElementById('themeToggle');
    if (toggleBtn) {
      toggleBtn.addEventListener('click', () => this.toggleTheme());
    }

    // System theme change
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
      if (!localStorage.getItem(this.storageKey)) {
        this.setTheme(e.matches ? 'dark' : 'light');
      }
    });
  }

  toggleTheme() {
    const currentTheme = document.documentElement.getAttribute('data-theme');
    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
    this.setTheme(newTheme);
  }

  setTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem(this.storageKey, theme);
    
    // Update toggle button
    const toggleBtn = document.getElementById('themeToggle');
    if (toggleBtn) {
      toggleBtn.setAttribute('aria-label', `Tema atual: ${theme === 'dark' ? 'escuro' : 'claro'}. Clique para alternar.`);
    }
  }
}

// Loading states
class LoadingManager {
  static show(element) {
    if (element) {
      element.classList.add('loading');
    }
  }

  static hide(element) {
    if (element) {
      element.classList.remove('loading');
    }
  }

  static showOnForm(form) {
    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) {
      this.show(submitBtn);
      submitBtn.disabled = true;
      const originalText = submitBtn.textContent;
      submitBtn.textContent = 'Processando...';
      
      // Store original text for restoration
      submitBtn.dataset.originalText = originalText;
    }
  }

  static hideOnForm(form) {
    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) {
      this.hide(submitBtn);
      submitBtn.disabled = false;
      submitBtn.textContent = submitBtn.dataset.originalText || 'Enviar';
      delete submitBtn.dataset.originalText;
    }
  }
}

// Micro-interactions
class MicroInteractions {
  static init() {
    this.setupButtonAnimations();
    this.setupCardHoverEffects();
    this.setupFormAnimations();
  }

  static setupButtonAnimations() {
    document.querySelectorAll('.btn').forEach(btn => {
      btn.addEventListener('mouseenter', () => {
        btn.style.transform = 'translateY(-1px)';
      });
      
      btn.addEventListener('mouseleave', () => {
        btn.style.transform = 'translateY(0)';
      });
    });
  }

  static setupCardHoverEffects() {
    document.querySelectorAll('.stat-card, .panel').forEach(card => {
      card.addEventListener('mouseenter', () => {
        card.style.transform = 'translateY(-2px)';
        card.style.boxShadow = 'var(--shadow-md)';
      });
      
      card.addEventListener('mouseleave', () => {
        card.style.transform = 'translateY(0)';
        card.style.boxShadow = 'var(--shadow)';
      });
    });
  }

  static setupFormAnimations() {
    document.querySelectorAll('input, textarea, select').forEach(field => {
      field.addEventListener('focus', () => {
        field.parentElement.classList.add('focused');
      });
      
      field.addEventListener('blur', () => {
        if (!field.value) {
          field.parentElement.classList.remove('focused');
        }
      });
    });
  }
}

// Form enhancements
class FormEnhancements {
  static init() {
    this.setupAutoSave();
    this.setupFormValidation();
    this.setupCharacterCounters();
  }

  static setupAutoSave() {
    const forms = document.querySelectorAll('form[data-autosave]');
    forms.forEach(form => {
      let timeout;
      
      form.addEventListener('input', () => {
        clearTimeout(timeout);
        timeout = setTimeout(() => {
          this.saveFormData(form);
        }, 2000);
      });
    });
  }

  static saveFormData(form) {
    const formData = new FormData(form);
    const data = Object.fromEntries(formData);
    
    // Save to localStorage
    const formId = form.id || 'form-' + Date.now();
    localStorage.setItem(`autosave-${formId}`, JSON.stringify(data));
    
    // Show indicator
    this.showAutoSaveIndicator(form);
  }

  static showAutoSaveIndicator(form) {
    const indicator = document.createElement('div');
    indicator.className = 'autosave-indicator';
    indicator.textContent = 'Salvo automaticamente';
    indicator.style.cssText = `
      position: fixed;
      top: 20px;
      right: 20px;
      background: var(--green);
      color: white;
      padding: 8px 16px;
      border-radius: 8px;
      font-size: 14px;
      z-index: 9999;
      animation: slideIn 0.3s ease;
    `;
    
    document.body.appendChild(indicator);
    
    setTimeout(() => {
      indicator.remove();
    }, 3000);
  }

  static setupFormValidation() {
    const forms = document.querySelectorAll('form[data-validate]');
    forms.forEach(form => {
      form.addEventListener('submit', (e) => {
        if (!this.validateForm(form)) {
          e.preventDefault();
        }
      });
    });
  }

  static validateForm(form) {
    const errors = [];
    const requiredFields = form.querySelectorAll('[required]');
    
    requiredFields.forEach(field => {
      if (!field.value.trim()) {
        errors.push(`${field.name || 'Campo'} é obrigatório`);
        field.classList.add('error');
      } else {
        field.classList.remove('error');
      }
    });
    
    if (errors.length > 0) {
      this.showErrors(errors);
      return false;
    }
    
    return true;
  }

  static showErrors(errors) {
    const errorContainer = document.createElement('div');
    errorContainer.className = 'error-container';
    errorContainer.innerHTML = `
      <div class="alert alert-error">
        <ul>
          ${errors.map(error => `<li>${error}</li>`).join('')}
        </ul>
      </div>
    `;
    
    const firstForm = document.querySelector('form');
    if (firstForm) {
      firstForm.parentNode.insertBefore(errorContainer, firstForm);
      
      setTimeout(() => {
        errorContainer.remove();
      }, 5000);
    }
  }

  static setupCharacterCounters() {
    const textareas = document.querySelectorAll('textarea[maxlength]');
    textareas.forEach(textarea => {
      const counter = document.createElement('div');
      counter.className = 'char-counter';
      counter.textContent = `0 / ${textarea.maxLength}`;
      
      textarea.parentNode.appendChild(counter);
      
      textarea.addEventListener('input', () => {
        const length = textarea.value.length;
        counter.textContent = `${length} / ${textarea.maxLength}`;
        
        if (length >= textarea.maxLength * 0.9) {
          counter.classList.add('warning');
        } else {
          counter.classList.remove('warning');
        }
      });
    });
  }
}

// Initialize everything
document.addEventListener('DOMContentLoaded', () => {
  new ThemeManager();
  MicroInteractions.init();
  FormEnhancements.init();
});

// Export for external use
window.AdminUI = {
  LoadingManager,
  MicroInteractions,
  FormEnhancements
};
