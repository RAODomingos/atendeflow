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
      soft:    { freq: 400, duration: 0.10, vol: 0.15 },
      sharp:   { freq: 800, duration: 0.06, vol: 0.4 },
      silent:  null,
    },
    new_conv: {
      default: { type: 'two-tone', freqs: [440, 660], duration: 0.25, vol: 0.35 },
      soft:    { type: 'two-tone', freqs: [350, 520], duration: 0.25, vol: 0.18 },
      sharp:   { type: 'two-tone', freqs: [700, 900], duration: 0.2,  vol: 0.45 },
      silent:  null,
    },
    incoming: {
      default: { freq: 600, duration: 0.15, vol: 0.3 },
      soft:    { freq: 480, duration: 0.15, vol: 0.15 },
      sharp:   { freq: 850, duration: 0.12, vol: 0.4 },
      silent:  null,
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
    isEnabled() { return this._enabled; },

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
              gain.gain.value = 0.0001;
              osc.connect(gain);
              gain.connect(ac.destination);
              osc.start(0);
              osc.stop(ac.currentTime + 0.005);
            }).catch(function() {});
          }
        } catch (_) {}
      };
      document.addEventListener('click', initFn);
      document.addEventListener('keydown', initFn);
      document.addEventListener('touchstart', initFn);
    },

    setProfile(profile) {
      this._profile = (SoundProfiles.message[profile]) ? profile : 'default';
    },

    setTypeProfile(type, profile) {
      if (SoundProfiles[type] && (SoundProfiles[type][profile] || profile === 'silent')) {
        this._typeProfiles[type] = profile;
      } else {
        delete this._typeProfiles[type];
      }
    },

    applyPreferences(prefs) {
      if (!prefs) return;
      if (prefs.sound_enabled === false) {
        this.disable();
      } else {
        this.enable();
      }
      if (prefs.sound_new_message) {
        this.setTypeProfile('message', prefs.sound_new_message);
      }
      if (prefs.sound_new_conversation) {
        this.setTypeProfile('new_conv', prefs.sound_new_conversation);
      }
      this.setTypeProfile('incoming', prefs.sound_new_message || 'default');
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
            'gmai.com': 'gmail.com', 'yaho.com': 'yahoo.com', 'gmil.com': 'gmail.com',
            'outlok.com': 'outlook.com', 'hotmial.com': 'hotmail.com',
            'gmail.co': 'gmail.com', 'yahoo.co': 'yahoo.com',
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
          if (val.length <= 10) val = '55' + val;
          if (val.length === 12 && val.startsWith('55')) val = '55' + val.substring(2);
          if (val !== el.value.replace(/\D/g, '') && val.length >= 10) el.value = val;
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
        const isConvPage = document.querySelector('.app-inbox');
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
        .catch(() => { this.hasMore = false; })
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
    init() { this.addFilterInput(); },

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
  // 9. LiveFeed — Incremental list updates
  // ──────────────────────────────────────────
  const LiveFeed = {
    _known: {},
    _viewingId: null,
    _pollTimer: null,
    _polling: false,
    _ready: false,

    init() {
      document.querySelectorAll('.conv-item').forEach(function(el) {
        var id = parseInt(el.dataset.convId, 10);
        if (!isNaN(id)) {
          this._known[id] = {
            unread: parseInt(el.dataset.unread || '0', 10),
            msgCount: parseInt(el.dataset.msgCount || '0', 10),
          };
        }
      }, this);

      this._viewingId = (window.__viewingConvId || null);
      this._ready = true;

      this._poll();
      this._pollTimer = setInterval(this._poll.bind(this), 10000);
    },

    setViewing(id) {
      this._viewingId = id ? String(id) : null;
      window.__viewingConvId = this._viewingId;
    },

    _baseUrl() {
      var u = document.querySelector('meta[name="base-url"]');
      return u ? u.getAttribute('content') : (window.location.origin + '/atendeflow');
    },

    _poll() {
      if (this._polling || !this._ready) return;
      this._polling = true;

      var baseUrl = this._baseUrl();
      var isChatbot = window.location.pathname.indexOf('/inbox/chatbot') !== -1;
      var url = isChatbot ? baseUrl + '/api/chatbot-conversations' : baseUrl + '/api/conversations';

      if (!isChatbot) {
        var p = new URLSearchParams(window.location.search);
        var fv = p.get('fstatus');
        if (!fv) { var h = document.querySelector('input[name="fstatus"]'); if (h) fv = h.value; }
        if (fv) url += '?fstatus=' + encodeURIComponent(fv);
        var iv = p.get('inbox');
        if (!iv) { var ih = document.querySelector('input[name="inbox"]'); if (ih) iv = ih.value; }
        if (iv) url += (fv ? '&' : '?') + 'inbox=' + encodeURIComponent(iv);
      }

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
      if (!container) return;

      var activeConvId = new URLSearchParams(window.location.search).get('conv');
      var knownIds = Object.keys(self._known);
      var newIds = [];
      var changes = [];

      data.forEach(function(c) {
        var id = parseInt(c.id, 10);
        if (!id) return;
        var newUnread = parseInt(c.unread_count || '0', 10);
        var newMsgCount = parseInt(c.message_count || '0', 10);

        if (!self._known[id]) {
          newIds.push(c);
        } else {
          var prevUnread = self._known[id].unread || 0;
          if (newUnread > prevUnread && String(id) !== self._viewingId) {
            changes.push({ type: 'incoming', conv: c, delta: newUnread - prevUnread });
          } else if (newUnread < prevUnread || newMsgCount !== self._known[id].msgCount) {
            changes.push({ type: 'update', conv: c });
          }
        }
        self._known[id] = { unread: newUnread, msgCount: newMsgCount };
      });

      if (newIds.length === 0 && changes.length === 0) {
        return;
      }

      newIds.forEach(function(c) {
        if (c.status === 'closed' || c.status === 'resolved' || c.status === 'spam') {
          if (!self._viewingId || String(c.id) !== self._viewingId) {
            // remove from DOM if exists
            var existing = container.querySelector('.conv-item[data-conv-id="' + c.id + '"]');
            if (existing) existing.remove();
          }
          return;
        }
        SoundManager.play('new_conv');
        var notif = newIds.length === 1
          ? 'Nova conversa: ' + (c.contact_name || 'Cliente')
          : (newIds.length + ' novas conversas');
        self._flashToast(notif, c);
        if (document.visibilityState !== 'visible') {
          self._notifyBrowser(notif, 'AtendeFlow');
        }
      });

      changes.forEach(function(ch) {
        if (ch.type === 'incoming') {
          if (document.visibilityState !== 'visible') {
            self._notifyBrowser((ch.conv.contact_name || 'Cliente') + ' enviou mensagem', 'AtendeFlow');
          }
        }
      });

      newIds.forEach(function(c) { self._prependItem(container, c, activeConvId); });
      changes.forEach(function(ch) { self._updateItem(container, ch.conv, activeConvId); });
      knownIds.forEach(function(idStr) {
        var id = parseInt(idStr, 10);
        if (!data.find(function(c) { return parseInt(c.id, 10) === id; })) {
          var existing = container.querySelector('.conv-item[data-conv-id="' + id + '"]');
          if (existing && (!self._viewingId || String(id) !== self._viewingId)) {
            existing.remove();
          }
        }
      });

      if (window.__enhancements && window.__enhancements.KeyboardNav) {
        window.__enhancements.KeyboardNav.refreshItems();
      }
    },

    _prependItem(container, c, activeConvId) {
      var id = parseInt(c.id, 10);
      var existing = container.querySelector('.conv-item[data-conv-id="' + id + '"]');
      if (existing) existing.remove();

      var el = this._createItem(c, activeConvId);
      el.classList.add('conv-new-flash');
      var first = container.querySelector('.conv-item');
      if (first) {
        container.insertBefore(el, first);
      } else {
        var empty = container.querySelector('.empty-state-enhanced');
        if (empty) empty.remove();
        container.insertBefore(el, container.firstChild);
      }
      setTimeout(function() { el.classList.remove('conv-new-flash'); }, 4000);
    },

    _updateItem(container, c, activeConvId) {
      var id = parseInt(c.id, 10);
      var existing = container.querySelector('.conv-item[data-conv-id="' + id + '"]');
      if (!existing) {
        if (c.status !== 'closed' && c.status !== 'resolved' && c.status !== 'spam') {
          this._prependItem(container, c, activeConvId);
        }
        return;
      }
      if (c.status === 'closed' || c.status === 'resolved' || c.status === 'spam') {
        if (!this._viewingId || String(id) !== this._viewingId) {
          existing.remove();
          return;
        }
      }
      var fresh = this._createItem(c, activeConvId);
      existing.replaceWith(fresh);
    },

    _createItem(c, activeConvId) {
      var id = parseInt(c.id, 10);
      var baseUrl = window.location.pathname + '?conv=';
      var un = parseInt(c.unread_count || '0', 10);
      var chIcon = { whatsapp:'fab fa-whatsapp', webchat:'fas fa-comment-dots', email:'fas fa-envelope', telegram:'fab fa-telegram', facebook:'fab fa-facebook', instagram:'fab fa-instagram', phone:'fas fa-phone' }[c.channel_type] || 'fas fa-comment-dots';

      var priorityChip = '';
      if (c.priority && c.priority !== 'normal') {
        priorityChip = '<span class="chip ' + (c.priority === 'urgent' ? 'chip-danger' : 'chip-warning') + '">' + this._priorityLabel(c.priority) + '</span>';
      }

      var statusChipClass = 'chip-success';
      if (c.status === 'resolved' || c.status === 'closed') statusChipClass = 'chip-neutral';
      else if (c.status !== 'open' && c.status !== 'new') statusChipClass = 'chip-info';

      var avatarHtml = '<div class="avatar">' + this._esc((c.contact_name || '?').charAt(0).toUpperCase()) + '</div>';
      if (c.contact_avatar) {
        var src = c.contact_avatar.indexOf('http') === 0 ? c.contact_avatar : this._baseUrl() + '/uploads/' + c.contact_avatar;
        avatarHtml = '<div class="avatar"><img src="' + this._esc(src) + '" alt=""></div>';
      }

      var statusDot = (c.status === 'open' || c.status === 'new') ? '<div class="status-dot"></div>' : '';
      var unreadBadge = un > 0 ? '<span class="nav-badge" style="position:absolute;top:-4px;right:-6px;font-size:10px;padding:1px 6px">' + (un > 99 ? '99+' : un) + '</span>' : '';
      var previewText = this._previewText(c, 80);
      var subjectHtml = c.subject ? '<strong>' + this._esc(c.subject) + '</strong> — ' : '';

      var el = document.createElement('a');
      el.href = baseUrl + id;
      el.className = 'conv-item' + (activeConvId === String(id) ? ' active' : '');
      el.dataset.convId = id;
      el.dataset.msgCount = String(c.message_count || 0);
      el.dataset.unread = String(un);

      el.innerHTML =
        '<div class="avatar-wrap">' + avatarHtml + statusDot + unreadBadge + '</div>' +
        '<div class="conv-body">' +
          '<div class="conv-top">' +
            '<span class="conv-name">' + this._esc(c.contact_name) + (c.contact_company ? '<span style="font-weight:400;color:var(--text-muted);font-size:12px"> — ' + this._esc(c.contact_company) + '</span>' : '') + '</span>' +
            '<span class="conv-time">' + this._timeAgo(c.last_message_at || c.created_at) + '</span>' +
          '</div>' +
          '<div class="conv-preview">' + subjectHtml + this._esc(previewText) + '</div>' +
          '<div class="conv-tags">' +
            '<span class="chip chip-neutral"><i class="' + chIcon + '" style="font-size:11px"></i> ' + this._esc(c.channel_name || '') + '</span>' +
            priorityChip +
            '<span class="chip ' + statusChipClass + '">' + this._statusLabel(c.status) + '</span>' +
          '</div>' +
          '<div class="conv-footer">' +
            '<span class="conv-agent"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> ' + this._esc(c.assigned_user_name || 'Não atribuído') + '</span>' +
            '<span class="conv-count"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg> ' + (parseInt(c.message_count, 10) || 0) + '</span>' +
          '</div>' +
        '</div>';

      return el;
    },

    addConversationFromSSE(c) {
      var self = this;
      var id = parseInt(c.id, 10);
      if (!id || this._known[id]) return;
      this._known[id] = { unread: 0, msgCount: 0 };
      this._poll();
      var container = document.getElementById('conversationsList');
      if (!container) return;
      var activeConvId = new URLSearchParams(window.location.search).get('conv');
      var initial = Object.assign({}, c, { unread_count: 0, message_count: 0, last_message_at: c.created_at });
      this._prependItem(container, initial, activeConvId);
      if (window.__enhancements && window.__enhancements.KeyboardNav) {
        window.__enhancements.KeyboardNav.refreshItems();
      }
    },

    incomingMessageFromSSE(payload) {
      var id = parseInt(payload.conversation_id, 10);
      if (!id) return;
      var self = this;
      if (this._known[id]) {
        this._known[id].unread = (this._known[id].unread || 0) + 1;
        this._known[id].msgCount = (this._known[id].msgCount || 0) + 1;
      } else {
        this._known[id] = { unread: 1, msgCount: 1 };
        this._poll();
        return;
      }
      var container = document.getElementById('conversationsList');
      if (!container) return;
      var existing = container.querySelector('.conv-item[data-conv-id="' + id + '"]');
      if (existing) {
        var badge = existing.querySelector('.nav-badge');
        var newCount = this._known[id].unread;
        if (newCount > 0) {
          if (badge) {
            badge.textContent = newCount > 99 ? '99+' : String(newCount);
          } else {
            var wrap = existing.querySelector('.avatar-wrap');
            if (wrap) {
              var b = document.createElement('span');
              b.className = 'nav-badge';
              b.style.cssText = 'position:absolute;top:-4px;right:-6px;font-size:10px;padding:1px 6px';
              b.textContent = newCount > 99 ? '99+' : String(newCount);
              wrap.appendChild(b);
            }
          }
        }
        var timeEl = existing.querySelector('.conv-time');
        if (timeEl) timeEl.textContent = 'agora';
        var previewEl = existing.querySelector('.conv-preview');
        if (previewEl) {
          var labels = { image: '🖼️ Imagem', video: '🎬 Vídeo', audio: '🎵 Áudio', file: '📎 Arquivo', sticker: '🖼️ Sticker' };
          previewEl.textContent = labels[payload.type] || (payload.content || '').substring(0, 80);
        }
        if (String(id) !== this._viewingId) {
          existing.classList.add('conv-new-flash');
          setTimeout(function() { existing.classList.remove('conv-new-flash'); }, 4000);
          if (container.firstChild !== existing) {
            container.insertBefore(existing, container.firstChild);
          }
        }
      } else {
        this._poll();
      }
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
    _previewText(m, l) {
      var labels = { image: '🖼️ Imagem', video: '🎬 Vídeo', audio: '🎵 Áudio', file: '📎 Arquivo', sticker: '🖼️ Sticker' };
      if (m.last_message_type && labels[m.last_message_type]) return labels[m.last_message_type];
      return this._truncate(m.last_message, l);
    },
    _statusLabel(s) { return ({ new:'Novo', open:'Aberto', waiting_customer:'Em atendimento', waiting_internal:'Aguardando Interno', resolved:'Resolvido', closed:'Fechado', spam:'Spam' })[s] || s; },
    _priorityLabel(p) { return ({ low:'Baixa', normal:'Normal', high:'Alta', urgent:'Urgente' })[p] || p; },

    _flashToast(body, c) {
      var existing = document.querySelector('.notif-toast');
      if (existing) existing.remove();
      var toast = document.createElement('div');
      toast.className = 'notif-toast';
      toast.innerHTML =
        '<div class="notif-toast-icon"><i class="fa-solid fa-comment-dots"></i></div>' +
        '<div class="notif-toast-content">' +
          '<div class="notif-toast-title">' + this._esc(body) + '</div>' +
          '<div class="notif-toast-body">Clique para abrir</div>' +
        '</div>' +
        '<button class="notif-toast-close">&times;</button>';
      toast.querySelector('.notif-toast-close').addEventListener('click', function () { toast.remove(); });
      if (c && c.id) {
        toast.addEventListener('click', function () {
          window.location.href = (window.__enhancements && window.__enhancements.LiveFeed && window.__enhancements.LiveFeed._baseUrl() || '') + '/inbox?conv=' + c.id;
        });
      }
      document.body.appendChild(toast);
      setTimeout(function () { if (toast.parentNode) toast.remove(); }, 6000);
    },

    _notifyBrowser(body, title) {
      if ('Notification' in window && Notification.permission === 'granted') {
        try {
          new Notification(title || 'AtendeFlow', {
            body: body,
            icon: '/assets/img/favicon.png',
            requireInteraction: false,
          });
        } catch (_) {}
      }
    },
  };

  // ──────────────────────────────────────────
  // 10. Global Notification Manager (SSE)
  // ──────────────────────────────────────────
  const GlobalNotifier = {
    eventSource: null,
    sseBackoff: 5000,
    sseMaxBackoff: 60000,
    lastUnreadConversations: -1,
    lastUnreadNotifications: -1,
    _browserNotifEnabled: true,
    _viewingId: null,
    _prefs: null,

    init() {
      this._viewingId = (window.__viewingConvId || null);
      this._loadLastCounts();
      this._loadPreferences();
      this._bindGesturePermissions();
      this._fetchSummary();
      this._trySSE();
      this._fallbackTimer = setInterval(this._fetchSummary.bind(this), 30000);
    },

    setViewing(id) {
      this._viewingId = id ? String(id) : null;
      window.__viewingConvId = this._viewingId;
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
          self._prefs = data;
          SoundManager.applyPreferences(data);
          self._browserNotifEnabled = data.browser_notif_enabled !== false;
        })
        .catch(function () {});
    },

    savePreferences(patch) {
      var baseUrl = this._baseUrl();
      if (!baseUrl) return Promise.resolve();
      var self = this;
      return fetch(baseUrl + '/api/user-preferences', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json' },
        body: JSON.stringify(patch || {}),
      })
        .then(function (r) { return r.json(); })
        .then(function (resp) {
          if (resp && resp.prefs) {
            self._prefs = resp.prefs;
            SoundManager.applyPreferences(resp.prefs);
            self._browserNotifEnabled = resp.prefs.browser_notif_enabled !== false;
          }
          return resp;
        })
        .catch(function () { return null; });
    },

    _loadLastCounts() {
      try {
        var saved = localStorage.getItem('atendeflow-unread');
        if (saved) {
          var data = JSON.parse(saved);
          this.lastUnreadConversations = typeof data.conversations === 'number' ? data.conversations : 0;
          this.lastUnreadNotifications = typeof data.notifications === 'number' ? data.notifications : 0;
          if (this.lastUnreadNotifications > 0) {
            this._updateBadge(this.lastUnreadNotifications);
          }
        }
      } catch (_) {}
    },

    _saveCounts() {
      try {
        localStorage.setItem('atendeflow-unread', JSON.stringify({
          conversations: this.lastUnreadConversations,
          notifications: this.lastUnreadNotifications,
          time: Date.now(),
        }));
      } catch (_) {}
    },

    _baseUrl() {
      var m = document.querySelector('meta[name="base-url"]');
      return m ? m.getAttribute('content') : '';
    },

    _fetchSummary() {
      var baseUrl = this._baseUrl();
      if (!baseUrl) return;
      var self = this;
      fetch(baseUrl + '/api/unread-summary', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      })
        .then(function (r) { return r.json(); })
        .then(function (data) { self._onFetch(data); })
        .catch(function () {});
    },

    _onFetch(data) {
      var convCount = data.unread_conversations || 0;
      if (convCount > this.lastUnreadConversations && this.lastUnreadConversations >= 0) {
        var delta = convCount - this.lastUnreadConversations;
        SoundManager.play('incoming');
        this._showToast(
          delta > 1 ? (delta + ' conversas com novas mensagens') : 'Nova conversa com mensagens',
          'Toque no sino para ver'
        );
      }
      this.lastUnreadConversations = convCount;
      this.lastUnreadNotifications = data.unread_notifications || 0;
      this._updateBadge(this.lastUnreadNotifications);
      this._saveCounts();
    },

    _trySSE() {
      var self = this;
      try {
        var baseUrl = this._baseUrl();
        if (!baseUrl || typeof EventSource === 'undefined') {
          this._scheduleReconnect();
          return;
        }
        this.eventSource = new EventSource(baseUrl + '/realtime/events');

        this.eventSource.addEventListener('connected', function (e) {
          try {
            var data = JSON.parse(e.data);
            self._sseReady = true;
            self.sseBackoff = 5000;
            if (data && data.userId) {
              self._userId = data.userId;
            }
          } catch (_) {}
        });

        this.eventSource.addEventListener('notification', function (e) {
          try { self._onNotification(JSON.parse(e.data)); } catch (_) {}
        });

        this.eventSource.addEventListener('conversation_new', function (e) {
          try {
            var data = JSON.parse(e.data);
            self._onConversationNew(data);
            if (window.__enhancements && window.__enhancements.LiveFeed) {
              window.__enhancements.LiveFeed.addConversationFromSSE(data);
            }
          } catch (_) {}
        });

        this.eventSource.addEventListener('message_incoming', function (e) {
          try {
            var data = JSON.parse(e.data);
            self._onMessageIncoming(data);
            if (window.__enhancements && window.__enhancements.LiveFeed) {
              window.__enhancements.LiveFeed.incomingMessageFromSSE(data);
            }
          } catch (_) {}
        });

        this.eventSource.addEventListener('typing', function (e) {
          try {
            var data = JSON.parse(e.data);
            if (data && data.conversation_id) {
              document.dispatchEvent(new CustomEvent('atendeflow:typing', { detail: data }));
            }
          } catch (_) {}
        });

        this.eventSource.addEventListener('unread_count', function (e) {
          var count = parseInt(e.data, 10);
          if (!isNaN(count)) {
            self.lastUnreadNotifications = count;
            self._updateBadge(count);
            self._saveCounts();
          }
        });

        this.eventSource.onerror = function () {
          if (self.eventSource) {
            self.eventSource.close();
            self.eventSource = null;
          }
          self._scheduleReconnect();
        };
      } catch (_) {
        this._scheduleReconnect();
      }
    },

    _scheduleReconnect() {
      var self = this;
      if (this._reconnectTimer) return;
      var delay = Math.min(this.sseBackoff, this.sseMaxBackoff);
      this._reconnectTimer = setTimeout(function() {
        self._reconnectTimer = null;
        self.sseBackoff = Math.min(self.sseBackoff * 2, self.sseMaxBackoff);
        self._trySSE();
      }, delay);
    },

    _onNotification(data) {
      var title = data.title || 'Nova notificação';
      var body = data.body || '';
      var convId = data.conversation_id;
      if (convId && this._viewingId && String(convId) === this._viewingId) {
        return;
      }
      var isNewConv = data.notification_type === 'new_conversation';
      SoundManager.play(isNewConv ? 'new_conv' : 'incoming');
      this._showToast(title, body, convId);
      if (document.visibilityState !== 'visible' && this._browserNotifEnabled) {
        this._notifyBrowser(title, body);
      }
      this._updateBadge(this.lastUnreadNotifications + 1);
      this.lastUnreadNotifications++;
      this._saveCounts();
    },

    _onConversationNew(data) {
      var id = parseInt(data.id, 10);
      if (!id) return;
      if (this._viewingId && String(id) === this._viewingId) {
        return;
      }
      var title = (data.contact_name || 'Cliente') + ' iniciou uma conversa';
      var body = data.channel_name ? 'Via ' + data.channel_name : '';
      SoundManager.play('new_conv');
      this._showToast(title, body, id);
      if (document.visibilityState !== 'visible' && this._browserNotifEnabled) {
        this._notifyBrowser(title, body);
      }
    },

    _onMessageIncoming(data) {
      var convId = parseInt(data.conversation_id, 10);
      if (!convId) return;
      if (this._viewingId && String(convId) === this._viewingId) {
        return;
      }
      var isViewingOther = this._viewingId && String(convId) !== this._viewingId;
      if (isViewingOther) {
        // subtle sound only
        SoundManager.play('incoming');
        return;
      }
      // Not viewing any conversation
      var labels = { image: '📷 Imagem', audio: '🎵 Áudio', video: '🎬 Vídeo', file: '📎 Arquivo', sticker: '🖼️ Sticker' };
      var preview = labels[data.type] || (data.content || '').substring(0, 80);
      var title = (data.contact_name || 'Cliente') + ' enviou mensagem';
      SoundManager.play('incoming');
      this._showToast(title, preview, convId);
      if (document.visibilityState !== 'visible' && this._browserNotifEnabled) {
        this._notifyBrowser(title, preview);
      }
    },

    _showToast(title, body, convId) {
      var existing = document.querySelector('.notif-toast');
      if (existing) existing.remove();

      var toast = document.createElement('div');
      toast.className = 'notif-toast';
      toast.innerHTML =
        '<div class="notif-toast-icon"><i class="fa-solid fa-bell"></i></div>' +
        '<div class="notif-toast-content">' +
          '<div class="notif-toast-title">' + this._esc(title) + '</div>' +
          '<div class="notif-toast-body">' + this._esc(body || '') + '</div>' +
        '</div>' +
        '<button class="notif-toast-close">&times;</button>';
      toast.querySelector('.notif-toast-close').addEventListener('click', function () { toast.remove(); });
      if (convId) {
        toast.addEventListener('click', function () {
          var baseUrl = document.querySelector('meta[name="base-url"]')?.content || '';
          window.location.href = baseUrl + '/inbox?conv=' + convId;
        });
      }
      document.body.appendChild(toast);
      setTimeout(function () { if (toast.parentNode) toast.remove(); }, 6000);
    },

    _esc(s) { if (s == null) return ''; var d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; },

    _updateBadge(count) {
      var badge = document.getElementById('notifBadge');
      if (!badge) return;
      var total = typeof count === 'number' ? count : (this.lastUnreadNotifications || 0);
      if (total > 0) {
        badge.textContent = total > 99 ? '99+' : String(total);
        badge.style.display = '';
      } else {
        badge.style.display = 'none';
      }
    },

    _notifyBrowser(body, title) {
      if (!this._browserNotifEnabled) return;
      if ('Notification' in window && Notification.permission === 'granted') {
        try {
          new Notification(title || 'AtendeFlow', {
            body: body,
            icon: '/assets/img/favicon.png',
            requireInteraction: false,
          });
        } catch (_) {}
      }
    },

    _requestPermission() {
      if ('Notification' in window && Notification.permission === 'default') {
        try { Notification.requestPermission(); } catch (_) {}
      }
    },

    destroy() {
      if (this.eventSource) {
        this.eventSource.close();
        this.eventSource = null;
      }
      if (this._fallbackTimer) {
        clearInterval(this._fallbackTimer);
        this._fallbackTimer = null;
      }
      if (this._reconnectTimer) {
        clearTimeout(this._reconnectTimer);
        this._reconnectTimer = null;
      }
    },
  };

  // ──────────────────────────────────────────
  // Init on DOM ready
  // ──────────────────────────────────────────
  function init() {
    SoundManager._initAudioOnGesture();
    GlobalNotifier.init();

    if (document.querySelector('.app-inbox')) {
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
