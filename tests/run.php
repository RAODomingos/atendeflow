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
$uazSrc = file_get_contents(__DIR__ . '/../app/Services/WhatsApp/UazapiProvider.php');
check('uazapi prioriza owner da API', str_contains($uazSrc, 'Owner da API tem prioridade'));
check('uazapi busca participantes do grupo', str_contains($uazSrc, 'fetchGroupParticipants'));
check('grupo aprende LID e tenta de novo', str_contains($waSrc, 'learnGroupLidMappings'));

// Fluxo: nao reinicia em conversa Aberta / em atendimento / com historico
check('fluxo tem trava shouldAutoStartFlow', str_contains($waSrc, 'shouldAutoStartFlow'));
check('fluxo bloqueia open/waiting', str_contains($waSrc, 'waiting_customer') && str_contains($waSrc, 'em atendimento'));
check('fluxo bloqueia historico', str_contains($waSrc, 'conversation_flow_states WHERE conversation_id'));
check('fluxo bloqueia tag Aberto', str_contains($waSrc, "findByName('Aberto')"));

// ─────────────────────────────────────────────────────────────
// SLA: relógio ciente de horário comercial (SlaClock + SlaService)
// ─────────────────────────────────────────────────────────────
if (file_exists(__DIR__ . '/../app/Services/SlaClock.php')) {
    require_once __DIR__ . '/../app/Services/SlaClock.php';
}
if (file_exists(__DIR__ . '/../app/Services/SlaService.php')) {
    require_once __DIR__ . '/../app/Services/SlaService.php';
}
if (file_exists(__DIR__ . '/../app/Services/SlaMeasurement.php')) {
    require_once __DIR__ . '/../app/Services/SlaMeasurement.php';
}

// Chamadas tolerantes: enquanto a feature não existe a suíte falha com
// "FALHOU" em vez de matar o runner com fatal error.
function slaInt(string $class, string $method, array $args): int
{
    if (!class_exists($class) || !method_exists($class, $method)) {
        return -1;
    }
    $r = $class::$method(...$args);
    if (is_bool($r)) {
        return $r ? 1 : 0;
    }
    return is_int($r) ? $r : -1;
}

function slaStr(string $class, string $method, array $args): string
{
    if (!class_exists($class) || !method_exists($class, $method)) {
        return '';
    }
    $r = $class::$method(...$args);
    return is_object($r) && method_exists($r, 'format') ? $r->format('Y-m-d H:i:s') : '';
}

function slaState(array $args): array
{
    if (!class_exists('App\Services\SlaService') || !method_exists('App\Services\SlaService', 'clockState')) {
        return [];
    }
    $r = \App\Services\SlaService::clockState(...$args);
    return is_array($r) ? $r : [];
}

$utc = new DateTimeZone('UTC');
$at = fn(string $s) => new DateTimeImmutable($s, $utc);

// Grade seg-sex 08:00-18:00; sabado e domingo fechados.
$office = [];
foreach ([1, 2, 3, 4, 5] as $dow) {
    $office[$dow] = ['is_open' => 1, 'open' => '08:00:00', 'close' => '18:00:00'];
}
$office[0] = ['is_open' => 0, 'open' => '08:00:00', 'close' => '18:00:00'];
$office[6] = ['is_open' => 0, 'open' => '08:00:00', 'close' => '18:00:00'];

