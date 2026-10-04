(function () {
    'use strict';

    var C = window.ATENDIMENTO_CONFIG || {};
    var WIDGET_KEY = C.widgetId || '';
    var API_BASE = C.apiUrl || (window.location.origin + (window.AF_BASE || ''));
    var PRIMARY = C.color || '#0078d4';
    var POSITION = C.position || 'right';
    var TITLE = C.title || 'Atendimento';
    var WELCOME = C.welcomeMessage || 'Olá! Como podemos ajudar?';
    var AVATAR = C.avatarUrl || '';
    var QUICK_REPLIES = Array.isArray(C.quickReplies) ? C.quickReplies : [];

    // Período em que a conversa é retomada após atualizar a página (ms)
    var RESUME_MS = (typeof C.resumeHours === 'number' ? C.resumeHours : 24) * 3600 * 1000;
    var STORE_KEY = 'afw_sess_' + WIDGET_KEY;

    function saveSession(id) {
        try { localStorage.setItem(STORE_KEY, JSON.stringify({ id: id, ts: Date.now() })); } catch (e) {}
    }
    function loadSession() {
        try {
            var raw = localStorage.getItem(STORE_KEY);
            if (!raw) return null;
            var obj = JSON.parse(raw);
            if (!obj || !obj.id) return null;
            if (Date.now() - (obj.ts || 0) > RESUME_MS) { clearSession(); return null; }
            return obj.id;
        } catch (e) { return null; }
    }
    function clearSession() {
        try { localStorage.removeItem(STORE_KEY); } catch (e) {}
    }
    function touchSession() {
        if (!sessionId) return;
        try {
            var raw = localStorage.getItem(STORE_KEY);
            var obj = raw ? JSON.parse(raw) : {};
            obj.id = sessionId; obj.ts = Date.now();
            localStorage.setItem(STORE_KEY, JSON.stringify(obj));
        } catch (e) {}
    }

    var FIELD_DEFS = {
        name:  { label: 'Nome', type: 'text', required: true },
        email: { label: 'E-mail', type: 'email', required: false },
        phone: { label: 'Telefone', type: 'tel', required: false },
        cnpj:  { label: 'CNPJ', type: 'text', required: false }
    };
    var FIELDS = {};
    ['name', 'email', 'phone', 'cnpj'].forEach(function (k) {
        var f = C.fields && C.fields[k];
        FIELDS[k] = {
            ask: f ? !!f.ask : (k === 'name'),
            required: f ? !!f.required : (k === 'name')
        };
    });

    if (!WIDGET_KEY) { console.warn('[OminiDesk] widgetId não configurado.'); return; }

    var sessionId = null, lastPoll = null, isPolling = false, unread = 0, started = false;
    var root, panel, messagesEl, composer, input, sendBtn, badge, scrollFab, quickEl, typingEl;

    // ── Injetar CSS + variáveis ──
    var link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = API_BASE + '/widget/chat.css?_=' + Date.now();
    document.head.appendChild(link);

    var styleVars = document.createElement('style');
    var sv = '#atendeflow-widget{';
    sv += '--afw-primary:' + PRIMARY + ';';
    sv += '--afw-primary-dark:' + shade(PRIMARY, -22) + ';';
    if (C.backgroundColor) sv += '--afw-bg:' + C.backgroundColor + ';';
    if (C.borderColor) sv += '--afw-border:' + C.borderColor + ';';
    if (C.agentBubbleColor) sv += '--afw-bot-bubble:' + C.agentBubbleColor + ';';
    if (C.agentBubbleText) sv += '--afw-bot-text:' + C.agentBubbleText + ';';
    if (C.clientBubbleColor) sv += '--afw-user:' + C.clientBubbleColor + ';';
    if (C.clientBubbleText) sv += '--afw-user-text:' + C.clientBubbleText + ';';
    sv += '}';
    styleVars.textContent = sv;
    document.head.appendChild(styleVars);

    // ── Estilos do widget de CSAT ──
    var csatStyle = document.createElement('style');
    csatStyle.textContent =
        '.afw-csat{background:var(--afw-bot-bubble,#fff);border:1px solid rgba(0,0,0,.08);border-radius:12px;padding:12px;max-width:300px}' +
        '.afw-csat-title{font-weight:700;color:#222}' +
        '.afw-csat-prompt{font-size:13px;color:#555;margin:4px 0 8px;white-space:pre-wrap}' +
        '.afw-csat-stars{font-size:24px;color:#d0d7de;cursor:pointer}' +
        '.afw-csat-stars .afw-star{border:0;background:none;font-size:24px;color:#d0d7de;cursor:pointer;padding:0 2px;line-height:1}' +
        '.afw-csat-stars .afw-star.on{color:#ffb400}' +
        '.afw-csat-comment{width:100%;margin-top:8px;border:1px solid #d0d7de;border-radius:8px;padding:6px;font:inherit;resize:vertical}' +
        '.afw-csat-send{margin-top:8px;width:100%;background:var(--afw-primary,#0078d4);color:#fff;border:0;border-radius:8px;padding:8px;cursor:pointer}' +
        '.afw-csat-thanks{color:#16a34a;font-weight:600;margin-top:6px}' +
        '.afw-csat-link{display:block;margin-top:8px;text-align:center;color:var(--afw-primary,#0078d4);font-weight:600;text-decoration:none}' +
        '.afw-csat-link:hover{text-decoration:underline}';
    document.head.appendChild(csatStyle);

    // ── SVG icons ──
    var ICON = {
        chat: '<svg class="afw-ico afw-ico--chat" viewBox="0 0 24 24"><path d="M12 3C6.5 3 2 6.7 2 11.2c0 2.4 1.2 4.5 3.1 6L4 21l4.3-2.2c1.1.3 2.3.5 3.7.5 5.5 0 10-3.7 10-8.2S17.5 3 12 3z"/></svg>',
        close: '<svg class="afw-ico afw-ico--close" viewBox="0 0 24 24"><path d="M19 6.4 17.6 5 12 10.6 6.4 5 5 6.4 10.6 12 5 17.6 6.4 19 12 13.4 17.6 19 19 17.6 13.4 12z"/></svg>',
        minimize: '<svg viewBox="0 0 24 24"><path d="M5 11h14v2H5z"/></svg>',
        down: '<svg viewBox="0 0 24 24"><path d="M12 16 6 10l1.4-1.4L12 13.2l4.6-4.6L18 10z"/></svg>',
        send: '<svg viewBox="0 0 24 24"><path d="M3.4 20.4 21 12 3.4 3.6 3 10l11 2-11 2z"/></svg>',
        attach: '<svg viewBox="0 0 24 24"><path d="M16.5 6.5l-7 7a2.5 2.5 0 0 0 3.5 3.5l7-7a4.5 4.5 0 0 0-6.4-6.4l-7 7a6.5 6.5 0 0 0 9.2 9.2l6.3-6.3"/></svg>',
        file: '<svg viewBox="0 0 24 24"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9l-7-7zm0 2.5L18.5 9H13V4.5z"/></svg>',
        soundOn: '<svg viewBox="0 0 24 24"><path d="M4 9v6h4l5 5V4L8 9H4z"/><path d="M16 8a5 5 0 0 1 0 8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
        soundOff: '<svg viewBox="0 0 24 24"><path d="M4 9v6h4l5 5V4L8 9H4z"/><path d="M16 9l5 6m0-6l-5 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
        mic: '<svg viewBox="0 0 24 24"><path d="M12 14a3 3 0 0 0 3-3V5a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3z"/><path d="M19 11a1 1 0 1 0-2 0 5 5 0 1 1-10 0 1 1 0 1 0-2 0 7 7 0 0 0 6 6.92V21h2v-3.08A7 7 0 0 0 19 11z"/></svg>',
        stop: '<svg viewBox="0 0 24 24"><rect x="6" y="6" width="12" height="12" rx="1.5"/></svg>',
        trash: '<svg viewBox="0 0 24 24"><path d="M9 3l-1 1H4v2h16V4h-4l-1-1H9zm-3 5v12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V8H6z"/></svg>'
    };

    var agentAvatar = AVATAR
        ? '<img src="' + esc(absUrl(AVATAR)) + '" alt="">'
        : '<div class="afw-avatar-fallback">' + esc(TITLE.charAt(0)) + '</div>';

    // Avatares vêm do banco como caminho relativo (ex.: "avatars/x.png"):
    // resolve para URL absoluta igual ao painel do atendente.
    function avatarUrl(u) {
        if (!u) return u;
        if (u.indexOf('http://') === 0 || u.indexOf('https://') === 0) return u;
        var p = String(u).replace(/^\/+/, '');
        if (p.indexOf('uploads/') === 0) return API_BASE + '/' + p;
        return API_BASE + '/uploads/' + p;
    }

    var msgAvatar = function (src) {
        if (src) return '<img class="afw-msg-avatar" src="' + esc(avatarUrl(src)) + '" alt="">';
        if (AVATAR) return '<img class="afw-msg-avatar" src="' + esc(avatarUrl(AVATAR)) + '" alt="">';
        return '<div class="afw-msg-avatar" style="background:rgba(0,0,0,.08);display:flex;align-items:center;justify-content:center;font-size:12px;color:#6b7488">' + esc(TITLE.charAt(0)) + '</div>';
    };

    // ── Montar DOM ──
    root = document.createElement('div');
    root.id = 'atendeflow-widget';
    root.innerHTML =
        '<button class="afw-launcher" id="afwLauncher" style="' + POSITION + ':24px" aria-label="Abrir atendimento">' +
            ICON.chat + ICON.close +
            '<span class="afw-badge" id="afwBadge">0</span>' +
        '</button>' +
        '<section class="afw-panel" id="afwPanel" role="dialog" aria-label="Atendimento" style="' + POSITION + ':24px">' +
            '<header class="afw-header">' +
                '<div class="afw-agent">' +
                    '<div class="afw-agent-avatar">' + agentAvatar + '<span class="afw-status-dot"></span></div>' +
                    '<div class="afw-agent-info">' +
                        '<div class="afw-agent-name" id="afwName">' + esc(TITLE) + '</div>' +
                        '<div class="afw-agent-status" id="afwStatusTxt">Online agora</div>' +
                    '</div>' +
                '</div>' +
                '<div class="afw-actions">' +
                    '<button class="afw-icon-btn" id="afwSound" aria-label="Som">' + ICON.soundOn + '</button>' +
                    '<button class="afw-icon-btn" id="afwMin" aria-label="Minimizar">' + ICON.minimize + '</button>' +
                    '<button class="afw-icon-btn" id="afwClose" aria-label="Fechar">' + ICON.close + '</button>' +
                '</div>' +
            '</header>' +
            '<div class="afw-body">' +
                '<div class="afw-messages" id="afwMessages"></div>' +
                '<button class="afw-scroll-fab" id="afwScrollFab" aria-label="Ir para o final">' + ICON.down + '</button>' +
                '<div class="afw-quick" id="afwQuick" style="display:none"></div>' +
                '<div class="afw-prechat" id="afwPrechat">' +
                    '<div class="afw-prechat-title">Antes de começar…</div>' +
                    '<div class="afw-prechat-sub">Informe seus dados para um atendimento mais rápido.</div>' +
                    '<div id="afwPrechatFields"></div>' +
                    '<button class="afw-start" id="afwStart">Iniciar conversa</button>' +
                '</div>' +
                '<div class="afw-composer" id="afwComposer">' +
                    '<button class="afw-attach" id="afwAttach" type="button" aria-label="Anexar arquivo" title="Anexar arquivo">' + ICON.attach + '</button>' +
                    '<button class="afw-mic" id="afwMic" type="button" aria-label="Gravar áudio" title="Gravar áudio">' + ICON.mic + '</button>' +
                    '<input type="file" id="afwFile" accept="image/*,audio/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip" hidden>' +
                    '<div class="afw-attach-preview" id="afwAttachPreview" style="display:none"></div>' +
                    '<div class="afw-quote-bar" id="afwQuoteBar" style="display:none"><span class="afw-quote-bar-text" id="afwQuoteText"></span><button type="button" class="afw-quote-bar-x" id="afwQuoteX" aria-label="Remover citação">×</button></div>' +
                    '<div class="afw-record" id="afwRecord" style="display:none">' +
                        '<span class="afw-rec-indicator" id="afwRecIndicator"></span>' +
                        '<span class="afw-rec-timer" id="afwRecTimer">00:00</span>' +
                        '<button type="button" class="afw-rec-btn afw-rec-cancel" id="afwRecCancel" title="Cancelar">' + ICON.trash + '</button>' +
                        '<button type="button" class="afw-rec-btn afw-rec-stop" id="afwRecStop" title="Parar">' + ICON.stop + '</button>' +
                    '</div>' +
                    '<div class="afw-input-wrap">' +
                        '<textarea class="afw-textarea" id="afwInput" rows="1" placeholder="Escreva sua mensagem…" autocomplete="off"></textarea>' +
                    '</div>' +
                    '<button class="afw-send" id="afwSend" disabled aria-label="Enviar">' + ICON.send + '</button>' +
                '</div>' +
            '</div>' +
        '</section>';
    document.body.appendChild(root);

    panel = g('afwPanel');
    messagesEl = g('afwMessages');
    composer = g('afwComposer');
    input = g('afwInput');
    sendBtn = g('afwSend');
    badge = g('afwBadge');
    scrollFab = g('afwScrollFab');
    quickEl = g('afwQuick');
    typingEl = null;
    var launcher = g('afwLauncher');

    // ── Helpers ──
    function g(id) { return document.getElementById(id); }
    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
    function linkify(s) { return esc(s).replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>'); }
    function isOpen() { return root.classList.contains('afw-open'); }

    function shade(hex, amt) {
        var h = hex.replace('#', '');
        if (h.length === 3) h = h[0]+h[0]+h[1]+h[1]+h[2]+h[2];
        var r = Math.max(0, Math.min(255, parseInt(h.substr(0,2),16) + amt));
        var g2 = Math.max(0, Math.min(255, parseInt(h.substr(2,2),16) + amt));
        var b = Math.max(0, Math.min(255, parseInt(h.substr(4,2),16) + amt));
        return '#' + [r,g2,b].map(function(x){ return x.toString(16).padStart(2,'0'); }).join('');
    }

    function nowTime() {
        return new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    }
    function fmtTime(dt) {
        if (!dt) return '';
        var d = new Date(dt.replace(' ', 'T'));
        if (isNaN(d)) return '';
        return d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    }

    function scrollToBottom() { messagesEl.scrollTop = messagesEl.scrollHeight; }
    function isNearBottom() { return messagesEl.scrollHeight - messagesEl.scrollTop - messagesEl.clientHeight < 60; }

    // ── Alertas sonoros (envio / recebimento) ──
    var audioCtx = null;
    var soundOn = (function () { try { return localStorage.getItem('afw_sound') !== '0'; } catch (e) { return true; } })();
    function getAudio() {
        if (!audioCtx) {
            var AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) return null;
            audioCtx = new AC();
        }
        if (audioCtx.state === 'suspended') audioCtx.resume();
        return audioCtx;
    }
    function beep(freq, start, dur, vol) {
        var ctx = getAudio();
        if (!ctx || !soundOn) return;
        var t0 = ctx.currentTime + start;
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.value = freq;
        gain.gain.setValueAtTime(0.0001, t0);
        gain.gain.exponentialRampToValueAtTime(vol || 0.15, t0 + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);
        osc.connect(gain); gain.connect(ctx.destination);
        osc.start(t0); osc.stop(t0 + dur + 0.02);
    }
    function playSendSound() { beep(523.25, 0, 0.12, 0.12); }
    function playReceiveSound() { beep(659.25, 0, 0.12, 0.14); beep(880, 0.13, 0.16, 0.14); }

    var soundBtn = g('afwSound');
    function refreshSoundBtn() {
        soundBtn.innerHTML = soundOn ? ICON.soundOn : ICON.soundOff;
        soundBtn.title = soundOn ? 'Som ligado' : 'Som desligado';
        soundBtn.classList.toggle('muted', !soundOn);
    }
    soundBtn.addEventListener('click', function () {
        soundOn = !soundOn;
        try { localStorage.setItem('afw_sound', soundOn ? '1' : '0'); } catch (e) {}
        refreshSoundBtn();
        if (soundOn) playReceiveSound();
    });
    refreshSoundBtn();

    // ── Render mensagem ──
    function fileKind(file) {
        var m = (file && file.type) || '';
        if (m.indexOf('image/') === 0) return 'image';
        if (m.indexOf('audio/') === 0) return 'audio';
        if (m.indexOf('video/') === 0) return 'video';
        return 'file';
    }
    function formatBytesJS(bytes) {
        var u = ['B', 'KB', 'MB', 'GB'];
        var i = 0;
        bytes = bytes || 0;
        while (bytes >= 1024 && i < u.length - 1) { bytes /= 1024; i++; }
        return Math.round(bytes * 10) / 10 + ' ' + u[i];
    }
    function absUrl(u) {
        if (!u) return u;
        if (u.indexOf('http://') === 0 || u.indexOf('https://') === 0) return u;
        return API_BASE + '/' + u.replace(/^\//, '');
    }
    function renderFileContent(type, content, isUser) {
        var meta = null;
        try { meta = JSON.parse(content); } catch (e) { meta = { url: content }; }
        var url = absUrl((meta && meta.url) ? meta.url : content);
        if (type === 'image') {
            return '<a class="afw-file-img-link" href="' + esc(url) + '" target="_blank" rel="noopener">' +
                '<img class="afw-file-img" src="' + esc(url) + '" alt="' + esc(meta ? meta.name : 'imagem') + '"></a>';
        }
        if (type === 'audio') {
            return '<audio class="afw-file-media" controls preload="metadata" src="' + esc(url) + '"></audio>';
        }
        if (type === 'video') {
            return '<video class="afw-file-media" controls preload="metadata" src="' + esc(url) + '"></video>';
        }
        if (type === 'sticker') {
            return '<a class="afw-file-img-link" href="' + esc(url) + '" target="_blank" rel="noopener">' +
                '<img class="afw-sticker" src="' + esc(url) + '" alt="sticker"></a>';
        }
        var name = (meta && meta.name) ? meta.name : 'arquivo';
        var size = (meta && meta.size) ? formatBytesJS(meta.size) : '';
        return '<a class="afw-file-link" href="' + esc(url) + '" target="_blank" rel="noopener" download>' +
            '<span class="afw-file-ico">' + ICON.file + '</span>' +
            '<span class="afw-file-meta">' +
                '<span class="afw-file-name">' + esc(name) + '</span>' +
                (size ? '<span class="afw-file-size">' + esc(size) + '</span>' : '') +
            '</span></a>';
    }
    var CLIENT_EMOJIS = ['👍', '❤️', '😂', '😮', '😢', '🙏', '🔥', '👏', '😍'];

    function parseReactions(m) {
        if (!m || !m.reactions) return [];
        try {
            var r = (typeof m.reactions === 'string') ? JSON.parse(m.reactions) : m.reactions;
            return Array.isArray(r) ? r : [];
        } catch (e) { return []; }
    }
    function reactionsHtml(m) {
        var list = parseReactions(m);
        if (!list.length) return '';
        var groups = {};
        list.forEach(function (r) {
            if (!r || !r.emoji) return;
            if (!groups[r.emoji]) groups[r.emoji] = 0;
            groups[r.emoji]++;
        });
        var pills = Object.keys(groups).map(function (em) {
            return '<span class="afw-react-pill" title="' + groups[em] + '">' + esc(em) + (groups[em] > 1 ? ' ' + groups[em] : '') + '</span>';
        }).join('');
        return pills ? '<div class="afw-react-pills">' + pills + '</div>' : '';
    }
    function isMsgDeleted(m) { return !!(m && m.deleted_at); }
    function isMsgEdited(m) {
        return !!(m && !m.deleted_at && m.updated_at && m.created_at && m.updated_at !== m.created_at);
    }
    function quotePreviewText(q) {
        if (!q) return '';
        var t = q.type || 'text';
        if (t !== 'text') {
            var labels = { image: '🖼️ Imagem', video: '🎬 Vídeo', audio: '🎵 Áudio', file: '📎 Arquivo', sticker: '🖼️ Sticker' };
            return labels[t] || 'Anexo';
        }
        var s = String(q.content || '');
        try {
            var p = JSON.parse(s);
            if (p && typeof p.text === 'string') s = p.text;
        } catch (e) {}
        s = s.replace(/<[^>]+>/g, '');
        return s.length > 120 ? s.substring(0, 120) + '…' : s;
    }
    function quoteBlockHtml(m, isUser) {
        var q = m && m.reply_to_data;
        if (!q) return '';
        var who = (q.direction === 'inbound') ? 'Você' : (TITLE || 'Atendente');
        return '<div class="afw-quote"><div class="afw-quote-name">' + esc(who) + '</div>' +
            '<div class="afw-quote-text">' + esc(quotePreviewText(q)) + '</div></div>';
    }
    // Barra de ações do cliente: reagir (todas), citar (mensagens do atendente),
    // editar/excluir (próprias).
    function msgActionsHtml(m, isUser) {
        if (!m || !m.id || m.deleted_at) return '';
        var t = m.type || 'text';
        if (t === 'system' || t === 'internal_note' || t === 'csat_request') return '';
        var h = '<div class="afw-msg-actions">' +
            '<button type="button" class="afw-act-btn" data-act="react-toggle" title="Reagir">😊</button>';
        if (!isUser) {
            h += '<button type="button" class="afw-act-btn" data-act="quote" title="Responder">↩️</button>';
        }
        if (isUser && t === 'text') {
            h += '<button type="button" class="afw-act-btn" data-act="edit" title="Editar">✏️</button>' +
                 '<button type="button" class="afw-act-btn" data-act="del" title="Apagar">🗑️</button>';
        } else if (isUser) {
            h += '<button type="button" class="afw-act-btn" data-act="del" title="Apagar">🗑️</button>';
        }
        return h + '</div>';
    }
    function bubbleBodyHtml(m, text, isUser, type, fileContent) {
        if (isMsgDeleted(m)) return '<span class="afw-deleted">🚫 Mensagem apagada</span>';
        var t = (m && m.type) || type;
        var body = (t && t !== 'text') ? renderFileContent(t, fileContent || text, isUser) : linkify(text == null ? '' : String(text));
        if (isMsgEdited(m) && (!t || t === 'text')) {
            body += '<span class="afw-edited">editada</span>';
        }
        return body;
    }
    function renderMsg(text, isUser, author, avatar, type, fileContent, time, m) {
        if (type === 'csat_request' && !isUser) {
            return renderCsatCard(text, avatar, time);
        }
        if ((type === 'button_list' || type === 'list_menu') && !isUser) {
            return renderInteractiveMsg(type, text, avatar, time);
        }
        var wrap = document.createElement('div');
        wrap.className = 'afw-msg ' + (isUser ? 'afw-msg--user' : 'afw-msg--bot');
        if (m && m.id) wrap.setAttribute('data-mid', m.id);
        wrap.innerHTML =
            msgAvatar(avatar) +
            '<div class="afw-msg-content">' +
                (isUser ? '' : '<span class="afw-msg-author">' + esc(author || TITLE) + '</span>') +
                quoteBlockHtml(m, isUser) +
                '<div class="afw-bubble">' + bubbleBodyHtml(m, text, isUser, type, fileContent) + '</div>' +
                reactionsHtml(m) +
                msgActionsHtml(m, isUser) +
                '<div class="afw-react-row" style="display:none"></div>' +
                '<span class="afw-time">' + (time || nowTime()) + '</span>' +
            '</div>';
        messagesEl.appendChild(wrap);
        return wrap;
    }
    // Atualiza um balão já renderizado (edição/reações/citação via poll).
    function updateMsgNode(wrap, m) {
        var isUserU = wrap.classList.contains('afw-msg--user');
        var oldQ = wrap.querySelector('.afw-quote');
        if (oldQ) oldQ.remove();
        var qHtml = quoteBlockHtml(m, isUserU);
        if (qHtml) {
            var contentQ = wrap.querySelector('.afw-msg-content');
            var bubQ = wrap.querySelector('.afw-bubble');
            if (contentQ && bubQ) {
                var tmpQ = document.createElement('div');
                tmpQ.innerHTML = qHtml;
                if (tmpQ.firstChild) contentQ.insertBefore(tmpQ.firstChild, bubQ);
            }
        }
        var bubble = wrap.querySelector('.afw-bubble');
        if (bubble) {
            var isUser = wrap.classList.contains('afw-msg--user');
            bubble.innerHTML = bubbleBodyHtml(m, m.content, isUser, m.type, m.content);
        }
        var content = wrap.querySelector('.afw-msg-content');
        if (content) {
            var oldPills = content.querySelector('.afw-react-pills');
            if (oldPills) oldPills.remove();
            var tmp = document.createElement('div');
            tmp.innerHTML = reactionsHtml(m);
            var pills = tmp.firstChild;
            var acts = content.querySelector('.afw-msg-actions');
            if (pills) content.insertBefore(pills, acts || content.querySelector('.afw-react-row'));
            var oldActs = content.querySelector('.afw-msg-actions');
            if (oldActs) oldActs.remove();
            var isUser2 = wrap.classList.contains('afw-msg--user');
            var tmp2 = document.createElement('div');
            tmp2.innerHTML = msgActionsHtml(m, isUser2);
            var acts2 = tmp2.firstChild;
            if (acts2) content.insertBefore(acts2, content.querySelector('.afw-react-row'));
        }
    }

    function webchatPost(mid, action, extra) {
        var fd = new FormData();
        fd.append('session_id', sessionId);
        Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });
        return fetch(API_BASE + '/api/webchat/messages/' + mid + '/' + action, { method: 'POST', body: fd })
            .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); });
    }
    function toggleReactRow(wrap) {
        var row = wrap.querySelector('.afw-react-row');
        if (!row) return;
        if (row.style.display === 'none' || !row.innerHTML) {
            row.innerHTML = CLIENT_EMOJIS.map(function (em) {
                return '<button type="button" class="afw-react-emoji" data-act="react" data-emoji="' + em + '">' + em + '</button>';
            }).join('');
            row.style.display = 'flex';
        } else {
            row.style.display = 'none';
        }
    }
    messagesEl.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-act]') : null;
        if (!b || !messagesEl.contains(b)) return;
        var wrap = b.closest('.afw-msg');
        if (!wrap) return;
        var mid = wrap.getAttribute('data-mid');
        if (!mid) return;
        var act = b.getAttribute('data-act');
        if (act === 'react-toggle') {
            toggleReactRow(wrap);
        } else if (act === 'react') {
            var emoji = b.getAttribute('data-emoji');
            b.disabled = true;
            webchatPost(mid, 'reaction', { reaction: emoji }).then(function (res) {
                b.disabled = false;
                if (res.body && res.body.success && res.body.message) {
                    updateMsgNode(wrap, res.body.message);
                }
                var row = wrap.querySelector('.afw-react-row');
                if (row) row.style.display = 'none';
            }).catch(function () { b.disabled = false; });
        } else if (act === 'quote') {
            setQuoteTarget(mid, wrap);
        } else if (act === 'edit') {
            startClientEdit(wrap, mid);
        } else if (act === 'del') {
            if (!confirm('Apagar esta mensagem?')) return;
            webchatPost(mid, 'delete', {}).then(function (res) {
                if (res.body && res.body.success) {
                    updateMsgNode(wrap, { id: parseInt(mid, 10), content: '', type: 'text', deleted_at: '1', reactions: null });
                }
            }).catch(function () {});
        }
    });
    function startClientEdit(wrap, mid) {
        var bubble = wrap.querySelector('.afw-bubble');
        if (!bubble || bubble.querySelector('.afw-edit-box')) return;
        var current = bubble.textContent.replace(/editada\s*$/, '').trim();
        bubble.innerHTML = '<textarea class="afw-edit-box"></textarea>' +
            '<div class="afw-edit-btns"><button type="button" class="afw-edit-cancel">Cancelar</button>' +
            '<button type="button" class="afw-edit-save">Salvar</button></div>';
        var ta = bubble.querySelector('.afw-edit-box');
        ta.value = current;
        ta.focus();
        bubble.querySelector('.afw-edit-cancel').addEventListener('click', function () {
            webchatRefreshOne(mid);
        });
        bubble.querySelector('.afw-edit-save').addEventListener('click', function () {
            var v = ta.value.trim();
            if (!v) return;
            webchatPost(mid, 'edit', { content: v }).then(function (res) {
                if (res.body && res.body.success && res.body.message) {
                    updateMsgNode(wrap, res.body.message);
                } else {
                    alert((res.body && res.body.error) || 'Não foi possível editar.');
                    webchatRefreshOne(mid);
                }
            }).catch(function () { webchatRefreshOne(mid); });
        });
    }
    function webchatRefreshOne(mid) {
        fetch(API_BASE + '/api/webchat/messages?' + new URLSearchParams({ session_id: sessionId, since: '1970-01-01 00:00:00' }))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var found = null;
                (data.messages || []).forEach(function (m) {
                    if (String(m.id) === String(mid)) found = m;
                });
                var wrap = messagesEl.querySelector('.afw-msg[data-mid="' + mid + '"]');
                if (found && wrap) updateMsgNode(wrap, found);
            }).catch(function () {});
    }

    function renderInteractiveMsg(type, content, avatar, time) {
        var data = {};
        try { data = JSON.parse(content); } catch (e) { data = { text: content }; }
        var wrap = document.createElement('div');
        wrap.className = 'afw-msg afw-msg--bot';
        var html = msgAvatar(avatar) +
            '<div class="afw-msg-content">' +
            '<div class="afw-bubble">' + linkify(data.text || '') + '</div>';

        if (type === 'button_list') {
            html += '<div class="afw-buttons">';
            (data.buttons || []).forEach(function (b) {
                html += '<button type="button" class="afw-interact-btn" data-value="' + esc(b.id || b.label) + '">' + esc(b.label) + '</button>';
            });
            html += '</div>';
        } else if (type === 'list_menu') {
            html += '<div class="afw-list-menu">';
            if (data.title) html += '<div class="afw-list-title">' + esc(data.title) + '</div>';
            (data.items || []).forEach(function (item) {
                html += '<button type="button" class="afw-interact-btn afw-list-item" data-value="' + esc(item.id || item.label) + '">' + esc(item.label) + '</button>';
            });
            html += '</div>';
        }

        html += '<span class="afw-time">' + (time || nowTime()) + '</span></div>';
        wrap.innerHTML = html;
        messagesEl.appendChild(wrap);

        wrap.querySelectorAll('.afw-interact-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var val = btn.getAttribute('data-value') || btn.textContent;
                send(val);
                btn.disabled = true;
                btn.classList.add('afw-interact-used');
            });
        });

        if (isNearBottom()) scrollToBottom();
        return wrap;
    }

    function renderCsatCard(content, avatar, time) {
        var data = {};
        try { data = JSON.parse(content); } catch (e) {}
        var csaLink = data.url ? '<a class="afw-csat-link" href="' + esc(data.url) + '" target="_blank" rel="noopener">Avaliar agora →</a>' : '';
        var wrap = document.createElement('div');
        wrap.className = 'afw-msg afw-msg--bot';
        wrap.innerHTML =
            msgAvatar(avatar) +
            '<div class="afw-msg-content">' +
                '<div class="afw-csat">' +
                    '<div class="afw-csat-title">' + esc(data.title || 'Avalie seu atendimento') + '</div>' +
                    '<div class="afw-csat-prompt">' + linkify(data.prompt || '') + '</div>' +
                    '<div class="afw-csat-stars" role="radiogroup">' +
                        '<button type="button" class="afw-star" data-v="1">★</button>' +
                        '<button type="button" class="afw-star" data-v="2">★</button>' +
                        '<button type="button" class="afw-star" data-v="3">★</button>' +
                        '<button type="button" class="afw-star" data-v="4">★</button>' +
                        '<button type="button" class="afw-star" data-v="5">★</button>' +
                    '</div>' +
                    '<textarea class="afw-csat-comment" placeholder="Comentário (opcional)"></textarea>' +
                    '<button type="button" class="afw-csat-send">Enviar avaliação</button>' +
                    csaLink +
                    '<div class="afw-csat-thanks" style="display:none">Obrigado pela sua avaliação! ✅</div>' +
                '</div>' +
                '<span class="afw-time">' + (time || nowTime()) + '</span>' +
            '</div>';
        messagesEl.appendChild(wrap);
        if (isNearBottom()) scrollToBottom();

        var card = wrap.querySelector('.afw-csat');
        var stars = wrap.querySelectorAll('.afw-star');
        var sel = 0;

        stars.forEach(function (s) {
            s.addEventListener('click', function () {
                sel = parseInt(s.getAttribute('data-v'), 10);
                stars.forEach(function (x) {
                    x.classList.toggle('on', parseInt(x.getAttribute('data-v'), 10) <= sel);
                });
            });
        });

        var alreadySent = false;
        try { alreadySent = localStorage.getItem('afw_csat_' + sessionId) === '1'; } catch (e) {}
        if (alreadySent) { lockCsatCard(card); return wrap; }

        card.querySelector('.afw-csat-send').addEventListener('click', function () {
            if (!sel) {
                card.querySelector('.afw-csat-prompt').textContent = 'Selecione uma nota de 1 a 5 estrelas.';
                return;
            }
            var comment = card.querySelector('.afw-csat-comment').value;
            var fd = new FormData();
            fd.append('session_id', sessionId);
            fd.append('rating', sel);
            fd.append('comment', comment);
            fetch(API_BASE + '/api/webchat/csat', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function () { lockCsatCard(card); })
                .catch(function () {});
        });
        return wrap;
    }

    function lockCsatCard(card) {
        try { localStorage.setItem('afw_csat_' + sessionId, '1'); } catch (e) {}
        var s = card.querySelector('.afw-csat-stars'); if (s) s.style.display = 'none';
        var c = card.querySelector('.afw-csat-comment'); if (c) c.style.display = 'none';
        var b = card.querySelector('.afw-csat-send'); if (b) b.style.display = 'none';
        var t = card.querySelector('.afw-csat-thanks'); if (t) t.style.display = 'block';
    }

    function renderHistory(messages) {
        if (!messages || !messages.length) { renderMsg(WELCOME, false, TITLE); return; }
        messages.forEach(function (m) {
            if (m.type === 'system' || m.type === 'internal_note') return;
            var isUser = m.direction === 'inbound';
            if (m.direction === 'outbound' && !m.content && !m.deleted_at) return;
            renderMsg(m.content, isUser, m.user_name || TITLE, m.avatar_url || AVATAR, m.type, m.content, fmtTime(m.created_at), m);
        });
    }
    // Devolve a mensagem ao DOM: atualiza o balão se já existe (edição/
    // reação/remoção do atendente), senão anexa como novidade (quando nova).
    // Retorna 'updated', 'appended' ou null (ignorada).
    function upsertPollMessage(m, opts) {
        opts = opts || {};
        if (!m || m.type === 'system' || m.type === 'internal_note') return null;
        var existing = (m.id != null) ? messagesEl.querySelector('.afw-msg[data-mid="' + m.id + '"]') : null;
        if (existing) {
            updateMsgNode(existing, m);
            return 'updated';
        }
        if (opts.onlyUpdate) return null;
        if (m.direction === 'outbound' && !m.content && !m.deleted_at) return null;
        if (opts.outboundOnly && m.direction !== 'outbound') return null;
        if (opts.since && !(m.created_at > opts.since)) return null;
        var isUser = m.direction === 'inbound';
        if (!m.content && (m.type === 'text' || !m.type) && !m.deleted_at) return null;
        renderMsg(m.content, isUser, m.user_name || TITLE, m.avatar_url || AVATAR, m.type, m.content, fmtTime(m.created_at), m);
        return 'appended';
    }

    function showTyping() {
        if (typingEl) return;
        typingEl = document.createElement('div');
        typingEl.className = 'afw-msg afw-msg--bot';
        typingEl.innerHTML = msgAvatar() + '<div class="afw-msg-content"><div class="afw-bubble afw-typing"><span></span><span></span><span></span></div></div>';
        messagesEl.appendChild(typingEl);
        if (isNearBottom()) scrollToBottom();
    }
    function hideTyping() { if (typingEl) { typingEl.remove(); typingEl = null; } }

    function setStatus(txt) { g('afwStatusTxt').textContent = txt; }

    function bumpUnread() {
        if (isOpen()) return;
        unread++;
        badge.textContent = unread > 9 ? '9+' : unread;
        badge.classList.add('show');
    }
    function clearUnread() { unread = 0; badge.classList.remove('show'); }

    // ── Quick replies ──
    function showQuick(list) {
        if (!list || !list.length) { quickEl.style.display = 'none'; return; }
        quickEl.innerHTML = '';
        list.forEach(function (q) {
            var b = document.createElement('button');
            b.className = 'afw-chip';
            b.textContent = q;
            b.addEventListener('click', function () { send(q); });
            quickEl.appendChild(b);
        });
        quickEl.style.display = 'flex';
    }

    // ── Abertura ──
    launcher.addEventListener('click', function () {
        var open = !isOpen();
        root.classList.toggle('afw-open', open);
        panel.classList.remove('afw-min');
        if (open) {
            clearUnread();
            scrollToBottom();
            getAudio();
            if (started) setTimeout(function(){ input.focus(); }, 250);
        }
    });

    g('afwClose').addEventListener('click', function () {
        root.classList.remove('afw-open');
    });
    g('afwMin').addEventListener('click', function () {
        root.classList.add('afw-min');
        setTimeout(function () { root.classList.remove('afw-open'); }, 220);
    });

    // ── Scroll FAB ──
    messagesEl.addEventListener('scroll', function () {
        scrollFab.classList.toggle('show', !isNearBottom());
    });
    scrollFab.addEventListener('click', scrollToBottom);

    // ── Pré-atendimento ──
    buildPrechat();
    g('afwStart').addEventListener('click', startSession);
    tryResume();

    function buildPrechat() {
        var wrap = g('afwPrechatFields');
        wrap.innerHTML = '';
        var firstId = null;
        ['name', 'email', 'phone', 'cnpj'].forEach(function (k) {
            if (!FIELDS[k].ask) return;
            var def = FIELD_DEFS[k];
            var id = 'afwF_' + k;
            if (!firstId) firstId = id;
            var field = document.createElement('div');
            field.className = 'afw-field';
            field.innerHTML =
                '<label for="' + id + '">' + def.label + (FIELDS[k].required ? ' *' : ' (opcional)') + '</label>' +
                '<input class="afw-input" id="' + id + '" type="' + def.type + '" placeholder="' + def.label + '" autocomplete="off">';
            wrap.appendChild(field);
            var inputEl = field.querySelector('input');
            inputEl.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); startSession(); }
            });
        });
        // link Enter to next field
        var asked = ['name','email','phone','cnpj'].filter(function(k){ return FIELDS[k].ask; });
        asked.forEach(function (k, i) {
            if (asked[i + 1]) {
                g('afwF_' + k).addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') { e.preventDefault(); g('afwF_' + asked[i + 1]).focus(); }
                });
            }
        });
        if (firstId) setTimeout(function () { var el = g(firstId); if (el) el.focus(); }, 350);
    }

    async function tryResume() {
        var savedId = loadSession();
        if (!savedId) return;

        try {
            var resp = await fetch(API_BASE + '/api/webchat/resume', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ widget_key: WIDGET_KEY, session_id: savedId })
            });
            var data = await resp.json();
            if (!data.valid) { clearSession(); return; }

            sessionId = data.session_id;
            started = true;
            g('afwPrechat').style.display = 'none';
            composer.classList.add('show');
            messagesEl.innerHTML = '';
            renderHistory(data.messages);
            lastPoll = data.messages && data.messages.length ? data.messages[data.messages.length - 1].created_at : null;
            showQuick(QUICK_REPLIES);
            scrollToBottom();
            startPolling();
            setTimeout(function () { input.focus(); }, 400);
        } catch (e) {
            clearSession();
        }
    }

    async function startSession() {
        var payload = { widget_key: WIDGET_KEY };
        var missing = null;
        ['name', 'email', 'phone', 'cnpj'].forEach(function (k) {
            if (!FIELDS[k].ask) return;
            var el = g('afwF_' + k);
            var val = el ? el.value.trim() : '';
            if (FIELDS[k].required && !val) {
                if (!missing) missing = el;
                if (el) el.style.borderColor = '#ff4757';
            } else if (el) {
                el.style.borderColor = '';
            }
            if (val) payload[k] = val;
        });
        if (missing) { missing.focus(); return; }

        var btn = g('afwStart');
        btn.disabled = true; btn.textContent = 'Conectando…';

        // skeleton de carregamento
        messagesEl.innerHTML =
            '<div class="afw-msg afw-msg--bot"><div class="afw-skeleton ln w1"></div><div class="afw-skeleton ln w2"></div><div class="afw-skeleton ln w3"></div></div>';

        try {
            var resp = await fetch(API_BASE + '/api/webchat/session', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            var data = await resp.json();
            if (!resp.ok) throw new Error(data.error || 'Falha');
            sessionId = data.session_id;
            started = true;
            saveSession(sessionId);
            g('afwPrechat').style.display = 'none';
            composer.classList.add('show');
            messagesEl.innerHTML = '';
            refreshSendState();

            // busca mensagens iniciais (boas-vindas / fluxo)
            showTyping();
            try {
                var r2 = await fetch(API_BASE + '/api/webchat/messages?' + new URLSearchParams({ session_id: sessionId }));
                var d2 = await r2.json();
                hideTyping();
                if (d2.conversation && (d2.conversation.status === 'closed' || d2.conversation.status === 'resolved')) {
                    started = false;
                    composer.classList.remove('show');
                    if (d2.messages && d2.messages.length) {
                        d2.messages
                            .filter(function (m) { return m.type !== 'system' && m.type !== 'internal_note'; })
                            .forEach(function (m) {
                                if (m.direction === 'outbound' && !m.content) return;
                                renderMsg(m.content, m.direction === 'inbound', m.user_name || TITLE, m.avatar_url || AVATAR, m.type, m.content, fmtTime(m.created_at));
                            });
                    }
                    renderMsg('Atendimento Finalizado', false, TITLE, AVATAR);
                    var endBtn = document.createElement('div');
                    endBtn.className = 'afw-ended-wrap';
                    endBtn.innerHTML = '<button class="afw-start-new-btn" onclick="AFW.restart()">Iniciar novo atendimento</button>';
                    messagesEl.appendChild(endBtn);
                    scrollToBottom();
                    btn.disabled = false; btn.textContent = 'Iniciar conversa';
                    return;
                }
                if (d2.messages && d2.messages.length) {
                    d2.messages
                        .filter(function (m) { return m.type !== 'system' && m.type !== 'internal_note'; })
                        .forEach(function (m) {
                            if (m.direction === 'outbound' && !m.content && !m.deleted_at) return;
                            renderMsg(m.content, m.direction === 'inbound', m.user_name || TITLE, m.avatar_url || AVATAR, m.type, m.content, fmtTime(m.created_at), m);
                        });
                    lastPoll = d2.messages[d2.messages.length - 1].created_at;
                }
                if (!messagesEl.children.length) renderMsg(WELCOME, false, TITLE);
            } catch (e2) {
                hideTyping();
                renderMsg(WELCOME, false, TITLE);
            }
            if (started) {
                showQuick(QUICK_REPLIES);
                scrollToBottom();
                startPolling();
                setTimeout(function(){ input.focus(); }, 400);
            }
        } catch (e) {
            messagesEl.innerHTML = '';
            renderMsg('Não consegui conectar. Tente novamente em instantes.', false, TITLE);
            btn.disabled = false; btn.textContent = 'Iniciar conversa';
        }
    }

    // ── Citação (responder mensagem do atendente) ──
    var quoteTargetId = null;
    function setQuoteTarget(mid, wrap) {
        var bubble = wrap ? wrap.querySelector('.afw-bubble') : null;
        var preview = bubble ? bubble.textContent.trim().substring(0, 80) : '';
        quoteTargetId = mid;
        var bar = g('afwQuoteBar');
        var txt = g('afwQuoteText');
        if (txt) txt.textContent = preview || 'Mensagem';
        if (bar) bar.style.display = 'flex';
        try { input.focus(); } catch (e) {}
    }
    function clearQuoteTarget() {
        quoteTargetId = null;
        var bar = g('afwQuoteBar');
        if (bar) bar.style.display = 'none';
    }

    // ── Envio ──
    function autoGrow() {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 110) + 'px';
    }
    input.addEventListener('input', function () {
        autoGrow();
        refreshSendState();
    });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
    });
    sendBtn.addEventListener('click', send);

    // ── Anexos ──
    var pendingFile = null;
    var attachBtn = g('afwAttach');
    var fileInput = g('afwFile');
    var attachPreview = g('afwAttachPreview');

    function refreshSendState() {
        sendBtn.disabled = !(input.value.trim() || pendingFile);
    }
    function clearAttach() {
        pendingFile = null;
        fileInput.value = '';
        attachPreview.style.display = 'none';
        attachPreview.innerHTML = '';
        refreshSendState();
    }
    function showAttach(file) {
        pendingFile = file;
        var size = file.size ? ' (' + formatBytesJS(file.size) + ')' : '';
        attachPreview.innerHTML =
            '<span class="afw-chip afw-chip--file">' + ICON.file +
            '<span class="afw-chip-name">' + esc(file.name) + esc(size) + '</span>' +
            '<button type="button" class="afw-chip-x" aria-label="Remover">×</button></span>';
        attachPreview.style.display = 'flex';
        attachPreview.querySelector('.afw-chip-x').addEventListener('click', clearAttach);
        refreshSendState();
    }
    attachBtn.addEventListener('click', function () { fileInput.click(); });
    fileInput.addEventListener('change', function () {
        if (fileInput.files && fileInput.files[0]) showAttach(fileInput.files[0]);
    });
    var quoteX = g('afwQuoteX');
    if (quoteX) quoteX.addEventListener('click', clearQuoteTarget);

    // ── Gravação de áudio no widget ──
    var micBtn = g('afwMic');
    var recBox = g('afwRecord');
    if (micBtn && recBox && window.AudioRecorder) {
        var rec = AudioRecorder.create('afwComposer', {
            startBtn: micBtn,
            stopBtn: g('afwRecStop'),
            cancelBtn: g('afwRecCancel'),
            timer: g('afwRecTimer'),
            indicator: g('afwRecIndicator'),
            fileInput: fileInput,
            fileChip: attachPreview,
            fileName: null,
            fileRemove: null,
            onState: function (state) {
                if (state === 'recording') {
                    recBox.style.display = 'flex';
                    micBtn.style.display = 'none';
                    attachBtn.style.display = 'none';
                    input.parentElement.style.display = 'none';
                    sendBtn.style.display = 'none';
                } else {
                    recBox.style.display = 'none';
                    micBtn.style.display = '';
                    attachBtn.style.display = '';
                    input.parentElement.style.display = '';
                    sendBtn.style.display = '';
                }
                if (state === 'ready') {
                    var f = (fileInput.files && fileInput.files[0])
                        || (window.AudioRecorder.getActiveFile && window.AudioRecorder.getActiveFile('afwComposer'));
                    if (f) showAttach(f);
                }
            }
        });
        rec.bind();
    }

    function send(forcedText) {
        // O listener de clique passa o MouseEvent: só usa forcedText se for string.
        var text = (typeof forcedText === 'string' ? forcedText : input.value).trim();
        if ((!text && !pendingFile) || !sessionId) return;

        // Fallback: se o input file ficou vazio (DataTransfer não funcionou),
        // usa o arquivo gravado pelo AudioRecorder.
        if (!pendingFile && window.AudioRecorder && window.AudioRecorder.getActiveFile) {
            var recorded = window.AudioRecorder.getActiveFile('afwComposer');
            if (recorded) {
                pendingFile = recorded;
                try { showAttach(recorded); } catch (e) {}
            }
        }
        var fileToSend = pendingFile;
        var prevPoll = lastPoll;

        var pendingOwn = [];
        var quoteIdToSend = quoteTargetId;
        var quotePreview = '';
        if (quoteIdToSend) {
            var qw = messagesEl.querySelector('.afw-msg[data-mid="' + quoteIdToSend + '"] .afw-bubble');
            quotePreview = qw ? qw.textContent.trim().substring(0, 120) : '';
        }
        if (text) {
            var fakeM = quoteIdToSend ? { reply_to: quoteIdToSend, reply_to_data: { direction: 'outbound', type: 'text', content: quotePreview } } : null;
            var tw = renderMsg(text, true, null, null, 'text', text, null, fakeM);
            tw.classList.add('afw-pending');
            pendingOwn.push(tw);
        }
        if (fileToSend) {
            var kind = fileKind(fileToSend);
            var localUrl = URL.createObjectURL(fileToSend);
            var wrap = document.createElement('div');
            wrap.className = 'afw-msg afw-msg--user afw-pending';
            var fileQuote = quoteIdToSend ? '<div class="afw-quote"><div class="afw-quote-name">Atendente</div><div class="afw-quote-text">' + esc(quotePreview) + '</div></div>' : '';
            wrap.innerHTML = msgAvatar() +
                '<div class="afw-msg-content">' + fileQuote + '<div class="afw-bubble">' +
                renderFileContent(kind, JSON.stringify({ url: localUrl, name: fileToSend.name, size: fileToSend.size }), true) +
                '</div><span class="afw-time">' + nowTime() + '</span></div>';
            messagesEl.appendChild(wrap);
            pendingOwn.push(wrap);
        }
        // Quando o servidor responder, vincula os balões otimistas às
        // mensagens reais (id + citação + ações de editar/apagar/reagir).
        function hydrateOwn(list) {
            var ownNews = (list || []).filter(function (m) {
                return m.direction === 'inbound' && (!prevPoll || m.created_at > prevPoll);
            });
            ownNews.forEach(function (m) {
                var w = pendingOwn.shift();
                if (!w || !m.id) return;
                w.classList.remove('afw-pending');
                w.setAttribute('data-mid', m.id);
                updateMsgNode(w, m);
            });
            pendingOwn.length = 0;
        }

        quickEl.style.display = 'none';
        input.value = ''; autoGrow(); sendBtn.disabled = true;
        clearAttach();
        clearQuoteTarget();
        scrollToBottom();
        try { playSendSound(); } catch (e) {}

        var fd = new FormData();
        fd.append('session_id', sessionId);
        if (text) fd.append('message', text);
        if (fileToSend) fd.append('file', fileToSend, fileToSend.name);
        if (quoteIdToSend) fd.append('reply_to', quoteIdToSend);

        fetch(API_BASE + '/api/webchat/messages', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.conversation && (data.conversation.status === 'closed' || data.conversation.status === 'resolved')) {
                    clearSession();
                    sessionId = null;
                    started = false;
                    isPolling = false;
                    composer.classList.remove('show');
                    if (data.messages && data.messages.length) {
                        data.messages
                            .filter(function (m) { return m.type !== 'system' && m.type !== 'internal_note'; })
                            .forEach(function (m) {
                                if (m.direction === 'outbound' && !m.content) return;
                                renderMsg(m.content, m.direction === 'inbound', m.user_name || TITLE, m.avatar_url || AVATAR, m.type, m.content, fmtTime(m.created_at));
                            });
                    }
                    renderMsg('Atendimento Finalizado', false, TITLE, AVATAR);
                    var endBtn = document.createElement('div');
                    endBtn.className = 'afw-ended-wrap';
                    endBtn.innerHTML = '<button class="afw-start-new-btn" onclick="AFW.restart()">Iniciar novo atendimento</button>';
                    messagesEl.appendChild(endBtn);
                    scrollToBottom();
                    return;
                }
                touchSession();
                hydrateOwn(data.messages);
                if (data.messages && data.messages.length) {
                    var added = 0;
                    data.messages.forEach(function (m) {
                        if (m.direction !== 'outbound') return;
                        var r = upsertPollMessage(m, { since: lastPoll, outboundOnly: true });
                        if (r === 'appended') added++;
                    });
                    if (added) {
                        hideTyping();
                        var near = isNearBottom();
                        if (near) scrollToBottom(); else bumpUnread();
                        showQuick(QUICK_REPLIES);
                        playReceiveSound();
                    }
                    lastPoll = data.messages[data.messages.length - 1].created_at;
                }
            })
            .catch(function () {})
            .then(function () { refreshSendState(); });
    }

    // ── Polling de respostas ──
    function startPolling() {
        if (isPolling) return;
        isPolling = true;
        (function poll() {
            if (!sessionId) { isPolling = false; return; }
            fetch(API_BASE + '/api/webchat/messages?' + new URLSearchParams({ session_id: sessionId, since: lastPoll || '' }))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.conversation && (data.conversation.status === 'closed' || data.conversation.status === 'resolved')) {
                        // Encerra o polling, mas MANTÉM o sessionId em memória:
                        // o cartão de avaliação (CSAT) precisa dele para enviar.
                        clearSession();
                        started = false;
                        isPolling = false;
                        composer.classList.remove('show');
                        if (data.messages && data.messages.length) {
                            data.messages
                                .filter(function (m) { return m.type !== 'system' && m.type !== 'internal_note'; })
                                .forEach(function (m) {
                                    if (m.direction === 'outbound' && !m.content) return;
                                    renderMsg(m.content, m.direction === 'inbound', m.user_name || TITLE, m.avatar_url || AVATAR, m.type, m.content, fmtTime(m.created_at));
                                });
                        }
                        renderMsg('Atendimento Finalizado', false, TITLE, AVATAR);
                        var endBtn = document.createElement('div');
                        endBtn.className = 'afw-ended-wrap';
                        endBtn.innerHTML = '<button class="afw-start-new-btn" onclick="AFW.restart()">Iniciar novo atendimento</button>';
                        messagesEl.appendChild(endBtn);
                        scrollToBottom();
                        return;
                    }
                    if (data.messages && data.messages.length) {
                        var added2 = 0;
                        data.messages.forEach(function (m) {
                            if (m.direction !== 'outbound') return;
                            var r2 = upsertPollMessage(m, { since: lastPoll, outboundOnly: true });
                            if (r2 === 'appended') added2++;
                        });
                        if (added2) {
                            hideTyping();
                            var near2 = isNearBottom();
                            if (near2) scrollToBottom(); else bumpUnread();
                            showQuick(QUICK_REPLIES);
                            playReceiveSound();
                            lastPoll = data.messages[data.messages.length - 1].created_at;
                            touchSession();
                        }
                    }
                    if (isPolling) setTimeout(poll, 2500);
                })
                .catch(function () { if (isPolling) setTimeout(poll, 5000); });
        })();
    }

    // Expor API mínima p/ demo
    window.AFW = {
        open: function () { root.classList.add('afw-open'); panel.classList.remove('afw-min'); clearUnread(); scrollToBottom(); },
        close: function () { root.classList.remove('afw-open'); },
        setStyle: function (vars) { for (var k in vars) root.style.setProperty('--afw-' + k, vars[k]); },
        restart: function () {
            clearSession();
            sessionId = null;
            started = false;
            isPolling = false;
            g('afwPrechat').style.display = 'flex';
            composer.classList.remove('show');
            messagesEl.innerHTML = '';
            var startBtn = g('afwStart');
            startBtn.disabled = false;
            startBtn.textContent = 'Iniciar conversa';
            var firstInput = g('afwF_name') || startBtn;
            if (firstInput) firstInput.focus();
        }
    };
})();
