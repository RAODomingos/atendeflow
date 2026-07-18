/* ==========================================
   AtendeFlow - UI/UX Enhancements
   Phase 3: Keyboard nav, infinite scroll,
            cache, loading states, sound,
            live conversation list update
   ========================================== */

(function () {
  'use strict';

  // ──────────────────────────────────────────
  // 0b. Sound Profiles
  // ──────────────────────────────────────────
  const SoundProfiles = {
    message: {
      default: { freq: 520, duration: 0.08, vol: 0.3 },
      soft: { freq: 400, duration: 0.10, vol: 0.15 },
      sharp: { freq: 800, duration: 0.06, vol: 0.4 },
      silent: null,
    },
    new_conv: {
      default: { type: 'two-tone', freqs: [440, 660], duration: 0.25, vol: 0.35 },
      soft: { type: 'two-tone', freqs: [350, 520], duration: 0.25, vol: 0.18 },
      sharp: { type: 'two-tone', freqs: [700, 900], duration: 0.2, vol: 0.45 },
      silent: null,
    },
    incoming: {
      default: { freq: 600, duration: 0.15, vol: 0.3 },
      soft: { freq: 480, duration: 0.15, vol: 0.15 },
      sharp: { freq: 850, duration: 0.12, vol: 0.4 },
      silent: null,
    },
  };

  // ──────────────────────────────────────────
  // 0c. Sound Manager (Web Audio API)
  // ──────────────────────────────────────────
  const SoundManager = {
    _ctx: null,
    _enabled: true,
    _profile: 'default',
    _typeProfiles: {},

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

    _initAudioOnGesture() {
      var self = this;
      var initFn = function() {
        document.removeEventListener('click', initFn);
        document.removeEventListener('keydown', initFn);
        document.removeEventListener('touchstart', initFn);
        try {
          var ac = self.ctx;
          if (ac && ac.state === 'suspended') {
            ac.resume().then(function() {
              var osc = ac.createOscillator();
              var gain = ac.createGain();
              gain.gain.value = 0.01;
              osc.connect(gain);
              gain.connect(ac.destination);
              osc.start(0);
              osc.stop(ac.currentTime + 0.01);
            }).catch(function() {});
          }
        } catch (_) {}
      };
      document.addEventListener('click', initFn);
      document.addEventListener('keydown', initFn);
      document.addEventListener('touchstart', initFn);
    },

    setProfile(profile) {
      this._profile = (profile && SoundProfiles.message[profile]) ? profile : 'default';
    },

    setTypeProfile(type, profile) {
      if (SoundProfiles[type] && SoundProfiles[type][profile]) {
        this._typeProfiles[type] = profile;
      } else {
        delete this._typeProfiles[type];
      }
    },

    play(type, profile) {
      if (!this._enabled) return;
      var p = profile || this._typeProfiles[type] || this._profile;
      var cfg = SoundProfiles[type];
      if (!cfg) return;
      var sound = cfg[p] || cfg['default'];
      if (!sound) return;
      try {
        var ac = this.ctx;
        if (!ac) return;
        if (ac.state === 'suspended') { ac.resume(); }
        if (type === 'new_conv' && sound.type === 'two-tone') {
          this._newConv(sound.freqs, sound.duration, sound.vol);
        } else if (sound.freq) {
          this._beep(sound.freq, sound.duration, sound.vol);
        }
      } catch (_) {}
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

    _newConv(freqs, duration, vol) {
      try {
        const ctx = this.ctx;
        if (!ctx) return;
        const now = ctx.currentTime;
        (freqs || [440, 660]).forEach(function (freq, i) {
          const osc = ctx.createOscillator();
          const gain = ctx.createGain();
          osc.type = 'sine';
          osc.frequency.value = freq;
          const t = now + i * 0.15;
          gain.gain.setValueAtTime(vol || 0.07, t);
          gain.gain.exponentialRampToValueAtTime(0.001, t + (duration || 0.2));
          osc.connect(gain);
          gain.connect(ctx.destination);
          osc.start(t);
          osc.stop(t + (duration || 0.2));
        });
      } catch (_) {}
    },
  };

  // ──────────────────────────────────────────
  // 1. Drag & Drop for Conversation Items
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
    },

    fixEmailFields() {
      document.querySelectorAll('input[type="email"]').forEach((el) => {
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
      document.querySelectorAll('input[type="tel"]').forEach((el) => {
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
  // ──────────────────────────────────────────
  // LiveFeed — Notificações em tempo real
  // ──────────────────────────────────────────
  const LiveFeed = {
    _known: {},
    _viewingId: null,
    _pollTimer: null,
    _polling: false,
    _ready: false,

    init() {
      document.querySelectorAll('.conversation-item').forEach(function(el) {
        var id = parseInt(el.dataset.convId, 10);
        if (!isNaN(id)) this._known[id] = parseInt(el.dataset.unread || '0', 10);
      }, this);

      var cv = document.getElementById('convView');
      this._viewingId = cv ? cv.dataset.conv : null;

      this._ready = true;
      this._poll();
      this._pollTimer = setInterval(this._poll.bind(this), 8000);
    },

    _baseUrl() {
      var u = document.querySelector('meta[name="base-url"]');
      return u ? u.getAttribute('content') : (window.location.origin + '/atendeflow');
    },

    _poll() {
      if (this._polling || !this._ready) return;
      this._polling = true;

      var url = this._baseUrl() + '/api/conversations';
      var p = new URLSearchParams(window.location.search);
      var fv = p.get('fstatus');
      if (!fv) { var h = document.querySelector('input[name="fstatus"]'); if (h) fv = h.value; }
      if (fv) url += '?fstatus=' + encodeURIComponent(fv);
      var iv = p.get('inbox');
      if (!iv) { var ih = document.querySelector('input[name="inbox"]'); if (ih) iv = ih.value; }
      if (iv) url += (fv ? '&' : '?') + 'inbox=' + encodeURIComponent(iv);

      var self = this;
      fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r) { if (!r.ok) throw new Error(String(r.status)); return r.json(); })
        .then(function(data) { self._onData(data); self._polling = false; })
        .catch(function() { self._polling = false; });
    },

    _onData(data) {
      if (!Array.isArray(data)) return;
      var self = this;
      var container = document.getElementById('conversationsList');

      data.forEach(function(c) {
        var id = parseInt(c.id, 10);
        if (!id) return;

        var isKnown = (id in self._known);
        var prevUnread = self._known[id] || 0;
        var newUnread = parseInt(c.unread_count || '0', 10);

        if (!isKnown) {
          SoundManager.play('new_conv');
          self._notify('Novo atendimento: ' + (c.contact_name || 'Cliente'), c);
        } else if (newUnread > prevUnread && String(id) !== self._viewingId) {
          SoundManager.play('incoming');
          if (document.visibilityState !== 'visible') {
            self._notify((c.contact_name || 'Cliente') + ' enviou mensagem', c);
          }
        }

        self._known[id] = newUnread;
      });

      // Update the conversation list DOM
      if (!container) return;
      var currentMap = {};
      container.querySelectorAll('.conversation-item').forEach(function(el) {
        var eid = parseInt(el.dataset.convId, 10);
        if (!isNaN(eid)) currentMap[eid] = el;
      });

      data.forEach(function(c) {
        var id = parseInt(c.id, 10);
        var existing = currentMap[id];
        if (existing) {
          self._updateItem(existing, c);
        } else if (container) {
          var el = self._createItem(c);
          container.insertBefore(el, container.firstChild);
        }
      });

      // Remove stale items
      var apiIds = {};
      data.forEach(function(c) { apiIds[parseInt(c.id, 10)] = true; });
      Object.keys(currentMap).forEach(function(idStr) {
        if (!apiIds[parseInt(idStr, 10)]) {
          var el = currentMap[parseInt(idStr, 10)];
          if (el && el.parentNode) el.parentNode.removeChild(el);
        }
      });
    },

    _createItem(c) {
      var id = parseInt(c.id, 10);
      var el = document.createElement('a');
      var baseUrl = window.location.pathname + '?conv=';
      el.href = baseUrl + id;
      el.className = 'conversation-item conv-card-hover';
      el.dataset.convId = id;
      el.dataset.msgCount = String(c.message_count || 0);
      el.dataset.unread = String(c.unread_count || 0);

      var initial = (c.contact_name || '?').charAt(0).toUpperCase();
      var src;
      if (c.contact_avatar) {
        src = c.contact_avatar.indexOf('http') === 0 ? c.contact_avatar : this._baseUrl() + '/uploads/' + c.contact_avatar;
      }
      var un = parseInt(c.unread_count || '0', 10);
      var chColor = { whatsapp: '#25D366', webchat: '#4361ee', email: '#f59e0b', telegram: '#0088cc', facebook: '#1877f2', instagram: '#e1306c', phone: '#6c757d' }[c.channel_type] || '#6c757d';

      el.innerHTML =
        '<div class="avatar-container">' +
          (c.contact_avatar ? '<img class="avatar" src="' + this._esc(src) + '" alt="">'
            : '<div class="avatar avatar-placeholder-sm">' + this._esc(initial) + '</div>') +
          ((c.status === 'open' || c.status === 'new') ? '<div class="status-badge-dot"></div>' : '') +
          (un > 0 ? '<span class="unread-badge">' + (un > 99 ? '99+' : un) + '</span>' : '') +
        '</div>' +
        '<div class="convo-info">' +
          '<div class="convo-header"><span class="convo-name">' + this._esc(c.contact_name) + '</span><span class="convo-time">' + this._timeAgo(c.last_message_at || c.created_at) + '</span></div>' +
          (c.subject ? '<div class="convo-subject">' + this._esc(c.subject) + '</div>' : '') +
          '<p class="convo-preview">' + this._esc(this._truncate(c.last_message, 80)) + '</p>' +
          '<div class="convo-meta">' +
            '<span class="convo-channel" style="background:' + chColor + '"><i class="' + (c.channel_type === 'whatsapp' ? 'fab fa-whatsapp' : c.channel_type === 'email' ? 'fas fa-envelope' : 'fas fa-comment-dots') + '"></i> ' + this._esc(c.channel_name || '') + '</span>' +
            '<span class="priority-badge priority-' + c.priority + '">' + this._priorityLabel(c.priority) + '</span>' +
            '<span class="status-badge status-' + c.status + '">' + this._statusLabel(c.status) + '</span>' +
          '</div>' +
          '<div class="convo-footer">' +
            '<span><i class="fas fa-user"></i> ' + this._esc(c.assigned_user_name || 'Não atribuído') + '</span>' +
            '<span><i class="fas fa-comment-dots"></i> ' + (parseInt(c.message_count, 10) || 0) + '</span>' +
            (c.department_name ? '<span><i class="fas fa-layer-group"></i> ' + this._esc(c.department_name) + '</span>' : '') +
          '</div>' +
        '</div>';

      return el;
    },

    _updateItem(el, c) {
      if (!el) return;
      var nu = parseInt(c.unread_count || '0', 10);
      el.dataset.unread = String(nu);
      var ac = el.querySelector('.avatar-container');
      var eb = ac ? ac.querySelector('.unread-badge') : null;
      if (nu > 0) {
        var bt = nu > 99 ? '99+' : nu;
        if (eb) eb.textContent = bt;
        else if (ac) { var nb = document.createElement('span'); nb.className = 'unread-badge'; nb.textContent = bt; ac.appendChild(nb); }
      } else if (eb) eb.remove();

      var pe = el.querySelector('.convo-preview');
      if (pe) { var np = this._truncate(c.last_message, 80); if (pe.textContent !== np) { pe.textContent = np; pe.classList.add('conv-preview-flash'); setTimeout(function() { pe.classList.remove('conv-preview-flash'); }, 600); } }

      var te = el.querySelector('.convo-time');
      if (te) te.textContent = this._timeAgo(c.last_message_at || c.created_at);

      var ft = el.querySelector('.convo-footer');
      if (ft) { var cs = ft.querySelectorAll('span'); if (cs.length >= 2) cs[1].innerHTML = '<i class="fas fa-comment-dots"></i> ' + (parseInt(c.message_count, 10) || 0); }

      var sb = el.querySelector('.status-badge');
      if (sb) { ['status-new','status-open','status-waiting_customer','status-waiting_internal','status-resolved','status-closed','status-spam'].forEach(function(cn) { sb.classList.remove(cn); }); sb.classList.add('status-' + c.status); sb.textContent = this._statusLabel(c.status); }

      var pb = el.querySelector('.priority-badge');
      if (pb) { ['priority-low','priority-normal','priority-high','priority-urgent'].forEach(function(cn) { pb.classList.remove(cn); }); pb.classList.add('priority-' + c.priority); pb.textContent = this._priorityLabel(c.priority); }

      var dt = el.querySelector('.status-badge-dot');
      if (dt) dt.style.display = (c.status === 'open' || c.status === 'new') ? '' : 'none';
      else if ((c.status === 'open' || c.status === 'new') && ac) { var nd = document.createElement('div'); nd.className = 'status-badge-dot'; ac.appendChild(nd); }
    },

    _esc(s) { if (s == null) return ''; var d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; },

    _timeAgo(dt) {
      if (!dt) return '';
      var d = new Date(String(dt).replace(' ', 'T'));
      if (isNaN(d)) return '';
      var diff = Math.floor((Date.now() - d) / 1000);
      if (diff < 60) return 'agora';
      if (diff < 3600) return Math.floor(diff / 60) + 'min';
      if (diff < 86400) return Math.floor(diff / 3600) + 'h';
      var days = Math.floor(diff / 86400);
      return (days < 30 ? days : days) + 'd';
    },

    _truncate(t, l) { if (!t) return 'Sem mensagens'; return t.length <= l ? t : t.substring(0, l) + '...'; },
    _statusLabel(s) { return ({ new:'Novo', open:'Aberto', waiting_customer:'Em atendimento', waiting_internal:'Aguardando Interno', resolved:'Resolvido', closed:'Fechado', spam:'Spam' })[s] || s; },
    _priorityLabel(p) { return ({ low:'Baixa', normal:'Normal', high:'Alta', urgent:'Urgente' })[p] || p; },

    _notify(body, c) {
      if ('Notification' in window && Notification.permission === 'granted') {
        try { new Notification('AtendeFlow', { body: body, icon: '/assets/img/favicon.png', tag: 'conv-' + (c ? c.id : ''), requireInteraction: true }); } catch(_) {}
      } else if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission();
      }
    },
  };

  // ──────────────────────────────────────────
  // 10. Global Notification Manager
  // ──────────────────────────────────────────
  const GlobalNotifier = {
    eventSource: null,
    pollTimer: null,
    useSSE: true,
    lastUnreadMessages: -1,
    lastUnreadNotifications: -1,
    pollInterval: 20000,
    _prefsLoaded: false,
    _browserNotifEnabled: true,

    init() {
      this._loadLastCounts();
      this._loadPreferences();
      this._bindGesturePermissions();
      this._trySSE();
      if (!this.useSSE) {
        this._startPolling();
      }
    },

    _bindGesturePermissions() {
      var self = this;
      var initFn = function() {
        self._requestPermission();
        document.removeEventListener('click', initFn);
        document.removeEventListener('keydown', initFn);
      };
      document.addEventListener('click', initFn);
      document.addEventListener('keydown', initFn);
    },

    _loadPreferences() {
      var baseUrl = this._baseUrl();
      if (!baseUrl) return;
      var self = this;
      fetch(baseUrl + '/api/user-preferences', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          self._prefsLoaded = true;
          // Sound preferences
          if (data.sound_enabled === false) {
            SoundManager.disable();
          } else {
            SoundManager.enable();
            SoundManager.setTypeProfile('message', data.sound_new_message || 'default');
            SoundManager.setTypeProfile('new_conv', data.sound_new_conversation || 'default');
            SoundManager.setTypeProfile('incoming', data.sound_new_message || 'default');
            SoundManager.setProfile(data.sound_new_message || 'default');
          }
          self._browserNotifEnabled = data.browser_notif_enabled !== false;
        })
        .catch(function () {
          self._prefsLoaded = true;
        });
    },

    _loadLastCounts() {
      try {
        var saved = localStorage.getItem('atendeflow-unread');
        if (saved) {
          var data = JSON.parse(saved);
          this.lastUnreadMessages = data.messages || 0;
          this.lastUnreadNotifications = data.notifications || 0;
        }
      } catch (_) {}
    },

    _saveCounts() {
      try {
        localStorage.setItem('atendeflow-unread', JSON.stringify({
          messages: this.lastUnreadMessages,
          notifications: this.lastUnreadNotifications,
          time: Date.now(),
        }));
      } catch (_) {}
    },

    _baseUrl() {
      var m = document.querySelector('meta[name="base-url"]');
      return m ? m.getAttribute('content') : '';
    },

    _trySSE() {
      try {
        var baseUrl = this._baseUrl();
        if (!baseUrl) { this.useSSE = false; return; }

        this.eventSource = new EventSource(baseUrl + '/realtime/events');

        var self = this;

        this.eventSource.addEventListener('notification', function (e) {
          try {
            self._onNotification(JSON.parse(e.data));
          } catch (_) {}
        });

        this.eventSource.addEventListener('unread_count', function (e) {
          var count = parseInt(e.data, 10);
          if (isNaN(count)) return;
          if (count > self.lastUnreadNotifications && self.lastUnreadNotifications >= 0) {
            SoundManager.play('incoming');
          }
          self.lastUnreadNotifications = count;
          self._updateBadge();
          self._saveCounts();
        });

        this.eventSource.onerror = function () {
          if (self.eventSource) {
            self.eventSource.close();
            self.eventSource = null;
          }
          self.useSSE = false;
          self._startPolling();
        };
      } catch (_) {
        this.useSSE = false;
      }
    },

    _startPolling() {
      if (this.pollTimer) return;
      var self = this;
      this._poll();
      this.pollTimer = setInterval(function () { self._poll(); }, this.pollInterval);
    },

    _stopPolling() {
      if (this.pollTimer) {
        clearInterval(this.pollTimer);
        this.pollTimer = null;
      }
    },

    _poll() {
      var baseUrl = this._baseUrl();
      if (!baseUrl) return;
      var self = this;

      fetch(baseUrl + '/api/unread-summary', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      })
        .then(function (r) { return r.json(); })
        .then(function (data) { self._onPollData(data); })
        .catch(function () {});
    },

    _onPollData(data) {
      var msgCount = data.unread_messages || 0;
      var notifCount = data.unread_notifications || 0;
      var isInboxPage = !!document.querySelector('.inbox-page');

      // New messages detected
      if (msgCount > this.lastUnreadMessages && this.lastUnreadMessages >= 0) {
        if (!isInboxPage) {
          SoundManager.play('incoming');
          if (document.visibilityState !== 'visible' && this._browserNotifEnabled) {
            this._notifyBrowser(
              String(msgCount - this.lastUnreadMessages) + ' nova(s) mensagem(ns)',
              'Você tem novas mensagens no AtendeFlow'
            );
          }
        }
      }

      // New notifications detected
      if (notifCount > this.lastUnreadNotifications && this.lastUnreadNotifications >= 0) {
        SoundManager.play('incoming');
      }

      this.lastUnreadMessages = msgCount;
      this.lastUnreadNotifications = notifCount;
      this._updateBadge();
      this._saveCounts();
    },

    _onNotification(data) {
      SoundManager.play('incoming');
      if (document.visibilityState !== 'visible' && this._browserNotifEnabled) {
        this._notifyBrowser(
          data.title || 'Nova notificação',
          data.body || ''
        );
      }
      this._updateBadge();
    },

    _updateBadge() {
      var badge = document.getElementById('notifBadge');
      if (!badge) return;
      var total = (this.lastUnreadMessages > 0 ? this.lastUnreadMessages : 0)
                + (this.lastUnreadNotifications > 0 ? this.lastUnreadNotifications : 0);
      if (total > 0) {
        badge.textContent = total > 99 ? '99+' : String(total);
        badge.style.display = '';
      } else {
        badge.style.display = 'none';
      }
    },

    _notifyBrowser(body, title) {
      if ('Notification' in window && Notification.permission === 'granted') {
        try {
          new Notification(title || 'AtendeFlow', {
            body: body,
            icon: '/assets/img/favicon.png',
            requireInteraction: true,
          });
        } catch (_) {}
      } else if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission();
      }
    },

    _requestPermission() {
      if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission();
      }
    },

    destroy() {
      if (this.eventSource) {
        this.eventSource.close();
        this.eventSource = null;
      }
      this._stopPolling();
    },
  };

  // ──────────────────────────────────────────
  // Init on DOM ready
  // ──────────────────────────────────────────
  function init() {
    SoundManager._initAudioOnGesture();
    // Global notification watcher (all pages)
    GlobalNotifier.init();

    if (document.querySelector('.inbox-page')) {
      DragManager.init();
      KeyboardNav.init();
      InfiniteScroll.init();
      MobileSearchFix.init();
      LiveFeed.init();
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
    DragManager, ComposerShortcuts, FormEnhancer,
    KeyboardNav, InfiniteScroll, TagManagerEnhancer, MobileSearchFix,
    LiveFeed, SoundManager, GlobalNotifier,
  };
})();