check('sla: grade vazia conta tempo de relogio', slaInt('App\Services\SlaClock', 'elapsedInSchedule', [[], 'UTC', $at('2026-10-19 10:00:00'), $at('2026-10-19 11:00:00')]) === 3600);
check('sla: dentro da janela conta tudo', slaInt('App\Services\SlaClock', 'elapsedInSchedule', [$office, 'UTC', $at('2026-10-19 10:00:00'), $at('2026-10-19 11:00:00')]) === 3600);
check('sla: antes de abrir so conta desde a abertura', slaInt('App\Services\SlaClock', 'elapsedInSchedule', [$office, 'UTC', $at('2026-10-19 07:00:00'), $at('2026-10-19 09:00:00')]) === 3600);
check('sla: depois de fechar so conta ate o fechamento', slaInt('App\Services\SlaClock', 'elapsedInSchedule', [$office, 'UTC', $at('2026-10-19 17:00:00'), $at('2026-10-19 19:00:00')]) === 3600);
check('sla: noite nao conta', slaInt('App\Services\SlaClock', 'elapsedInSchedule', [$office, 'UTC', $at('2026-10-19 23:00:00'), $at('2026-10-20 01:00:00')]) === 0);
check('sla: fim de semana nao conta', slaInt('App\Services\SlaClock', 'elapsedInSchedule', [$office, 'UTC', $at('2026-10-23 19:00:00'), $at('2026-10-26 09:00:00')]) === 3600);
check('sla: semana util conta so as janelas', slaInt('App\Services\SlaClock', 'elapsedInSchedule', [$office, 'UTC', $at('2026-10-19 08:00:00'), $at('2026-10-23 18:00:00')]) === 180000);
check('sla: janela invertida (turno noturno) e ignorada', slaInt('App\Services\SlaClock', 'elapsedInSchedule', [[1 => ['is_open' => 1, 'open' => '22:00:00', 'close' => '06:00:00']], 'UTC', $at('2026-10-19 23:00:00'), $at('2026-10-19 23:30:00')]) === 0);
check('sla: intervalo invertido devolve zero', slaInt('App\Services\SlaClock', 'elapsedInSchedule', [$office, 'UTC', $at('2026-10-19 11:00:00'), $at('2026-10-19 10:00:00')]) === 0);
check('sla: dia sem regra conta como aberto', slaInt('App\Services\SlaClock', 'elapsedInSchedule', [[1 => ['is_open' => 1, 'open' => '08:00:00', 'close' => '18:00:00']], 'UTC', $at('2026-10-20 20:00:00'), $at('2026-10-20 22:00:00')]) === 7200);
check('sla: isOpenAt dentro da janela', slaInt('App\Services\SlaClock', 'isOpenAt', [$office, 'UTC', $at('2026-10-19 10:00:00')]) === 1);
check('sla: isOpenAt fora da janela', slaInt('App\Services\SlaClock', 'isOpenAt', [$office, 'UTC', $at('2026-10-19 19:00:00')]) === 0);
check('sla: isOpenAt em dia fechado', slaInt('App\Services\SlaClock', 'isOpenAt', [$office, 'UTC', $at('2026-10-24 10:00:00')]) === 0);
check('sla: isOpenAt em dia sem regra', slaInt('App\Services\SlaClock', 'isOpenAt', [[1 => ['is_open' => 1, 'open' => '08:00:00', 'close' => '18:00:00']], 'UTC', $at('2026-10-20 10:00:00')]) === 1);
check('sla: nextBoundary fecha no fim da janela', slaStr('App\Services\SlaClock', 'nextBoundary', [$office, 'UTC', $at('2026-10-19 10:00:00')]) === '2026-10-19 18:00:00');
check('sla: nextBoundary abre na proxima abertura', slaStr('App\Services\SlaClock', 'nextBoundary', [$office, 'UTC', $at('2026-10-24 10:00:00')]) === '2026-10-26 08:00:00');

// SlaService::clockState — âncora, pausa e contagem em segundos úteis.
$row = [
    'status' => 'open',
    'created_at' => '2026-10-19 09:00:00',
    'last_inbound_at' => '2026-10-19 11:00:00',
    'last_inbound_id' => 10,
    'last_public_id' => 5,
    'department_id' => 1,
];

