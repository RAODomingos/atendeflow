# Dashboard com SLA Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reestruturar o dashboard por ordem de decisão e dar ao SLA duas faixas próprias — "SLA agora" (6 fichas) e "SLA em detalhe" (quebras + violações) — reaproveitando o SLA de medição (histórico) e o SLA de relógio (fila viva).

**Architecture:** Um `DashboardSlaService` novo monta o payload de SLA do dashboard (juntando `ConversationSla` — medição histórica — com `SlaService` — relógio vivo da fila) e devolve um array pronto para a view. A âncora do relógio (`last_in`) sai do `listBaseSql()` privado e vira um fragmento público `Conversation::slaAnchorsSql()`, para inbox e dashboard compartilharem exatamente a mesma regra. Nenhuma migration: a tabela `conversation_slas` já existe.

**Tech Stack:** PHP 8.3 (sem framework, MVC próprio), MySQL, views PHP com SVG inline, JS vanilla inline, runner de testes próprio (`tests/run.php` + e2e com banco).

**Spec:** `docs/superpowers/specs/2026-10-26-dashboard-sla-design.md`

## Global Constraints

- **PHP não está no PATH.** Usar sempre o caminho completo:
  `$php = 'C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe'`
- **Gates:** `& $php tests/run.php` e `& $php tests/e2e_dashboard_sla.php`. Ambos precisam sair com exit 0.
- **Fuso horário:** todo fixture/script chama `date_default_timezone_set((require __DIR__ . '/../config/app.php')['timezone'])` logo após o bootstrap. O PHP CLI roda em UTC; o app grava em `America/Sao_Paulo`. Sem essa linha o fixture sai 3h fora e o relógio do SLA lê "âncora no futuro" — o teste falha sem erro visível.
- **Nunca stagear o repositório inteiro.** A árvore carrega ~86 arquivos de WIP do usuário. Stagear um caminho por vez e conferir com `git diff --cached --name-only` antes de commitar. Nunca `git add -A` nem `git add .`.
- **Identificadores de código sem acento**; texto de interface em pt-BR.
- **Design system: `DESIGN.md` é a fonte da verdade** (indigo `#4f46e5`, Inter). O arquivo `.impeccable/design.json` está **desatualizado** ( Fluent / Segoe UI / `#0078d4`) — não usar.
- **Não tocar** em `app/Services/SlaService.php`, `app/Services/SlaClock.php`, `app/Services/SlaMeasurement.php`. O motor de SLA é o motor; este trabalho só o consome.
- **Sem migration.** A tabela `conversation_slas` já existe (`database/migrations/2026_10_20_sla_measurable.sql`).
- **O período filtra por `c.created_at`** (data de criação da conversa), nunca pela data da violação. É o critério que `ConversationSla::compliance()` já usa.
- **As fichas de compliance medem `sla_kind = 'standard'`** (conversas 1:1). Conversa de grupo tem SLA de menção à parte e não entra.
- **Não-admin nunca executa `agentBreakdown()`** — a guarda é no código, não na view.
- **Contrato de degradação:** o payload sempre traz `visible` (bool) e `reason` (`'disabled'`, `'unavailable'` ou `null`). A página renderiza sem as faixas de SLA quando `visible === false`; nunca dá erro fatal.
- **Parâmetro de período: `?period=7|30|90`**, default `30` — mesmo nome de `/reports/sla` e dos demais relatórios.

## File Structure

| Arquivo | Responsabilidade |
|---|---|
| `app/Models/Conversation.php` | Fragmento público da âncora do relógio + query da fila em risco |
| `app/Models/ConversationSla.php` | Contagem de violações do período |
| `app/Services/DashboardSlaService.php` | **Novo.** Monta o payload de SLA do dashboard (relógio vivo + medição histórica + quebras) |
| `app/Controllers/DashboardController.php` | Lê o período, cacheia por período, entrega o payload à view |
| `app/Controllers/Api/DashboardStatsController.php` | Devolve o mesmo payload no auto-refresh de 20s |
| `app/Views/dashboard/index.php` | Reestruturação das faixas + auto-refresh dos 6 números de SLA |
| `tests/run.php` | Checks estáticos do contrato do dashboard de SLA |
| `tests/e2e_dashboard_sla.php` | **Novo.** E2E com banco: buckets do relógio, compliance, violações, permissão |

**Ordem de execução:** as Tasks 7, 8, 9 e 10 tocam todas em `app/Views/dashboard/index.php` — são sequenciais por nature, nunca em paralelo.

---

### Task 1: Âncora do relógio compartilhada

Extrai o fragmento `last_in` do `listBaseSql()` privado para um método público, para que a inbox e o dashboard compartilhem exatamente a mesma regra de "última inbound / última resposta humana".

**Files:**
- Modify: `app/Models/Conversation.php` (dentro e ao lado de `listBaseSql()`, ~linha 154)
- Modify: `tests/run.php` (nova seção 14, antes do `echo` final)

**Interfaces:**
- Consumes: nada (primeira tarefa).
- Produces: `public static function slaAnchorsSql(): string` — devolve o derivado SQL `(SELECT conversation_id, ... FROM messages GROUP BY conversation_id)`. `listBaseSql()` passa a usá-lo.

- [ ] **Step 1: Escreva o check estático que falha**

Em `tests/run.php`, insira antes de `echo "\n$passes passed, $failures failed\n";`:

```php
// 14) Dashboard com SLA: ancora do relogio compartilhada
$convModelSrc = file_get_contents(__DIR__ . '/../app/Models/Conversation.php');
check(
    'dashboard sla: a ancora do relogio e um fragmento compartilhado',
    str_contains($convModelSrc, 'function slaAnchorsSql')
        && str_contains($convModelSrc, 'self::slaAnchorsSql()')
);
```

- [ ] **Step 2: Rode o teste e confirme que falha**

Run: `& $php tests/run.php`
Expected: `FALHOU - dashboard sla: a ancora do relogio e um fragmento compartilhado`

- [ ] **Step 3: Implemente o fragmento público**

Em `app/Models/Conversation.php`, adicione **antes** do método `listBaseSql()`:

```php
    /**
     * Derived table da âncora do relógio do SLA.
     *
     * Última mensagem do cliente e última resposta HUMANA nossa.
     *
     * 'Humana' = outbound com user_id preenchido (a mensagem de bot/fluxo
     * ou de automação tem user_id nulo e NÃO conta como resposta): é a
     * mesma regra do motor de medição (SlaMeasurement::isHumanReply).
     *
     * Fragmento público porque a inbox (listBaseSql) e o dashboard
     * (openConversationsAwaitingReply) precisam da MESMA âncora: se
     * divergirem, o número do dashboard e a cor da fila contam coisas
     * diferentes.
     */
    public static function slaAnchorsSql(): string
    {
        return "(
                SELECT conversation_id,
                       MAX(CASE WHEN direction = 'inbound' THEN created_at END) as last_inbound_at,
                       MAX(CASE WHEN direction = 'inbound' THEN id END) as last_inbound_id,
                       MAX(CASE WHEN direction = 'outbound' AND user_id IS NOT NULL
                             AND type NOT IN ('internal_note','system') THEN id END) as last_human_id
                FROM messages
                GROUP BY conversation_id
            )";
    }
```

- [ ] **Step 4: Faça `listBaseSql()` usar o fragmento**

Em `app/Models/Conversation.php`, dentro de `listBaseSql()`, substitua o bloco inteiro:

```php
                LEFT JOIN (
                    SELECT conversation_id,
                           MAX(CASE WHEN direction = 'inbound' THEN created_at END) as last_inbound_at,
                           MAX(CASE WHEN direction = 'inbound' THEN id END) as last_inbound_id,
                           MAX(CASE WHEN direction = 'outbound' AND user_id IS NOT NULL
                                 AND type NOT IN ('internal_note','system') THEN id END) as last_human_id
                    FROM messages
                    GROUP BY conversation_id
                ) last_in ON last_in.conversation_id = c.id
```

por:

```php
                LEFT JOIN " . self::slaAnchorsSql() . " last_in ON last_in.conversation_id = c.id
```

O texto da coluna `last_in.*` (as três âncoras) continua idêntico — nada muda no resultado da query da inbox.

- [ ] **Step 5: Rode o teste e confirme que passa**

Run: `& $php tests/run.php`
Expected: `ok - dashboard sla: a ancora do relogio e um fragmento compartilhado` e o resumo final sem falhas. O check `sla fila: o relogio corre mesmo com o bot falando por ultimo` (de `tests/sla_fila_chegada.php`) **precisa** continuar passando: é a regressão que prova que a âncora não mudou de comportamento.

- [ ] **Step 6: Commite**

```bash
git add app/Models/Conversation.php tests/run.php
git diff --cached --name-only
git commit -m "refactor(sla): extrai a ancora do relogio para um fragmento compartilhado"
```

---

### Task 2: Query da fila em risco

Uma query que traz só as conversas cuja bola está com a gente, com as âncoras do relógio.

**Files:**
- Modify: `app/Models/Conversation.php` (após `openConversationsAwaitingReply` → novo método; colocar logo após `listBaseSql()`)
- Create: `tests/e2e_dashboard_sla.php`

