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

// Regra de senha
check('senha curta rejeitada', password_strength_error('Abc123') !== null);
check('senha sem numero rejeitada', password_strength_error('abcdefghij') !== null);
check('senha sem letra rejeitada', password_strength_error('1234567890') !== null);
check('senha forte aceita', password_strength_error('Atende12345') === null);
check('senha vazia rejeitada', password_strength_error('') !== null);
check('senha null rejeitada', password_strength_error(null) !== null);

echo "\n$passes passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