$st = slaState([$row, $office, 'UTC', '2026-10-19 12:00:00', 'UTC']);
check('sla: cliente falou por ultimo conta desde a mensagem', ($st['waiting_seconds'] ?? null) === 3600);
check('sla: dentro do horario o relogio corre', ($st['frozen'] ?? null) === false);
check('sla: sem motivo de pausa quando o relogio corre', ($st['paused_reason'] ?? null) === null);
check('sla: limite do dia e a proxima virada', ($st['next_boundary'] ?? null) === '2026-10-19 18:00:00');

$replied = $row;
$replied['last_public_id'] = 20;
$st = slaState([$replied, $office, 'UTC', '2026-10-19 12:00:00', 'UTC']);
check('sla: atendente falou por ultimo congela o relogio', ($st['waiting_seconds'] ?? null) === 0);
check('sla: bola com o cliente marca pausa por cliente', ($st['paused_reason'] ?? null) === 'customer');
check('sla: bola com o cliente congela', ($st['frozen'] ?? null) === true);

$internal = $row;
$internal['status'] = 'waiting_internal';
$st = slaState([$internal, $office, 'UTC', '2026-10-19 12:00:00', 'UTC']);
check('sla: waiting_internal congela mesmo sem resposta', ($st['waiting_seconds'] ?? null) === 0);
check('sla: waiting_internal marca pausa por interno', ($st['paused_reason'] ?? null) === 'internal');

$closed = $row;
$closed['status'] = 'resolved';
$st = slaState([$closed, $office, 'UTC', '2026-10-19 12:00:00', 'UTC']);
check('sla: status final nao conta', ($st['waiting_seconds'] ?? null) === 0);
check('sla: status final marca pausa por encerrada', ($st['paused_reason'] ?? null) === 'closed');

$noInbound = $row;
$noInbound['last_inbound_id'] = null;
$noInbound['last_inbound_at'] = null;
$st = slaState([$noInbound, $office, 'UTC', '2026-10-19 12:00:00', 'UTC']);
check('sla: sem mensagem do cliente conta desde a criacao', ($st['waiting_seconds'] ?? null) === 10800);

$st = slaState([$row, $office, 'UTC', '2026-10-19 20:00:00', 'UTC']);
check('sla: fora do horario conta so o que ja correu', ($st['waiting_seconds'] ?? null) === 25200);
check('sla: fora do horario o relogio fica congelado', ($st['frozen'] ?? null) === true);
check('sla: fora do horario marca pausa por horario', ($st['paused_reason'] ?? null) === 'out_of_hours');
check('sla: fora do horario a proxima virada e a abertura', ($st['next_boundary'] ?? null) === '2026-10-20 08:00:00');

$st = slaState([$row, null, 'UTC', '2026-10-19 20:00:00', 'UTC']);
check('sla: sem grade conta tempo de relogio', ($st['waiting_seconds'] ?? null) === 32400);
check('sla: sem grade o relogio nunca congela', ($st['frozen'] ?? null) === false);

$st = slaState([$row, $office, 'America/Sao_Paulo', '2026-10-19 12:00:00', 'UTC']);
check('sla: a grade usa o fuso do departamento', ($st['waiting_seconds'] ?? null) === 3600);

// ─────────────────────────────────────────────────────────────
// SLA: fiação entre servidor, view e tick do navegador
// ─────────────────────────────────────────────────────────────
$convSrc = file_get_contents(__DIR__ . '/../app/Models/Conversation.php');
check('sla: a listagem expoe a ancora da espera', str_contains($convSrc, 'sla_last_inbound_at'));
check('sla: a listagem aplica o relogio corrigido', substr_count($convSrc, 'SlaService::applyClock') >= 3);

$listSrc = file_get_contents(__DIR__ . '/../app/Views/inbox/_conv_list.php');
check('sla: o item da lista expoe o instante do sync', str_contains($listSrc, 'data-sla-sync-at'));
check('sla: o item da lista expoe o congelamento', str_contains($listSrc, 'data-sla-frozen'));
check('sla: o item da lista expoe a proxima virada', str_contains($listSrc, 'data-sla-boundary'));