**Interfaces:**
- Consumes: `Conversation::slaAnchorsSql()` (Task 1).
- Produces: `public static function openConversationsAwaitingReply(): array` — devolve linhas com `id`, `status`, `inbox_id`, `department_id`, `created_at`, `sla_last_inbound_at`, `sla_last_inbound_id`, `sla_last_human_id`.

- [ ] **Step 1: Crie o arquivo de e2e com o fixture**

Crie `tests/e2e_dashboard_sla.php` com este conteúdo inicial:

```php
<?php
/**
 * E2E do bloco de SLA do dashboard (2026-10-26).
 *
 * Cobre o que a spec 2026-10-26-dashboard-sla-design.md definiu:
 *  - a fila em risco (bucket do relógio vivo)
 *  - o compliance de 1ª resposta e resolução
 *  - a contagem de violações do período
 *  - a permissão: não-admin não recebe a quebra por atendente
 *
 * Roda junto do runner principal (tests/run.php) e tambémstandalone:
 *   php tests/e2e_dashboard_sla.php
 *
 * O fixture desliga o horário comercial da caixa (relógio de parede) e
 * fixa os limites de atenção/alerta, para o teste não depender da hora
 * em que roda.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/autoload.php';
require_once __DIR__ . '/../app/Core/Helper.php';
\App\Config\Environment::load();

// Mesmo fuso do app: o banco guarda os horários em America/Sao_Paulo e o
// relógio do SLA interpreta os timestamps nesse fuso. Sem esta linha o
// fixture sai 3h fora e o relógio lê "âncora no futuro".
$appConfig = require __DIR__ . '/../config/app.php';
date_default_timezone_set($appConfig['timezone'] ?? 'America/Sao_Paulo');

use App\Core\Database;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Services\SlaService;

if (!function_exists('check')) {
    // Standalone: o runner principal não está carregado, então o próprio
    // arquivo define o check e os contadores.
    $GLOBALS['failures'] = 0;
    $GLOBALS['passes'] = 0;
    function check(string $name, bool $cond): void
    {
        if ($cond) {
            $GLOBALS['passes']++;
            echo "ok - $name\n";
        } else {
            $GLOBALS['failures']++;
            echo "FALHOU - $name\n";
        }
    }
}

$db = Database::getInstance();

// Caixa de departamento + um departamento coberto por ela.
$fixture = $db->fetch(
    "SELECT i.id AS inbox_id, d.department_id
       FROM inboxes i
       JOIN inbox_departments d ON d.inbox_id = i.id
      WHERE i.type = 'department'
        AND (i.conversation_scope IS NULL OR i.conversation_scope = '' OR i.conversation_scope = 'all')
      ORDER BY i.id LIMIT 1"
);
if (!$fixture) {
    $fixture = $db->fetch(
        "SELECT i.id AS inbox_id, d.department_id
           FROM inboxes i
           JOIN inbox_departments d ON d.inbox_id = i.id
          WHERE i.type = 'department'
          ORDER BY i.id LIMIT 1"
    );
}

$inboxId = (int) ($fixture['inbox_id'] ?? 0);
$departmentId = (int) ($fixture['department_id'] ?? 0);
$contactId = (int) ($db->fetch("SELECT id FROM contacts WHERE is_group = 0 ORDER BY id LIMIT 1")['id'] ?? 0);
$channelId = (int) ($db->fetch("SELECT id FROM channels ORDER BY id LIMIT 1")['id'] ?? 0);

// Estado anterior da caixa: o teste restaura tudo no final.
$inboxBefore = null;
if ($inboxId > 0) {
    $inboxBefore = $db->fetch(
        "SELECT sla_business_hours, sla_attention_minutes, sla_alert_minutes FROM inboxes WHERE id = ?",
        [$inboxId]
    );
    // Relógio de parede (sem horário comercial) e limites fixos:
    // o teste não pode depender da hora em que roda.
    $db->update('inboxes', [
        'sla_business_hours' => 0,
        'sla_attention_minutes' => 60,
        'sla_alert_minutes' => 120,
    ], 'id = ?', [$inboxId]);
    SlaService::forgetConfig();
}

// ── Duas conversas de fixture ────────────────────────────────────────────
// convA: cliente falou há 10min  → abaixo da atenção (60min) → normal.
// convB: cliente falou há 200min → acima do alerta (120min)   → alerta.
$convA = 0;
$convB = 0;

if ($inboxId > 0 && $contactId > 0 && $channelId > 0 && $departmentId > 0) {
    foreach ([['A', 600, 'convA'], ['B', 12000, 'convB']] as [$tag, $age, $var]) {
        $id = Conversation::create([
            'contact_id'    => $contactId,
            'channel_id'    => $channelId,
            'department_id' => $departmentId,
            'inbox_id'      => $inboxId,
            'subject'       => 'Teste SLA dashboard ' . $tag,
            'status'        => 'new',
            'source'        => 'whatsapp',
        ]);
        if ($id > 0) {
            Conversation::addMessage($id, [
                'direction'  => 'inbound',
                'type'       => 'text',
                'content'    => 'preciso de ajuda',
                'user_id'    => null,
                'created_at' => date('Y-m-d H:i:s', time() - $age),
                'is_read'    => 1,
            ]);
            if ($tag === 'A') { $convA = $id; } else { $convB = $id; }
        }
    }
}

// ── Asserções: a query da fila em risco ─────────────────────────────────
$rows = Conversation::openConversationsAwaitingReply();
$byId = [];
foreach ($rows as $r) {
    $byId[(int) $r['id']] = $r;
}

check(
    'dashboard sla: a conversa dentro do limite entra na fila em risco',
    isset($byId[$convA]),
    'convA=' . $convA
);
check(
    'dashboard sla: a conversa estourada entra na fila em risco',
    isset($byId[$convB]),
    'convB=' . $convB
);
check(
    'dashboard sla: a fila em risco traz as ancoras do relogio',
    isset($byId[$convB]) && ($byId[$convB]['sla_last_inbound_id'] ?? 0) > 0
);
check(
    'dashboard sla: a fila em risco traz inbox e departamento',
    isset($byId[$convB])
        && (int) ($byId[$convB]['inbox_id'] ?? 0) === $inboxId
        && (int) ($byId[$convB]['department_id'] ?? 0) === $departmentId
);

// ── Limpeza ─────────────────────────────────────────────────────────────
foreach ([$convA, $convB] as $id) {
    if ($id > 0) {
        $db->delete('messages', 'conversation_id = ?', [$id]);
        $db->delete('conversation_events', 'conversation_id = ?', [$id]);
        $db->delete('notifications', 'conversation_id = ?', [$id]);
        $db->delete('conversation_slas', 'conversation_id = ?', [$id]);
        $db->delete('conversations', 'id = ?', [$id]);
    }
}
if ($inboxId > 0 && $inboxBefore !== null) {
    $db->update('inboxes', [
        'sla_business_hours' => $inboxBefore['sla_business_hours'],
        'sla_attention_minutes' => $inboxBefore['sla_attention_minutes'],
        'sla_alert_minutes' => $inboxBefore['sla_alert_minutes'],
    ], 'id = ?', [$inboxId]);
    SlaService::forgetConfig();
}

// ── Resumo ───────────────────────────────────────────────────────────────
// Quando o arquivo é carregado pelo runner principal, quem fecha o
// processo é o runner (com o total de todos os testes). Standalone, este
// arquivo fecha com o próprio total.
if (!function_exists('check')) {
    echo "\n" . $GLOBALS['passes'] . " passed, " . $GLOBALS['failures'] . " failed\n";
    exit($GLOBALS['failures'] > 0 ? 1 : 0);
}
```

- [ ] **Step 2: Rode o e2e e confirme que falha**

Run: `& $php tests/e2e_dashboard_sla.php`
Expected: `FALHOU - dashboard sla: a conversa dentro do limite entra na fila em risco` (o método ainda não existe → erro fatal "Call to undefined method"). O erro fatal **é** a falha esperada neste passo.

- [ ] **Step 3: Implemente a query**

Em `app/Models/Conversation.php`, adicione logo após o fim de `listBaseSql()`:

```php
    /**
     * Conversas abertas cuja bola está com a gente, com as âncoras do
     * relógio do SLA.
     *
     * Só o que o relógio precisa: status, caixa, departamento e as
     * âncoras (última inbound / última resposta humana). Sem os JOINs
     * pesados da listagem — o dashboard quer o relógio, não a lista.
     *
     * Filtros no SQL (o cálculo em PHP fica barato):
     *  - status ativo, mas sem `waiting_internal`: esse status congela o
     *    relógio (a bola está com outro setor);
     *  - `last_human_id <= last_inbound_id`: o atendente ainda não
     *    respondeu depois do cliente. Conversa sem inbound usa a criação
     *    como âncora (mesma regra de SlaService::clockState).
     *
     * @return array<int, array>
     */
    public static function openConversationsAwaitingReply(): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT c.id, c.status, c.inbox_id, c.department_id, c.created_at,
                    last_in.last_inbound_at as sla_last_inbound_at,
                    last_in.last_inbound_id as sla_last_inbound_id,
                    last_in.last_human_id as sla_last_human_id
             FROM conversations c
             LEFT JOIN " . self::slaAnchorsSql() . " last_in ON last_in.conversation_id = c.id
             WHERE c.status IN ('new','open','waiting_customer')
               AND (
                    last_in.last_inbound_id IS NULL
                    OR last_in.last_human_id IS NULL
                    OR last_in.last_human_id <= last_in.last_inbound_id
               )"
        );
    }
```

