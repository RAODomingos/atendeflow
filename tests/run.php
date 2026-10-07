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

echo "\n$passes passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