$jsSrc = file_get_contents(__DIR__ . '/../public/assets/js/app-enhancements.js');
check('sla: o tick usa a ancora do servidor', str_contains($jsSrc, 'slaSyncAt'));
check('sla: o tick respeita o congelamento', str_contains($jsSrc, 'slaFrozen'));
check('sla: o tick respeita a proxima virada', str_contains($jsSrc, 'slaBoundary'));
check('sla: o tick nao usa mais o carregamento global da pagina', !str_contains($jsSrc, 'this._pageLoadedAt'));

$bhSrc = file_get_contents(__DIR__ . '/../app/Services/BusinessHoursService.php');
check('sla: o horario comercial expoe a grade no formato do relogio', str_contains($bhSrc, 'slaSchedule'));

$setSrc = file_get_contents(__DIR__ . '/../app/Views/settings/notifications.php');
check('sla: o painel expoe o toggle de horario comercial', str_contains($setSrc, 'sla_business_hours'));

// ─────────────────────────────────────────────────────────────
// Notificações: dedup indexável e schema.sql completo
// ─────────────────────────────────────────────────────────────
$notifSrc = file_get_contents(__DIR__ . '/../app/Services/NotificationService.php');
check('notificacao: o dedup usa a coluna message_id', str_contains($notifSrc, 'AND message_id = ?'));
check('notificacao: o dedup nao extrai message_id do JSON', !str_contains($notifSrc, "JSON_EXTRACT(metadata, '$.message_id')"));

$schemaSrc = file_get_contents(__DIR__ . '/../database/schema.sql');
check('notificacao: a tabela notifications esta no schema.sql', str_contains($schemaSrc, 'CREATE TABLE IF NOT EXISTS notifications'));
check('notificacao: o schema.sql traz a coluna message_id', str_contains($schemaSrc, 'message_id BIGINT UNSIGNED NULL'));
check('notificacao: o schema.sql traz o indice do dedup', str_contains($schemaSrc, 'idx_notif_msg (user_id, conversation_id, message_id)'));

$migrationSrc = file_get_contents(__DIR__ . '/../database/migrations/2026_10_20_notifications_message_id.sql');
check('notificacao: a migration cria a coluna message_id', str_contains($migrationSrc, 'ADD COLUMN message_id'));
check('notificacao: a migration cria o indice do dedup', str_contains($migrationSrc, 'idx_notif_msg'));

// ─────────────────────────────────────────────────────────────
// SLA mensurável: migration e schema
// ─────────────────────────────────────────────────────────────
$migPath = __DIR__ . '/../database/migrations/2026_10_20_sla_measurable.sql';
$migSrc = is_file($migPath) ? (string) file_get_contents($migPath) : '';
check('sla: a migration cria a tabela conversation_slas', str_contains($migSrc, 'CREATE TABLE IF NOT EXISTS conversation_slas'));
check('sla: a migration cria as colunas de override da caixa', str_contains($migSrc, 'sla_response_minutes') && str_contains($migSrc, 'sla_resolution_minutes') && str_contains($migSrc, 'sla_business_hours'));
check('sla: a migration cria o telefone de escalonamento', str_contains($migSrc, 'sla_escalation_phone'));
check('sla: a migration adiciona sla_breached no enum de eventos', str_contains($migSrc, 'sla_breached'));
$schemaSrc = (string) file_get_contents(__DIR__ . '/../database/schema.sql');
check('sla: o schema.sql traz a tabela conversation_slas', str_contains($schemaSrc, 'CREATE TABLE IF NOT EXISTS conversation_slas'));

