<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Atendeflow</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
</head>
<body class="login-page">
    <div class="login-split">
        <aside class="login-hero">
            <div class="login-hero-inner">
                <a href="<?= base_url('login') ?>" class="login-brand">
                    <span class="login-brand-mark"><i class="fas fa-headset"></i></span>
                    Atendeflow
                </a>
                <h1>Central de atendimento omnichannel</h1>
                <p>Gerencie conversas de WhatsApp, e-mail e chat em um só lugar, com sua equipe.</p>
                <ul class="login-features">
                    <li><i class="fas fa-bolt"></i> Respostas rápidas e em tempo real</li>
                    <li><i class="fas fa-diagram-project"></i> Fluxos automatizados de triagem</li>
                    <li><i class="fas fa-chart-line"></i> Indicadores e relatórios claros</li>
                </ul>
            </div>
        </aside>

        <main class="login-main">
            <div class="login-card">
                <div class="login-header">
                    <div class="login-logo">
                        <i class="fas fa-headset"></i>
                    </div>
                    <h1>Entrar</h1>
                    <p>Use sua conta Atendeflow para continuar</p>
                </div>

                <?php $error = \App\Core\Session::getFlash('error'); ?>
                <?php if ($error): ?>
                    <div class="login-alert">
                        <i class="fas fa-exclamation-circle"></i>
                        <?= e($error) ?>
                    </div>
                <?php endif; ?>

                <form action="<?= base_url('login') ?>" method="POST" class="login-form">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <div class="input-wrap">
                            <i class="fas fa-envelope input-icon"></i>
                            <input type="email" id="email" name="email" class="form-control"
                                   value="<?= e(\App\Core\Session::getFlash('old_email')) ?>"
                                   placeholder="seu@email.com" required autofocus>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="input-wrap">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" id="password" name="password" class="form-control"
                                   placeholder="Sua senha" required>
                            <button type="button" class="pw-toggle" id="pwToggle" aria-label="Mostrar senha">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block">
                        <i class="fas fa-sign-in-alt"></i>
                        Entrar
                    </button>
                </form>

                <div class="login-footer">
                    <p>Usuário padrão: <strong>admin@atendeflow.local</strong></p>
                    <p>Senha: <strong>Admin@123</strong></p>
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

            // Partículas flutuantes no hero (versão antiga)
            var hero = document.querySelector('.login-hero');
            if (hero) {
                for (var i = 0; i < 9; i++) {
                    var dot = document.createElement('div');
                    var size = 5 + Math.random() * 9;
                    dot.className = 'login-hero-dot';
                    dot.style.cssText = 'width:' + size + 'px;height:' + size + 'px;left:' +
                        (Math.random() * 100) + '%;top:' + (Math.random() * 100) + '%;' +
                        'animation-duration:' + (6 + Math.random() * 7) + 's;' +
                        'animation-delay:' + (Math.random() * 5) + 's;';
                    hero.appendChild(dot);
                }
            }

            // Input focus effects
            document.querySelectorAll('.input-wrap .form-control').forEach(function(input) {
                input.addEventListener('focus', function() {
                    this.closest('.input-wrap').classList.add('focused');
                });
                input.addEventListener('blur', function() {
                    this.closest('.input-wrap').classList.remove('focused');
                });
            });
        })();
    </script>
</body>
</html>
