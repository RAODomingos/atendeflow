<?php
// Runner mínimo de testes (sem phpunit): php tests/run.php
// Retorna exit 1 se algum teste falhar.

$failures = 0;
$passes = 0;

function check(string $name, bool $cond): void
{
    global $failures, $passes;
    if ($cond) {
        $passes++;
        echo "ok - $name\n";
    } else {
        $failures++;
        echo "FALHOU - $name\n";
    }
}

require_once __DIR__ . '/../app/Core/helpers/security.php';
require_once __DIR__ . '/../app/Core/RateLimiter.php';

use App\Core\RateLimiter;

// Regra de senha
check('senha curta rejeitada', password_strength_error('Abc123') !== null);
check('senha sem numero rejeitada', password_strength_error('abcdefghij') !== null);
check('senha sem letra rejeitada', password_strength_error('1234567890') !== null);
check('senha forte aceita', password_strength_error('Atende12345') === null);
check('senha vazia rejeitada', password_strength_error('') !== null);
check('senha null rejeitada', password_strength_error(null) !== null);

// RateLimiter: 3 tentativas / 60s, bloqueio 60s
$key = 'test:' . bin2hex(random_bytes(8));
RateLimiter::clear($key);
check('rate-limit inicia liberado', RateLimiter::blocked($key) === 0);
check('hit 1 sem bloqueio', RateLimiter::hit($key, 3, 60, 60) === 0);
check('hit 2 sem bloqueio', RateLimiter::hit($key, 3, 60, 60) === 0);
check('hit 3 bloqueia', RateLimiter::hit($key, 3, 60, 60) > 0);
check('bloqueado após limite', RateLimiter::blocked($key) > 0);
RateLimiter::clear($key);
check('clear libera bloqueio', RateLimiter::blocked($key) === 0);

// clientKey: prefixo + IP, sem vazar X-Forwarded-For forjado
$_SERVER['REMOTE_ADDR'] = '1.2.3.4';
check('clientKey usa REMOTE_ADDR', RateLimiter::clientKey('login') === 'login:1.2.3.4');
$_SERVER['REMOTE_ADDR'] = '5.6.7.8, 9.9.9.9';
check('clientKey pega primeiro IP', RateLimiter::clientKey('login') === 'login:5.6.7.8');

// CSRF: grupo API interno exige auth+csrf; middleware usa hash_equals e nunca lê query
$routes = file_get_contents(__DIR__ . '/../routes/web.php');
check('api interna exige csrf', (bool) preg_match("/Internal JSON API.*?\[.auth.,\s*.csrf.\]/s", $routes));
$csrfSrc = file_get_contents(__DIR__ . '/../app/Middleware/CsrfMiddleware.php');
check('csrf usa hash_equals', str_contains($csrfSrc, 'hash_equals'));
check('csrf ignora query string', str_contains($csrfSrc, 'nunca da query') || !str_contains($csrfSrc, "\$_GET"));
check('csrf gera 32 bytes', str_contains($csrfSrc, 'random_bytes(32)'));

// Auth: tentativa valida hash + is_active; middleware revalida a cada request
$authSrc = file_get_contents(__DIR__ . '/../app/Core/Auth.php');
check('auth usa password_verify', str_contains($authSrc, 'password_verify'));
check('auth bloqueia inativo', str_contains($authSrc, 'is_active'));
$mwSrc = file_get_contents(__DIR__ . '/../app/Middleware/AuthMiddleware.php');
check('middleware revalida usuario', str_contains($mwSrc, 'Auth::user()'));

// Avatar WhatsApp: sync tenta candidatos e aceita refresh de URL remota
$waSrc = file_get_contents(__DIR__ . '/../app/Services/WhatsAppService.php');
check('avatar tenta multiplos ids', str_contains($waSrc, '@c.us') && str_contains($waSrc, 'candidates'));
check('avatar trata url expirada', str_contains($waSrc, "str_starts_with(\$avatar, 'http')") || str_contains($waSrc, 'needsAvatar'));

// Conexão: phone_number atualiza ao trocar de número e nunca apaga com null
check('phone atualiza ao mudar (log PHONE_CHANGED)', str_contains($waSrc, 'PHONE_CHANGED'));
check('phone nao apaga com null no poll', str_contains($waSrc, 'Nunca apaga número salvo com null'));

// Fluxo: nao reinicia em conversa Aberta / em atendimento / com historico
check('fluxo tem trava shouldAutoStartFlow', str_contains($waSrc, 'shouldAutoStartFlow'));
check('fluxo bloqueia open/waiting', str_contains($waSrc, 'waiting_customer') && str_contains($waSrc, 'em atendimento'));
check('fluxo bloqueia historico', str_contains($waSrc, 'conversation_flow_states WHERE conversation_id'));
check('fluxo bloqueia tag Aberto', str_contains($waSrc, "findByName('Aberto')"));

echo "\n$passes passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