- [ ] **Step 4: Rode o e2e e confirme que passa**

Run: `& $php tests/e2e_dashboard_sla.php`
Expected: 4 linhas `ok - dashboard sla: ...` e nenhum `FALHOU`.

- [ ] **Step 5: Registre o e2e no runner principal**

Em `tests/run.php`, insira antes de `echo "\n$passes passed, $failures failed\n";`:

```php
// 15) Dashboard com SLA: fila em risco, compliance, violacoes e permissao
require_once __DIR__ . '/e2e_dashboard_sla.php';
```

O e2e define o próprio `check()` apenas quando o runner não está carregado (`function_exists`), e não chama `exit` nesse caso — quem fecha o processo continua sendo o runner.

- [ ] **Step 6: Commite**

```bash
git add app/Models/Conversation.php tests/e2e_dashboard_sla.php tests/run.php
git diff --cached --name-only
git commit -m "feat(sla): query da fila em risco com as ancoras do relogio"
```

---

### Task 3: Contagem de violações do período

`violatedConversations()` devolve linhas com `LIMIT`; a ficha do dashboard precisa do total.

**Files:**
- Modify: `app/Models/ConversationSla.php` (antes de `violatedConversations()`, ~linha 527)
- Modify: `tests/e2e_dashboard_sla.php` (nova asserção)

**Interfaces:**
- Consumes: nada novo.
- Produces: `public static function violatedCount(int $days): int`.

- [ ] **Step 1: Escreva a asserção que falha**

Em `tests/e2e_dashboard_sla.php`, insira **antes** do bloco `// ── Limpeza`:

```php
// ── Asserções: a contagem de violações ─────────────────────────────────
$violatedCount = \App\Models\ConversationSla::violatedCount(30);
$violatedList = \App\Models\ConversationSla::violatedConversations(30, 200);
check(
    'dashboard sla: a contagem de violacoes bate com a lista',
    $violatedCount === count($violatedList),
    'count=' . $violatedCount . ' lista=' . count($violatedList)
);
```

- [ ] **Step 2: Rode o e2e e confirme que falha**

Run: `& $php tests/e2e_dashboard_sla.php`
Expected: erro fatal `Call to undefined method App\Models\ConversationSla::violatedCount()`.

- [ ] **Step 3: Implemente o método**

Em `app/Models/ConversationSla.php`, insira antes do método `violatedConversations()`:

```php
    /**
     * Conversas com qualquer SLA furado no período.
     *
     * Mesma população de violatedConversations(), mas em contagem: a
     * ficha do dashboard precisa do total, a lista devolve linhas.
     */
    public static function violatedCount(int $days): int
    {
        $row = Database::getInstance()->fetch(
            "SELECT COUNT(*) c FROM conversation_slas s
             JOIN conversations c ON c.id = s.conversation_id
             WHERE c.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
               AND (s.response_breached = 1 OR s.resolution_breached = 1)
               AND s.sla_kind = 'standard'",
            [max(1, $days)]
        );
        return (int) ($row['c'] ?? 0);
    }
```

- [ ] **Step 4: Rode o e2e e confirme que passa**

Run: `& $php tests/e2e_dashboard_sla.php`
Expected: `ok - dashboard sla: a contagem de violacoes bate com a lista`.

- [ ] **Step 5: Commite**

```bash
git add app/Models/ConversationSla.php tests/e2e_dashboard_sla.php
git diff --cached --name-only
git commit -m "feat(sla): contagem de violacoes do periodo para o dashboard"
```

---

### Task 4: `DashboardSlaService`

O service que junta SLA de medição (histórico) e SLA de relógio (fila viva) num payload pronto para a view.

**Files:**
- Create: `app/Services/DashboardSlaService.php`
- Modify: `tests/e2e_dashboard_sla.php` (asserções dos buckets e do payload)

**Interfaces:**
- Consumes: `Conversation::openConversationsAwaitingReply()` (Task 2), `ConversationSla::violatedCount()` (Task 3), `SlaService::applyClock()`, `SlaService::levelFor()`, `SlaService::config()`, `ConversationSla::compliance()`, `criticalNow()`, `departmentBreakdown()`, `inboxBreakdown()`, `agentBreakdown()`, `violatedConversations()`.
- Produces:
  - `public static function payload(int $period, bool $isAdmin): array`
  - `public static function liveRisk(): array`
  - `public static function avgMeasuredResponse(int $period): ?float`
  - `public static function isEnabled(): bool`
  - `public static function normalizePeriod(int $period): int`
  - `public const REASON_DISABLED = 'disabled'`, `public const REASON_UNAVAILABLE = 'unavailable'`

- [ ] **Step 1: Escreva as asserções que falham**

Em `tests/e2e_dashboard_sla.php`, insira **antes** do bloco `// ── Limpeza`:

```php
// ── Asserções: os buckets do relogio vivo ──────────────────────────────
$risk = \App\Services\DashboardSlaService::liveRisk();
check(
    'dashboard sla: o relogio vivo devolve os tres buckets',
    isset($risk['now'], $risk['alert'], $risk['attention'], $risk['normal'], $risk['total']),
    json_encode($risk)
);
check(
    'dashboard sla: a conversa estourada cai no bucket de alerta',
    ($risk['alert'] ?? 0) >= 1,
    'alert=' . ($risk['alert'] ?? '-')
);
check(
    'dashboard sla: a conversa dentro do limite nao entra em risco',
    ($risk['now'] ?? 0) <= ($risk['total'] ?? 0)
);

// ── Asserções: o payload ────────────────────────────────────────────────
$payload = \App\Services\DashboardSlaService::payload(30, true);
check(
    'dashboard sla: o payload traz o contrato de degradacao',
    array_key_exists('visible', $payload) && array_key_exists('reason', $payload)
);
check(
    'dashboard sla: o payload admin traz a quebra por atendente',
    is_array($payload['byAgent'] ?? null)
);
check(
    'dashboard sla: o payload traz as quebras por departamento e caixa',
    is_array($payload['byDepartment'] ?? null) && is_array($payload['byInbox'] ?? null)
);
check(
    'dashboard sla: o payload traz as violacoes do periodo',
    is_array($payload['violations'] ?? null)
);

$payloadUser = \App\Services\DashboardSlaService::payload(30, false);
check(
    'dashboard sla: o payload de nao-admin NAO traz a quebra por atendente',
    ($payloadUser['byAgent'] ?? null) === []
);

check(
    'dashboard sla: o periodo invalido cai no default de 30 dias',
    \App\Services\DashboardSlaService::normalizePeriod(45) === 30
        && \App\Services\DashboardSlaService::normalizePeriod(7) === 7
        && \App\Services\DashboardSlaService::normalizePeriod(90) === 90
);
```

- [ ] **Step 2: Rode o e2e e confirme que falha**

Run: `& $php tests/e2e_dashboard_sla.php`
Expected: erro fatal `Class "App\Services\DashboardSlaService" not found`.

- [ ] **Step 3: Crie o service**

Crie `app/Services/DashboardSlaService.php`:

