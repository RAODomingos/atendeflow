<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entrar - Ominidesk</title>
    <link rel="icon" type="image/png" href="<?= asset('assets/img/favicon.png') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
    <script>
    (function(){try{var t=localStorage.getItem('omini-theme');if(!t)t=window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';if(t==='dark')document.documentElement.setAttribute('data-theme','dark');}catch(e){}})();
    </script>
</head>
<body class="login-page">
    <button class="login-theme-toggle" id="themeToggle" type="button" title="Alternar tema claro/escuro" aria-label="Alternar tema claro/escuro">
        <i class="fa-regular fa-moon" id="themeToggleIcon"></i>
    </button>

    <div class="login-split">
        <aside class="login-hero">
            <div class="login-hero-inner">
                <a href="<?= base_url('login') ?>" class="login-brand">
                    <img src="<?= asset('assets/img/ominidesk-mark.png') ?>" alt="Ominidesk" width="40" height="40">
                    Ominidesk
                </a>
                <h1>Central de atendimento omnichannel</h1>
                <p>Gerencie conversas de WhatsApp, e-mail e chat em um só lugar, com sua equipe.</p>
                <ul class="login-features">
                    <li><i class="fas fa-bolt"></i> Respostas rápidas e em tempo real</li>
                    <li><i class="fas fa-diagram-project"></i> Fluxos automatizados de triagem</li>
                    <li><i class="fas fa-chart-line"></i> Indicadores e relatórios claros</li>
                </ul>
                <p class="login-hero-note">Atendimento multicanal para times que precisam de contexto, não de abas.</p>
            </div>
        </aside>

        <main class="login-main">
            <div class="login-card">
                <div class="login-brand-mobile">
                    <img src="<?= asset('assets/img/ominidesk-mark.png') ?>" alt="Ominidesk" width="32" height="32">
                    Ominidesk
                </div>

                <div class="login-header">
                    <h1>Entrar</h1>
                    <p>Use sua conta Ominidesk para continuar</p>
                </div>

                <?php $error = \App\Core\Session::getFlash('error'); ?>
                <?php if ($error): ?>
                    <div class="login-alert" role="alert">
                        <i class="fas fa-exclamation-circle"></i>
                        <span><?= e($error) ?></span>
                    </div>
                <?php endif; ?>

                <form action="<?= base_url('login') ?>" method="POST" class="login-form">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label class="sr-only" for="email" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">E-mail</label>
                        <div class="input-wrap">
                            <i class="fas fa-envelope input-icon"></i>
                            <input type="email" id="email" name="email" class="form-control"
                                   value="<?= e(\App\Core\Session::getFlash('old_email')) ?>"
                                   placeholder="seu@email.com" autocomplete="username" required autofocus>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="sr-only" for="password" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">Senha</label>
                        <div class="input-wrap has-toggle">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" id="password" name="password" class="form-control"
                                   placeholder="Sua senha" autocomplete="current-password" required>
                            <button type="button" class="pw-toggle" id="pwToggle" aria-label="Mostrar senha" aria-pressed="false">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <p class="caps-hint" id="capsHint"><i class="fas fa-keyboard"></i> Caps Lock está ativado</p>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block">
                        <span>Entrar</span>
                        <i class="fas fa-arrow-right"></i>
                    </button>
                </form>

                <div class="login-footer">
                    <div class="login-credentials">
                        <span class="login-credentials-label">Acesso padrão</span>
                        <span class="login-credentials-row">
                            <code>admin@atendeflow.local</code>
                            <span class="login-credentials-sep">&middot;</span>
                            <code>Admin@123</code>
                        </span>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        (function () {
            var t = document.getElementById('pwToggle');
            var p = document.getElementById('password');
            if (t && p) {
                t.addEventListener('click', function () {
                    var show = p.type === 'password';
                    p.type = show ? 'text' : 'password';
                    t.setAttribute('aria-pressed', show ? 'true' : 'false');
                    t.setAttribute('aria-label', show ? 'Ocultar senha' : 'Mostrar senha');
                    t.innerHTML = show
                        ? '<i class="fas fa-eye-slash"></i>'
                        : '<i class="fas fa-eye"></i>';
                });
            }

            var form = document.querySelector('.login-form');
            if (form) {
                form.addEventListener('submit', function () {
                    var b = form.querySelector('button[type="submit"]');
                    if (b && !b.classList.contains('is-loading')) {
                        b.classList.add('is-loading');
                        b.disabled = true;
                    }
                });
            }

            // Caps Lock ligado — evita a confusão clássica de "senha errada".
            var capsHint = document.getElementById('capsHint');
            if (p && capsHint) {
                p.addEventListener('keyup', function (e) {
                    var on = e.getModifierState && e.getModifierState('CapsLock');
                    capsHint.classList.toggle('on', !!on);
                });
                p.addEventListener('blur', function () { capsHint.classList.remove('on'); });
            }

            // Efeito de foco nos campos com ícone.
            document.querySelectorAll('.input-wrap .form-control').forEach(function (input) {
                input.addEventListener('focus', function () {
                    this.closest('.input-wrap').classList.add('focused');
                });
                input.addEventListener('blur', function () {
                    this.closest('.input-wrap').classList.remove('focused');
                });
            });

            // Tema claro/escuro — respeita a preferência salva pelo sistema.
            var tt = document.getElementById('themeToggle');
            var ti = document.getElementById('themeToggleIcon');
            function syncIcon() {
                var dark = document.documentElement.getAttribute('data-theme') === 'dark';
                if (ti) ti.className = dark ? 'fa-regular fa-sun' : 'fa-regular fa-moon';
            }
            if (tt) {
                syncIcon();
                tt.addEventListener('click', function () {
                    var dark = document.documentElement.getAttribute('data-theme') === 'dark';
                    var next = dark ? 'light' : 'dark';
                    document.documentElement.setAttribute('data-theme', next);
                    try { localStorage.setItem('omini-theme', next); } catch (e) {}
                    syncIcon();
                });
            }
        })();
    </script>
</body>
</html>