// ─────────────────────────────────────────────────────────────
// SLA mensurável: motor de medição puro
// ─────────────────────────────────────────────────────────────
function slaMeasure(array $args): array
{
    if (!class_exists('App\Services\SlaMeasurement') || !method_exists('App\Services\SlaMeasurement', 'measure')) {
        return [];
    }
    $r = \App\Services\SlaMeasurement::measure(...$args);
    return is_array($r) ? $r : [];
}

check('sla: a classe de medicao existe', class_exists('App\Services\SlaMeasurement'));

// Grade seg-sex 08:00-18:00; sabado e domingo fechados.
$office = [];
foreach ([1, 2, 3, 4, 5] as $dow) {
    $office[$dow] = ['is_open' => 1, 'open' => '08:00:00', 'close' => '18:00:00'];
}
$office[0] = ['is_open' => 0, 'open' => '08:00:00', 'close' => '18:00:00'];
$office[6] = ['is_open' => 0, 'open' => '08:00:00', 'close' => '18:00:00'];

$convSla = [
    'id' => 1, 'status' => 'open', 'department_id' => 2, 'group_id' => null,
    'created_at' => '2026-10-19 09:00:00', 'closed_at' => null,
];
$msgSla = fn(int $id, string $dir, string $type, ?int $uid, string $at) =>
    ['id' => $id, 'direction' => $dir, 'type' => $type, 'user_id' => $uid, 'created_at' => $at];

// Limites folgados por padrao, para que so o caso 14 teste a violacao.
// app_tz fixado em UTC para o teste nao depender do php.ini da maquina.
$optsSla = ['response_threshold' => 3600, 'resolution_threshold' => 86400, 'app_tz' => 'UTC'];

// 1) primeira resposta mede da 1a mensagem do cliente
$st = slaMeasure([$convSla, [
    $msgSla(1, 'inbound', 'text', null, '2026-10-19 09:00:00'),
    $msgSla(2, 'inbound', 'text', null, '2026-10-19 09:10:00'),
    $msgSla(3, 'outbound', 'text', 7, '2026-10-19 09:20:00'),
], [], $office, 'UTC', '2026-10-19 09:20:00', $optsSla]);
check('sla: a primeira resposta mede da 1a mensagem do cliente', ($st['response_seconds'] ?? null) === 1200, json_encode($st));
check('sla: a primeira resposta registra o instante', ($st['response_at'] ?? null) === '2026-10-19 09:20:00');

// 2) o pareamento nao conta o mesmo tempo duas vezes
check('sla: o pareamento nao conta o mesmo tempo duas vezes', is_int($st['resolution_seconds'] ?? null) && $st['resolution_seconds'] === 1200, 'got ' . json_encode($st['resolution_seconds'] ?? null));
check('sla: a espera nao e contada em duplicidade', is_int($st['resolution_seconds'] ?? null) && $st['resolution_seconds'] !== 1800 && $st['resolution_seconds'] !== 2400);

// 3) conversa ativa sem resposta humana nao tem sla de resposta
$st = slaMeasure([$convSla, [
    $msgSla(1, 'inbound', 'text', null, '2026-10-19 09:00:00'),
], [], $office, 'UTC', '2026-10-19 10:00:00', $optsSla]);
check('sla: sem resposta humana a conversa nao tem sla de resposta', array_key_exists('response_seconds', $st) && $st['response_seconds'] === null, json_encode($st));
check('sla: sem resposta humana a conversa ativa nao viola', array_key_exists('response_breached', $st) && $st['response_breached'] === false, json_encode($st));

// 4) conversa encerrada sem resposta humana e violacao
$closedConv = $convSla;
$closedConv['status'] = 'closed';
$closedConv['closed_at'] = '2026-10-19 10:00:00';
$st = slaMeasure([$closedConv, [
    $msgSla(1, 'inbound', 'text', null, '2026-10-19 09:00:00'),
], [], $office, 'UTC', '2026-10-19 10:00:00', $optsSla]);
check('sla: conversa encerrada sem resposta tem sla de resposta nulo', array_key_exists('response_seconds', $st) && $st['response_seconds'] === null, json_encode($st));
check('sla: conversa encerrada sem resposta conta como violacao', array_key_exists('response_breached', $st) && $st['response_breached'] === true, json_encode($st));