```php
<?php

namespace App\Services;

use App\Core\Database;
use App\Models\Conversation;
use App\Models\ConversationSla;

/**
 * Monta o payload de SLA do dashboard.
 *
 * Uma unidade, uma responsabilidade: juntar o SLA de medição (histórico,
 * em `conversation_slas`) com o SLA de relógio (fila viva, `SlaService`)
 * e devolver o array que a view consome.
 *
 * Fica fora do `SlaService` (que é o motor) e do controller (que já
 * carrega 15+ queries): a view não monta dado, e o controller não
 * conhece regra de SLA.
 *
 * Contrato de degradação: `visible` diz se a faixa de SLA aparece;
 * `reason` diz por quê quando não aparece — 'disabled' (SLA desligado
 * na configuração) ou 'unavailable' (a tabela de medição não pôde ser
 * lida). São causas diferentes e o usuário precisa de mensagens
 * diferentes.
 */
class DashboardSlaService
{
    /** SLA desligado na configuração. */
    public const REASON_DISABLED = 'disabled';

    /** Tabela de medição ausente ou query quebrada. */
    public const REASON_UNAVAILABLE = 'unavailable';

    /** Períodos aceitos no seletor. */
    public const PERIODS = [7, 30, 90];

    /**
     * Payload completo da faixa "SLA agora" + dados da faixa de detalhe.
     *
     * @param int  $period  7, 30 ou 90 dias
     * @param bool $isAdmin inclui a quebra por atendente
     */
    public static function payload(int $period, bool $isAdmin): array
    {
        $period = self::normalizePeriod($period);

        if (!self::isEnabled()) {
            $payload = self::emptyPayload($period);
            $payload['reason'] = self::REASON_DISABLED;
            return $payload;
        }

        try {
            $compliance = ConversationSla::compliance($period);
            $risk = self::liveRisk();

            $payload = [
                'visible' => true,
                'reason' => null,
                'period' => $period,
                'risk' => $risk,
                'breachedOpen' => ConversationSla::criticalNow(),
                'response' => $compliance['response'],
                'resolution' => $compliance['resolution'],
                'violated' => ConversationSla::violatedCount($period),
                'measured' => (int) $compliance['response']['measured'],
                'byDepartment' => self::withActiveCounts(
                    ConversationSla::departmentBreakdown($period),
                    self::activeCounts('department')
                ),
                'byInbox' => self::withActiveCounts(
                    ConversationSla::inboxBreakdown($period),
                    self::activeCounts('inbox')
                ),
                'byAgent' => [],
                'violations' => ConversationSla::violatedConversations($period, 8),
            ];

            // A quebra por atendente é comparação de desempenho entre
            // colegas: só o admin vê. A query nem é executada para o
            // atendente comum.
            if ($isAdmin) {
                $payload['byAgent'] = ConversationSla::agentBreakdown($period);
            }

            return $payload;
        } catch (\Throwable $e) {
            // Tabela de medição ausente (migração não aplicada) ou query
            // quebrada: o dashboard continua de pé, sem o bloco de SLA.
            $payload = self::emptyPayload($period);
            $payload['reason'] = self::REASON_UNAVAILABLE;
            return $payload;
        }
    }

    /**
     * Distribuição das conversas abertas pelo nível do relógio do SLA.
     *
     * Usa exatamente a mesma matemática da inbox: `applyClock()` (conta
     * só segundos úteis, com a grade do departamento) e `levelFor()`
     * (limites por caixa). Se este número divergir da cor da fila, o
     * dashboard mente.
     *
     * @return array{now: int, alert: int, attention: int, normal: int, total: int}
     */
    public static function liveRisk(): array
    {
        $buckets = ['now' => 0, 'alert' => 0, 'attention' => 0, 'normal' => 0, 'total' => 0];

        $rows = Conversation::openConversationsAwaitingReply();
        if (!$rows) {
            return $buckets;
        }

        $rows = SlaService::applyClock($rows);

        foreach ($rows as $row) {
            $level = SlaService::levelFor(
                (int) ($row['waiting_seconds'] ?? 0),
                $row['status'] ?? null,
                null,
                !empty($row['inbox_id']) ? (int) $row['inbox_id'] : null
            );
            $buckets['total']++;
            if ($level === SlaService::LEVEL_ALERT) {
                $buckets['alert']++;
            } elseif ($level === SlaService::LEVEL_ATTENTION) {
                $buckets['attention']++;
            } elseif ($level === SlaService::LEVEL_NORMAL) {
                $buckets['normal']++;
            }
        }

        $buckets['now'] = $buckets['alert'] + $buckets['attention'];
        return $buckets;
    }

    /**
     * Média medida de 1ª resposta, em minutos.
     *
     * Vem de `conversation_slas` — a mesma base das porcentagens de
     * compliance. A média bruta de `messages` que existia no dashboard
     * contava resposta de bot e podia dar negativo (o `MIN(outbound)`
     * podia ser anterior à última inbound).
     */
    public static function avgMeasuredResponse(int $period): ?float
    {
        try {
            $row = Database::getInstance()->fetch(
                "SELECT AVG(response_seconds) as avg_seconds
                 FROM conversation_slas s
                 JOIN conversations c ON c.id = s.conversation_id
                 WHERE c.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                   AND s.sla_kind = 'standard'
                   AND s.response_seconds IS NOT NULL",
                [max(1, $period)]
            );
        } catch (\Throwable $e) {
            return null;
        }

        $avg = $row['avg_seconds'] ?? null;
        return $avg === null ? null : round((float) $avg / 60, 1);
    }

    /**
     * O relógio do SLA está ligado?
     *
     * A configuração global decide; a caixa pode desligar, mas não
     * liga — se o global está off, não há relógio.
     */
    public static function isEnabled(): bool
    {
        return (string) (SlaService::config()['enabled'] ?? '1') === '1';
    }

    /** Período válido, ou o default de 30 dias. */
    public static function normalizePeriod(int $period): int
    {
        return in_array($period, self::PERIODS, true) ? $period : 30;
    }

    /**
     * Conversas ativas por dimensão.
     *
     * A chave é o rótulo, para casar com `ConversationSla::breakdown()`:
     * as duas consultas precisam falar do mesmo departamento/caixa com
     * o mesmo nome.
     */
    private static function activeCounts(string $dimension): array
    {
        $map = [
            'department' => ['LEFT JOIN departments d ON d.id = c.department_id', "COALESCE(d.name, 'Sem departamento')"],
            'inbox' => ['LEFT JOIN inboxes i ON i.id = c.inbox_id', "COALESCE(i.name, 'Sem caixa')"],
        ];
        if (!isset($map[$dimension])) {
            return [];
        }
        [$join, $label] = $map[$dimension];

        $rows = Database::getInstance()->fetchAll(
            "SELECT {$label} as label, COUNT(c.id) as active
             FROM conversations c
             {$join}
             WHERE c.status NOT IN ('closed','spam','resolved')
             GROUP BY label"
        );

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['label']] = (int) $r['active'];
        }
        return $out;
    }

    /** Junta o breakdown de compliance com as conversas ativas. */
    private static function withActiveCounts(array $rows, array $active): array
    {
        foreach ($rows as &$r) {
            $r['active'] = $active[$r['label']] ?? 0;
        }
        unset($r);
        return $rows;
    }

    /** Payload vazio, com o contrato de degradação já preenchido. */
    private static function emptyPayload(int $period): array
    {
        return [
            'visible' => false,
            'reason' => null,
            'period' => $period,
            'risk' => ['now' => 0, 'alert' => 0, 'attention' => 0, 'normal' => 0, 'total' => 0],
            'breachedOpen' => 0,
            'response' => ['percent' => null, 'breached' => 0, 'measured' => 0],
            'resolution' => ['percent' => null, 'breached' => 0, 'measured' => 0],
            'violated' => 0,
            'measured' => 0,
            'byDepartment' => [],
            'byInbox' => [],
            'byAgent' => [],
            'violations' => [],
        ];
    }
}
```

- [ ] **Step 4: Rode o e2e e confirme que passa**

Run: `& $php tests/e2e_dashboard_sla.php`
Expected: todas as linhas `ok - dashboard sla: ...`, nenhum `FALHOU`.

- [ ] **Step 5: Commite**

```bash
git add app/Services/DashboardSlaService.php tests/e2e_dashboard_sla.php
git diff --cached --name-only
git commit -m "feat(sla): service que monta o payload de SLA do dashboard"
```

---

### Task 5: Controller do dashboard — período, cache e payload

**Files:**
- Modify: `app/Controllers/DashboardController.php`
- Modify: `tests/run.php` (checks estáticos do cache e do service)

**Interfaces:**
- Consumes: `DashboardSlaService::payload()`, `DashboardSlaService::normalizePeriod()`, `DashboardSlaService::avgMeasuredResponse()` (Task 4).
- Produces: a view recebe `$sla` (o payload) e `$slaPeriod` (int).

- [ ] **Step 1: Escreva os checks estáticos que falham**

Em `tests/run.php`, na seção 14 (criada na Task 1), acrescente:

```php
$dashCtlSrc = file_get_contents(__DIR__ . '/../app/Controllers/DashboardController.php');
$slaSvcSrc = file_get_contents(__DIR__ . '/../app/Services/DashboardSlaService.php');
check(
    'dashboard sla: o controller entrega o payload do service',
    str_contains($dashCtlSrc, 'DashboardSlaService::payload')
);
check(
    'dashboard sla: o periodo entra na chave de cache',
    str_contains($dashCtlSrc, "':sla' . \$period")
);
check(
    'dashboard sla: a quebra por atendente so roda para admin',
    str_contains($slaSvcSrc, 'if ($isAdmin)') && str_contains($slaSvcSrc, "['byAgent']")
);
check(
    'dashboard sla: o service mede a resposta media pela medicao',
    str_contains($slaSvcSrc, 'AVG(response_seconds)')
);
```

- [ ] **Step 2: Rode o teste e confirme que falha**

Run: `& $php tests/run.php`
Expected: `FALHOU - dashboard sla: o controller entrega o payload do service`

- [ ] **Step 3: Leia o período e inclua-o na chave de cache**

Em `app/Controllers/DashboardController.php`, no topo do método `index()`, logo após `$isAdmin = Auth::isAdmin();`, insira:

```php
        // Período do bloco de SLA: 7, 30 ou 90 dias (default 30).
        $period = DashboardSlaService::normalizePeriod((int) $request->get('period', 30));
```

E troque o bloco do cache:

```php
        $data = \App\Core\FileCache::remember(
            'dashboard:' . $userId . ':' . ($isAdmin ? 'admin' : 'user'),
            function () use ($userId, $isAdmin) {
```

por:

```php
        $data = \App\Core\FileCache::remember(
            'dashboard:' . $userId . ':' . ($isAdmin ? 'admin' : 'user') . ':sla' . $period,
            function () use ($userId, $isAdmin, $period) {
```

- [ ] **Step 4: Troque a média bruta pela média medida**

