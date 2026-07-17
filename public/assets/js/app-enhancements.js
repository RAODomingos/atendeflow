/* ==========================================
   AtendeFlow - UI/UX Enhancements
   Phase 3: Keyboard nav, infinite scroll,
            cache, loading states, sound,
            live conversation list update
   ========================================== */

(function () {
  'use strict';

  // ──────────────────────────────────────────
  // 0. Conversation Memory Cache
  // ──────────────────────────────────────────
  const ConversationCache = {
    _cache: {},
    _timers: {},

    get(key) {
      const entry = this._cache[key];
      if (!entry) return null;
      if (entry.ttl && Date.now() > entry.ttl) {
        delete this._cache[key];
        return null;
      }
      return entry.data;
    },

    set(key, data, ttlMs) {
      this._cache[key] = {
        data,
        ttl: ttlMs ? Date.now() + ttlMs : null,
      };
    },

    clear(key) {
      if (key) delete this._cache[key];
      else this._cache = {};
    },

    debounce(key, fn, wait) {
      clearTimeout(this._timers[key]);
      this._timers[key] = setTimeout(fn, wait);
    },
  };

  // ──────────────────────────────────────────
  // 0b. Sound Manager (Web Audio API)
  // ──────────────────────────────────────────
  const SoundManager = {
    _ctx: null,
    _enabled: true,

    get ctx() {
      if (!this._ctx) {
        try {
          this._ctx = new (window.AudioContext || window.webkitAudioContext)();
        } catch (_) {}
      }
      if (this._ctx && this._ctx.state === 'suspended') {
        this._ctx.resume();
      }
      return this._ctx;
    },

    enable() { this._enabled = true; },
    disable() { this._enabled = false; },
    toggle() { this._enabled = !this._enabled; return this._enabled; },

    play(type) {
      if (!this._enabled) return;
      if (type === 'message') this._beep(520, 0.08, 0.06);
      else if (type === 'new_conv') this._newConv();
      else if (type === 'incoming') this._beep(600, 0.12, 0.08);
    },

    _beep(freq, duration, vol) {
      try {
        const ctx = this.ctx;
        if (!ctx) return;
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.value = freq;
        gain.gain.setValueAtTime(vol, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + duration);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + duration);
      } catch (_) {}
    },

    _newConv() {
      try {
        const ctx = this.ctx;
        if (!ctx) return;
        const now = ctx.currentTime;
        [440, 660].forEach(function (freq, i) {
          const osc = ctx.createOscillator();
          const gain = ctx.createGain();
          osc.type = 'sine';
          osc.frequency.value = freq;
          const t = now + i * 0.15;
          gain.gain.setValueAtTime(0.07, t);
          gain.gain.exponentialRampToValueAtTime(0.001, t + 0.2);
          osc.connect(gain);
          gain.connect(ctx.destination);
          osc.start(t);
          osc.stop(t + 0.2);
        });
      } catch (_) {}
    },
  };

  // ──────────────────────────────────────────
  // 1. Smart Filters Panel (Inbox)
  // ──────────────────────────────────────────
  const FiltersPanel = {
    state: {
      search: '',
      status: [],
      department: [],
      dateRange: null,
      tags: [],
      assigned: null,
      priority: [],
    },
    key: 'atendeflow-inbox-filters',

    init() {
      this.restore();
      this.bindEvents();
    },

    restore() {
      try {
        const saved = localStorage.getItem(this.key);
        if (saved) Object.assign(this.state, JSON.parse(saved));
      } catch (_) {}
    },

    persist() {
      try {
        localStorage.setItem(this.key, JSON.stringify(this.state));
      } catch (_) {}
    },

    bindEvents() {
      const searchInput = document.querySelector('.pane-search-input');
      if (searchInput) {
        searchInput.addEventListener('input', (e) => {
          this.state.search = e.target.value;
          this.persist();
        });
      }

      document.querySelectorAll('[data-filter-status]').forEach((el) => {
        el.addEventListener('click', (e) => {
          e.preventDefault();
          const val = el.dataset.filterStatus;
          const idx = this.state.status.indexOf(val);
          if (idx >= 0) this.state.status.splice(idx, 1);
          else this.state.status.push(val);
          this.persist();
          this.apply();
        });
      });

      document.querySelectorAll('[data-filter-priority]').forEach((el) => {
        el.addEventListener('click', (e) => {
          e.preventDefault();
          const val = el.dataset.filterPriority;
          const idx = this.state.priority.indexOf(val);
          if (idx >= 0) this.state.priority.splice(idx, 1);
          else this.state.priority.push(val);
          this.persist();
          this.apply();
        });
      });
    },

    apply() {
      const params = new URLSearchParams(window.location.search);
      if (this.state.status.length) params.set('fstatus', this.state.status.join(','));
      else params.delete('fstatus');
      if (this.state.priority.length) params.set('priority', this.state.priority.join(','));
      else params.delete('priority');
      if (this.state.search) params.set('search', this.state.search);
      else params.delete('search');
      const qs = params.toString();
      const url = window.location.pathname + (qs ? '?' + qs : '');
      if (url !== window.location.href.split('?')[0] + (qs ? '?' + qs : '')) {
        window.location.href = url;
      }
    },

    clear() {
      this.state = { search: '', status: [], department: [], dateRange: null, tags: [], assigned: null, priority: [] };
      this.persist();
      window.location.href = window.location.pathname;
    },
  };

  // ──────────────────────────────────────────
  // 2. Drag & Drop for Conversation Items
  // ──────────────────────────────────────────
  const DragManager = {
    dragged: null,

    init() {
      const items = document.querySelectorAll('.conversation-item');
      if (!items.length) return;

      items.forEach((item) => {
        item.setAttribute('draggable', true);
        item.addEventListener('dragstart', (e) => this.onStart(e, item));
        item.addEventListener('dragend', (e) => this.onEnd(e, item));
        item.addEventListener('dragover', (e) => this.onOver(e, item));
        item.addEventListener('dragleave', (e) => this.onLeave(e, item));
        item.addEventListener('drop', (e) => this.onDrop(e, item));
      });
    },

    onStart(e, item) {
      this.dragged = item;
      item.classList.add('dragging');
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', item.dataset.convId || '');
    },

    onEnd(_e, item) {
      this.dragged = null;
      item.classList.remove('dragging');
      document.querySelectorAll('.conversation-item').forEach((el) => el.classList.remove('drag-over'));
    },

    onOver(e, item) {
      if (this.dragged === item) return;
      e.preventDefault();
      item.classList.add('drag-over');
    },

    onLeave(_e, item) {
      item.classList.remove('drag-over');
    },

    onDrop(e, item) {
      e.preventDefault();
      item.classList.remove('drag-over');
      if (this.dragged && this.dragged !== item) {
        const parent = item.parentNode;
        const items = [...parent.querySelectorAll('.conversation-item')];
        const fromIdx = items.indexOf(this.dragged);
        const toIdx = items.indexOf(item);
        if (fromIdx >= 0 && toIdx >= 0) {
          if (fromIdx < toIdx) {
            item.parentNode.insertBefore(this.dragged, item.nextSibling);
          } else {
            item.parentNode.insertBefore(this.dragged, item);
          }
        }
      }
    },
  };

  // ──────────────────────────────────────────
  // 3. Composer Keyboard Shortcuts
  // ──────────────────────────────────────────
  const ComposerShortcuts = {
    init() {
      this.setupShortcuts();
      this.setupQuickEmoji();
    },

    setupShortcuts() {
      document.addEventListener('keydown', (e) => {
        const ctrl = e.ctrlKey || e.metaKey;
        if (!ctrl) return;
        const key = e.key.toLowerCase();

        if (key === 'k') {
          e.preventDefault();
          this.openMacroPicker();
        } else if (key === 'e') {
          e.preventDefault();
          this.toggleEmojiPicker();
        }
      });
    },

    openMacroPicker() {
      const btn = document.querySelector('[onclick*="openMacroModal"]') || document.querySelector('.composer-btn[title*="Macro"]');
      if (btn) btn.click();
    },

    toggleEmojiPicker() {
      const btn = document.getElementById('emojiToggle');
      if (btn) btn.click();
    },

    setupQuickEmoji() {
      const textarea = document.getElementById('messageInput');
      if (!textarea) return;

      let pickerTimer = null;
      textarea.addEventListener('input', () => {
        clearTimeout(pickerTimer);
        const cursorPos = textarea.selectionStart;
        const before = textarea.value.substring(0, cursorPos);
        const match = before.match(/:\w*$/);
        if (match) {
          pickerTimer = setTimeout(() => this.showEmojiSuggestion(match[0]), 200);
        }
      });
    },

    showEmojiSuggestion(_partial) {
      const picker = document.getElementById('emojiPopover');
      if (picker && picker.style.display !== 'none') return;
      const btn = document.getElementById('emojiToggle');
      if (btn) btn.click();
    },
  };

  // ──────────────────────────────────────────
  // 4. Typo Correction & Auto-Completion
  // ──────────────────────────────────────────
  const FormEnhancer = {
    init() {
      this.fixEmailFields();
      this.fixPhoneFields();
      this.fixNameFields();
    },

    fixEmailFields() {
      document.querySelectorAll('input[type="email"], input[data-autofix="email"]').forEach((el) => {
        el.addEventListener('blur', () => {
          let val = el.value.trim();
          if (!val) return;
          const corrections = {
            'gmai.com': 'gmail.com',
            'yaho.com': 'yahoo.com',
            'gmil.com': 'gmail.com',
            'outlok.com': 'outlook.com',
            'hotmial.com': 'hotmail.com',
            'gmail.co': 'gmail.com',
            'yahoo.co': 'yahoo.com',
          };
          const domain = val.split('@')[1];
          if (domain && corrections[domain]) {
            const fixed = val.replace('@' + domain, '@' + corrections[domain]);
            if (fixed !== val && confirm('Corrigir "' + val + '" para "' + fixed + '"?')) {
              el.value = fixed;
              el.dispatchEvent(new Event('change', { bubbles: true }));
            }
          }
        });
      });
    },

    fixPhoneFields() {
      document.querySelectorAll('input[type="tel"], input[data-autofix="phone"]').forEach((el) => {
        el.addEventListener('blur', () => {
          let val = el.value.replace(/\D/g, '');
          if (!val) return;
          if (val.length <= 10) {
            val = '55' + val;
          }
          if (val.length === 12 && val.startsWith('55')) {
            val = '55' + val.substring(2);
          }
          if (val !== el.value.replace(/\D/g, '') && val.length >= 10) {
            el.value = val;
          }
        });
      });
    },

    fixNameFields() {
      document.querySelectorAll('input[data-autofix="name"]').forEach((el) => {
        el.addEventListener('blur', () => {
          const val = el.value.trim();
          if (!val) return;
          const fixed = val
            .split(/\s+/)
            .map((w) => w.charAt(0).toUpperCase() + w.slice(1).toLowerCase())
            .join(' ');
          if (fixed !== val) {
            el.value = fixed;
            el.dispatchEvent(new Event('change', { bubbles: true }));
          }
        });
      });
    },
  };

  // ──────────────────────────────────────────
  // 5. Keyboard Navigation (Inbox List)
  // ──────────────────────────────────────────
  const KeyboardNav = {
    init() {
      this.items = [];
      this.currentIdx = -1;
      this.refreshItems();
      this.bindKeys();
    },

    refreshItems() {
      this.items = Array.from(document.querySelectorAll('.conversation-item'));
      const active = this.items.findIndex((el) => el.classList.contains('active'));
      this.currentIdx = active >= 0 ? active : -1;
    },

    bindKeys() {
      document.addEventListener('keydown', (e) => {
        if (!this.items.length) return;
        if (/^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName || '')) return;
        const isConvPage = document.querySelector('.inbox-page');
        if (!isConvPage) return;

        const k = e.key;

        if (k === 'ArrowDown' || (k === 'n' && (e.ctrlKey || e.metaKey))) {
          e.preventDefault();
          this.moveTo(Math.min(this.currentIdx + 1, this.items.length - 1));
        } else if (k === 'ArrowUp' || (k === 'p' && (e.ctrlKey || e.metaKey))) {
          e.preventDefault();
          this.moveTo(Math.max(this.currentIdx - 1, 0));
        } else if (k === 'Enter' && this.currentIdx >= 0) {
          e.preventDefault();
          this.items[this.currentIdx].click();
        } else if (k === 'Home') {
          e.preventDefault();
          this.moveTo(0);
        } else if (k === 'End') {
          e.preventDefault();
          this.moveTo(this.items.length - 1);
        }
      });
    },

    moveTo(idx) {
      this.items.forEach((el) => el.classList.remove('kb-highlight'));
      this.currentIdx = idx;
      const el = this.items[idx];
      if (el) {
        el.classList.add('kb-highlight');
        el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      }
    },
  };

  // ──────────────────────────────────────────
  // 6. Infinite Scroll (Inbox List)
  // ──────────────────────────────────────────
  const InfiniteScroll = {
    page: 1,
    loading: false,
    hasMore: true,
    sentinel: null,
    pageSize: 30,

    init() {
      const list = document.getElementById('conversationsList');
      if (!list) return;
      this.list = list;

      this.sentinel = document.createElement('div');
      this.sentinel.className = 'load-more-trigger';
      this.sentinel.textContent = 'Carregar mais...';
      this.sentinel.addEventListener('click', () => this.loadMore());
      list.appendChild(this.sentinel);

      this.observer = new IntersectionObserver((entries) => {
        if (entries[0].isIntersecting && this.hasMore && !this.loading) {
          this.loadMore();
        }
      }, { rootMargin: '200px' });
      this.observer.observe(this.sentinel);
    },

    loadMore() {
      if (this.loading || !this.hasMore) return;
      this.loading = true;
      this.sentinel.classList.add('loading');
      this.sentinel.textContent = 'Carregando...';

      const params = new URLSearchParams(window.location.search);
      params.set('page', this.page + 1);
      params.set('per_page', this.pageSize);
      const url = window.location.pathname + '?ajax=1&' + params.toString();

      fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then((r) => r.json())
        .then((data) => {
          if (data && data.html) {
            this.sentinel.insertAdjacentHTML('beforebegin', data.html);
            this.page++;
            this.hasMore = data.has_more !== false;
            DragManager.init();
            KeyboardNav.refreshItems();
          } else {
            this.hasMore = false;
          }
        })
        .catch(() => {
          this.hasMore = false;
        })
        .finally(() => {
          this.loading = false;
          this.sentinel.classList.remove('loading');
          this.sentinel.textContent = this.hasMore ? 'Carregar mais...' : '';
          if (!this.hasMore) {
            this.observer.unobserve(this.sentinel);
            this.sentinel.remove();
          }
        });
    },
  };

  // ──────────────────────────────────────────
  // 7. Tag Manager Filter Enhancement
  // ──────────────────────────────────────────
  const TagManagerEnhancer = {
    init() {
      this.addFilterInput();
    },

    addFilterInput() {
      const grid = document.getElementById('tagGrid');
      if (!grid) return;

      const pickerSection = grid.closest('.tag-picker-section');
      if (!pickerSection) return;

      const filterRow = document.createElement('div');
      filterRow.style.cssText = 'padding: 6px 10px;';
      filterRow.innerHTML =
        '<input type="text" class="tag-filter-input" placeholder="Filtrar etiquetas..." ' +
        'style="width:100%;padding:5px 8px;border:1px solid var(--border-color);border-radius:6px;font-size:12px;outline:none;box-sizing:border-box">';

      const header = pickerSection.querySelector('.tag-picker-header');
      if (header) {
        header.after(filterRow);

        const input = filterRow.querySelector('.tag-filter-input');
        input.addEventListener('input', () => {
          const q = input.value.toLowerCase().trim();
          grid.querySelectorAll('.tag-grid-item').forEach((btn) => {
            const match = !q || btn.textContent.toLowerCase().includes(q);
            btn.style.display = match ? '' : 'none';
          });
        });
      }
    },
  };

  // ──────────────────────────────────────────
  // 8. Mobile Search Bar Fix
  // ──────────────────────────────────────────
  const MobileSearchFix = {
    init() {
      if (window.innerWidth > 768) return;
      const searchForm = document.getElementById('inboxSearchForm');
      if (!searchForm) return;
      const input = searchForm.querySelector('.pane-search-input');
      if (!input) return;

      input.addEventListener('focus', () => {
        input.style.width = '100%';
        const tabs = document.getElementById('inboxTabs');
        if (tabs) tabs.style.display = 'none';
      });

      input.addEventListener('blur', () => {
        setTimeout(() => {
          const tabs = document.getElementById('inboxTabs');
          if (tabs && !input.value) tabs.style.display = '';
        }, 200);
      });
    },
  };

  // ──────────────────────────────────────────
  // 9. Conversation List Live Polling
  // ──────────────────────────────────────────
  const ConvListPoller = {
    pollTimer: null,
    knownIds: [],
    _firstPoll: true,
    channelColors: {
      whatsapp: '#25D366',
      webchat: '#4361ee',
      email: '#f59e0b',
      telegram: '#0088cc',
      facebook: '#1877f2',
      instagram: '#e1306c',
      phone: '#6c757d',
    },

    init() {
      this.refreshKnownIds();
      this.start();
    },

    refreshKnownIds() {
      this.knownIds = Array.from(document.querySelectorAll('.conversation-item'))
        .map(function (el) { return parseInt(el.dataset.convId, 10); })
        .filter(function (id) { return !isNaN(id); });
    },

    start() {
      this.poll();
      this.pollTimer = setInterval(this.poll.bind(this), 12000);
    },

    stop() {
      if (this.pollTimer) {
        clearInterval(this.pollTimer);
        this.pollTimer = null;
      }
    },

    _apiUrl(path) {
      // Use the API variable set by panel.php, or derive base from page
      if (typeof window.API !== 'undefined') return window.API + path;
      var link = document.querySelector('a[href*="/inbox"]');
      if (link) {
        var href = link.getAttribute('href');
        var m = href.match(/^(.+?)\/inbox/);
        if (m) return m[1] + '/api' + path;
      }
      return '/atendeflow/api' + path;
    },

    poll() {
      const list = document.getElementById('conversationsList');
      const emptyState = list && list.querySelector('.empty-state-enhanced');
      if (!list || emptyState) return;

      var fv = new URLSearchParams(window.location.search).get('fstatus');
      if (!fv) {
        var h = document.querySelector('input[name="fstatus"]');
        if (h) fv = h.value;
      }
      var url = this._apiUrl('/conversations');
      if (fv) url += (url.indexOf('?') > -1 ? '&' : '?') + 'fstatus=' + encodeURIComponent(fv);
      fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(this._onData.bind(this))
        .catch(function () {});
    },

    _onData(data) {
      if (!Array.isArray(data) || !data.length) return;
      var self = this;

      // Reorder / update the DOM
      var container = document.getElementById('conversationsList');
      if (!container) return;

      var currentItems = {};
      container.querySelectorAll('.conversation-item').forEach(function (el) {
        var id = parseInt(el.dataset.convId, 10);
        if (!isNaN(id)) currentItems[id] = el;
      });

      var baseUrl = window.location.pathname + '?';
      var sp = new URLSearchParams(window.location.search);
      var inboxParam = sp.get('inbox');
      if (inboxParam) baseUrl += 'inbox=' + encodeURIComponent(inboxParam) + '&';
      var fstatusParam = sp.get('fstatus');
      if (fstatusParam) baseUrl += 'fstatus=' + encodeURIComponent(fstatusParam) + '&';
      baseUrl += 'conv=';

      // Get active conv id
      var activeEl = container.querySelector('.conversation-item.active');
      var activeId = activeEl ? parseInt(activeEl.dataset.convId, 10) : null;

      var frag = document.createDocumentFragment();
      data.forEach(function (c, idx) {
        var id = parseInt(c.id, 10);
        var existing = currentItems[id];

        if (existing) {
          // Update existing item
          self._updateItem(existing, c);
          frag.appendChild(existing);
        } else {
          // Create new item
          var item = self._createItem(c, baseUrl, activeId);
          frag.appendChild(item);
        }
      });

      container.innerHTML = '';
      container.appendChild(frag);

      // Re-init dependent features
      DragManager.init();
      KeyboardNav.refreshItems();

      // Track known IDs
      this.refreshKnownIds();

      // --- Notifications & Sounds ---
      // Skip sounds/notifications on first poll (avoids burst on page load / conv switch)
      if (this._firstPoll) { this._firstPoll = false; }
      else {
        var convView = document.getElementById('convView');
        var viewingConvId = convView ? convView.dataset.conv : null;

        function isViewingThis(id) { return viewingConvId === String(id); }
        function hasFocus() { return document.visibilityState === 'visible'; }

        data.forEach(function (c) {
          var id = parseInt(c.id, 10);
          var oldItem = currentItems[id];
          var oldUnread = oldItem ? parseInt(oldItem.dataset.unread || '0', 10) : 0;
          var newUnread = parseInt(c.unread_count || '0', 10);

          var gotNewMsg = !oldItem || (newUnread > oldUnread);

          if (gotNewMsg && !isViewingThis(id)) {
            if (!oldItem) {
              SoundManager.play('new_conv');
              self._notifyBrowser('Novo atendimento: ' + (c.contact_name || 'Cliente'), c);
            } else {
              SoundManager.play('incoming');
              if (!hasFocus()) {
                self._notifyBrowser(
                  (c.contact_name || 'Cliente') + ' enviou mensagem',
                  c
                );
              }
            }
          }
        });
      } // end else (not first poll)

      // Update message counts on data attributes
      container.querySelectorAll('.conversation-item').forEach(function (el) {
        var id = parseInt(el.dataset.convId, 10);
        var match = data.find(function (c) { return parseInt(c.id, 10) === id; });
        if (match) {
          el.dataset.msgCount = String(match.message_count || 0);
        }
      });
    },

    _esc(s) {
      if (s == null) return '';
      var d = document.createElement('div');
      d.textContent = String(s);
      return d.innerHTML;
    },

    _notifyBrowser(body, c) {
      if ('Notification' in window && Notification.permission === 'granted') {
        try {
          new Notification('AtendeFlow', {
            body: body,
            icon: '/assets/img/favicon.png',
            tag: 'conv-' + (c ? c.id : ''),
            requireInteraction: true,
          });
        } catch (_) {}
      } else if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission();
      }
    },

    _timeAgo(dt) {
      if (!dt) return '';
      var d = new Date(String(dt).replace(' ', 'T'));
      if (isNaN(d)) return '';
      var now = new Date();
      var diffMs = now - d;
      var sec = Math.floor(diffMs / 1000);
      if (sec < 60) return 'agora';
      var min = Math.floor(sec / 60);
      if (min < 60) return min + 'min';
      var hr = Math.floor(min / 60);
      if (hr < 24) return hr + 'h';
      var days = Math.floor(hr / 24);
      if (days < 30) return days + 'd';
      return days + 'd';
    },

    _truncate(text, limit) {
      if (!text) return 'Sem mensagens';
      if (text.length <= limit) return text;
      return text.substring(0, limit) + '...';
    },

    _statusLabel(s) {
      var map = { new: 'Novo', open: 'Aberto', waiting_customer: 'Em atendimento', waiting_internal: 'Aguardando Interno', resolved: 'Resolvido', closed: 'Fechado', spam: 'Spam' };
      return map[s] || s;
    },

    _priorityLabel(p) {
      var map = { low: 'Baixa', normal: 'Normal', high: 'Alta', urgent: 'Urgente' };
      return map[p] || p;
    },

    _createItem(c, baseUrl, activeId) {
      var id = parseInt(c.id, 10);
      var el = document.createElement('a');
      el.href = baseUrl + id;
      el.className = 'conversation-item conv-card-hover' + (id === activeId ? ' active' : '');
      el.dataset.convId = id;
      el.dataset.msgCount = String(c.message_count || 0);
      el.dataset.unread = String(c.unread_count || 0);

      var initial = (c.contact_name || '?').charAt(0).toUpperCase();
      var avatarHtml;
      if (c.contact_avatar) {
        var src = c.contact_avatar.indexOf('http') === 0 ? c.contact_avatar : '/uploads/' + c.contact_avatar;
        avatarHtml = '<img class="avatar" src="' + this._esc(src) + '" alt="">';
      } else {
        avatarHtml = '<div class="avatar avatar-placeholder-sm">' + this._esc(initial) + '</div>';
      }
      var dotHtml = (c.status === 'open' || c.status === 'new')
        ? '<div class="status-badge-dot"></div>' : '';
      var unread = parseInt(c.unread_count, 10) || 0;
      var unreadHtml = unread > 0
        ? '<span class="unread-badge">' + (unread > 99 ? '99+' : unread) + '</span>'
        : '';

      var chColor = this.channelColors[c.channel_type] || '#6c757d';
      var chIcon = c.channel_type === 'whatsapp' ? 'fab fa-whatsapp'
        : c.channel_type === 'email' ? 'fas fa-envelope'
        : 'fas fa-comment-dots';

      var preview = this._truncate(c.last_message, 80);
      var timeHtml = this._timeAgo(c.last_message_at || c.created_at);
      var subjectHtml = c.subject
        ? '<div class="convo-subject">' + this._esc(c.subject) + '</div>'
        : '';

      el.innerHTML =
        '<div class="avatar-container">' + avatarHtml + dotHtml + unreadHtml + '</div>' +
        '<div class="convo-info">' +
          '<div class="convo-header">' +
            '<span class="convo-name">' + this._esc(c.contact_name) + '</span>' +
            '<span class="convo-time">' + this._esc(timeHtml) + '</span>' +
          '</div>' +
          subjectHtml +
          '<p class="convo-preview">' + this._esc(preview) + '</p>' +
          '<div class="convo-meta">' +
            '<span class="convo-channel" style="background:' + chColor + '">' +
              '<i class="' + chIcon + '"></i> ' + this._esc(c.channel_name || '') +
            '</span>' +
            '<span class="priority-badge priority-' + c.priority + '">' + this._priorityLabel(c.priority) + '</span>' +
            '<span class="status-badge status-' + c.status + '">' + this._statusLabel(c.status) + '</span>' +
          '</div>' +
          '<div class="convo-footer">' +
            '<span><i class="fas fa-user"></i> ' + this._esc(c.assigned_user_name || 'Não atribuído') + '</span>' +
            '<span><i class="fas fa-comment-dots"></i> ' + (parseInt(c.message_count, 10) || 0) + '</span>' +
            (c.department_name ? '<span><i class="fas fa-layer-group"></i> ' + this._esc(c.department_name) + '</span>' : '') +
          '</div>' +
        '</div>';

      // Mobile navigation
      el.addEventListener('click', function (e) {
        if (window.innerWidth <= 768) {
          document.querySelector('.inbox-3col')?.classList.add('show-detail');
        }
      });

      return el;
    },

    _updateItem(el, c) {
      if (!el) return;

      var prevUnread = parseInt(el.dataset.unread || '0', 10);
      var newUnread = parseInt(c.unread_count || '0', 10);
      el.dataset.unread = String(newUnread);

      // Update unread badge
      var avatarContainer = el.querySelector('.avatar-container');
      var existingBadge = avatarContainer ? avatarContainer.querySelector('.unread-badge') : null;
      if (newUnread > 0) {
        var badgeText = newUnread > 99 ? '99+' : newUnread;
        if (existingBadge) {
          existingBadge.textContent = badgeText;
        } else if (avatarContainer) {
          var newBadge = document.createElement('span');
          newBadge.className = 'unread-badge';
          newBadge.textContent = badgeText;
          avatarContainer.appendChild(newBadge);
        }
      } else if (existingBadge) {
        existingBadge.remove();
      }

      // Update preview
      var previewEl = el.querySelector('.convo-preview');
      if (previewEl) {
        var newPreview = this._truncate(c.last_message, 80);
        if (previewEl.textContent !== newPreview) {
          previewEl.textContent = newPreview;
          previewEl.classList.add('conv-preview-flash');
          var self = this;
          setTimeout(function () { previewEl.classList.remove('conv-preview-flash'); }, 600);
        }
      }

      // Update time
      var timeEl = el.querySelector('.convo-time');
      if (timeEl) {
        var newTime = this._timeAgo(c.last_message_at || c.created_at);
        timeEl.textContent = newTime;
      }

      // Update message count in footer
      var footer = el.querySelector('.convo-footer');
      if (footer) {
        var countSpans = footer.querySelectorAll('span');
        if (countSpans.length >= 2) {
          countSpans[1].innerHTML = '<i class="fas fa-comment-dots"></i> ' + (parseInt(c.message_count, 10) || 0);
        }
      }

      // Update status badge
      var statusBadge = el.querySelector('.status-badge');
      if (statusBadge) {
        var statusClasses = ['status-new', 'status-open', 'status-waiting_customer', 'status-waiting_internal', 'status-resolved', 'status-closed', 'status-spam'];
        statusClasses.forEach(function (cls) { statusBadge.classList.remove(cls); });
        statusBadge.classList.add('status-' + c.status);
        statusBadge.textContent = this._statusLabel(c.status);
      }

      // Update priority badge
      var priorityBadge = el.querySelector('.priority-badge');
      if (priorityBadge) {
        var priorityClasses = ['priority-low', 'priority-normal', 'priority-high', 'priority-urgent'];
        priorityClasses.forEach(function (cls) { priorityBadge.classList.remove(cls); });
        priorityBadge.classList.add('priority-' + c.priority);
        priorityBadge.textContent = this._priorityLabel(c.priority);
      }

      // Update subject
      var subjectEl = el.querySelector('.convo-subject');
      if (c.subject) {
        if (!subjectEl) {
          var previewEl2 = el.querySelector('.convo-preview');
          var subj = document.createElement('div');
          subj.className = 'convo-subject';
          subj.textContent = c.subject;
          if (previewEl2) previewEl2.parentNode.insertBefore(subj, previewEl2);
        } else if (subjectEl.textContent !== c.subject) {
          subjectEl.textContent = c.subject;
        }
      } else if (subjectEl) {
        subjectEl.remove();
      }

      // Update status dot
      var dot = el.querySelector('.status-badge-dot');
      if (dot) {
        var show = c.status === 'open' || c.status === 'new';
        dot.style.display = show ? '' : 'none';
      } else if (c.status === 'open' || c.status === 'new') {
        if (avatarContainer) {
          var newDot = document.createElement('div');
          newDot.className = 'status-badge-dot';
          avatarContainer.appendChild(newDot);
        }
      }
    },
  };

  // ──────────────────────────────────────────
  // Init on DOM ready
  // ──────────────────────────────────────────
  function init() {
    if (document.querySelector('.inbox-page')) {
      FiltersPanel.init();
      DragManager.init();
      KeyboardNav.init();
      InfiniteScroll.init();
      MobileSearchFix.init();
      ConvListPoller.init();
    }
    if (document.getElementById('messageInput')) {
      ComposerShortcuts.init();
    }
    FormEnhancer.init();
    TagManagerEnhancer.init();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.__enhancements = {
    FiltersPanel, DragManager, ComposerShortcuts, FormEnhancer,
    KeyboardNav, InfiniteScroll, ConversationCache, TagManagerEnhancer, MobileSearchFix,
    ConvListPoller, SoundManager,
  };
})();
