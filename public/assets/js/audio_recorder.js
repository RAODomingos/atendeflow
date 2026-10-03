(function (global) {
    'use strict';

    /**
     * Módulo de captura de áudio via MediaRecorder API.
     *
     * Uso:
     *   const rec = AudioRecorder.create({
     *       startBtn: document.getElementById('recStart'),
     *       stopBtn:  document.getElementById('recStop'),
     *       cancelBtn: document.getElementById('recCancel'),
     *       timer:    document.getElementById('recTimer'),
     *       indicator: document.getElementById('recIndicator'),
     *       fileInput: document.getElementById('attachInput'),
     *       fileChip:  document.getElementById('composerFile'),
     *       fileName:  document.getElementById('composerFileName'),
     *       fileRemove: document.getElementById('composerFileX'),
     *       onState: (state) => { ... }   // 'idle' | 'recording' | 'ready' | 'denied' | 'error'
     *   });
     *   rec.bind();
     */
    function create(opts) {
        const cfg = Object.assign({
            startBtn: null,
            stopBtn: null,
            cancelBtn: null,
            timer: null,
            indicator: null,
            fileInput: null,
            fileChip: null,
            fileName: null,
            fileRemove: null,
            maxMs: 10 * 60 * 1000,
            mimePreference: ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/ogg', 'audio/mp4'],
            onState: function () {}
        }, opts || {});

        let mediaRecorder = null;
        let mediaStream = null;
        let chunks = [];
        let startTime = 0;
        let timerInterval = null;
        let lastBlob = null;
        let lastFile = null;
        let lastObjectUrl = null;
        let state = 'idle';

        function setState(next) {
            state = next;
            try { cfg.onState(next); } catch (e) { /* noop */ }
        }

        function pad(n) { return n < 10 ? '0' + n : '' + n; }
        function fmt(ms) {
            const s = Math.floor(ms / 1000);
            return pad(Math.floor(s / 60)) + ':' + pad(s % 60);
        }

        function updateTimer() {
            if (!cfg.timer) return;
            const elapsed = Date.now() - startTime;
            cfg.timer.textContent = fmt(elapsed);
        }

        function pickMime() {
            if (!global.MediaRecorder) return '';
            for (let i = 0; i < cfg.mimePreference.length; i++) {
                const m = cfg.mimePreference[i];
                if (MediaRecorder.isTypeSupported(m)) return m;
            }
            return '';
        }

        function setFileInput(file) {
            if (!cfg.fileInput) return;
            try {
                if (typeof DataTransfer !== 'undefined' && window.DataTransfer.prototype.items) {
                    const dt = new DataTransfer();
                    dt.items.add(file);
                    cfg.fileInput.files = dt.files;
                } else if (cfg.fileInput.webkitFiles && cfg.fileInput.webkitFiles.add) {
                    // Fallback antigo do WebKit
                    while (cfg.fileInput.webkitFiles.length > 0) {
                        cfg.fileInput.webkitFiles.remove(0);
                    }
                    cfg.fileInput.webkitFiles.add(file);
                } else {
                    // Último recurso: nada a fazer — caller pode usar FormData manualmente
                    try { console.warn('[AudioRecorder] não foi possível atribuir o arquivo ao input'); } catch (e) {}
                }
                try { console.log('[AudioRecorder] arquivo no input:', file.name, file.size, file.type); } catch (e) {}
            } catch (e) {
                try { console.error('[AudioRecorder] setFileInput falhou', e); } catch (er) {}
            }
        }

        function showFileChip(file) {
            if (!cfg.fileChip) return;
            if (cfg.fileName) cfg.fileName.textContent = file.name;
            cfg.fileChip.style.display = 'flex';
        }

        function clearFileChip() {
            if (cfg.fileChip) cfg.fileChip.style.display = 'none';
            if (cfg.fileName) cfg.fileName.textContent = '';
        }

        function clearFileInput() {
            if (cfg.fileInput) cfg.fileInput.value = '';
        }

        function filenameFor(blob) {
            const d = new Date();
            const stamp = d.getFullYear()
                + pad(d.getMonth() + 1)
                + pad(d.getDate()) + '-'
                + pad(d.getHours())
                + pad(d.getMinutes())
                + pad(d.getSeconds());
            let ext = 'webm';
            const t = (blob && blob.type) || '';
            if (t.indexOf('ogg') >= 0) ext = 'ogg';
            else if (t.indexOf('mp4') >= 0) ext = 'm4a';
            else if (t.indexOf('mpeg') >= 0) ext = 'mp3';
            return 'audio-' + stamp + '.' + ext;
        }

        function resetVisuals() {
            if (cfg.timer) cfg.timer.textContent = '00:00';
            if (cfg.indicator) cfg.indicator.classList.remove('recording');
        }

        function cleanupStream() {
            if (mediaStream) {
                try { mediaStream.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {}
                mediaStream = null;
            }
            if (timerInterval) {
                clearInterval(timerInterval);
                timerInterval = null;
            }
        }

        // Consulta o estado da permissão quando a API existe.
        // Retorna 'granted' | 'denied' | 'prompt' | 'unknown'.
        function queryMicPermission() {
            try {
                if (navigator.permissions && navigator.permissions.query) {
                    return navigator.permissions.query({ name: 'microphone' })
                        .then(function (st) { return st.state || 'unknown'; })
                        .catch(function () { return 'unknown'; });
                }
            } catch (e) {}
            return Promise.resolve('unknown');
        }

        function micErrorMessage(err, permState) {
            var name = (err && err.name) || '';
            if (name === 'NotFoundError' || name === 'OverconstrainedError' || name === 'DevicesNotFoundError') {
                return 'Nenhum microfone encontrado neste dispositivo. Conecte um microfone e tente de novo.';
            }
            if (name === 'NotReadableError' || name === 'AbortError' || name === 'TrackStartError') {
                return 'O microfone está em uso por outro aplicativo ou aba. Feche o outro uso e tente de novo.';
            }
            if (name === 'SecurityError' || window.isSecureContext === false) {
                return 'Gravação de áudio exige HTTPS ou localhost. Acesse o sistema por uma URL segura.';
            }
            if (name === 'NotAllowedError' || name === 'PermissionDeniedError' || name === 'PermissionDismissedError') {
                if (permState === 'denied') {
                    return 'Microfone bloqueado para este site. Clique no cadeado da barra de endereço → Permissões → Microfone → Permitir, e tente de novo.';
                }
                // Permissão consta como liberada (ou o navegador não informa),
                // mas o acesso foi recusado: bloqueio no SO/antivírus, outro
                // app com uso exclusivo, ou política do navegador.
                return 'O navegador recusou o microfone mesmo com permissão liberada. Verifique: 1) Privacidade do microfone no Windows; 2) antivírus; 3) outro app usando o microfone (Zoom/Teams); 4) política da empresa no navegador.';
            }
            return 'Não foi possível acessar o microfone: ' + (err && err.message ? err.message : 'erro desconhecido');
        }

        function start() {
            if (state === 'recording') return;
            try { console.log('[AudioRecorder] start() called'); } catch (er) {}
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                try { console.warn('[AudioRecorder] getUserMedia indisponível'); } catch (er) {}
                setState('error');
                var unsupportedMsg = (window.isSecureContext === false)
                    ? 'Gravação de áudio exige HTTPS ou localhost. Acesse o sistema por uma URL segura.'
                    : 'Seu navegador não suporta gravação de áudio.';
                if (typeof showError === 'function') {
                    showError(unsupportedMsg);
                } else {
                    alert(unsupportedMsg);
                }
                return;
            }
            if (window.isSecureContext === false) {
                try { console.warn('[AudioRecorder] contexto não seguro (HTTP sem localhost)'); } catch (er) {}
            }
            // Garante que nenhum stream anterior ficou pendurado ocupando o mic.
            cleanupStream();
            try { console.log('[AudioRecorder] pedindo getUserMedia'); } catch (er) {}
            queryMicPermission().then(function (permState) {
                return navigator.mediaDevices.getUserMedia({ audio: true })
                    .then(function (stream) {
                    try { console.log('[AudioRecorder] getUserMedia OK'); } catch (er) {}
                    mediaStream = stream;
                    chunks = [];
                    const mime = pickMime();
                    try {
                        mediaRecorder = mime
                            ? new MediaRecorder(stream, { mimeType: mime })
                            : new MediaRecorder(stream);
                    } catch (e) {
                        mediaRecorder = new MediaRecorder(stream);
                    }
                    mediaRecorder.ondataavailable = function (ev) {
                        if (ev.data && ev.data.size > 0) chunks.push(ev.data);
                    };
                    mediaRecorder.onstop = function () {
                        const type = (mediaRecorder && mediaRecorder.mimeType) || (chunks[0] && chunks[0].type) || 'audio/webm';
                        const blob = new Blob(chunks, { type: type });
                        const name = filenameFor(blob);
                        const file = new File([blob], name, { type: type });
                        lastBlob = blob;
                        lastFile = file;
                        if (lastObjectUrl) { try { URL.revokeObjectURL(lastObjectUrl); } catch (e) {} }
                        lastObjectUrl = URL.createObjectURL(blob);
                        setFileInput(file);
                        showFileChip(file);
                        cleanupStream();
                        setState('ready');
                    };
                    mediaRecorder.onerror = function (ev) {
                        setState('error');
                        cleanupStream();
                        try { console.error('MediaRecorder error', ev); } catch (e) {}
                    };
                    mediaRecorder.start();
                    startTime = Date.now();
                    updateTimer();
                    timerInterval = setInterval(updateTimer, 250);
                    if (cfg.indicator) cfg.indicator.classList.add('recording');
                    setState('recording');
                    if (cfg.maxMs > 0) {
                        setTimeout(function () { if (state === 'recording') stop(); }, cfg.maxMs);
                    }
                })
                .catch(function (err) {
                    var errName = (err && err.name) || '';
                    var deniedLike = (errName === 'NotAllowedError' || errName === 'PermissionDeniedError'
                        || errName === 'PermissionDismissedError' || errName === 'SecurityError');
                    setState(deniedLike ? 'denied' : 'error');
                    try { console.warn('getUserMedia error', err); } catch (e) {}
                    var msg = micErrorMessage(err, permState);
                    if (typeof showError === 'function') {
                        showError(msg);
                    } else {
                        alert(msg);
                    }
                });
            }).catch(function () {
                // queryMicPermission falhou de forma inesperada: nada a fazer,
                // o getUserMedia acima já tratou o próprio erro.
            });
        }

        function stop() {
            if (state !== 'recording' || !mediaRecorder) return;
            try { mediaRecorder.stop(); } catch (e) {}
            // O onstop cuida do resto.
        }

        function cancel() {
            if (state === 'recording' && mediaRecorder) {
                try { mediaRecorder.ondataavailable = null; } catch (e) {}
                try { mediaRecorder.onstop = null; } catch (e) {}
                try { mediaRecorder.stop(); } catch (e) {}
                chunks = [];
            }
            cleanupStream();
            lastBlob = null;
            lastFile = null;
            if (lastObjectUrl) { try { URL.revokeObjectURL(lastObjectUrl); } catch (e) {} lastObjectUrl = null; }
            clearFileInput();
            clearFileChip();
            resetVisuals();
            setState('idle');
        }

        function bind() {
            if (cfg.startBtn) cfg.startBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                try { console.log('[AudioRecorder] start click'); } catch (er) {}
                start();
            });
            if (cfg.stopBtn) cfg.stopBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                try { console.log('[AudioRecorder] stop click'); } catch (er) {}
                stop();
            });
            if (cfg.cancelBtn) cfg.cancelBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                try { console.log('[AudioRecorder] cancel click'); } catch (er) {}
                cancel();
            });
            if (cfg.fileRemove) cfg.fileRemove.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                cancel();
            });
        }

        function destroy() {
            cancel();
            try { mediaRecorder = null; } catch (e) {}
        }

        return {
            start: start,
            stop: stop,
            cancel: cancel,
            bind: bind,
            destroy: destroy,
            getState: function () { return state; },
            getFile: function () { return lastFile; },
            hasFile: function () { return !!lastFile; }
        };
    }

    /**
     * Registry de instâncias ativas, indexado por id do form.
     * Os forms chamam AudioRecorder.getActive('composerForm') no submit
     * para recuperar o arquivo de áudio gravado (caso o DataTransfer
     * não tenha conseguido popular o input file).
     */
    const registry = {};
    const origCreate = create;
    function createWithRegistry(formId, opts) {
        const inst = origCreate(opts);
        if (formId) {
            registry[formId] = inst;
            const origCancel = inst.cancel;
            inst.cancel = function () {
                origCancel();
                delete registry[formId];
            };
        }
        return inst;
    }
    global.AudioRecorder = {
        create: createWithRegistry,
        getActive: function (formId) { return registry[formId] || null; },
        getActiveFile: function (formId) {
            const r = registry[formId];
            return r ? r.getFile() : null;
        }
    };
})(window);