Em `app/Controllers/DashboardController.php`, **remova** o bloco inteiro:

```php
        // --- Avg response time (30 days) ---
        $avgResponse = $db->fetch(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, inbound_time, outbound_time)) as avg_minutes
             FROM (
                 SELECT m.conversation_id,
                        MAX(CASE WHEN m.direction = 'inbound' THEN m.created_at END) as inbound_time,
                        MIN(CASE WHEN m.direction = 'outbound' AND m.type = 'text' THEN m.created_at END) as outbound_time
                 FROM messages m
                 WHERE m.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                 GROUP BY m.conversation_id
                 HAVING inbound_time IS NOT NULL AND outbound_time IS NOT NULL
             ) sub"
        );
        $avgResponseTime = $avgResponse && $avgResponse['avg_minutes'] !== null
            ? round((float) $avgResponse['avg_minutes']) : null;
```

e, no mesmo lugar, escreva:

```php
        // --- Tempo médio de resposta (medido) ---
        // Média de `conversation_slas`, a mesma base das porcentagens de
        // compliance. A média bruta de `messages` contava resposta de bot
        // e podia dar negativo.
        $avgResponseTime = DashboardSlaService::avgMeasuredResponse($period);
```

- [ ] **Step 5: Entregue o payload à view**

Em `app/Controllers/DashboardController.php`, no `return [...]` do controller, **remova**:

```php
            'slaCriticalNow' => \App\Models\ConversationSla::criticalNow(),
            'slaCompliance30' => \App\Models\ConversationSla::compliance(30)['response']['percent'] ?? null,
```

e acrescente, no mesmo lugar:

```php
            'sla' => DashboardSlaService::payload($period, $isAdmin),
            'slaPeriod' => $period,
```

- [ ] **Step 6: Importe o service**

No topo de `app/Controllers/DashboardController.php`, acrescente o `use`:

```php
use App\Services\DashboardSlaService;
```

Logo abaixo de `use App\Models\Inbox;`.

- [ ] **Step 7: Rode os testes e confirme que passam**

Run: `& $php tests/run.php` e `& $php tests/e2e_dashboard_sla.php`
Expected: ambos com 0 falhas. (A view ainda não usa `$sla` — ela só recebe dados a mais por enquanto; o erro de "variável indefinida" só aparece na Task 7, e a view usa `?? ` nos acessos.)

- [ ] **Step 8: Commite**

```bash
git add app/Controllers/DashboardController.php tests/run.php
git diff --cached --name-only
git commit -m "feat(dashboard): entrega o payload de SLA e perioda o cache"
```

---

### Task 6: API de auto-refresh

Sem isso, o auto-refresh de 20s mantém o SLA velho na tela enquanto o resto do dashboard se atualiza.

**Files:**
- Modify: `app/Controllers/Api/DashboardStatsController.php`
- Modify: `tests/run.php` (check estático)

**Interfaces:**
- Consumes: `DashboardSlaService::payload()` (Task 4).
- Produces: a API devolve a chave `sla` (o payload) e `slaPeriod` (int).

- [ ] **Step 1: Escreva o check estático que falha**

Em `tests/run.php`, na seção 14, acrescente:

```php
$dashApiSrc = file_get_contents(__DIR__ . '/../app/Controllers/Api/DashboardStatsController.php');
check(
    'dashboard sla: a api devolve o payload de sla',
    str_contains($dashApiSrc, "'sla' =>") && str_contains($dashApiSrc, 'DashboardSlaService::payload')
);
check(
    'dashboard sla: a api perioda o cache',
    str_contains($dashApiSrc, "':sla' . \$period")
);
```

- [ ] **Step 2: Rode o teste e confirme que falha**

Run: `& $php tests/run.php`
Expected: `FALHOU - dashboard sla: a api devolve o payload de sla`

- [ ] **Step 3: Perioda o cache da API**

Em `app/Controllers/Api/DashboardStatsController.php`, troque:

```php
        $userId = Auth::id();

        // Mesmo cache de 30s do dashboard (auto-refresh a cada 20s).
        $data = \App\Core\FileCache::remember(
            'dashboard-stats:' . $userId,
            function () use ($userId) {
```

por:

```php
        $userId = Auth::id();
        $isAdmin = Auth::isAdmin();

        // Mesmo período do dashboard: o bloco de SLA da tela e o da API
        // precisam falar do mesmo recorte.
        $period = DashboardSlaService::normalizePeriod((int) $request->get('period', 30));

        // Mesmo cache de 30s do dashboard (auto-refresh a cada 20s).
        $data = \App\Core\FileCache::remember(
            'dashboard-stats:' . $userId . ':sla' . $period,
            function () use ($userId, $isAdmin, $period) {
```

- [ ] **Step 4: Troque as fichas de SLA antigas pelo payload**

Em `app/Controllers/Api/DashboardStatsController.php`, **remova**:

```php
        // SLA: conversas ativas que já furaram o SLA de 1ª resposta e
        // compliance dos últimos 30 dias.
        $slaCriticalNow = \App\Models\ConversationSla::criticalNow();
        $slaCompliance = \App\Models\ConversationSla::compliance(30);
```

e acrescente, no mesmo lugar:

```php
        // SLA: o payload inteiro (relógio vivo + medição + quebras).
        $sla = DashboardSlaService::payload($period, $isAdmin);
```

Depois, no `return [...]`, **remova**:

```php
            'slaCriticalNow' => $slaCriticalNow,
            'slaCompliance30' => $slaCompliance['response']['percent'] ?? null,
```

e acrescente:

```php
            'sla' => $sla,
            'slaPeriod' => $period,
```

- [ ] **Step 5: Importe o service**

No topo de `app/Controllers/Api/DashboardStatsController.php`, acrescente:

```php
use App\Services\DashboardSlaService;
```

Logo abaixo de `use App\Models\User;`.

- [ ] **Step 6: Rode os testes e confirme que passam**

Run: `& $php tests/run.php` e `& $php tests/e2e_dashboard_sla.php`
Expected: ambos com 0 falhas.

- [ ] **Step 7: Commite**

```bash
git add app/Controllers/Api/DashboardStatsController.php tests/run.php
git diff --cached --name-only
git commit -m "feat(dashboard): a api de auto-refresh devolve o payload de SLA"
```

---

### Task 7: View — header e faixa "SLA agora"

**Files:**
- Modify: `app/Views/dashboard/index.php`

**Interfaces:**
- Consumes: `$sla` (payload), `$slaPeriod` (int) — entregues pelo controller (Task 5).

- [ ] **Step 1: Ponha o período no container e restaure o título do header**

Em `app/Views/dashboard/index.php`, troque:

```php
<div class="dashboard" id="dashboardApp">
    <!-- Header -->
    <div class="dash-header" style="justify-content:flex-end">
        <div class="dash-header-actions">
```

por:

```php
<div class="dashboard" id="dashboardApp" data-sla-period="<?= (int) ($slaPeriod ?? 30) ?>">
    <!-- Header -->
    <div class="dash-header">
        <div>
            <div class="dash-title"><?= e($greeting) ?><?= $greetName !== '' ? ', ' . e($greetName) : '' ?></div>
            <div class="dash-subtitle"><?= e($todayName) ?>, <?= e($todayPt) ?></div>
        </div>
        <div class="dash-header-actions">
```

`dash-title` e `dash-subtitle` já existem no CSS da view (linhas 688-689); o wrapper é um `div` sem classe porque não há `.dash-title-block` definido — não invente classe que não tem regra.

- [ ] **Step 2: Crie a faixa "SLA agora"**

Em `app/Views/dashboard/index.php`, insira a faixa **imediatamente depois** do bloco `<?php endif; ?>` que fecha o banner de menções de grupo (ou seja, logo antes de `<!-- Operação agora: atendimento normal x grupos -->`):