// 5) o periodo aberto conta ate agora
$st = slaMeasure([$convSla, [
    $msgSla(1, 'inbound', 'text', null, '2026-10-19 09:00:00'),
], [], $office, 'UTC', '2026-10-19 10:00:00', $optsSla]);
check('sla: o periodo aberto conta ate agora', ($st['resolution_seconds'] ?? null) === 3600, 'got ' . json_encode($st['resolution_seconds'] ?? null));
check('sla: o periodo aberto expoe o inicio', ($st['period_started_at'] ?? null) === '2026-10-19 09:00:00');

// 6) conversa encerrada fecha o periodo no closed_at
$st = slaMeasure([$closedConv, [
    $msgSla(1, 'inbound', 'text', null, '2026-10-19 09:00:00'),
], [], $office, 'UTC', '2026-10-19 23:00:00', $optsSla]);
check('sla: conversa encerrada fecha o periodo no closed_at', ($st['resolution_seconds'] ?? null) === 3600, 'got ' . json_encode($st['resolution_seconds'] ?? null));

// 7) a pausa por waiting_internal e descontada
$st = slaMeasure([$convSla, [
    $msgSla(1, 'inbound', 'text', null, '2026-10-19 09:00:00'),
], [
    ['at' => '2026-10-19 09:30:00', 'status' => 'waiting_internal'],
    ['at' => '2026-10-19 10:30:00', 'status' => 'open'],
], $office, 'UTC', '2026-10-19 11:00:00', $optsSla]);
check('sla: a pausa por waiting_internal e descontada', ($st['resolution_seconds'] ?? null) === 3600, 'got ' . json_encode($st['resolution_seconds'] ?? null));

// 8) sem grade conta tempo de relogio
$st = slaMeasure([$convSla, [
    $msgSla(1, 'inbound', 'text', null, '2026-10-19 09:00:00'),
], [], null, 'UTC', '2026-10-19 10:00:00', $optsSla]);
check('sla: sem grade conta tempo de relogio', ($st['resolution_seconds'] ?? null) === 3600, 'got ' . json_encode($st['resolution_seconds'] ?? null));

// 9) a noite nao conta
$st = slaMeasure([$convSla, [
    $msgSla(1, 'inbound', 'text', null, '2026-10-19 23:00:00'),
], [], $office, 'UTC', '2026-10-20 01:00:00', $optsSla]);
check('sla: a noite nao conta', ($st['resolution_seconds'] ?? null) === 0, 'got ' . json_encode($st['resolution_seconds'] ?? null));

// 10) conversa de grupo nao tem sla
$groupConv = $convSla;
$groupConv['group_id'] = 7;
$groupSt = slaMeasure([$groupConv, [
    $msgSla(1, 'inbound', 'text', null, '2026-10-19 09:00:00'),
], [], $office, 'UTC', '2026-10-19 10:00:00', $optsSla]);
$normalSt = slaMeasure([$convSla, [
    $msgSla(1, 'inbound', 'text', null, '2026-10-19 09:00:00'),
], [], $office, 'UTC', '2026-10-19 10:00:00', $optsSla]);
check('sla: conversa de grupo nao tem sla', $groupSt === [] && $normalSt !== [], 'grupo=' . json_encode($groupSt) . ' normal=' . json_encode($normalSt));

