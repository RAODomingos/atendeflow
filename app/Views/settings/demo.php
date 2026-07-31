<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Demo - <?= e($widget['title']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f0f2f5;
            min-height: 100vh;
            display: flex;
            padding: 40px;
        }
        .demo-wrapper {
            display: grid;
            grid-template-columns: 1fr 340px;
            gap: 32px;
            max-width: 1200px;
            width: 100%;
            margin: 0 auto;
        }
        .demo-preview {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .demo-info {
            background: #fff;
            border-radius: 12px;
            padding: 24px 28px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        .demo-info h1 {
            font-size: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .demo-info .subtitle {
            color: #6c757d;
            font-size: 14px;
            margin-top: 4px;
        }
        .demo-preview-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            overflow: hidden;
            position: relative;
            min-height: 520px;
            background-image:
                radial-gradient(circle at 20% 50%, #e8ecf1 0%, transparent 50%),
                radial-gradient(circle at 80% 50%, #e8ecf1 0%, transparent 50%),
                linear-gradient(180deg, #f8f9fa 0%, #eef0f4 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px dashed #d0d5dd;
        }
        .demo-preview-card .hint {
            text-align: center;
            color: #6c757d;
        }
        .demo-preview-card .hint i {
            font-size: 40px;
            color: #adb5bd;
            margin-bottom: 12px;
        }
        .demo-preview-card .hint p {
            font-size: 14px;
        }
        .demo-preview-card .hint small {
            font-size: 12px;
            color: #999;
        }

        /* ----- Panel de Estilo ----- */
        .style-panel {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            overflow: hidden;
            position: sticky;
            top: 40px;
        }
        .style-panel-header {
            padding: 16px 20px;
            border-bottom: 1px solid #e8ecf1;
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            background: #fafbfc;
        }
        .style-panel-body {
            padding: 20px;
        }
        .style-group {
            margin-bottom: 20px;
        }
        .style-group:last-child { margin-bottom: 0; }
        .style-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            color: #6c757d;
            margin-bottom: 6px;
            letter-spacing: 0.3px;
        }
        .style-group .input-row {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .style-group .input-row input[type="color"] {
            width: 44px;
            height: 38px;
            padding: 2px;
            border: 1px solid #d0d5dd;
            border-radius: 6px;
            cursor: pointer;
            flex-shrink: 0;
        }
        .style-group .input-row input[type="text"],
        .style-group .input-row select {
            flex: 1;
        }
        .style-group input[type="text"],
        .style-group select,
        .style-group textarea {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid #d0d5dd;
            border-radius: 6px;
            font-size: 13px;
            font-family: inherit;
            outline: none;
            transition: border-color 0.2s;
        }
        .style-group input:focus,
        .style-group select:focus {
            border-color: #4A90D9;
            box-shadow: 0 0 0 3px rgba(74,144,217,0.12);
        }
        .style-group .radio-group {
            display: flex;
            gap: 8px;
        }
        .style-group .radio-group label {
            text-transform: none;
            font-weight: 400;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 4px;
            cursor: pointer;
            padding: 6px 14px;
            border: 1px solid #d0d5dd;
            border-radius: 6px;
            transition: all 0.15s;
        }
        .style-group .radio-group label:hover {
            border-color: #4A90D9;
        }
        .style-group .radio-group input:checked + span {
            color: #4A90D9;
            font-weight: 600;
        }
        .style-group .radio-group label:has(input:checked) {
            border-color: #4A90D9;
            background: rgba(74,144,217,0.06);
        }
        .style-group .radio-group input { display: none; }

        .install-code-section {
            border-top: 1px solid #e8ecf1;
            padding: 16px 20px;
            background: #fafbfc;
        }
        .install-code-section summary {
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            color: #495057;
        }
        .install-code-section pre {
            background: #1a1d23;
            color: #e8e8e8;
            padding: 16px;
            border-radius: 8px;
            font-size: 12px;
            overflow-x: auto;
            margin-top: 10px;
            position: relative;
        }
        .install-code-section pre .copy-btn {
            position: absolute;
            top: 8px;
            right: 8px;
            background: rgba(255,255,255,0.1);
            color: #fff;
            border: none;
            padding: 4px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 11px;
        }
        .install-code-section pre .copy-btn:hover { background: rgba(255,255,255,0.2); }

        .reset-btn {
            padding: 6px 14px;
            border: 1px solid #d0d5dd;
            border-radius: 6px;
            background: #fff;
            cursor: pointer;
            font-size: 12px;
            color: #6c757d;
            transition: all 0.15s;
        }
        .reset-btn:hover {
            border-color: #adb5bd;
            color: #333;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #4A90D9;
            text-decoration: none;
            font-size: 13px;
            margin-bottom: 12px;
        }
        .back-link:hover { text-decoration: underline; }

        .tag {
            display: inline-block;
            background: #e8ecf1;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
            color: #495057;
        }

        @media (max-width: 900px) {
            .demo-wrapper { grid-template-columns: 1fr; padding: 0; }
            body { padding: 20px; }
            .style-panel { position: static; }
        }
    </style>
</head>
<body>
    <div class="demo-wrapper">
        <div class="demo-preview">
            <div class="demo-info">
                <a href="<?= url('channels') ?>" class="back-link">&larr; Voltar para Canais</a>
                <h1><i class="fas fa-comment-dots" style="color:#4A90D9"></i> <?= e($widget['title']) ?></h1>
                <p class="subtitle">Pré-visualização ao vivo &mdash; personalize o estilo do chat e veja as alterações em tempo real.</p>
                <div style="margin-top:12px;display:flex;gap:12px;font-size:13px;color:#6c757d">
                    <span><i class="fas fa-fingerprint"></i> <span class="tag"><?= e(truncate($widget['widget_key'], 16)) ?>…</span></span>
                    <?php if ($widget['flow_name']): ?>
                        <span><i class="fas fa-diagram-project"></i> <?= e($widget['flow_name']) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="demo-preview-card" id="previewCard">
                <div class="hint">
                    <i class="fas fa-arrow-down"></i>
                    <p>Clique no botão de chat no canto inferior <strong id="hintPosition">direito</strong></p>
                    <small>O widget real será carregado com as configurações abaixo</small>
                </div>
            </div>
        </div>

        <div>
            <div class="style-panel">
                <div class="style-panel-header">
                    <i class="fas fa-palette"></i> Personalizar Estilo
                    <button class="reset-btn" onclick="resetStyles()" style="margin-left:auto">Restaurar</button>
                </div>
                <div class="style-panel-body">
                    <div class="style-group">
                        <label>Cor primária</label>
                        <div class="input-row">
                            <input type="color" id="sColor" value="#2f6fed" oninput="applyStyles()">
                            <input type="text" id="sColorHex" value="#2f6fed" maxlength="7" oninput="syncColor(this.value)">
                        </div>
                    </div>

                    <div class="style-group">
                        <label>Cor das mensagens do usuário</label>
                        <div class="input-row">
                            <input type="color" id="sUserColor" value="#2f6fed" oninput="applyStyles()">
                            <input type="text" id="sUserColorHex" value="#2f6fed" maxlength="7" oninput="syncUserColor(this.value)">
                        </div>
                    </div>

                    <div class="style-group">
                        <label>Fundo do chat</label>
                        <div class="input-row">
                            <input type="color" id="sBg" value="#f4f6fb" oninput="applyStyles()">
                            <input type="text" id="sBgHex" value="#f4f6fb" maxlength="7" oninput="syncBg(this.value)">
                        </div>
                    </div>

                    <div class="style-group">
                        <label>Cor da borda</label>
                        <div class="input-row">
                            <input type="color" id="sBorder" value="#e7eaf1" oninput="applyStyles()">
                            <input type="text" id="sBorderHex" value="#e7eaf1" maxlength="7" oninput="syncBorder(this.value)">
                        </div>
                    </div>

                    <div class="style-group">
                        <label>Balão do atendente</label>
                        <div class="input-row">
                            <input type="color" id="sBotBubble" value="#f0f2f7" oninput="applyStyles()">
                            <input type="text" id="sBotBubbleHex" value="#f0f2f7" maxlength="7" oninput="syncBotBubble(this.value)">
                        </div>
                    </div>

                    <div class="style-group">
                        <label>Posição do botão</label>
                        <div class="radio-group">
                            <label><input type="radio" name="position" value="right" checked onchange="applyStyles()"><span>Direita</span></label>
                            <label><input type="radio" name="position" value="left" onchange="applyStyles()"><span>Esquerda</span></label>
                        </div>
                    </div>

                    <div class="style-group">
                        <label>Título do cabeçalho</label>
                        <input type="text" id="sTitle" value="<?= e($widget['title']) ?>" oninput="applyStyles()">
                    </div>

                    <div class="style-group">
                        <label>Mensagem de boas-vindas</label>
                        <textarea id="sWelcome" rows="2" oninput="applyStyles()">Olá! Como podemos ajudar?</textarea>
                    </div>

                    <div class="style-group">
                        <label>Tamanho do botão</label>
                        <div class="input-row">
                            <input type="range" id="sSize" min="42" max="64" value="52" oninput="applyStyles()">
                            <span id="sSizeVal" style="font-size:12px;color:#6c757d;min-width:32px">52px</span>
                        </div>
                    </div>

                </div>

                <div class="install-code-section">
                    <details>
                        <summary><i class="fas fa-code"></i> Código de instalação</summary>
                        <pre id="installCode">&lt;script&gt;
window.ATENDIMENTO_CONFIG = {
    widgetId: "<?= e($widget['widget_key']) ?>",
    title: "<?= e($widget['title']) ?>",
    color: "<?= e($widget['color_primary']) ?>",
    position: "<?= e($widget['position']) ?>",
    apiUrl: "<?= rtrim(base_url(), '/') ?>"<?php if ($widget['avatar_url']): ?>,
    avatarUrl: "<?= e($widget['avatar_url']) ?>"<?php endif; ?>
};
&lt;/script&gt;
&lt;script async src="<?= base_url('widget/chat.js') ?>"&gt;&lt;/script&gt;<button class="copy-btn" onclick="copyInstallCode()">Copiar</button></pre>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Config que o widget real vai usar — com apiUrl apontando para o /atendeflow
    window.ATENDIMENTO_CONFIG = {
        widgetId: "<?= e($widget['widget_key']) ?>",
        title: "<?= e($widget['title']) ?>",
        color: "<?= e($widget['color_primary']) ?>",
        position: "<?= e($widget['position']) ?>",
        apiUrl: "<?= rtrim(base_url(), '/') ?>",
        backgroundColor: "#f4f6fb",
        borderColor: "#e7eaf1",
        agentBubbleColor: "#f0f2f7",
        clientBubbleColor: "<?= e($widget['color_primary']) ?>",
        welcomeMessage: "Olá! Bem-vindo à demonstração. Teste o atendimento aqui mesmo.",
        avatarUrl: "<?= e($widget['avatar_url'] ?? '') ?>",
        quickReplies: ["Olá 👋", "Falar com atendente", "Ver planos"],
        fields: {
            name:  { ask: <?= $widget['ask_name'] ? 'true' : 'false' ?>, required: <?= $widget['require_name'] ? 'true' : 'false' ?> },
            email: { ask: <?= $widget['ask_email'] ? 'true' : 'false' ?>, required: <?= $widget['require_email'] ? 'true' : 'false' ?> },
            phone: { ask: <?= $widget['ask_phone'] ? 'true' : 'false' ?>, required: <?= $widget['require_phone'] ? 'true' : 'false' ?> },
            cnpj:  { ask: <?= $widget['ask_cnpj'] ? 'true' : 'false' ?>, required: <?= $widget['require_cnpj'] ? 'true' : 'false' ?> }
        }
    };
    </script>
    <script async src="<?= base_url('widget/chat.js') ?>?_=<?= time() ?>"></script>

    <script>
    function shade(hex, amt) {
        hex = (hex || '#000').replace('#', '');
        if (hex.length === 3) hex = hex.split('').map(function(c){ return c+c; }).join('');
        var r = Math.max(0, Math.min(255, parseInt(hex.substr(0,2),16) + amt));
        var g = Math.max(0, Math.min(255, parseInt(hex.substr(2,2),16) + amt));
        var b = Math.max(0, Math.min(255, parseInt(hex.substr(4,2),16) + amt));
        return '#' + [r,g,b].map(function(x){ return x.toString(16).padStart(2,'0'); }).join('');
    }

    function applyStyles() {
        const color = document.getElementById('sColor').value;
        const userColor = document.getElementById('sUserColor').value;
        const bg = document.getElementById('sBg').value;
        const border = document.getElementById('sBorder').value;
        const botBubble = document.getElementById('sBotBubble').value;
        const position = document.querySelector('input[name="position"]:checked').value;
        const title = document.getElementById('sTitle').value || 'Atendimento';
        const welcome = document.getElementById('sWelcome').value || 'Olá! Como podemos ajudar?';
        const size = document.getElementById('sSize').value;

        document.getElementById('sColorHex').value = color;
        document.getElementById('sUserColorHex').value = userColor;
        document.getElementById('sBgHex').value = bg;
        document.getElementById('sBorderHex').value = border;
        document.getElementById('sBotBubbleHex').value = botBubble;
        document.getElementById('sSizeVal').textContent = size + 'px';
        document.getElementById('hintPosition').textContent = position === 'right' ? 'direito' : 'esquerdo';

        if (window.AFW) {
            const root = document.getElementById('atendeflow-widget');
            if (root) {
                root.style.setProperty('--afw-primary', color);
                root.style.setProperty('--afw-primary-dark', shade(color, -22));
                root.style.setProperty('--afw-user', userColor);
                root.style.setProperty('--afw-bg', bg);
                root.style.setProperty('--afw-border', border);
                root.style.setProperty('--afw-bot-bubble', botBubble);

                const launcher = document.getElementById('afwLauncher');
                const panel = document.getElementById('afwPanel');
                const opp = position === 'right' ? 'left' : 'right';
                [launcher, panel].forEach(function(el){
                    if (!el) return;
                    el.style[opp] = '';
                    el.style[position] = '24px';
                });
                if (panel) panel.style.bottom = (parseInt(size) + 36) + 'px';
            }

            const nameEl = document.getElementById('afwName');
            if (nameEl) nameEl.textContent = title;

            // atualiza a mensagem de boas-vindas já renderizada (primeira bolha do bot)
            const firstBot = document.querySelector('#afwMessages .afw-msg--bot .afw-bubble');
            if (firstBot && !firstBot.querySelector('.afw-typing') && !firstBot.closest('.afw-skeleton')) {
                firstBot.textContent = welcome;
            }
        }

        updateCodePreview(color, userColor, bg, border, botBubble, position, title, welcome);
    }

    function syncColor(val) {
        if (/^#[0-9a-f]{6}$/i.test(val)) {
            document.getElementById('sColor').value = val;
            applyStyles();
        }
    }

    function syncUserColor(val) {
        if (/^#[0-9a-f]{6}$/i.test(val)) {
            document.getElementById('sUserColor').value = val;
            applyStyles();
        }
    }
    function syncBg(val) {
        if (/^#[0-9a-f]{6}$/i.test(val)) {
            document.getElementById('sBg').value = val;
            applyStyles();
        }
    }
    function syncBorder(val) {
        if (/^#[0-9a-f]{6}$/i.test(val)) {
            document.getElementById('sBorder').value = val;
            applyStyles();
        }
    }
    function syncBotBubble(val) {
        if (/^#[0-9a-f]{6}$/i.test(val)) {
            document.getElementById('sBotBubble').value = val;
            applyStyles();
        }
    }

    function resetStyles() {
        document.getElementById('sColor').value = '#2f6fed';
        document.getElementById('sColorHex').value = '#2f6fed';
        document.getElementById('sUserColor').value = '#2f6fed';
        document.getElementById('sUserColorHex').value = '#2f6fed';
        document.getElementById('sBg').value = '#f4f6fb';
        document.getElementById('sBgHex').value = '#f4f6fb';
        document.getElementById('sBorder').value = '#e7eaf1';
        document.getElementById('sBorderHex').value = '#e7eaf1';
        document.getElementById('sBotBubble').value = '#f0f2f7';
        document.getElementById('sBotBubbleHex').value = '#f0f2f7';
        document.querySelector('input[name="position"][value="right"]').checked = true;
        document.getElementById('sTitle').value = '<?= e($widget['title']) ?>';
        document.getElementById('sWelcome').value = 'Olá! Como podemos ajudar?';
        document.getElementById('sSize').value = '52';
        applyStyles();
    }

    function updateCodePreview(color, userColor, bg, border, botBubble, position, title, welcome) {
        const key = '<?= e($widget['widget_key']) ?>';
        const baseUrl = '<?= rtrim(base_url(), '/') ?>';
        const code = `<script>
 window.ATENDIMENTO_CONFIG = {
     widgetId: "${key}",
     title: "${title}",
     color: "${color}",
     position: "${position}",
     welcomeMessage: "${welcome}",
     backgroundColor: "${bg}",
     borderColor: "${border}",
     agentBubbleColor: "${botBubble}",
     clientBubbleColor: "${userColor}",
     apiUrl: "${baseUrl}"
 };
 <\/script>
 <script async src="${baseUrl}/widget/chat.js"><\/script>`;

        const pre = document.getElementById('installCode');
        if (pre) {
            const btnHtml = '<button class="copy-btn" onclick="copyInstallCode()">Copiar</button>';
            pre.innerHTML = code + btnHtml;
        }
    }

    function copyInstallCode() {
        const pre = document.getElementById('installCode');
        const code = pre.textContent.replace('Copiar', '').trim();
        navigator.clipboard.writeText(code).then(() => {
            const btn = pre.querySelector('.copy-btn');
            btn.textContent = 'Copiado!';
            setTimeout(() => btn.textContent = 'Copiar', 2000);
        });
    }

    // Detect when widget is loaded, apply styles e abre o painel
    const checkWidget = setInterval(() => {
        if (window.AFW) {
            window.afwWidgetStyles = true;
            applyStyles();
            window.AFW.open();
            document.getElementById('afwNome') && document.getElementById('afwNome').focus();
            clearInterval(checkWidget);
        }
    }, 300);
    </script>
</body>
</html>
