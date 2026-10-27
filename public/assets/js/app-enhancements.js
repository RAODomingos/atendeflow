/* ==========================================
   OminiDesk - UI/UX Enhancements
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
    // Menção (@grupo / @usuário) — som DIFERENTE do recado comum:
    // trinca ascendente marcante, impossível confundir com 'incoming'.
    mention: {
      default: { type: 'three-tone', freqs: [880, 660, 1174], duration: 0.16, vol: 0.50 },
      soft:    { type: 'three-tone', freqs: [700, 520, 940], duration: 0.16, vol: 0.30 },
      sharp:   { type: 'three-tone', freqs: [990, 740, 1320], duration: 0.14, vol: 0.60 },
      silent:  null,
    },
    // SLA "attention" (conv em espera > N minutos) — pulso discreto e rápido
    attention: {
      default: { type: 'two-tone', freqs: [600, 800], duration: 0.18, vol: 0.35 },
      soft:    { type: 'two-tone', freqs: [480, 640], duration: 0.18, vol: 0.22 },
      sharp:   { type: 'two-tone', freqs: [820, 1020], duration: 0.14, vol: 0.45 },
      silent:  null,
    },
    // SLA "alert" (conv em espera crítica) — mais grave, mais longo, em repetição
    alert: {
      default: { type: 'three-tone', freqs: [660, 880, 660], duration: 0.20, vol: 0.50 },
      soft:    { type: 'three-tone', freqs: [520, 700, 520], duration: 0.20, vol: 0.32 },
      sharp:   { type: 'three-tone', freqs: [880, 1100, 880], duration: 0.18, vol: 0.60 },
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
        } catch (_) {
          this._ctx = null;
        }
      }
      // Não tenta resumir aqui — resume() é uma promise, melhor aguardar
      // antes de tocar. O _initAudioOnGesture() desbloqueia o contexto
      // no primeiro clique do usuário.
      return this._ctx;
    },

    /**
     * Garante que o contexto de áudio está rodando. Aguarda a promise de
     * resume() para evitar osc.start() em contexto suspended (que toca
     * silenciosamente ou lança warning).
     */
    _ensureRunning() {
      var ac = this.ctx;
      if (!ac) return Promise.resolve(false);
      if (ac.state === 'running') return Promise.resolve(true);
      if (ac.state === 'closed') return Promise.resolve(false);
      return ac.resume().then(function () { return ac.state === 'running'; }).catch(function () { return false; });
    },

    enable() { this._enabled = true; },
    disable() { this._enabled = false; },
    toggle() { this._enabled = !this._enabled; return this._enabled; },
    isEnabled() { return this._enabled; },

    _initAudioOnGesture() {
      var self = this;
      var unlocked = false;
      var initFn = function () {
        if (unlocked) return;
        // Primeiro clique do usuário — desbloqueia o AudioContext.
        // Aguardamos a confirmação de resume() antes de remover os listeners.
        self._ensureRunning().then(function (running) {
          if (running) {
            unlocked = true;
            document.removeEventListener('click', initFn);
            document.removeEventListener('keydown', initFn);
            document.removeEventListener('touchstart', initFn);
            if (window.console && console.debug) console.debug('[SoundManager] audio context unlocked');
          }
        });
      };
      document.addEventListener('click', initFn, { passive: true });
      document.addEventListener('keydown', initFn, { passive: true });
      document.addEventListener('touchstart', initFn, { passive: true });
    },

    setProfile(profile) {
      this._profile = (SoundProfiles.message[profile]) ? profile : 'default';
    },

    _customSounds: {},
    _customEls: {},

    setTypeProfile(type, profile) {
      if (profile === 'silent') {
        this._typeProfiles[type] = profile;
        return;
      }
      if (SoundProfiles[type] && SoundProfiles[type][profile]) {
        this._typeProfiles[type] = profile;
        return;
      }
      // Áudio personalizado enviado pelo usuário: 'custom:<id>'.
      if (typeof profile === 'string' && /^custom:\d+$/.test(profile)) {
        this._typeProfiles[type] = profile;
        return;
      }
      delete this._typeProfiles[type];
    },

    /**
     * Mapa id → url dos áudios personalizados (vem de /api/sounds ou das
     * preferências). Tocados via <audio>, fora do WebAudio.
     */
    setCustomSounds(map) {
      this._customSounds = map || {};
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
      this.setTypeProfile('mention', prefs.sound_mention || 'default');
    },

    play(type, profile) {
      if (!this._enabled) return;
      var p = profile || this._typeProfiles[type] || this._profile;
      // Áudio personalizado (upload do usuário) tem prioridade.
      if (typeof p === 'string' && p.indexOf('custom:') === 0) {
        this._playCustom(p);
        return;
      }
      var cfg = SoundProfiles[type];
      if (!cfg) return;
      var sound = cfg[p] || cfg['default'];
      if (!sound) return;
      // Garante que o contexto está rodando antes de criar osciladores.
      // (se ainda suspended, o play() aguarda o desbloqueio e só então toca)
      var self = this;
      this._ensureRunning().then(function (running) {
        if (!running) return;
        try {
          if (type === 'new_conv' && sound.type === 'two-tone') {
            self._newConv(sound.freqs, sound.duration, sound.vol, 0.15);
          } else if (sound.type === 'three-tone') {
            self._newConv(sound.freqs, sound.duration, sound.vol, 0.13);
          } else if (sound.type === 'two-tone') {
            self._newConv(sound.freqs, sound.duration, sound.vol, 0.12);
          } else if (sound.freq) {
            self._beep(sound.freq, sound.duration, sound.vol);
          }
        } catch (_) {}
      });
    },

    _playCustom(profile) {
      var id = String(profile).split(':')[1];
      var url = this._customSounds && this._customSounds[id];
      if (!url) return;
      try {
        var key = 'custom' + id;
        var a = this._customEls[key];
        if (!a) {
          a = new Audio(url);
          a.preload = 'auto';
          this._customEls[key] = a;
        }
        try { a.pause(); } catch (_) {}
        try { a.currentTime = 0; } catch (_) {}
        var pr = a.play();
        if (pr && pr.catch) pr.catch(function () {});
      } catch (_) {}
    },

    _beep(freq, duration, vol) {
      try {
        const ctx = this.ctx;
        if (!ctx) return;
        const now = ctx.currentTime + 0.005; // pequeno offset p/ garantir scheduling
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.value = freq;
        gain.gain.setValueAtTime(0.0001, now);
        gain.gain.exponentialRampToValueAtTime(Math.max(0.001, vol), now + 0.01);
        gain.gain.exponentialRampToValueAtTime(0.0001, now + duration);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start(now);
        osc.stop(now + duration + 0.02);
      } catch (_) {}
    },

    _newConv(freqs, duration, vol, step) {
      try {
        const ctx = this.ctx;
        if (!ctx) return;
        const base = ctx.currentTime + 0.005;
        const stride = step || 0.15;
        (freqs || [440, 660]).forEach(function (freq, i) {
          const osc = ctx.createOscillator();
          const gain = ctx.createGain();
          osc.type = 'sine';
          osc.frequency.value = freq;
          const t = base + i * stride;
          const safeVol = Math.max(0.001, vol || 0.07);
          gain.gain.setValueAtTime(0.0001, t);
          gain.gain.exponentialRampToValueAtTime(safeVol, t + 0.01);
          gain.gain.exponentialRampToValueAtTime(0.0001, t + (duration || 0.2));
          osc.connect(gain);
          gain.connect(ctx.destination);
          osc.start(t);
          osc.stop(t + (duration || 0.2) + 0.02);
        });
      } catch (_) {}
    },
  };

  // ──────────────────────────────────────────
  // 0d. SLA Manager (cores e sons de espera na lista de conversas)
  // ──────────────────────────────────────────
  const SlaManager = {
    _cfgCache: {},        // chaveada por inbox_id (string) ou 'global'
    _cfgLoadedAt: {},     // timestamps para TTL
    _cfgTtlMs: 5 * 60 * 1000,
    _tickTimer: null,
    _tickIntervalMs: 15 * 1000,
    // último nível conhecido por conv, para disparar som só na transição
    _lastLevel: {},
    // inflight por inbox_id, evita múltiplos fetches em rajada
    _inflight: {},
    // som é tocado uma vez por par (inbox, conv) — então cacheamos o
    // som efetivo por inbox aqui
    _sounds: { attention: 'default', alert: 'default' },

    // Defaults caso a API falhe — devem bater com SlaService::DEFAULT_CONFIG
    _defaultCfg: {
      enabled: true,
      attention_seconds: 5 * 60,
      alert_seconds: 10 * 60,
      colors: {
        normal: '#dcfce7',
        attention: '#fef3c7',
        alert: '#fee2e2',
        normal_text: '#166534',
        attention_text: '#92400e',
        alert_text: '#991b1b',
      },
      sounds: { attention: 'default', alert: 'default' },
    },

    init() {
      this._ensureStyleInjected();
      this._loadAllVisible();
      this._startTicker();
    },

    _key(inboxId) {
      return inboxId ? String(inboxId) : 'global';
    },

    getConfig(inboxId) {
      var k = this._key(inboxId);
      if (this._cfgCache[k]) return this._cfgCache[k];
      return this._defaultCfg;
    },

    _loadAllVisible() {
      var list = document.getElementById('conversationsList');
      if (!list) {
        // Sem lista na página, ainda assim carrega a config global como fallback.
        this._loadConfig(null);
        return;
      }
      var seen = {};
      list.querySelectorAll('.conv-item[data-sla-inbox]').forEach(function (el) {
        var ib = el.dataset.slaInbox || '';
        var k = ib || 'global';
        if (seen[k]) return;
        seen[k] = true;
        this._loadConfig(ib ? parseInt(ib, 10) : null);
      }.bind(this));
    },

    _loadConfig(inboxId) {
      var self = this;
      var key = this._key(inboxId);
      if (this._inflight[key]) return;
      this._inflight[key] = true;

      var baseUrl = (document.querySelector('meta[name="base-url"]') || {}).content || '';
      var url = baseUrl + '/api/sla/config';
      if (inboxId) url += '?inbox_id=' + encodeURIComponent(String(inboxId));

      fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { if (!r.ok) throw new Error(String(r.status)); return r.json(); })
        .then(function (cfg) { self._applyConfig(key, cfg); })
        .catch(function () {
          // Em caso de falha, usa o default para esta chave e para
          // os itens desta caixa; mantém cache "vazio" para evitar
          // loop de requisições.
          self._cfgCache[key] = self._defaultCfg;
          self._cfgLoadedAt[key] = Date.now();
        })
        .finally(function () { self._inflight[key] = false; });
    },

    refresh() {
      this._cfgLoadedAt = {};
      this._cfgCache = {};
      this._loadAllVisible();
    },

    _applyConfig(key, cfg) {
      this._cfgCache[key] = cfg;
      this._cfgLoadedAt[key] = Date.now();
      // Cache de sons do global (chave 'global') usado como fallback de
      // som quando uma caixa não tiver config carregada ainda.
      if (key === 'global') {
        this._sounds = cfg.sounds || this._defaultCfg.sounds;
      }
      this.repaintAll();
    },

    _ensureStyleInjected() {
      if (document.getElementById('sla-manager-style')) return;
      var style = document.createElement('style');
      style.id = 'sla-manager-style';
      style.textContent = [
        // Cores aplicadas por conv-item via CSS custom properties
        // setadas inline — permite SLA diferente por caixa.
        '.conv-item.conv-sla-normal{background:var(--sla-color-normal,#dcfce7);}',
        '.conv-item.conv-sla-attention{background:var(--sla-color-attention,#fef3c7);}',
        '.conv-item.conv-sla-alert{background:var(--sla-color-alert,#fee2e2);animation:slaPulse 2.4s ease-in-out infinite;}',
        '.conv-item.conv-sla-attention .conv-name,',
        '.conv-item.conv-sla-attention .conv-preview,',
        '.conv-item.conv-sla-attention .conv-time{color:var(--sla-color-attention-text,#92400e);}',
        '.conv-item.conv-sla-alert .conv-name,',
        '.conv-item.conv-sla-alert .conv-preview,',
        '.conv-item.conv-sla-alert .conv-time{color:var(--sla-color-alert-text,#991b1b);}',
        '@keyframes slaPulse{0%,100%{background-color:var(--sla-color-alert,#fee2e2)}50%{background-color:#fecaca}}',
      ].join('\n');
      document.head.appendChild(style);
    },

    _startTicker() {
      var self = this;
      if (this._tickTimer) clearInterval(this._tickTimer);
      // O repaint inicial (init) é silencioso para semear _lastLevel sem
      // tocar N sons ao abrir a página; os ticks seguintes notificam as
      // transições de nível (none/normal → attention → alert).
      this._tickTimer = setInterval(function () { self.repaintAll(true); }, this._tickIntervalMs);
    },

    _applyColors(el, cfg) {
      if (!cfg || !cfg.colors) return;
      var c = cfg.colors;
      el.style.setProperty('--sla-color-normal', c.normal);
      el.style.setProperty('--sla-color-attention', c.attention);
      el.style.setProperty('--sla-color-alert', c.alert);
      el.style.setProperty('--sla-color-normal-text', c.normal_text);
      el.style.setProperty('--sla-color-attention-text', c.attention_text);
      el.style.setProperty('--sla-color-alert-text', c.alert_text);
    },

    /**
     * Recalcula a classe SLA de um conv-item e dispara o som
     * apropriado na transição de nível.
     *
     * conv: { id, status, last_message_direction, waiting_seconds, inbox_id }
     * notifySound: true se é uma transição (ex.: poll ou evento SSE)
     */
    applyToItem(el, conv, notifySound) {
      if (!el || !conv) return null;
      var inboxId = conv.inbox_id != null ? parseInt(conv.inbox_id, 10) : null;
      if ((inboxId === null || isNaN(inboxId)) && el.dataset.slaInbox) {
        inboxId = parseInt(el.dataset.slaInbox, 10) || null;
      }
      var key = this._key(inboxId);
      var cfg = this._cfgCache[key] || null;
      // Se ainda não temos a config deste inbox no cache, NÃO repinta
      // agora — o servidor já renderizou a classe correta, e o repaint
      // será disparado quando a config chegar via _applyConfig().
      if (!cfg) {
        // Garante que um fetch está em voo (ou dispara) para esta caixa.
        if (!this._inflight[key]) this._loadConfig(inboxId);
        return null;
      }
      var level = this.computeLevel(conv, cfg);
      el.classList.remove('conv-sla-normal', 'conv-sla-attention', 'conv-sla-alert');
      if (level !== 'none') {
        el.classList.add('conv-sla-' + level);
        this._applyColors(el, cfg);
      } else {
        el.style.removeProperty('--sla-color-normal');
        el.style.removeProperty('--sla-color-attention');
        el.style.removeProperty('--sla-color-alert');
        el.style.removeProperty('--sla-color-normal-text');
        el.style.removeProperty('--sla-color-attention-text');
        el.style.removeProperty('--sla-color-alert-text');
      }
      el.dataset.slaLevel = level;
      // Persiste a base (valor do servidor), não o valor inflado com o tempo
      // decorrido — senão cada tick somaria o elapsed de novo.
      var baseWaiting = (conv.waiting_base != null) ? conv.waiting_base : (conv.waiting_seconds | 0);
      el.dataset.slaWaiting = String(baseWaiting);
      if (inboxId) el.dataset.slaInbox = String(inboxId);

      // Grupo WhatsApp sem menção não vista é só histórico: pinta a cor
      // mas nunca toca som de SLA (menção segue com som próprio de sino).
      var isGroup = !!(conv.group_id || (el.dataset && el.dataset.groupId));
      var unread = (conv.unread_count != null)
        ? parseInt(conv.unread_count, 10)
        : parseInt((el.dataset && el.dataset.unread) || '0', 10);
      var allowSound = notifySound && !(isGroup && !unread);
      if (allowSound) {
        var prev = this._lastLevel[conv.id];
        if (prev !== level) {
          this._maybePlayTransition(prev, level, cfg, {
            convId: conv.id,
            name: this._convDisplayName(el, conv),
          });
          this._lastLevel[conv.id] = level;
        }
      } else {
        this._lastLevel[conv.id] = level;
      }
      return level;
    },

    /**
     * Repinta as cores SLA da lista. Com notifySound=true (ticker),
     * toca o som quando o nível SOBE (none/normal → attention → alert).
     * A chamada inicial é silenciosa para semear _lastLevel sem tocar
     * N sons ao abrir a inbox.
     * Também aproveita para disparar fetches de configs que ainda
     * não foram carregadas.
     */
    repaintAll(notifySound) {
      var list = document.getElementById('conversationsList');
      if (!list) return;
      var items = list.querySelectorAll('.conv-item');
      var self = this;
      var seen = {};
      var now = Date.now();
      items.forEach(function (el) {
        var id = parseInt(el.dataset.convId, 10);
        if (!id) return;
        var base = parseInt(el.dataset.slaWaiting || '0', 10);
        var conv = {
          id: id,
          status: el.dataset.slaStatus || '',
          waiting_seconds: base + self._elapsedSinceSync(el, now),
          waiting_base: base,
          last_message_direction: el.dataset.slaDir || '',
          inbox_id: el.dataset.slaInbox || null,
        };
        var k = conv.inbox_id || 'global';
        if (!seen[k] && !self._cfgCache[k]) {
          seen[k] = true;
          self._loadConfig(conv.inbox_id ? parseInt(conv.inbox_id, 10) : null);
        }
        self.applyToItem(el, conv, !!notifySound);
      });
    },

    /**
     * Segundos que o relógio do SLA correu desde o último sync do servidor.
     *
     * O servidor já entregou `waiting_seconds` em segundos ÚTEIS (dentro
     * do horário de atendimento do departamento). Entre dois syncs só
     * acrescentamos o tempo decorrido — e apenas enquanto o relógio
     * estiver correndo:
     *   - congelado (fora do horário, ou a bola está com o cliente) => 0;
     *   - aberto => até a próxima virada do horário.
     */
    _elapsedSinceSync(el, nowMs) {
      if (el.dataset.slaFrozen === '1') return 0;
      var syncAt = parseInt(el.dataset.slaSyncAt || '0', 10);
      if (!syncAt) return 0;
      var elapsed = Math.floor((nowMs - syncAt * 1000) / 1000);
      if (elapsed < 0) return 0;
      var boundary = parseInt(el.dataset.slaBoundary || '0', 10);
      if (boundary && nowMs > boundary * 1000) {
        // A janela de atendimento fechou no meio do intervalo: o
        // relógio congela no fechamento, não no fim do sync.
        elapsed = Math.max(0, Math.floor((boundary * 1000 - syncAt * 1000) / 1000));
      }
      return elapsed;
    },

    /**
     * Versão "notificada": chamada quando o poll ou SSE traz dados
     * novos e queremos tocar o som se o nível mudou.
     */
    applyFromApi(conv) {
      var id = parseInt(conv.id, 10);
      if (!id) return;
      var el = document.querySelector('.conv-item[data-conv-id="' + id + '"]');
      if (!el) return;
      this._applyServerClock(el, conv);
      this.applyToItem(el, conv, true);
    },

    /**
     * Copia para o item o estado do relógio servido pelo servidor
     * (instante do sync, congelamento e próxima virada do horário).
     */
    _applyServerClock(el, conv) {
      if (conv.sla_sync_at != null) {
        el.dataset.slaSyncAt = String(parseInt(conv.sla_sync_at, 10) || 0);
      }
      if (conv.sla_frozen != null) {
        el.dataset.slaFrozen = conv.sla_frozen ? '1' : '0';
      }
      if (conv.sla_boundary_at != null) {
        el.dataset.slaBoundary = String(parseInt(conv.sla_boundary_at, 10) || 0);
      }
    },

    computeLevel(conv, cfg) {
      if (!cfg) cfg = this.getConfig(conv && conv.inbox_id);
      if (!cfg || !cfg.enabled) return 'none';
      if (!conv) return 'none';
      var status = conv.status || '';
      var waiting = parseInt(conv.waiting_seconds, 10) || 0;

      // Aplica-se aos status ativos (new/open/waiting_*).
      var isActive = ['new', 'open', 'waiting_customer', 'waiting_internal'].indexOf(status) !== -1;
      if (!isActive) return 'none';

      if (waiting >= cfg.alert_seconds) return 'alert';
      if (waiting >= cfg.attention_seconds) return 'attention';
      // Abaixo do limite: 'new' (aguardando atendimento) fica verde;
      // 'open'/'waiting_*' (atendente já assumiu) fica sem cor de fundo.
      return status === 'new' ? 'normal' : 'none';
    },

    _convDisplayName(el, conv) {
      try {
        var t = el && el.querySelector ? el.querySelector('.conv-name') : null;
        if (t && t.textContent.trim()) return t.textContent.trim().substring(0, 60);
      } catch (_) {}
      return 'Conversa #' + (conv && conv.id ? conv.id : '');
    },

    _maybePlayTransition(prev, next, cfg, info) {
      if (!SoundManager || !SoundManager.isEnabled()) return;
      // Só toca quando CRESCE o nível (none/normal → attention, attention → alert,
      // ou none/normal → alert diretamente). Nunca toca na descida.
      var order = { none: 0, normal: 0, attention: 1, alert: 2 };
      var p = order[prev] || 0;
      var n = order[next] || 0;
      if (n <= p) return;
      var sounds = (cfg && cfg.sounds) || this._sounds;
      if (next === 'attention') {
        SoundManager.play('attention', sounds.attention);
      } else if (next === 'alert') {
        SoundManager.play('alert', sounds.alert);
      }
      // Espelha no navegador do PC (só com aba oculta).
      try {
        if (window.__enhancements && window.__enhancements.GlobalNotifier) {
          window.__enhancements.GlobalNotifier.notifySla(next, info && info.convId, info && info.name);
        }
      } catch (_) {}
    },

    forgetConversation(id) {
      delete this._lastLevel[id];
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
            if (fixed !== val) {
              OminiConfirm('Corrigir "' + val + '" para "' + fixed + '"?', { danger: false, confirmText: 'Corrigir' }).then(ok => {
                if (!ok) return;
                el.value = fixed;
                el.dispatchEvent(new Event('change', { bubbles: true }));
              });
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

      // A lista da caixa é renderizada inteira pelo servidor (sem paginação e
      // sem endpoint ajax): o sentinela só disparava uma requisição inútil a
      // cada visita e deixava "Carregando..." parado na tela. Só entra em cena
      // se o servidor marcar a lista como paginada.
      if (list.dataset.paginated !== '1') return;

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
      if (this._abort) this._abort.abort();
      this._abort = new AbortController();

      const params = new URLSearchParams(window.location.search);
      params.set('page', this.page + 1);
      params.set('per_page', this.pageSize);
      const url = window.location.pathname + '?ajax=1&' + params.toString();

      fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, signal: this._abort.signal })
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
    /* Mapa {id: status} das conversas — distingue "saiu da aba" de
       "foi finalizada" (necessário com as três abas da caixa). */
    _statusMap: {},
    _statusMapLoaded: false,
    _statusPending: false,
    _statusWaiters: [],

    init() {
      document.querySelectorAll('.conv-item').forEach(function(el) {
        var id = parseInt(el.dataset.convId, 10);
        if (!isNaN(id)) {
          this._known[id] = {
            unread: parseInt(el.dataset.unread || '0', 10),
            msgCount: parseInt(el.dataset.msgCount || '0', 10),
            flow: parseInt(el.dataset.flow || '0', 10),
            tags: el.dataset.tags || '',
            status: el.dataset.slaStatus || '',
            contactName: (el.querySelector('.conv-name') || {}).textContent || '',
          };
        }
      }, this);

      this._viewingId = (window.__viewingConvId || null);
      this._ready = true;

      // Inicializa o SlaManager (carrega config, injeta CSS, aplica classes
      // e faz repintura periódica). É seguro chamar várias vezes.
      if (window.__enhancements && window.__enhancements.SlaManager && !window.__enhancements.SlaManager._initialized) {
        window.__enhancements.SlaManager._initialized = true;
        window.__enhancements.SlaManager.init();
      }

      this._poll();
      this._pollTimer = setInterval(this._poll.bind(this), 10000);
    },

    setViewing(id) {
      this._viewingId = id ? String(id) : null;
      window.__viewingConvId = this._viewingId;
    },

    /**
     * Limpa o painel de chat quando a conversa visualizada é finalizada
     * pelo sistema. Mostra uma mensagem no painel e remove o id da URL
     * (sem reload), para que o próximo F5/refresh leve o usuário de volta
     * à lista "Em atendimento" já atualizada.
     */
    _closeViewingPanel(message) {
      this.setViewing(null);
      var panel = document.getElementById('conversationDetail');
      if (panel) {
        var safeMsg = (message || 'Conversa encerrada pelo sistema.').replace(/</g, '&lt;');
        panel.innerHTML =
          '<div class="chat-empty" style="flex-direction:column;gap:12px">' +
            '<i class="fas fa-archive" style="font-size:42px;color:var(--text-muted);opacity:.5"></i>' +
            '<p style="font-size:14px;color:var(--text-muted);max-width:320px;text-align:center">' + safeMsg + '</p>' +
            '<a href="' + (this._baseUrl() + '/inbox') + '" class="btn btn-sm btn-primary" style="padding:8px 18px;border-radius:10px">Voltar para a caixa</a>' +
          '</div>';
      }
      // Remove ?conv=X da URL sem recarregar
      try {
        var url = new URL(window.location.href);
        url.searchParams.delete('conv');
        window.history.replaceState({}, '', url.toString());
      } catch (_) {}
    },

    forcePoll() {
      if (this._polling || !this._ready) return;
      this._poll();
    },

    /**
     * Remove da lista uma conversa que sumiu do filtro da aba atual.
     *
     * Com três abas, sair do filtro NÃO implica finalização: a conversa
     * pode ter ido para "Aguardando". O status real vem de
     * /api/conversations-status — uma requisição leve, feita só quando
     * alguma conversa some. Sem ele, o cliente anunciava "Conversa
     * finalizada" para um simples "aguardando cliente" e ainda fechava o
     * painel de chat que o atendente estava lendo.
     */
    _removeVanished(container, id, existing, prev) {
      var self = this;

      if (!self._statusMapLoaded) {
        self._fetchStatusMap(function () {
          self._removeVanished(container, id, existing, prev);
        });
        return;
      }

      var status = self._statusMap[id];
      var isFinal = status === 'closed' || status === 'resolved' || status === 'spam';

      if (isFinal) {
        // Finalizada de verdade: some da lista e avisa.
        existing.remove();
        if (window.__enhancements && window.__enhancements.SlaManager) {
          window.__enhancements.SlaManager.forgetConversation(id);
        }
        var name = (prev && prev.contactName) || '';
        self._flashToast('Conversa finalizada: ' + (name || '#' + id), null, {
          icon: 'fa-solid fa-circle-check',
          subText: 'Encerrada automaticamente',
          noClick: true,
          duration: 5000,
        });
        // Se o usuário estava visualizando esta conversa, mostra um banner
        // no painel de chat e limpa a URL.
        if (self._viewingId && String(id) === self._viewingId) {
          self._closeViewingPanel('Conversa encerrada pelo sistema.');
        }
        return;
      }

      // Saiu da aba atual (ex.: foi para "Aguardando"): remove da lista
      // sem anunciar finalização e sem fechar o painel em uso.
      if (!self._viewingId || String(id) !== self._viewingId) {
        existing.remove();
        if (window.__enhancements && window.__enhancements.SlaManager) {
          window.__enhancements.SlaManager.forgetConversation(id);
        }
      }
    },

    /**
     * Busca o mapa {id: status} das conversas das caixas do usuário.
     * Uma requisição por rodada, com as chamadas pendentes na fila.
     */
    _fetchStatusMap(cb) {
      var self = this;
      self._statusWaiters = self._statusWaiters || [];
      self._statusWaiters.push(cb);
      if (self._statusPending) return;
      self._statusPending = true;

      var baseUrl = self._baseUrl();
      var url = baseUrl + '/api/conversations-status';
      var p = new URLSearchParams(window.location.search);
      var iv = p.get('inbox');
      if (!iv) { var ih = document.querySelector('input[name="inbox"]'); if (ih) iv = ih.value; }
      if (iv) url += '?inbox=' + encodeURIComponent(iv);

      fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r) { if (!r.ok) throw new Error(String(r.status)); return r.json(); })
        .then(function(map) {
          if (map && typeof map === 'object') self._statusMap = map;
        })
        .catch(function() {})
        .then(function() {
          self._statusPending = false;
          self._statusMapLoaded = true;
          var waiters = self._statusWaiters || [];
          self._statusWaiters = [];
          waiters.forEach(function(w) { try { w(); } catch (_) {} });
        });
    },

    _baseUrl() {
      var u = document.querySelector('meta[name="base-url"]');
      return u ? u.getAttribute('content') : (window.location.origin + '/atendeflow');
    },

    /**
     * Valor de um filtro da barra: a URL primeiro, depois o controle.
     * Checkbox desligado NÃO é filtro (o value existe de todo modo).
     */
    _filterValue(key) {
      var p = new URLSearchParams(window.location.search);
      var v = p.get(key);
      if (v) return v;
      var el = document.querySelector('[name="' + key + '"]');
      if (!el) return '';
      if (el.type === 'checkbox') return el.checked ? (el.value || '1') : '';
      return el.value || '';
    },

    /**
     * Recorte atual da listagem em query string.
     *
     * O poll e os contadores de aba precisam levar o MESMO recorte da
     * página. Sem isso o poll reescreve a lista com conversas de fora do
     * filtro e o filtro "não funciona" — some o que estava filtrando.
     */
    _filterQuery() {
      var parts = [];
      var self = this;
      ['fstatus', 'inbox', 'search', 'channel_id', 'priority', 'assignee', 'unread', 'mine', 'unassigned'].forEach(function (k) {
        var v = self._filterValue(k);
        if (v) parts.push(k + '=' + encodeURIComponent(v));
      });
      return parts.length ? '?' + parts.join('&') : '';
    },

    /**
     * A conversa pertence ao recorte atual da listagem?
     *
     * O poll já vem filtrado pelo servidor; o SSE não. Sem esta checagem,
     * uma conversa de outro setor entra numa lista filtrada por setor.
     * A aba (status) não entra na conta: quem chega pelo SSE é conversa
     * nova, e ela precisa aparecer na aba que o atendente está olhando.
     */
    _matchesCurrentFilter(c) {
      if (!c) return true;
      var ch = this._filterValue('channel_id');
      if (ch && String(c.channel_id || '') !== ch) return false;

      var pr = this._filterValue('priority');
      if (pr && String(c.priority || 'normal') !== pr) return false;

      var me = window.__currentUserId || this._userId || '';

      // "Minha Caixa": o recorte é o que está alocado a mim.
      if (this._filterValue('mine') && String(c.assigned_user_id || '') !== String(me)) return false;

      // "Não Atribuídas": só conversa sem responsável.
      if (this._filterValue('unassigned') && c.assigned_user_id) return false;

      var asg = this._filterValue('assignee');
      if (asg === 'me') {
        if (String(c.assigned_user_id || '') !== String(me)) return false;
      } else if (asg === 'unassigned') {
        if (c.assigned_user_id) return false;
      } else if (asg) {
        if (String(c.assigned_user_id || '') !== asg) return false;
      }

      var unread = this._filterValue('unread');
      if (unread && parseInt(c.unread_count || '0', 10) <= 0) return false;

      return true;
    },

    _poll() {
      if (this._polling || !this._ready) return;
      this._polling = true;

      var baseUrl = this._baseUrl();
      var url = baseUrl + '/api/conversations' + this._filterQuery();

      var self = this;
      fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r) { if (!r.ok) throw new Error(String(r.status)); return r.json(); })
        .then(function(data) { self._onData(data); self._polling = false; })
        .catch(function() { self._polling = false; });

      this._fetchTabCounts();
    },

    /**
     * Atualiza os contadores das abas ("Em atendimento" / "Aguardando").
     * "Concluído" não tem contador. Endpoint leve (COUNT por status).
     */
    _fetchTabCounts() {
      var baseUrl = this._baseUrl();
      // Mesmo recorte da listagem: os números das abas precisam contar
      // o que a lista mostra, não a fila inteira.
      var url = baseUrl + '/api/conversations-counts' + this._filterQuery();

      fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r) { if (!r.ok) throw new Error(String(r.status)); return r.json(); })
        .then(function(c) {
          if (!c) return;
          ['active', 'waiting'].forEach(function(tab) {
            var el = document.querySelector('[data-tab-count="' + tab + '"]');
            if (!el) return;
            var n = parseInt(c[tab] || '0', 10) || 0;
            el.textContent = String(n);
            if (n > 0) el.classList.remove('is-empty');
            else el.classList.add('is-empty');
          });
        })
        .catch(function() {});
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
        var newFlow = c.has_active_flow ? 1 : 0;
        var newTags = c.tags_json || '';
        var newStatus = c.status || '';

        if (!self._known[id]) {
          newIds.push(c);
        } else {
          var prevUnread = self._known[id].unread || 0;
          var prevStatus = self._known[id].status || '';
          var changed = newUnread !== prevUnread
            || newMsgCount !== self._known[id].msgCount
            || newFlow !== (self._known[id].flow || 0)
            || newTags !== (self._known[id].tags || '')
            || newStatus !== prevStatus;
          if (newUnread > prevUnread && String(id) !== self._viewingId) {
            changes.push({ type: 'incoming', conv: c, delta: newUnread - prevUnread });
          } else if (changed) {
            changes.push({ type: 'update', conv: c, prevStatus: prevStatus });
          }
        }
        self._known[id] = {
          unread: newUnread,
          msgCount: newMsgCount,
          flow: newFlow,
          tags: newTags,
          status: newStatus,
          contactName: c.contact_name || (self._known[id] && self._known[id].contactName) || '',
        };
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
        // Conversa de grupo entra na lista em silêncio: só menção (@)
        // avisa, via evento de sino com som próprio.
        if (c.group_id) {
          return;
        }
        SoundManager.play('new_conv');
        var notif = newIds.length === 1
          ? 'Nova conversa: ' + (c.contact_name || 'Cliente')
          : (newIds.length + ' novas conversas');
        self._flashToast(notif, c);
        if (document.visibilityState !== 'visible') {
          self._notifyBrowser('OminiDesk', notif, { tag: 'live-new' });
        }
      });

      changes.forEach(function(ch) {
        if (ch.type === 'incoming') {
          // Grupo: o aviso (quando há) já veio pelo sino de menção.
          if (ch.conv && ch.conv.group_id) {
            return;
          }
          if (document.visibilityState !== 'visible') {
            self._notifyBrowser((ch.conv.contact_name || 'Cliente') + ' enviou mensagem', 'Nova mensagem', { tag: 'live-msg-' + ch.conv.id, convId: ch.conv.id });
          }
        }
      });

      newIds.forEach(function(c) { self._prependItem(container, c, activeConvId); });
      changes.forEach(function(ch) { self._updateItem(container, ch.conv, activeConvId); });
      knownIds.forEach(function(idStr) {
        var id = parseInt(idStr, 10);
        if (!data.find(function(c) { return parseInt(c.id, 10) === id; })) {
          // A conversa não veio no response atual: saiu do filtro da aba.
          var prev = self._known[id];
          var prevStatus = (prev && prev.status) || '';
          var existing = container.querySelector('.conv-item[data-conv-id="' + id + '"]');
          if (!existing) return;

          // Com as três abas, sair da aba atual NÃO é o mesmo que ser
          // finalizada (pode ter ido para "Aguardando"). O status real vem
          // do endpoint de status; enquanto ele não chega, a conversa é
          // apenas removida da lista, sem anunciar finalização.
          self._removeVanished(container, id, existing, prev);
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
      // Conversa NOVA: mostra o nível SLA atual sem disparar som (transição
      // inicial = "apareceu", não "piorou"). A próxima mudança será capturada
      // por _updateItem com notifySound=true.
      if (window.__enhancements && window.__enhancements.SlaManager) {
        window.__enhancements.SlaManager.applyToItem(el, c, false);
      }
      setTimeout(function() { el.classList.remove('conv-new-flash'); }, 4000);
    },

    _updateItem(container, c, activeConvId) {
      var id = parseInt(c.id, 10);
      var existing = container.querySelector('.conv-item[data-conv-id="' + id + '"]');
      if (!existing) {
        if (c.status !== 'closed' && c.status !== 'resolved' && c.status !== 'spam'
            && this._matchesCurrentFilter(c)) {
          this._prependItem(container, c, activeConvId);
        }
        return;
      }
      // A remoção de itens finalizados pelo sistema é feita no bloco
      // knownIds.forEach() (quando a conversa SOME do response, sinal de
      // que o filtro fstatus=active não a inclui mais). Aqui só tratamos
      // a atualização visual quando o item continua na listagem.
      var fresh = this._createItem(c, activeConvId);
      existing.replaceWith(fresh);
      // Atualização via poll/SSE: notificar se o nível SLA subiu.
      if (window.__enhancements && window.__enhancements.SlaManager) {
        window.__enhancements.SlaManager.applyToItem(fresh, c, true);
      }
    },

    _createItem(c, activeConvId) {
      var id = parseInt(c.id, 10);
      // Mesmo link do PHP: preserva a aba e os filtros atuais (?fstatus,
      // ?inbox, ?search) ao abrir a conversa.
      var cur = new URLSearchParams(window.location.search);
      cur.delete('conv');
      var curQs = cur.toString();
      var baseUrl = window.location.pathname + (curQs ? '?' + curQs + '&conv=' : '?conv=');
      var un = parseInt(c.unread_count || '0', 10);
      var chIcon = { whatsapp:'fab fa-whatsapp', webchat:'fas fa-comment-dots', email:'fas fa-envelope', telegram:'fab fa-telegram', facebook:'fab fa-facebook', instagram:'fab fa-instagram', phone:'fas fa-phone' }[c.channel_type] || 'fas fa-comment-dots';

      var priorityChip = '';
      if (c.priority && c.priority !== 'normal') {
        priorityChip = '<span class="chip ' + (c.priority === 'urgent' ? 'chip-danger' : 'chip-warning') + '">' + this._priorityLabel(c.priority) + '</span>';
      }

      // A etiqueta "Fluxo" é gravada pelo backend (Flow::applyFlowActiveTag) e
      // já vem renderizada via tags_json abaixo — não precisa de chip sintético.

      var tagsChips = '';
      if (c.tags_json) {
        try {
          var tArr = JSON.parse(c.tags_json) || [];
          tArr.forEach(function(t) {
            var tcolor = t.color || '#5b6472';
            tagsChips += '<span class="chip conv-tag-chip" style="--tag-color:' + this._esc(tcolor) + '">' + this._esc(t.name) + '</span>';
          }, this);
        } catch (_) {}
      }

      var statusChipClass = 'chip-success';
      if (c.status === 'resolved' || c.status === 'closed') statusChipClass = 'chip-neutral';
      else if (c.status !== 'open' && c.status !== 'new') statusChipClass = 'chip-info';

      var initial = (c.contact_name || '?').charAt(0).toUpperCase();
      // Conversa de grupo: a foto do grupo (o contato sintético não tem avatar).
      var avatarSrc = c.group_avatar || c.contact_avatar;
      var avatarHtml = '<div class="avatar">' + this._esc(initial) + '</div>';
      if (avatarSrc) {
        var src = avatarSrc.indexOf('http') === 0 ? avatarSrc : this._baseUrl() + '/uploads/' + avatarSrc;
        avatarHtml = '<div class="avatar"><img src="' + this._esc(src) + '" alt="" loading="lazy" onerror="this.parentNode.textContent=\'' + this._esc(initial) + '\'"></div>';
      }

      var statusDot = (c.status === 'open' || c.status === 'new') ? '<div class="status-dot"></div>' : '';
      var unreadBadge = un > 0 ? '<span class="nav-badge" style="position:absolute;top:-4px;right:-6px;font-size:10px;padding:1px 6px">' + (un > 99 ? '99+' : un) + '</span>' : '';

      // Protocolo: só dígitos, como format_protocol() do PHP.
      var protocol = String(c.protocol || '').replace(/\D/g, '');
      var protocolHtml = protocol
        ? ' <span style="font-weight:400;color:var(--text-muted);font-size:12px;font-variant-numeric:tabular-nums" title="Protocolo do atendimento">#' + this._esc(protocol) + '</span>'
        : '';

      // Preview: mídia (ícone + rótulo) ou texto — igual ao PHP. A loja
      // (unidade) da CONVERSA vem logo depois, também como no PHP.
      var mediaIcons = {
        image:['fa-image','Imagem'], video:['fa-video','Vídeo'], audio:['fa-volume-high','Áudio'],
        file:['fa-paperclip','Arquivo'], sticker:['fa-smile','Sticker']
      };
      var lmType = c.last_message_type || '';
      var isMedia = !!mediaIcons[lmType];
      var previewInner = isMedia
        ? '<i class="fas ' + mediaIcons[lmType][0] + ' conv-preview-icon" aria-hidden="true"></i>' + this._esc(mediaIcons[lmType][1])
        : this._esc(this._previewText(c, 90));
      var subjectHtml = c.subject ? '<strong>' + this._esc(c.subject) + '</strong> — ' : '';
      var unitHtml = c.unit ? '<span class="conv-preview-unit">| ' + this._esc(c.unit) + '</span>' : '';

      var groupChip = c.group_id
        ? '<span class="chip chip-info" title="Conversa de grupo WhatsApp"><i class="fas fa-users" style="font-size:11px"></i> Grupo</span>'
        : '';

      var el = document.createElement('a');
      el.href = baseUrl + id;
      el.className = 'conv-item' + (activeConvId === String(id) ? ' active' : '');
      el.dataset.convId = id;
      el.dataset.msgCount = String(c.message_count || 0);
      el.dataset.unread = String(un);
      el.dataset.flow = c.has_active_flow ? '1' : '0';
      el.dataset.tags = c.tags_json || '';
      el.dataset.slaStatus = String(c.status || '');
      el.dataset.slaDir = String(c.last_message_direction || '');
      el.dataset.slaWaiting = String(parseInt(c.waiting_seconds, 10) || 0);
      el.dataset.slaInbox = c.inbox_id != null ? String(c.inbox_id) : '';
      el.dataset.slaSyncAt = String(parseInt(c.sla_sync_at, 10) || 0);
      el.dataset.slaFrozen = c.sla_frozen ? '1' : '0';
      el.dataset.slaBoundary = String(parseInt(c.sla_boundary_at, 10) || 0);
      el.dataset.groupId = c.group_id ? String(c.group_id) : '';

      el.innerHTML =
        '<div class="avatar-wrap">' + avatarHtml + statusDot + unreadBadge + '</div>' +
        '<div class="conv-body">' +
          '<div class="conv-top">' +
            '<span class="conv-name">' + this._esc(c.contact_name) + protocolHtml + '</span>' +
            '<span class="conv-time">' + this._timeAgo(c.last_message_at || c.created_at) + '</span>' +
          '</div>' +
          '<div class="conv-preview">' +
            '<span class="conv-preview-text">' + subjectHtml + previewInner + '</span>' + unitHtml +
          '</div>' +
          '<div class="conv-tags">' +
            '<span class="chip chip-neutral"><i class="' + chIcon + '" style="font-size:11px"></i> ' + this._esc(c.channel_name || '') + '</span>' +
            groupChip +
            tagsChips +
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
      this._known[id] = { unread: 0, msgCount: 0, status: c.status || '', contactName: c.contact_name || '' };
      this._poll();
      var container = document.getElementById('conversationsList');
      if (!container) return;
      var activeConvId = new URLSearchParams(window.location.search).get('conv');
      var initial = Object.assign({}, c, { unread_count: 0, message_count: 0, last_message_at: c.created_at });
      // Fora do recorte atual (ex.: outro setor na lista filtrada por
      // setor): não entra na lista. O poll traz a conversa se ela for do
      // recorte, com os contadores certos.
      if (!this._matchesCurrentFilter(initial)) return;
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
    /**
     * Texto de preview da conversa — espelha conversation_preview_text() do
     * PHP: payload JSON de webhook/fluxo vira o texto útil (nada de JSON
     * cru na lista) e o corte é o mesmo.
     */
    _previewText(m, l) {
      var raw = String(m.last_message == null ? '' : m.last_message).trim();
      var first = raw.charAt(0);
      if (first === '{' || first === '[') {
        try {
          var decoded = JSON.parse(raw);
          if (decoded && typeof decoded === 'object') {
            var keys = ['prompt', 'message', 'text', 'body', 'content', 'description'];
            for (var i = 0; i < keys.length; i++) {
              var v = decoded[keys[i]];
              if (typeof v === 'string' && v.trim() !== '') return this._truncate(v.trim(), l);
            }
            var arrKeys = ['buttons', 'options', 'actions', 'items'];
            for (var j = 0; j < arrKeys.length; j++) {
              var arr = decoded[arrKeys[j]];
              if (Array.isArray(arr) && arr.length) {
                var opt = arr[0];
                if (opt && typeof opt === 'object') {
                  var optKeys = ['title', 'label', 'text'];
                  for (var k = 0; k < optKeys.length; k++) {
                    if (typeof opt[optKeys[k]] === 'string' && opt[optKeys[k]].trim() !== '') {
                      return this._truncate(opt[optKeys[k]].trim(), l);
                    }
                  }
                }
              }
            }
            if (typeof decoded.title === 'string' && decoded.title.trim() !== '') {
              return this._truncate(decoded.title.trim(), l);
            }
            return 'Mensagem automática';
          }
        } catch (_) {}
      }
      return this._truncate(raw, l);
    },
    _statusLabel(s) { return ({ new:'Novo', open:'Aberto', waiting_customer:'Aguardando cliente', waiting_internal:'Aguardando interno', resolved:'Resolvido', closed:'Fechado', spam:'Spam' })[s] || s; },
    _priorityLabel(p) { return ({ low:'Baixa', normal:'Normal', high:'Alta', urgent:'Urgente' })[p] || p; },

    _flashToast(body, c, opts) {
      opts = opts || {};
      var existing = document.querySelector('.notif-toast');
      if (existing) existing.remove();
      var toast = document.createElement('div');
      toast.className = 'notif-toast';
      var iconClass = opts.icon || 'fa-solid fa-comment-dots';
      var subText = opts.subText || 'Clique para abrir';
      toast.innerHTML =
        '<div class="notif-toast-icon"><i class="' + iconClass + '"></i></div>' +
        '<div class="notif-toast-content">' +
          '<div class="notif-toast-title">' + this._esc(body) + '</div>' +
          '<div class="notif-toast-body">' + this._esc(subText) + '</div>' +
        '</div>' +
        '<button class="notif-toast-close">&times;</button>';
      toast.querySelector('.notif-toast-close').addEventListener('click', function () { toast.remove(); });
      if (c && c.id && !opts.noClick) {
        toast.addEventListener('click', function () {
          window.location.href = (window.__enhancements && window.__enhancements.LiveFeed && window.__enhancements.LiveFeed._baseUrl() || '') + '/inbox?conv=' + c.id;
        });
      }
      document.body.appendChild(toast);
      setTimeout(function () { if (toast.parentNode) toast.remove(); }, opts.duration || 6000);
    },

    _notifyBrowser(title, body, opts) {
      if (window.__enhancements && window.__enhancements.GlobalNotifier) {
        return window.__enhancements.GlobalNotifier.notifyBrowser(title, body, opts);
      }
      return false;
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
      this._fetchInboxCounts();
      this._trySSE();
      this._fallbackTimer = setInterval(this._fetchSummary.bind(this), 30000);
      this._inboxTimer = setInterval(this._fetchInboxCounts.bind(this), 15000);
      document.addEventListener('visibilitychange', function () {
        if (!document.hidden && window.__enhancements && window.__enhancements.GlobalNotifier) {
          window.__enhancements.GlobalNotifier._fetchInboxCounts();
        }
      });
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
          // Áudios personalizados: id → url (para 'custom:<id>').
          try {
            var map = {};
            (data.custom_sounds || []).forEach(function (s) {
              if (s && s.id && s.url) map[String(s.id)] = s.url;
            });
            SoundManager.setCustomSounds(map);
          } catch (_) {}
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
        // Se um sino (SSE) acabou de avisar — inclusive menção com som
        // próprio — não toca de novo: só atualiza os contadores.
        var justNotified = this._lastNotifAt && (Date.now() - this._lastNotifAt < 45000);
        if (!justNotified) {
          SoundManager.play('incoming');
          this._showToast(
            delta > 1 ? (delta + ' conversas com novas mensagens') : 'Nova conversa com mensagens',
            'Toque no sino para ver'
          );
        }
      }
      this.lastUnreadConversations = convCount;
      this.lastUnreadNotifications = data.unread_notifications || 0;
      this._updateBadge(this.lastUnreadNotifications);
      this._saveCounts();
    },

    _fetchInboxCounts() {
      var baseUrl = this._baseUrl();
      if (!baseUrl || document.hidden) return;
      var self = this;
      fetch(baseUrl + '/api/inbox-counts', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data && data.counts) self._onInboxCounts(data.counts, data.total || 0);
        })
        .catch(function () {});
    },

    _onInboxCounts(counts, total) {
      this._inboxCounts = counts || {};
      this._inboxTotal = total || 0;
      this._updateInboxBadges(counts || {}, total || 0);
    },

    _setBadge(link, count) {
      if (!link) return;
      var badge = link.querySelector('[data-inbox-badge]');
      var prev = badge ? parseInt(badge.textContent, 10) || 0 : 0;
      if (count > 0) {
        if (!badge) {
          badge = document.createElement('span');
          badge.className = 'nav-badge';
          badge.setAttribute('data-inbox-badge', link.getAttribute('data-inbox-link'));
          link.appendChild(badge);
        }
        badge.textContent = count > 99 ? '99+' : String(count);
        if (count > prev && badge.animate) {
          try {
            badge.animate(
              [{ transform: 'scale(1.5)' }, { transform: 'scale(1)' }],
              { duration: 300 }
            );
          } catch (_) {}
        }
      } else if (badge) {
        badge.remove();
      }
    },

    _updateInboxBadges(counts, total) {
      var self = this;
      var allLink = document.querySelector('[data-inbox-link="all"]');
      this._setBadge(allLink, total);
      document.querySelectorAll('[data-inbox-link]').forEach(function (link) {
        var id = link.getAttribute('data-inbox-link');
        if (!id || id === 'all') return;
        var c = parseInt(counts[id] || counts[parseInt(id, 10)] || '0', 10) || 0;
        self._setBadge(link, c);
      });
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

        this.eventSource.addEventListener('conversation_updated', function (e) {
          try {
            var data = JSON.parse(e.data);
            // Tags/fluxo mudaram (fluxo iniciado/finalizado/timeout, etiqueta
            // aplicada/removida): dispara re-busca da lista para atualizar os
            // chips "Fluxo"/"Aberto" e demais etiquetas em tempo real.
            if (window.__enhancements && window.__enhancements.LiveFeed) {
              window.__enhancements.LiveFeed.forcePoll();
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

        this.eventSource.addEventListener('inbox_counts', function (e) {
          try {
            var data = JSON.parse(e.data);
            self._onInboxCounts(data.counts || {}, data.total || 0);
          } catch (_) {}
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

    _lastNotifAt: 0,

    _onNotification(data) {
      var title = data.title || 'Nova notificação';
      var body = data.body || '';
      var convId = data.conversation_id;
      if (convId && this._viewingId && String(convId) === this._viewingId) {
        return;
      }
      this._lastNotifAt = Date.now();
      var isMention = data.notification_type === 'group_mention' || data.notification_type === 'mention';
      var isNewConv = data.notification_type === 'new_conversation';
      if (isMention) {
        // Menção (@número no grupo / @usuário): som DIFERENTE + toast âmbar.
        SoundManager.play('mention');
        this._showToast(title, body, convId, { mention: true, icon: 'fa-solid fa-at' });
        if (document.visibilityState !== 'visible') {
          this.notifyBrowser(title, body, { tag: 'mention-' + (convId || data.id || 'x'), convId: convId });
        }
      } else {
        SoundManager.play(isNewConv ? 'new_conv' : 'incoming');
        this._showToast(title, body, convId);
        if (document.visibilityState !== 'visible') {
          this.notifyBrowser(title, body, { tag: 'notif-' + (data.id || convId || Date.now()), convId: convId });
        }
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
      if (document.visibilityState !== 'visible') {
        this.notifyBrowser(title, body, { tag: 'newconv-' + id, convId: id });
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
      if (document.visibilityState !== 'visible') {
        this.notifyBrowser(title, preview, { tag: 'msg-' + convId, convId: convId });
      }
    },

    _showToast(title, body, convId, opts) {
      opts = opts || {};
      var existing = document.querySelector('.notif-toast');
      if (existing) existing.remove();

      var toast = document.createElement('div');
      toast.className = 'notif-toast' + (opts.mention ? ' notif-toast-mention' : '');
      var icon = opts.icon || 'fa-solid fa-bell';
      toast.innerHTML =
        '<div class="notif-toast-icon"><i class="' + icon + '"></i></div>' +
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

    /**
     * Central de notificações do navegador (PC): título/corpo na ordem
     * certa, clique abre a conversa, tag evita empilhar, fecha sozinha.
     * Retorna true quando exibiu.
     */
    browserPermission() {
      try {
        return ('Notification' in window) ? Notification.permission : 'unsupported';
      } catch (_) {
        return 'unsupported';
      }
    },

    notifyBrowser(title, body, opts) {
      opts = opts || {};
      if (!this._browserNotifEnabled) return false;
      if (!('Notification' in window) || Notification.permission !== 'granted') return false;
      try {
        var baseUrl = this._baseUrl();
        var n = new Notification(title || 'OminiDesk', {
          body: body || '',
          icon: baseUrl + '/assets/img/favicon.png',
          tag: opts.tag || 'ominidesk',
          renotify: !!opts.tag,
          requireInteraction: false,
        });
        var convId = opts.convId;
        n.onclick = function () {
          try { window.focus(); } catch (_) {}
          try {
            if (convId) {
              window.location.href = baseUrl + '/inbox?conv=' + convId;
            } else if (window.focus) {
              window.focus();
            }
          } catch (_) {}
          try { n.close(); } catch (_) {}
        };
        setTimeout(function () { try { n.close(); } catch (_) {} }, opts.timeout || 9000);
        return true;
      } catch (_) {
        return false;
      }
    },

    testBrowserNotif() {
      return this.notifyBrowser(
        'OminiDesk — notificações ativadas ✓',
        'Você receberá avisos do navegador mesmo com outra aba aberta.',
        { tag: 'ominidesk-test', timeout: 7000 }
      );
    },

    /**
     * SLA atingiu attention/alert: avisa no navegador (só com aba oculta;
     * com a aba aberta a cor + som da lista já bastam).
     */
    notifySla(level, convId, name) {
      if (document.visibilityState === 'visible') return;
      var label = level === 'alert' ? 'SLA em ALERTA (vermelho)' : 'SLA em atenção (amarelo)';
      this.notifyBrowser(label, (name || 'Conversa') + ' aguardando há muito tempo.', {
        tag: 'sla-' + (convId || level),
        convId: convId,
      });
    },

    _requestPermission() {
      if ('Notification' in window && Notification.permission === 'default') {
        try {
          var p = Notification.requestPermission();
          if (p && p.then) {
            p.then(function () {
              try {
                if (typeof window.refreshBrowserNotifStatus === 'function') {
                  window.refreshBrowserNotifStatus();
                }
              } catch (_) {}
            });
          }
        } catch (_) {}
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
  // Tabelas com rolagem horizontal
  // ──────────────────────────────────────────
  // Várias telas (contatos, tags, macros, canais, grupos…) renderizam a
  // <table> direto dentro do card. Em telas estreitas a tabela estoura o
  // cartão e é cortada sem rolagem (887px de conteúdo num cartão de 360px).
  // Envolver em .table-wrap (overflow-x:auto) resolve todas de uma vez.
  const ResponsiveTables = {
    init() {
      document.querySelectorAll('table.table').forEach((table) => {
        const parent = table.parentElement;
        if (!parent) return;
        if (parent.classList.contains('table-wrap')) return;
        if (parent.style && parent.style.overflowX === 'auto') return;
        if (parent.closest('.table-wrap')) return;

        const wrap = document.createElement('div');
        wrap.className = 'table-wrap';
        wrap.setAttribute('tabindex', '0');
        wrap.setAttribute('role', 'region');
        wrap.setAttribute('aria-label', 'Tabela — role horizontalmente para ver todas as colunas');
        parent.insertBefore(wrap, table);
        wrap.appendChild(table);
      });
    },
  };

  // ──────────────────────────────────────────
  // Init on DOM ready
  // ──────────────────────────────────────────
  function init() {
    SoundManager._initAudioOnGesture();
    GlobalNotifier.init();
    ResponsiveTables.init();

    if (document.querySelector('.app-inbox')) {
      // SlaManager.init() injeta CSS e dispara a primeira repintura; roda
      // antes do LiveFeed para que o primeiro _poll já encontre as classes
      // SLA aplicadas.
      SlaManager.init();
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
    LiveFeed, SoundManager, GlobalNotifier, SlaManager,
  };
})();

/* ==========================================
   OminiColor - contraste garantido (WCAG AA)
   Espelha os helpers PHP readable_pair() e
   readable_text_on_tint() para o que é montado
   no navegador (chips criados por JS).
   ========================================== */
(function () {
  'use strict';

  function hexRgb(c) {
    var h = String(c == null ? '' : c).trim().replace('#', '');
    if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
    if (!/^[0-9a-fA-F]{6}$/.test(h)) h = '6c757d';
    return [parseInt(h.substr(0, 2), 16), parseInt(h.substr(2, 2), 16), parseInt(h.substr(4, 2), 16)];
  }

  function lum(rgb) {
    var f = function (v) {
      v /= 255;
      return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
    };
    return 0.2126 * f(rgb[0]) + 0.7152 * f(rgb[1]) + 0.0722 * f(rgb[2]);
  }

  function ratio(l1, l2) {
    var hi = Math.max(l1, l2), lo = Math.min(l1, l2);
    return (hi + 0.05) / (lo + 0.05);
  }

  function toHex(n) {
    var s = Math.max(0, Math.min(255, Math.round(n))).toString(16);
    return s.length === 1 ? '0' + s : s;
  }

  /** [fundo, texto] com contraste >= 4,5:1 (escurece o fundo se preciso). */
  function readablePair(c) {
    var rgb = hexRgb(c);
    var l = lum(rgb);
    var darkL = lum([32, 31, 30]);
    if (ratio(1, l) >= 4.5) return [String(c), '#ffffff'];
    if (ratio(l, darkL) >= 4.5) return [String(c), '#0f172a'];
    var f = 1;
    for (var i = 0; i < 60; i++) {
      f -= 0.01;
      if (f <= 0.05) break;
      var ll = lum([Math.round(rgb[0] * f), Math.round(rgb[1] * f), Math.round(rgb[2] * f)]);
      if (ratio(1, ll) >= 4.6) break;
    }
    return ['#' + toHex(rgb[0] * f) + toHex(rgb[1] * f) + toHex(rgb[2] * f), '#ffffff'];
  }

  /** Cor de texto legível sobre fundo claro tingido com a cor informada. */
  function readableTextOnTint(c, tint) {
    tint = (tint === undefined) ? 0.125 : tint;
    var rgb = hexRgb(c);
    var bg = lum([
      Math.round(rgb[0] * tint + 255 * (1 - tint)),
      Math.round(rgb[1] * tint + 255 * (1 - tint)),
      Math.round(rgb[2] * tint + 255 * (1 - tint)),
    ]);
    var f = 1;
    for (var i = 0; i < 80; i++) {
      var ll = lum([Math.round(rgb[0] * f), Math.round(rgb[1] * f), Math.round(rgb[2] * f)]);
      if (ratio(ll, bg) >= 4.6) break;
      f -= 0.01;
      if (f <= 0.1) break;
    }
    return '#' + toHex(rgb[0] * f) + toHex(rgb[1] * f) + toHex(rgb[2] * f);
  }

  window.OminiColor = { readablePair: readablePair, readableTextOnTint: readableTextOnTint };
})();

/* ==========================================
   OminiConfirm - modal de confirmação (substitui confirm())
   window.OminiConfirm(msg, opts) -> Promise<boolean>
   + interceptor para <form data-confirm="...">
   ========================================== */
(function () {
  'use strict';
  var overlay = null, box = null, resolveFn = null, returnFocus = null;

  function close(result) {
    if (!overlay || !overlay.classList.contains('open')) return;
    var cb = resolveFn; resolveFn = null;
    overlay.classList.remove('open');
    if (returnFocus && returnFocus.focus) { try { returnFocus.focus(); } catch (e) {} }
    returnFocus = null;
    if (cb) cb(result);
  }

  function build() {
    if (overlay) return;
    overlay = document.createElement('div');
    overlay.className = 'omini-confirm-overlay';
    overlay.innerHTML =
      '<div class="omini-confirm" role="alertdialog" aria-modal="true" aria-labelledby="ominiConfirmTitle">' +
        '<div class="omini-confirm-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>' +
        '<div class="omini-confirm-body"><h4 id="ominiConfirmTitle"></h4><p class="omini-confirm-msg"></p></div>' +
        '<div class="omini-confirm-actions">' +
          '<button type="button" class="btn btn-outline" data-act="cancel"></button>' +
          '<button type="button" class="btn btn-danger" data-act="ok"></button>' +
        '</div>' +
      '</div>';
    box = overlay.querySelector('.omini-confirm');
    overlay.addEventListener('mousedown', function (e) {
      if (e.target === overlay) { close(false); return; }
      var act = e.target && e.target.closest ? e.target.closest('[data-act]') : null;
      if (act) close(act.getAttribute('data-act') === 'ok');
    });
    document.addEventListener('keydown', function (e) {
      if (!overlay.classList.contains('open')) return;
      if (e.key === 'Escape') { e.preventDefault(); close(false); }
      else if (e.key === 'Enter') { e.preventDefault(); close(true); }
    });
    document.body.appendChild(overlay);
  }

  window.OminiConfirm = function (message, opts) {
    opts = opts || {};
    build();
    returnFocus = document.activeElement;
    resolveFn = null;
    box.classList.toggle('danger', opts.danger !== false);
    box.querySelector('#ominiConfirmTitle').textContent = opts.title || 'Confirmar ação';
    box.querySelector('.omini-confirm-msg').textContent = String(message || '');
    var okBtn = box.querySelector('[data-act="ok"]');
    okBtn.textContent = opts.confirmText || 'Confirmar';
    okBtn.className = 'btn ' + (opts.danger === false ? 'btn-primary' : 'btn-danger');
    box.querySelector('[data-act="cancel"]').textContent = opts.cancelText || 'Cancelar';
    overlay.classList.add('open');
    setTimeout(function () {
      var cancel = box.querySelector('[data-act="cancel"]');
      if (cancel) cancel.focus();
    }, 30);
    return new Promise(function (res) { resolveFn = res; });
  };

  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || !f.getAttribute) return;
    var msg = f.getAttribute('data-confirm');
    if (!msg) return;
    e.preventDefault();
    if (e.stopImmediatePropagation) e.stopImmediatePropagation();
    window.OminiConfirm(msg, { danger: f.getAttribute('data-confirm-danger') !== 'false' }).then(function (ok) {
      if (!ok) return;
      f.removeAttribute('data-confirm');
      try {
        if (f.requestSubmit) f.requestSubmit();
        else HTMLFormElement.prototype.submit.call(f);
      } catch (err) { f.submit(); }
      f.setAttribute('data-confirm', msg);
    });
  }, true);

  // Transcrição de áudios sob demanda (botão .btn-transcribe no inbox).
  // CSRF vai via wrapper global de fetch do layout (X-CSRF-TOKEN).
  document.addEventListener('click', function (e) {
    var btn = e.target && e.target.closest ? e.target.closest('.btn-transcribe') : null;
    if (!btn || btn.disabled) return;
    var mid = btn.getAttribute('data-transcribe-id');
    if (!mid) return;
    btn.disabled = true;
    btn.innerHTML = 'Transcrevendo…';
    fetch('/api/messages/' + encodeURIComponent(mid) + '/transcribe', { method: 'POST' })
      .then(function (r) { return r.json().then(function (d) { return { status: r.status, body: d }; }); })
      .then(function (res) {
        var box = btn.parentElement ? btn.parentElement.querySelector('.msg-transcription') : null;
        if (res.status === 200 && res.body && res.body.text) {
          var t = document.createElement('div');
          t.textContent = res.body.text;
          var html = '<strong>Transcrição:</strong> ' + t.innerHTML;
          if (box) { box.innerHTML = html; box.hidden = false; }
          btn.remove();
        } else {
          btn.disabled = false;
          btn.innerHTML = 'Tentar novamente';
          var err = (res.body && res.body.error) || 'Falha na transcrição.';
          btn.title = err;
        }
      })
      .catch(function () {
        btn.disabled = false;
        btn.innerHTML = 'Tentar novamente';
      });
  });
})();
