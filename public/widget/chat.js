(function () {
    'use strict';

    var C = window.ATENDIMENTO_CONFIG || {};
    var WIDGET_KEY = C.widgetId || '';
    var API_BASE = C.apiUrl || (window.location.origin + (window.AF_BASE || ''));
    var PRIMARY = C.color || '#2f6fed';
    var POSITION = C.position || 'right';
    var TITLE = C.title || 'Atendimento';
    var WELCOME = C.welcomeMessage || 'Olá! Como podemos ajudar?';
    var AVATAR = C.avatarUrl || '';
    var QUICK_REPLIES = Array.isArray(C.quickReplies) ? C.quickReplies : ['Olá 👋', 'Falar com atendente', 'Ver planos'];

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

    if (!WIDGET_KEY) { console.warn('[AtendeFlow] widgetId não configurado.'); return; }

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
        '.afw-csat-send{margin-top:8px;width:100%;background:var(--afw-primary,#2f6fed);color:#fff;border:0;border-radius:8px;padding:8px;cursor:pointer}' +
        '.afw-csat-thanks{color:#16a34a;font-weight:600;margin-top:6px}' +
        '.afw-csat-link{display:block;margin-top:8px;text-align:center;color:var(--afw-primary,#2f6fed);font-weight:600;text-decoration:none}' +
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
        soundOff: '<svg viewBox="0 0 24 24"><path d="M4 9v6h4l5 5V4L8 9H4z"/><path d="M16 9l5 6m0-6l-5 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>'
    };

    var agentAvatar = AVATAR
        ? '<img src="' + AVATAR + '" alt="">'
        : '<div class="afw-avatar-fallback">' + esc(TITLE.charAt(0)) + '</div>';

    var msgAvatar = function (src) {
        if (src) return '<img class="afw-msg-avatar" src="' + src + '" alt="">';
        if (AVATAR) return '<img class="afw-msg-avatar" src="' + AVATAR + '" alt="">';
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
                    '<input type="file" id="afwFile" accept="image/*,audio/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip" hidden>' +
                    '<div class="afw-attach-preview" id="afwAttachPreview" style="display:none"></div>' +
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
        var name = (meta && meta.name) ? meta.name : 'arquivo';
        var size = (meta && meta.size) ? formatBytesJS(meta.size) : '';
        return '<a class="afw-file-link" href="' + esc(url) + '" target="_blank" rel="noopener" download>' +
            '<span class="afw-file-ico">' + ICON.file + '</span>' +
            '<span class="afw-file-meta">' +
                '<span class="afw-file-name">' + esc(name) + '</span>' +
                (size ? '<span class="afw-file-size">' + esc(size) + '</span>' : '') +
            '</span></a>';
    }
    function renderMsg(text, isUser, author, avatar, type, fileContent, time) {
        if (type === 'csat_request' && !isUser) {
            return renderCsatCard(text, avatar, time);
        }
        if ((type === 'button_list' || type === 'list_menu') && !isUser) {
            return renderInteractiveMsg(type, text, avatar, time);
        }
        var wrap = document.createElement('div');
        wrap.className = 'afw-msg ' + (isUser ? 'afw-msg--user' : 'afw-msg--bot');
        var body = (type && type !== 'text') ? renderFileContent(type, fileContent || text, isUser) : esc(text);
        wrap.innerHTML =
            msgAvatar(avatar) +
            '<div class="afw-msg-content">' +
                (isUser ? '' : '<span class="afw-msg-author">' + esc(author || TITLE) + '</span>') +
                '<div class="afw-bubble">' + body + '</div>' +
                '<span class="afw-time">' + (time || nowTime()) + '</span>' +
            '</div>';
        messagesEl.appendChild(wrap);
        return wrap;
    }

    function renderInteractiveMsg(type, content, avatar, time) {
        var data = {};
        try { data = JSON.parse(content); } catch (e) { data = { text: content }; }
        var wrap = document.createElement('div');
        wrap.className = 'afw-msg afw-msg--bot';
        var html = msgAvatar(avatar) +
            '<div class="afw-msg-content">' +
            '<div class="afw-bubble">' + esc(data.text || '') + '</div>';

        if (type === 'button_list') {
            html += '<div class="afw-buttons">';
            (data.buttons || []).forEach(function (b) {
                html += '<button type="button" class="afw-interact-btn" data-value="' + esc(b.id || b.label) + '">' + esc(b.label) + '</button>';
            });
            html += '</div>';
        } else if (type === 'list_menu') {
            html += '<div class="afw-list-menu">';
            html += '<div class="afw-list-title">' + esc(data.title || 'Opções') + '</div>';
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
                    '<div class="afw-csat-prompt">' + esc(data.prompt || '') + '</div>' +
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
            if (m.direction === 'outbound' && !m.content) return;
            renderMsg(m.content, isUser, m.user_name || TITLE, m.avatar_url || AVATAR, m.type, m.content, fmtTime(m.created_at));
        });
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
                    if (d2.messages && d2.messages.length) {
                        d2.messages
                            .filter(function (m) { return m.type !== 'system' && m.type !== 'internal_note'; })
                            .forEach(function (m) {
                                if (m.direction === 'outbound' && !m.content) return;
                                renderMsg(m.content, m.direction === 'inbound', m.user_name || TITLE, m.avatar_url || AVATAR, m.type, m.content, fmtTime(m.created_at));
                            });
                        lastPoll = d2.messages[d2.messages.length - 1].created_at;
                    }
                if (!messagesEl.children.length) renderMsg(WELCOME, false, TITLE);
            } catch (e2) {
                hideTyping();
                renderMsg(WELCOME, false, TITLE);
            }
            showQuick(QUICK_REPLIES);
            scrollToBottom();
            startPolling();
            setTimeout(function(){ input.focus(); }, 400);
        } catch (e) {
            messagesEl.innerHTML = '';
            renderMsg('Não consegui conectar. Tente novamente em instantes.', false, TITLE);
            btn.disabled = false; btn.textContent = 'Iniciar conversa';
        }
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

    function send(forcedText) {
        var text = (forcedText != null ? forcedText : input.value).trim();
        if ((!text && !pendingFile) || !sessionId) return;

        var fileToSend = pendingFile;

        if (text) renderMsg(text, true);
        if (fileToSend) {
            var kind = fileKind(fileToSend);
            var localUrl = URL.createObjectURL(fileToSend);
            var wrap = document.createElement('div');
            wrap.className = 'afw-msg afw-msg--user';
            wrap.innerHTML = msgAvatar() +
                '<div class="afw-msg-content"><div class="afw-bubble">' +
                renderFileContent(kind, JSON.stringify({ url: localUrl, name: fileToSend.name, size: fileToSend.size }), true) +
                '</div><span class="afw-time">' + nowTime() + '</span></div>';
            messagesEl.appendChild(wrap);
        }

        quickEl.style.display = 'none';
        input.value = ''; autoGrow(); sendBtn.disabled = true;
        clearAttach();
        scrollToBottom();
        try { playSendSound(); } catch (e) {}

        var fd = new FormData();
        fd.append('session_id', sessionId);
        if (text) fd.append('message', text);
        if (fileToSend) fd.append('file', fileToSend, fileToSend.name);

        fetch(API_BASE + '/api/webchat/messages', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                touchSession();
                if (data.messages && data.messages.length) {
                    var news = data.messages.filter(function (m) {
                        return m.direction === 'outbound' && m.type !== 'system' && m.type !== 'internal_note' && (!lastPoll || m.created_at > lastPoll);
                    });
                    if (news.length) {
                        hideTyping();
                        var near = isNearBottom();
                        news.forEach(function (m) { if (m.content) renderMsg(m.content, false, m.user_name || TITLE, m.avatar_url || AVATAR, m.type, m.content); });
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
                    if (data.messages && data.messages.length) {
                        var news = data.messages.filter(function (m) {
                            return m.direction === 'outbound' && m.type !== 'system' && m.type !== 'internal_note' && (!lastPoll || m.created_at > lastPoll);
                        });
                        if (news.length) {
                            hideTyping();
                            var near = isNearBottom();
                            news.forEach(function (m) { if (m.content) renderMsg(m.content, false, m.user_name || TITLE, m.avatar_url || AVATAR, m.type, m.content); });
                            if (near) scrollToBottom(); else bumpUnread();
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
        setStyle: function (vars) { for (var k in vars) root.style.setProperty('--afw-' + k, vars[k]); }
    };
})();