```php
    <!-- SLA agora: o relógio vivo + a medição do período -->
    <?php if (!empty($sla['visible'])): ?>
    <div class="dash-section-title"><span>SLA agora</span><a href="<?= url('reports/sla') ?>?period=<?= (int) $slaPeriod ?>" class="dash-section-link">Relatório de SLA <i class="fas fa-arrow-right"></i></a></div>
    <div class="stats-grid dash-stats dash-stats-6">
        <div class="stat-card stat-hero">
            <div class="stat-icon stat-icon-danger"><i class="fas fa-fire"></i></div>
            <div class="stat-info">
                <span class="stat-value" id="slaRiskNow" data-count="<?= (int) $sla['risk']['now'] ?>"><?= (int) $sla['risk']['now'] ?></span>
                <span class="stat-label">Em risco agora</span>
                <span class="stat-sub" id="slaRiskSub"><?= (int) $sla['risk']['alert'] ?> em alerta &middot; <?= (int) $sla['risk']['attention'] ?> em atenção</span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon stat-icon-danger"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="stat-info">
                <span class="stat-value" id="slaBreachedOpen" data-count="<?= (int) $sla['breachedOpen'] ?>"><?= (int) $sla['breachedOpen'] ?></span>
                <span class="stat-label">SLA já furado</span>
                <span class="stat-sub">1ª resposta furada, ainda abertas</span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon stat-icon-success"><i class="fas fa-bolt"></i></div>
            <div class="stat-info">
                <span class="stat-value" id="slaResponsePct" data-count="<?= $sla['response']['percent'] ?? 0 ?>" data-decimals="1" data-empty="<?= ($sla['response']['percent'] ?? null) === null ? '1' : '0' ?>">
                    <?= ($sla['response']['percent'] ?? null) !== null
                        ? rtrim(rtrim(number_format((float) $sla['response']['percent'], 1, ',', '.'), '0'), ',') . '%'
                        : '—' ?>
                </span>
                <span class="stat-label">1ª resposta no prazo</span>
                <span class="stat-sub"><?= (int) $sla['response']['breached'] ?> furaram</span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon stat-icon-success"><i class="fas fa-flag-checkered"></i></div>
            <div class="stat-info">
                <span class="stat-value" id="slaResolutionPct" data-count="<?= $sla['resolution']['percent'] ?? 0 ?>" data-decimals="1" data-empty="<?= ($sla['resolution']['percent'] ?? null) === null ? '1' : '0' ?>">
                    <?= ($sla['resolution']['percent'] ?? null) !== null
                        ? rtrim(rtrim(number_format((float) $sla['resolution']['percent'], 1, ',', '.'), '0'), ',') . '%'
                        : '—' ?>
                </span>
                <span class="stat-label">Resolução no prazo</span>
                <span class="stat-sub"><?= (int) $sla['resolution']['breached'] ?> furaram</span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon stat-icon-violet"><i class="fas fa-list"></i></div>
            <div class="stat-info">
                <span class="stat-value" id="slaViolations" data-count="<?= (int) $sla['violated'] ?>"><?= (int) $sla['violated'] ?></span>
                <span class="stat-label">Violações no período</span>
                <span class="stat-sub">qualquer SLA furado em <?= (int) $slaPeriod ?>d</span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon stat-icon-primary"><i class="fas fa-stopwatch"></i></div>
            <div class="stat-info">
                <span class="stat-value" id="slaMeasured" data-count="<?= (int) $sla['measured'] ?>"><?= (int) $sla['measured'] ?></span>
                <span class="stat-label">Conversas medidas</span>
                <span class="stat-sub">com SLA registrado</span>
            </div>
        </div>
    </div>
    <?php endif; ?>
```

- [ ] **Step 3: Remova a ficha "SLA crítico agora" de "Operação agora"**

Em `app/Views/dashboard/index.php`, **remova** o bloco inteiro do `stat-card` que contém `id="slaCriticalNow"` (do `<div class="stat-icon stat-icon-danger"><i class="fas fa-stopwatch"></i></div>` até o `</div>` do `stat-info`).

A faixa "Operação agora" fica com 5 fichas: Normal em aberto, Grupos em aberto, Novos aguardando, Atendentes online, Resolvidos hoje.

- [ ] **Step 4: Dê a grade de 5 colunas à faixa "Operação agora"**

Em `app/Views/dashboard/index.php`, troque:

```php
    <div class="stats-grid dash-stats dash-stats-6">
        <div class="stat-card stat-hero">
```

por:

```php
    <div class="stats-grid dash-stats dash-stats-5">
        <div class="stat-card stat-hero">
```

E, no `<style>` da view, troque:

```css
@media(max-width:1400px){.dash-stats-6{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:1100px){.dash-stats-4{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:768px){.dash-stats-6{grid-template-columns:repeat(2,minmax(0,1fr))}.dash-stats-4{grid-template-columns:repeat(2,minmax(0,1fr))}}
```

por:

```css
@media(max-width:1400px){.dash-stats-6{grid-template-columns:repeat(3,minmax(0,1fr))}.dash-stats-5{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:1100px){.dash-stats-4{grid-template-columns:repeat(2,minmax(0,1fr))}.dash-stats-5{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:768px){.dash-stats-6{grid-template-columns:repeat(2,minmax(0,1fr))}.dash-stats-4{grid-template-columns:repeat(2,minmax(0,1fr))}.dash-stats-5{grid-template-columns:repeat(2,minmax(0,1fr))}}
```

- [ ] **Step 5: Atualize o texto de apoio da "Resposta média"**

Em `app/Views/dashboard/index.php`, na ficha "Resposta média" (que tem `id="statAvgResp"`), troque:

```php
                <?php if ($avgResponseTime !== null): ?>
                    <span class="stat-sub">últimos 30 dias <span class="stat-suffix">min</span></span>
                <?php endif; ?>
```

por:

```php
                <?php if ($avgResponseTime !== null): ?>
                    <span class="stat-sub">média medida · <?= (int) ($slaPeriod ?? 30) ?>d <span class="stat-suffix">min</span></span>
                <?php endif; ?>
```

- [ ] **Step 6: Verifique no navegador**

Abra o dashboard (rota `/`) e confirme:
- o header mostra "Bom dia, <seu nome>" e a data;
- a faixa "SLA agora" aparece logo abaixo dos banners, com 6 fichas;
- "Operação agora" tem 5 fichas e nenhuma sobra na segunda linha;
- o "Relatório de SLA" da faixa aponta para `/reports/sla?period=30`.

Se a faixa "SLA agora" não aparecer, o `visible` está `false`: confira se a tabela `conversation_slas` existe na base e se `sla_enabled` está ligado em `/settings`.

- [ ] **Step 7: Commite**

```bash
git add app/Views/dashboard/index.php
git diff --cached --name-only
git commit -m "feat(dashboard): faixa SLA agora com o relogio vivo e o compliance"
```

---

### Task 8: View — faixa "SLA em detalhe"

**Files:**
- Modify: `app/Views/dashboard/index.php`

**Interfaces:**
- Consumes: `$sla['byDepartment']`, `$sla['byInbox']`, `$sla['byAgent']`, `$sla['violations']`, `$slaPeriod` (Task 5).

- [ ] **Step 1: Crie os helpers de formatação no topo da view**

Em `app/Views/dashboard/index.php`, logo depois do bloco `<?php $greetName = ... ?>` (antes de `<div class="dashboard" id="dashboardApp">`), insira:

```php
<?php
// Formatadores da faixa de SLA (mesmos do relatório de SLA).
$slaPct = function ($v): string {
    return $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 1, ',', '.'), '0'), ',') . '%';
};
$slaHuman = function ($sec): string {
    if ($sec === null) return '—';
    $sec = (int) $sec;
    if ($sec < 60) return $sec . 's';
    if ($sec < 3600) return (int) round($sec / 60) . ' min';
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    return $m > 0 ? $h . 'h' . $m : $h . 'h';
};
// A barra é o % dentro do SLA (aqui o que importa é o cumprimento);
// a cor segue os mesmos cortes do relatório: >=90 verde, >=70 âmbar.
$slaColor = function ($p): string {
    $p = $p === null ? 0 : (float) $p;
    return $p >= 90 ? 'var(--success)' : ($p >= 70 ? 'var(--warning)' : 'var(--danger)');
};
?>
```

- [ ] **Step 2: Crie a faixa "SLA em detalhe"**

Em `app/Views/dashboard/index.php`, insira a faixa **imediatamente depois** do fim da faixa "Minha fila" (ou seja, logo antes de `<!-- Operação agora: atendimento normal x grupos -->`):