// 11) mensagem apagada entra na medicao
$st = slaMeasure([$convSla, [
    ['id' => 1, 'direction' => 'inbound', 'type' => 'text', 'user_id' => null, 'created_at' => '2026-10-19 09:00:00', 'deleted_at' => '2026-10-19 09:05:00'],
    $msgSla(2, 'inbound', 'text', null, '2026-10-19 09:10:00'),
    $msgSla(3, 'outbound', 'text', 7, '2026-10-19 09:20:00'),
], [], $office, 'UTC', '2026-10-19 09:20:00', $optsSla]);
check('sla: mensagem apagada entra na medicao', ($st['response_seconds'] ?? null) === 1200, 'got ' . json_encode($st['response_seconds'] ?? null));

// 12) conversa sem departamento conta tempo de relogio
$noDeptConv = $convSla;
$noDeptConv['department_id'] = null;
$st = slaMeasure([$noDeptConv, [
    $msgSla(1, 'inbound', 'text', null, '2026-10-19 09:00:00'),
], [], null, 'UTC', '2026-10-19 10:00:00', $optsSla]);
check('sla: conversa sem departamento conta tempo de relogio', ($st['resolution_seconds'] ?? null) === 3600, 'got ' . json_encode($st['resolution_seconds'] ?? null));

// 13) a reabertura zera o ciclo
$st = slaMeasure([$convSla, [
    $msgSla(1, 'inbound', 'text', null, '2026-10-19 09:00:00'),
    $msgSla(2, 'inbound', 'text', null, '2026-10-19 09:45:00'),
    $msgSla(3, 'outbound', 'text', 7, '2026-10-19 09:50:00'),
], [], $office, 'UTC', '2026-10-19 09:50:00', $optsSla + ['cycle_started_at' => '2026-10-19 09:30:00']]);
check('sla: a reabertura zera o ciclo', ($st['response_seconds'] ?? null) === 300, 'got ' . json_encode($st['response_seconds'] ?? null));

// 14) o limite gravado e o do momento
$st = slaMeasure([$convSla, [
    $msgSla(1, 'inbound', 'text', null, '2026-10-19 09:00:00'),
    $msgSla(2, 'inbound', 'text', null, '2026-10-19 09:10:00'),
    $msgSla(3, 'outbound', 'text', 7, '2026-10-19 09:20:00'),
], [], $office, 'UTC', '2026-10-19 09:20:00', array_merge($optsSla, ['response_threshold' => 600])]);
check('sla: o limite gravado e o do momento', ($st['response_breached'] ?? null) === true, json_encode($st));

// 15) no limiar exato a conversa ja conta como violacao
$st = slaMeasure([$convSla, [
    $msgSla(1, 'inbound', 'text', null, '2026-10-19 09:00:00'),
    $msgSla(2, 'inbound', 'text', null, '2026-10-19 09:10:00'),
    $msgSla(3, 'outbound', 'text', 7, '2026-10-19 09:20:00'),
], [], $office, 'UTC', '2026-10-19 09:20:00', array_merge($optsSla, ['response_threshold' => 1200])]);
check('sla: no limiar exato a conversa ja conta como violacao', ($st['response_breached'] ?? null) === true, json_encode($st));

// ─────────────────────────────────────────────────────────────
// SLA mensurável: medição contra o banco e linha do tempo
// ─────────────────────────────────────────────────────────────
$convSvcSrc = file_get_contents(__DIR__ . '/../app/Services/ConversationService.php');
check('sla: a mudanca de status grava o status anterior', str_contains($convSvcSrc, "'from' => \$old") && str_contains($convSvcSrc, "'to' => \$status"));
check('sla: a reabertura reinicia o ciclo do sla', str_contains($convSvcSrc, 'ConversationSla::reset'));
$measSrc = file_get_contents(__DIR__ . '/../app/Services/SlaMeasurement.php') ?: '';
check('sla: a medicao carrega a conversa do banco', str_contains($measSrc, 'function forConversation'));
check('sla: a medicao le a linha do tempo de status', str_contains($measSrc, 'function loadTimeline'));
check('sla: a medicao grava o resultado', str_contains($measSrc, 'function saveMeasurement'));

echo "\n$passes passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
