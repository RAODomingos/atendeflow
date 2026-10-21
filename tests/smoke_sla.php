<?php
/**
 * Smoke test HTTP: loga no app, abre a inbox e confere que a página
 * carrega com o novo relógio do SLA (sem erro de PHP).
 */
$base = 'http://127.0.0.1:8099';
$jar = tempnam(sys_get_temp_dir(), 'atendeflow_smoke');

function req(string $url, ?array $post = null, string $jar = ''): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 20,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => (string) $body, 'err' => $err];
}

$fail = 0;
function check(string $name, bool $cond, string $detail = ''): void
{
    global $fail;
    if ($cond) {
        echo "ok - $name" . ($detail !== '' ? " ($detail)" : '') . PHP_EOL;
    } else {
        $fail++;
        echo "FALHOU - $name" . ($detail !== '' ? " ($detail)" : '') . PHP_EOL;
    }
}

$r = req($base . '/login', null, $jar);
if (!preg_match('/name="_csrf_token" value="([^"]+)"/', $r['body'], $m)) {
    echo "ERRO: nao achei o token CSRF no form de login (http {$r['code']}, {$r['err']})\n";
    exit(1);
}
$token = $m[1];

$r = req($base . '/login', [
    '_csrf_token' => $token,
    'email' => 'admin@atendeflow.local',
    'password' => 'Admin@123',
], $jar);
check('login responde 200', $r['code'] === 200, 'http ' . $r['code']);

$r = req($base . '/inbox', null, $jar);
check('inbox responde 200', $r['code'] === 200, 'http ' . $r['code']);
check('inbox nao contem erro fatal', !preg_match('/(Fatal error|Parse error|Uncaught|Deprecated:)/i', $r['body']));

$hasItem = (bool) preg_match('/class="conv-item/', $r['body']);
check('a inbox renderiza itens de conversa', $hasItem);

if ($hasItem) {
    check('o item expoe o instante do sync', str_contains($r['body'], 'data-sla-sync-at='));
    check('o item expoe o congelamento', str_contains($r['body'], 'data-sla-frozen='));
    check('o item expoe a proxima virada', str_contains($r['body'], 'data-sla-boundary='));
    preg_match('/data-sla-sync-at="(\d+)"/', $r['body'], $sm);
    preg_match('/data-sla-frozen="([01])"/', $r['body'], $fm);
    preg_match('/data-sla-waiting="(\d+)"/', $r['body'], $wm);
    echo '   [info] sync=' . ($sm[1] ?? '-') . ' frozen=' . ($fm[1] ?? '-') . ' waiting=' . ($wm[1] ?? '-') . PHP_EOL;
    check('o sync gravado e um epoch recente', (int) ($sm[1] ?? 0) > time() - 3600);
}

$r = req($base . '/api/conversations', null, $jar);
check('api de conversas responde 200', $r['code'] === 200, 'http ' . $r['code']);
$json = json_decode($r['body'], true);
check('api de conversas devolve JSON', is_array($json), substr($r['body'], 0, 120));
if (is_array($json) && $json) {
    $row = $json[0];
    check('a api expoe waiting_seconds', array_key_exists('waiting_seconds', $row));
    check('a api expoe sla_sync_at', array_key_exists('sla_sync_at', $row));
    check('a api expoe sla_frozen', array_key_exists('sla_frozen', $row));
    check('a api expoe sla_boundary_at', array_key_exists('sla_boundary_at', $row));
    check('a api expoe os campos de sla da conversa', array_key_exists('sla_response_seconds', $row) && array_key_exists('sla_resolution_seconds', $row));
    $convId = (int) ($row['id'] ?? 0);

    if ($convId) {
        $r = req($base . '/api/conversations/' . $convId, null, $jar);
        $one = json_decode($r['body'], true);
        check('a api de uma conversa expoe o sla', is_array($one) && array_key_exists('sla_response_seconds', $one), substr($r['body'], 0, 120));

        $r = req($base . '/inbox/' . $convId . '/panel', null, $jar);
        check('o painel da conversa tem o chip de sla', str_contains((string) $r['body'], 'header-chip-sla'), 'http ' . $r['code']);
    }
}

$r = req($base . '/api/dashboard-stats', null, $jar);
$stats = json_decode($r['body'], true);
check('a api do dashboard expoe o sla critico', is_array($stats) && array_key_exists('slaCriticalNow', $stats), substr($r['body'], 0, 120));
check('a api do dashboard expoe o compliance de 30d', is_array($stats) && array_key_exists('slaCompliance30', $stats));

$r = req($base . '/api/sla/config', null, $jar);
$cfg = json_decode($r['body'], true);
check('api de sla expoe o toggle de horario', is_array($cfg) && array_key_exists('business_hours', $cfg), $r['body']);

$r = req($base . '/settings/notifications', null, $jar);
check('painel de notificacoes responde 200', $r['code'] === 200, 'http ' . $r['code']);
check('painel expoe o toggle sla_business_hours', str_contains($r['body'], 'name="sla_business_hours"'));

@unlink($jar);
exit($fail > 0 ? 1 : 0);