```php
    <!-- SLA em detalhe: onde o SLA está furando -->
    <?php if (!empty($sla['visible'])): ?>
    <div class="dash-section-title" style="margin-top:20px">
        <span>SLA em detalhe</span>
        <form method="GET" action="<?= url('/') ?>" class="dash-sla-period">
            <label for="slaPeriod">Período:</label>
            <select id="slaPeriod" name="period" class="form-control form-control-sm" onchange="this.form.submit()">
                <option value="7" <?= (int) $slaPeriod === 7 ? 'selected' : '' ?>>Últimos 7 dias</option>
                <option value="30" <?= (int) $slaPeriod === 30 ? 'selected' : '' ?>>Últimos 30 dias</option>
                <option value="90" <?= (int) $slaPeriod === 90 ? 'selected' : '' ?>>Últimos 90 dias</option>
            </select>
        </form>
    </div>

    <?php
    $slaBreaks = [
        ['titulo' => 'Por departamento', 'icone' => 'fa-users', 'dados' => $sla['byDepartment']],
        ['titulo' => 'Por caixa', 'icone' => 'fa-inbox', 'dados' => $sla['byInbox']],
    ];
    if (!empty($sla['byAgent'])) {
        $slaBreaks[] = ['titulo' => 'Por atendente', 'icone' => 'fa-user', 'dados' => $sla['byAgent']];
    }
    ?>
    <div class="dashboard-grid" style="grid-template-columns:<?= count($slaBreaks) === 3 ? 'repeat(3,1fr)' : '1fr 1fr' ?>;margin-top:0">
        <?php foreach ($slaBreaks as $b): ?>
            <div class="card chart-card">
                <div class="card-header">
                    <h3><i class="fas <?= $b['icone'] ?>" style="color:var(--primary)"></i> <?= e($b['titulo']) ?> <small class="card-header-sub">dentro do SLA</small></h3>
                </div>
                <div class="card-body">
                    <?php if (empty($b['dados'])): ?>
                        <div class="empty-state"><p>Nenhuma medição no período.</p></div>
                    <?php else: ?>
                        <?php foreach ($b['dados'] as $row): ?>
                            <?php $p = $row['percent'] === null ? null : (float) $row['percent']; ?>
                            <div class="hbar-row">
                                <div class="hbar-top">
                                    <span class="dept-bar-name"><?= e($row['label']) ?></span>
                                    <span class="hbar-pct"><?= $slaPct($p) ?></span>
                                    <span class="dept-bar-count"><?= (int) $row['breached'] ?>/<?= (int) $row['total'] ?></span>
                                </div>
                                <div class="dept-bar-track">
                                    <div class="dept-bar-fill" style="width:<?= $p === null ? 0 : round($p) ?>%;background:<?= $slaColor($p) ?>"></div>
                                </div>
                                <div class="sla-break-meta"><?= (int) ($row['active'] ?? 0) ?> ativas</div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card chart-card" style="margin-top:16px">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:10px">
            <h3><i class="fas fa-list" style="color:var(--primary)"></i> Conversas com violação no período</h3>
            <a href="<?= url('reports/sla') ?>?period=<?= (int) $slaPeriod ?>" class="btn btn-sm btn-outline" style="padding:6px 12px;border-radius:8px">
                <i class="fas fa-arrow-right"></i> Ver relatório completo
            </a>
        </div>
        <div class="card-body">
            <?php if (empty($sla['violations'])): ?>
                <div class="empty-state"><p>Nenhuma violação no período. 🎉</p></div>
            <?php else: ?>
                <div style="overflow-x:auto">
                    <table class="table" style="width:100%;font-size:13px">
                        <thead>
                            <tr>
                                <th>Protocolo</th>
                                <th>Contato</th>
                                <th>Responsável</th>
                                <th>Departamento</th>
                                <th>1ª resposta</th>
                                <th>Resolução</th>
                                <th>Aberta em</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($sla['violations'] as $v): ?>
                            <tr>
                                <td><a href="<?= url('inbox') ?>?conv=<?= (int) $v['id'] ?>">#<?= e(format_protocol((string) ($v['protocol'] ?? ''))) ?></a></td>
                                <td><?= e($v['contact_name'] ?? '') ?></td>
                                <td><?= e($v['assigned_user_name'] ?: '—') ?></td>
                                <td><?= e($v['department_name'] ?: '—') ?></td>
                                <td>
                                    <?php if (!empty($v['response_breached'])): ?>
                                        <span style="color:var(--danger);font-weight:600"><?= $slaHuman($v['response_seconds']) ?></span>
                                        <span style="color:var(--text-muted)">/ <?= $slaHuman($v['response_threshold']) ?></span>
                                    <?php else: ?>
                                        <span style="color:var(--success)"><?= $slaHuman($v['response_seconds']) ?></span>
                                        <span style="color:var(--text-muted)">/ <?= $slaHuman($v['response_threshold']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($v['resolution_breached'])): ?>
                                        <span style="color:var(--danger);font-weight:600"><?= $slaHuman($v['resolution_seconds']) ?></span>
                                        <span style="color:var(--text-muted)">/ <?= $slaHuman($v['resolution_threshold']) ?></span>
                                    <?php else: ?>
                                        <span style="color:var(--success)"><?= $slaHuman($v['resolution_seconds']) ?></span>
                                        <span style="color:var(--text-muted)">/ <?= $slaHuman($v['resolution_threshold']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="color:var(--text-muted)"><?= e(date('d/m/Y H:i', strtotime((string) $v['created_at']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
```

- [ ] **Step 3: Estilize as duas classes novas**

Em `app/Views/dashboard/index.php`, no `<style>` da view, acrescente:

```css
.dash-sla-period{display:inline-flex;align-items:center;gap:6px}
.dash-sla-period label{font-size:12px;color:var(--text-muted)}
.dash-sla-period .form-control{max-width:170px}
.sla-break-meta{font-size:10px;color:var(--text-muted);margin-top:2px}
```

- [ ] **Step 4: Verifique no navegador**

Abra o dashboard e confirme:
- a faixa "SLA em detalhe" aparece com o seletor de período;
- trocar o período para 7 dias recarrega a página e as porcentagens e a tabela mudam;
- as linhas mostram "% dentro do SLA", "furaram/medidas" e "N ativas";
- a tabela lista as conversas com violação e o protocolo leva para a conversa.

- [ ] **Step 5: Commite**

```bash
git add app/Views/dashboard/index.php
git diff --cached --name-only
git commit -m "feat(dashboard): faixa SLA em detalhe com quebras e violacoes"
```

---

### Task 9: View — fusão da faixa de grupos e fim da "Equipe e caixas"

**Files:**
- Modify: `app/Views/dashboard/index.php`

**Interfaces:**
- Consumes: nada novo (usa `$trendNormalValues`, `$trendGroupValues`, `$groupsTop`, `$hasGroups`).

- [ ] **Step 1: Renomeie a faixa "Atendimento × Grupos"**

Em `app/Views/dashboard/index.php`, troque:

```php
    <div class="dash-section-title" style="margin-top:20px"><span>Atendimento × Grupos</span><a href="<?= url('whatsapp/groups') ?>" class="dash-section-link">Ver grupos <i class="fas fa-arrow-right"></i></a></div>
```

por:

```php
    <div class="dash-section-title" style="margin-top:20px"><span>Grupos de WhatsApp</span><a href="<?= url('whatsapp/groups') ?>" class="dash-section-link">Ver grupos <i class="fas fa-arrow-right"></i></a></div>
```

- [ ] **Step 2: Remova o donut "abertos normal × grupos"**

A faixa "Grupos de WhatsApp" tem **três** cards hoje: o gráfico de barras "Novas por dia" e o donut "Abertos" dentro de um `dashboard-grid` 2fr/1fr, e o card "Grupos WhatsApp" (`id="dashGroupList"`) full-width **abaixo** da grade.

Remova **apenas** o card do donut — o `<div class="card chart-card">` que começa em
`<h3><i class="fas fa-chart-pie" ...></i> Abertos <small class="card-header-sub">normal × grupos</small></h3>` e termina no `</div>` do `card-body` com `class="donut-body"` (ele contém `id="donutNGTotal"`, `id="gCountNormalOpen"` e `id="gCountGroupOpen"`).

**Remova também as variáveis que só existiam para ele.** No topo da view, o bloco de cálculo do donut normal × grupos (de `$ngOpenTotal = ...` até o fechamento de `$ngDonutSegs`) fica sem uso — apague. **Não** apague `$donutR` e `$donutC`: o donut de status (faixa "Tendência e distribuição") usa os dois.

**Ajuste a grade** para não sobrar coluna vazia: troque

```php
    <div class="dashboard-grid" style="grid-template-columns:2fr 1fr;margin-top:0">
```

por

```php
    <div class="dashboard-grid" style="grid-template-columns:1fr;margin-top:0">
```

na **faixa de grupos** (a outra ocorrência de `2fr 1fr`, na faixa "Tendência e distribuição", fica como está).

- [ ] **Step 3: Renomeie o card da lista de grupos**

O card "Grupos WhatsApp" full-width fica com um título que não repete o título da faixa. Em `app/Views/dashboard/index.php`, troque:

```php
                <h3><i class="fas fa-users" style="color:var(--primary)"></i> Grupos WhatsApp <small class="card-header-sub"><?= $totalGroups ?> grupos · <?= $groupMentionsUnread ?> menções não lidas</small></h3>
```

por:

```php
                <h3><i class="fas fa-users" style="color:var(--primary)"></i> Top grupos por atividade <small class="card-header-sub"><?= $totalGroups ?> grupos · <?= $groupMentionsUnread ?> menções não lidas</small></h3>
```

- [ ] **Step 4: Remova da faixa "Equipe e caixas"**

Em `app/Views/dashboard/index.php`, **remova** o bloco inteiro da faixa "Equipe e caixas": do

```php
    <!-- Row: departments + inboxes -->
    <div class="dash-section-title" style="margin-top:20px"><span>Equipe e caixas</span><a href="<?= url('reports') ?>" class="dash-section-link">Relatórios <i class="fas fa-arrow-right"></i></a></div>
```

até o `</div>` final que fecha o `dashboard-grid` com os dois cards (`Por departamento` e `Por caixa`).

O volume de conversas ativas por departamento e por caixa não se perde: virou a coluna "ativas" das linhas da faixa "SLA em detalhe" (Task 8).

**Consequência no JS:** os ids `gCountNormalOpen`, `gCountGroupOpen` e `donutNGTotal` não existem mais. Remova do `refreshDashboard()` as três linhas que os atualizam (o código já checa `if (el)`, então deixá-las seria inofensivo — mas saia limpo).

- [ ] **Step 5: Verifique no navegador**

Abra o dashboard e confirme:
- a faixa "Grupos de WhatsApp" mostra o gráfico de barras em largura total e a lista de grupos abaixo, sem coluna vazia;
- a faixa "Equipe e caixas" sumiu;
- a faixa "SLA em detalhe" mostra a coluna "N ativas" com os mesmos números que a faixa removida mostrava;
- o donut de status da faixa "Tendência e distribuição" continua desenhado.

- [ ] **Step 6: Commite**

```bash
git add app/Views/dashboard/index.php
git diff --cached --name-only
git commit -m "refactor(dashboard): funde as faixas de grupos e absorve Equipe e caixas no SLA"
```

---

### Task 10: JS — auto-refresh dos 6 números de SLA

**Files:**
- Modify: `app/Views/dashboard/index.php` (bloco `<script>` do fim da view)

**Interfaces:**
- Consumes: a chave `sla` da API (`/api/dashboard-stats?slaPeriod` → `?period=`), entregue pela Task 6.

- [ ] **Step 1: Mande o período no fetch**

Em `app/Views/dashboard/index.php`, troque:

```js
    function refreshDashboard() {
        fetch('<?= base_url('api/dashboard-stats') ?>', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
```

por:

```js
    function refreshDashboard() {
        var app = document.getElementById('dashboardApp');
        var period = (app && app.getAttribute('data-sla-period')) || '30';
        fetch('<?= base_url('api/dashboard-stats') ?>?period=' + encodeURIComponent(period), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
```

- [ ] **Step 2: Remova as fichas de SLA antigas do mapa**

Em `app/Views/dashboard/index.php`, no `refreshDashboard()`, troque:

```js
                var map = {
                    statNormalOpen: (d.normalOpen !== undefined ? d.normalOpen : d.globalOpen) || 0,
                    statGroupOpen: d.groupOpen || 0,
                    statOnline: d.onlineUsers || 0,
                    statTodayConvs: d.todayConversations || 0,
                    statTodayMsgs: d.todayMessages || 0,
                    statTodayResolved: d.todayResolved || 0,
                    statTodayNew: ((d.counts && (d.counts.new || 0)) - (d.groupNew || 0)) || 0,
                    slaCriticalNow: d.slaCriticalNow || 0
                };
```

por:

```js
                var map = {
                    statNormalOpen: (d.normalOpen !== undefined ? d.normalOpen : d.globalOpen) || 0,
                    statGroupOpen: d.groupOpen || 0,
                    statOnline: d.onlineUsers || 0,
                    statTodayConvs: d.todayConversations || 0,
                    statTodayMsgs: d.todayMessages || 0,
                    statTodayResolved: d.todayResolved || 0,
                    statTodayNew: ((d.counts && (d.counts.new || 0)) - (d.groupNew || 0)) || 0
                };
```

E **remova** o bloco:

```js
                el = document.getElementById('slaComplianceSub');
                if (el) {
                    if (d.slaCompliance30 !== null && typeof d.slaCompliance30 !== 'undefined') {
                        el.textContent = String(d.slaCompliance30).replace('.', ',') + '% dentro do SLA (30d)';
                    } else {
                        el.innerHTML = '<i class="fas fa-circle" style="color:var(--text-muted);font-size:7px"></i> sem medições ainda';
                    }
                }
```

- [ ] **Step 3: Adicione a atualização dos 6 números de SLA**

Em `app/Views/dashboard/index.php`, no `refreshDashboard()`, insira **logo depois** do loop `for (var key in map) { ... }`:

```js
                // SLA: as 6 fichas da faixa "SLA agora"
                var sla = d.sla || {};
                var slaMap = {
                    slaRiskNow: (sla.risk && sla.risk.now) || 0,
                    slaBreachedOpen: sla.breachedOpen || 0,
                    slaViolations: sla.violated || 0,
                    slaMeasured: sla.measured || 0
                };
                for (var skey in slaMap) {
                    el = document.getElementById(skey);
                    if (el) { el.textContent = slaMap[skey]; pulse(el); }
                }

                // As porcentagens podem não existir (sem medição no período):
                // nesse caso o estado vazio é "—", não 0.
                var slaPctMap = {
                    slaResponsePct: sla.response ? sla.response.percent : null,
                    slaResolutionPct: sla.resolution ? sla.resolution.percent : null
                };
                for (var pkey in slaPctMap) {
                    el = document.getElementById(pkey);
                    if (!el) continue;
                    var pv = slaPctMap[pkey];
                    if (pv === null || typeof pv === 'undefined') {
                        el.textContent = '—';
                    } else {
                        el.textContent = fmtDecimal(Number(pv), 1) + '%';
                    }
                    pulse(el);
                }

                el = document.getElementById('slaRiskSub');
                if (el && sla.risk) {
                    el.textContent = (sla.risk.alert || 0) + ' em alerta · '
                        + (sla.risk.attention || 0) + ' em atenção';
                    pulse(el);
                }
```

- [ ] **Step 4: Verifique no navegador**

Abra o dashboard e:
- espere 20 segundos: os 6 números de SLA continuam corretos (sem virar "NaN" nem "undefined");
- confirme no console do navegador que não há erro de JS;
- troque o período para 7 dias e espere o refresh: os números de SLA batem com o que a página mostra (não com o recorte de 30 dias).

- [ ] **Step 5: Commite**

```bash
git add app/Views/dashboard/index.php
git diff --cached --name-only
git commit -m "feat(dashboard): auto-refresh dos numeros de SLA"
```

---

### Task 11: Gate final

**Files:** nenhum arquivo alterado. Se algo falhar, volte à task dona do problema.

- [ ] **Step 1: Rode a suíte completa**

Run:
```powershell
$php = 'C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe'
& $php tests/run.php
if ($LASTEXITCODE -ne 0) { Write-Output 'FALHOU: tests/run.php'; exit 1 }
& $php tests/e2e_dashboard_sla.php
if ($LASTEXITCODE -ne 0) { Write-Output 'FALHOU: tests/e2e_dashboard_sla.php'; exit 1 }
```
Expected: os dois com 0 falhas.

- [ ] **Step 2: Rode os e2e que já existiam (não regredir SLA nem a inbox)**

Run:
```powershell
$php = 'C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe'
& $php tests/e2e_sla_reopen.php
& $php tests/e2e_regressao_critica.php
```
Expected: 0 falhas.

**`tests/e2e_sla_measurable.php` tem uma falha PRÉ-EXISTENTE** (medida na baseline
com as alterações da Task 1 em stash): *"a autoatribuicao escolheu o menos
carregado"*. O número esperado do teste varia conforme a carga dos atendentes
na base — é comportamento de autoatribuição em andamento, não regressão deste
trabalho, e este script não carrega nenhum arquivo que estas tasks tocam.
Rode-o para **conferir que o número de falhas não subiu** em relação à
baseline (1 falha antes → 1 falha depois), **não** para exigir 0 falhas.

- [ ] **Step 3: Confira a árvore do git**

Run:
```powershell
git status --porcelain
git log --oneline -12
```
Expected: apenas os arquivos deste trabalho aparecem como commitados. **Não** stageie nada além disso — a árvore tem o WIP do usuário.

- [ ] **Step 4: Verifique no navegador, com o usuário**

Abra o dashboard e o relatório de SLA lado a lado. Confirme com o usuário:
- o número "Em risco agora" bate com as conversas pintadas de amarelo/vermelho na inbox;
- o "SLA já furado" bate com as conversas já furadas e ainda abertas;
- a faixa "SLA em detalhe" responde ao período;
- o "Resposta média" mudou de valor em relação ao valor anterior (é outro cálculo, agora medido) — e comunique isso.

---

## Self-Review

**Cobertura da spec:** §2.3 (6 fichas) → Tasks 4, 7, 10. §2.4 (9 faixas, fusões, header) → Tasks 7, 9. §2.5 (faixa de detalhe) → Task 8. §2.6 (permissões) → Tasks 4, 5. §2.7 (período) → Tasks 5, 6, 8. §3.2 (âncora compartilhada) → Task 1. §3.3 (query da fila) → Task 2. §3.4 (service) → Task 4. §3.5 (violatedCount) → Task 3. §3.6 (cache por período) → Tasks 5, 6. §3.7 (degradação) → Task 4 (`visible`/`reason`), Task 7 (`if (!empty($sla['visible']))`). §4.1 (auto-refresh) → Task 10. §4.2 (links) → Tasks 7, 8. §5.1 (checks estáticos) → Tasks 1, 5, 6. §5.2 (e2e) → Tasks 2, 3, 4. §7 (média medida) → Tasks 4, 5, 7.

**Sem placeholders:** todo passo tem o código real ou o comando real. Nenhum "TBD", "similar à task N", "adicionar tratamento de erro".

**Consistência de tipos:** `payload()` devolve `visible`, `reason`, `period`, `risk{now,alert,attention,normal,total}`, `breachedOpen`, `response{percent,breached,measured}`, `resolution{...}`, `violated`, `measured`, `byDepartment[]`, `byInbox[]`, `byAgent[]`, `violations[]`. A view usa exatamente esses nomes (`$sla['risk']['now']`, `$sla['response']['percent']`, `$sla['byDepartment']`, `$sla['violations']`). O JS usa `d.sla.risk.now`, `d.sla.breachedOpen`, `d.sla.violated`, `d.sla.measured`, `d.sla.response.percent`, `d.sla.resolution.percent` — o mesmo payload. `slaAnchorsSql()`, `openConversationsAwaitingReply()`, `violatedCount()`, `liveRisk()`, `avgMeasuredResponse()`, `isEnabled()`, `normalizePeriod()` têm o mesmo nome em todas as tasks.
