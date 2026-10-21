# SLA mensurável — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Medir os dois SLAs (primeira resposta e resolução) em segundos úteis, registrar a violação no momento em que acontece, exibi-la no dashboard, no relatório e na tela da conversa, e escalar automaticamente.

**Architecture:** O histórico de mensagens é a única fonte da verdade: uma função pura (`SlaMeasurement::measure()`) recalcula os dois SLAs a partir das mensagens, da linha do tempo de status e da grade de horário do departamento. Conversas encerradas gravam o valor em `conversation_slas` (relatórios viram SELECT agregado); conversas ativas são medidas sob demanda por um worker por cron. A violação dispara o `SlaEscalation`, que executa 5 ações configuráveis.

**Tech Stack:** PHP 8.1+ puro (MVC procedural próprio, sem framework), MySQL 8, views server-rendered + JS vanilla, worker por cron. Sem dependência nova.

**Spec:** `docs/superpowers/specs/2026-10-20-sla-mensuravel-design.md`

---

## Global Constraints

- **PHP >= 8.1**, MySQL 8. Sem dependência nova em `composer.json`. Sem framework JS.
- **Fuso da aplicação:** `America/Sao_Paulo` (`config/app.php`). Timestamps do banco estão nesse fuso. A grade do departamento tem fuso próprio (`departments.business_hours_timezone`).
- **Idempotência:** toda migration, todo o worker e todo o backfill precisam ser idempotentes — rodar duas vezes não muda nada.
- **O runner de migrations** (`database/migrate.php`) tolera 1050/1060/1061/1062/1091/1826. Nomes de migration: `database/migrations/YYYY-MM-DD_descricao.sql`, aplicados em ordem de glob.
- **O harness de teste** é `tests/run.php` com `check(string $name, bool $cond): void` — **sem banco**. Testes que precisam de MySQL vão em `tests/e2e_*.php` (autolimpantes) e os de HTTP em `tests/smoke_sla.php`.
- **Toda função nova precisa de teste que falhou antes.** Os testes de fiação (que verificam a existência de arquivos/strings) seguem o padrão já existente no `tests/run.php` (`str_contains($src, '...')`).
- **Toda consulta nova precisa de índice** ou justificativa no plano.
- **PT-BR** em toda a interface e nas mensagens.
- **Nomes:** tabela `conversation_slas`, model `App\Models\ConversationSla`, serviço `App\Services\SlaMeasurement`, serviço `App\Services\SlaEscalation`, worker `workers/sla_worker.php`, rota `GET /reports/sla`.
- **Não tocar** em `app/Controllers/InboxController.php`, `app/Core/Session.php`, `app/Models/CannedResponse.php`, `app/Models/Macro.php` — têm alterações de outra pessoa no working tree.

---

## Review Focus

Cinco condições que a spec implica e que nenhuma tarefa de teste cobre sozinha. Cada uma ganha o teste indicado, no passo de teste da tarefa dona do código.

1. **Conversa de grupo WhatsApp (`group_id` não nulo)** — não pode ser encerrada e a "resposta" é menção, não atendimento. Não deve gerar linha de SLA, não entra no relatório, não escala. → teste no **Task 2**.
2. **Reabertura de conversa** (de `resolved`/`closed` para um status ativo) — reinicia o ciclo: zera os积累ados e passa a medir só a partir da reabertura. → teste no **Task 3**.
3. **Mensagem apagada (`deleted_at` preenchido)** — a medição **não** filera mensagens apagadas, para concordar com o relógio da lista (`Conversation.php:199`). → teste no **Task 2**.
4. **Limite da config alterado depois da medição** — o valor histórico não pode mudar (snapshot em `conversation_slas`). → teste no **Task 4**.
5. **Conversa sem departamento** — sem grade, o SLA conta tempo de relógio puro (mesma premissa de `BusinessHoursService::isOpen`). → teste no **Task 2**.

---

## Mapa de arquivos

**Novos:**

| Arquivo | Responsabilidade |
|---|---|
| `database/migrations/2026_10_20_sla_measurable.sql` | Tabela `conversation_slas`, colunas novas em `inboxes`/`departments`, enum de `conversation_events` |
| `app/Models/ConversationSla.php` | CRUD da linha de SLA + agregados de compliance |
| `app/Services/SlaMeasurement.php` | Motor de medição: função pura + wrapper de banco |
| `app/Services/SlaEscalation.php` | As 5 ações de escalonamento |
| `workers/sla_worker.php` | Cron: medir, detectar violação, finalizar, backfill |
| `app/Views/reports/sla.php` | Relatório de compliance |
| `tests/e2e_sla_measurable.php` | E2E de medição e escalonamento no MySQL |

**Modificados:**

| Arquivo | Mudança |
|---|---|
| `database/schema.sql` | Espelhar a migration (o `.sql` precisa ser auto-suficiente) |
| `app/Services/SlaService.php` | Chaves de config novas + override por caixa em `applyClock()` |
| `app/Services/ConversationService.php` | `changeStatus()` grava `metadata = {from, to}` |
| `app/Services/NotificationService.php` | `notifySystem()` |
| `app/Controllers/SettingsController.php` | Salvar config global e por caixa |
| `app/Views/settings/notifications.php` | UI global |
| `app/Views/settings/inbox_form.php` | UI por caixa |
| `app/Controllers/ReportsController.php` | Método `sla()` |
| `app/Views/dashboard/index.php` | Card de KPI |
| `app/Controllers/Api/DashboardStatsController.php` | Dado do KPI |
| `app/Views/inbox/panel.php` | Chip de SLA no header |
| `public/assets/js/app-enhancements.js` | Re-render do chip no poll |
| `routes/web.php` | Rota `/reports/sla` |
| `tests/run.php`, `tests/e2e_sla_measurable.php`, `tests/smoke_sla.php` | Testes |
| `README.md`, `FUNCIONALIDADES_SISTEMA.md` | Docs |

---

### Task 1: Migration e model `ConversationSla`

**Files:**
- Create: `database/migrations/2026_10_20_sla_measurable.sql`
- Create: `app/Models/ConversationSla.php`
- Modify: `database/schema.sql`
- Test: `tests/run.php`

**Interfaces:**
- Consumes: nada (primeira tarefa).
- Produces:
  - `App\Models\ConversationSla::find(int $conversationId): ?array`
  - `App\Models\ConversationSla::save(int $conversationId, array $data): int` — upsert; `$data` aceita as colunas `response_seconds`, `response_at`, `response_breached`, `response_threshold`, `resolution_seconds`, `resolution_breached`, `resolution_threshold`, `period_started_at`, `metadata` (array → JSON).
  - `App\Models\ConversationSla::reset(int $conversationId, ?string $cycleStartedAt = null): void` — zera os acumulados e grava `metadata.cycle_started_at`.
  - Colunas da tabela `conversation_slas` (ver passo 2).

- [ ] **Step 1: Escreva o teste falhando (fiação da migration)**

Em `tests/run.php`, antes do `echo "\n$passes passed..."`:

```php
$migSrc = file_get_contents(__DIR__ . '/../database/migrations/2026_10_20_sla_measurable.sql') ?: '';
check('sla: a migration cria a tabela conversation_slas', str_contains($migSrc, 'CREATE TABLE IF NOT EXISTS conversation_slas'));
check('sla: a migration cria as colunas de override da caixa', str_contains($migSrc, 'sla_response_minutes') && str_contains($migSrc, 'sla_resolution_minutes') && str_contains($migSrc, 'sla_business_hours'));
check('sla: a migration cria o telefone de escalonamento', str_contains($migSrc, 'sla_escalation_phone'));
check('sla: a migration adiciona sla_breached no enum de eventos', str_contains($migSrc, 'sla_breached'));
$schemaSrc = file_get_contents(__DIR__ . '/../database/schema.sql') ?: '';
check('sla: o schema.sql traz a tabela conversation_slas', str_contains($schemaSrc, 'CREATE TABLE IF NOT EXISTS conversation_slas'));
```

> **Atenção:** o `?: ''` é obrigatório. `str_contains(false, ...)` é `TypeError` no PHP 8 e mataria o runner em vez de produzir uma falha limpa. Use o mesmo guard em toda leitura de arquivo que possa não existir ainda.

- [ ] **Step 2: Rode o teste e confirme que falha**

Run: `php tests/run.php`
Expected: `FALHOU - sla: a migration cria a tabela conversation_slas` (as 5 novas linhas falham; as anteriores passam).

- [ ] **Step 3: Escreva a migration**

Crie `database/migrations/2026_10_20_sla_measurable.sql` com, **nesta ordem**:

1. `CREATE TABLE IF NOT EXISTS conversation_slas` — colunas exatamente como na spec §3.1, mais `metadata JSON NULL`, `INDEX idx_slas_breached (response_breached, resolution_breached)`, `INDEX idx_slas_updated (updated_at)`, FK para `conversations(id) ON DELETE CASCADE`.
2. `ALTER TABLE inboxes` — 7 colunas `NULL DEFAULT NULL`: `sla_response_minutes INT`, `sla_resolution_minutes INT`, `sla_business_hours TINYINT(1)`, `sla_escalate_managers TINYINT(1)`, `sla_escalate_tag TINYINT(1)`, `sla_escalate_autoassign TINYINT(1)`, `sla_escalate_whatsapp TINYINT(1)`. (NULL = herda o global.)
3. `ALTER TABLE departments ADD COLUMN sla_escalation_phone VARCHAR(20) NULL DEFAULT NULL`.
4. `ALTER TABLE conversation_events MODIFY COLUMN event_type ENUM('created','assigned','transferred','status_changed','priority_changed','note_added','flow_started','flow_completed','tag_added','tag_removed','department_changed','reopened','sla_breached','sla_escalated') NOT NULL`.

Uma instrução por linha, terminada em `;`. Comentário `--` no topo explicando o porquê.

- [ ] **Step 4: Espelhe a migration no `database/schema.sql`**

`database/schema.sql` precisa ser auto-suficiente (o README manda `mysql < database/schema.sql` antes das migrations). Adicione:
- a tabela `conversation_slas` (mesma DDL, sem `IF NOT EXISTS` duplicado — o schema.sql já usa `CREATE TABLE IF NOT EXISTS` em outros pontos, siga o mesmo padrão);
- as 7 colunas de `inboxes` dentro do `CREATE TABLE inboxes` (schema.sql:182);
- `sla_escalation_phone` dentro do `CREATE TABLE departments` (schema.sql:37);
- os 2 tipos novos no enum de `conversation_events` (schema.sql:316).

- [ ] **Step 5: Aplique a migration e confirme**

Run: `php database/migrate.php`
Expected: `Migration complete.` sem erro. Depois: `php -r "…SHOW COLUMNS FROM conversation_slas…"` deve listar 13 colunas.

- [ ] **Step 6: Escreva o model `App\Models\ConversationSla`**

`app/Models/ConversationSla.php`, namespace `App\Models`, usando `App\Core\Database`:

```php
public static function find(int $conversationId): ?array
public static function save(int $conversationId, array $data): int
public static function reset(int $conversationId, ?string $cycleStartedAt = null): void
```

`save()` faz upsert em `conversation_slas` por `conversation_id`: se `find()` devolve linha, `Database::update()`, senão `Database::insert()` com `conversation_id` + `created_at`/`updated_at`. O `metadata`, quando arrive como array, é convertido com `json_encode($data['metadata'], JSON_UNESCAPED_UNICODE)`.

`reset()` zera `response_seconds = NULL`, `response_at = NULL`, `response_breached = 0`, `resolution_seconds = 0`, `resolution_breached = 0`, `period_started_at = NULL` e grava `metadata = {"cycle_started_at": "..."}` (ou `{}` quando null).

- [ ] **Step 7: Rode o teste e confirme que passa**

Run: `php tests/run.php`
Expected: todas as linhas passam (as 5 novas + as anteriores).

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_10_20_sla_measurable.sql database/schema.sql app/Models/ConversationSla.php tests/run.php
git commit -m "feat(sla): tabela conversation_slas e colunas de override

Migration com a tabela de medicao (uma linha por conversa), os overrides
por caixa (limites, horario comercial e toggles de escalonamento), o
telefone de plantao do departamento e os tipos sla_breached/sla_escalated
no enum de conversation_events. O schema.sql espelha a migration para
continuar auto-suficiente."
```

---

### Task 2: Motor de medição puro (`SlaMeasurement::measure`)

**Files:**
- Create: `app/Services/SlaMeasurement.php`
- Test: `tests/run.php`

**Interfaces:**
- Consumes: `App\Services\SlaClock::elapsedInSchedule(array $schedule, string $timezone, DateTimeInterface $from, DateTimeInterface $to): int` (já existe).
- Produces:
  - `App\Services\SlaMeasurement::measure(array $conv, array $messages, array $timeline, ?array $schedule, string $scheduleTz, string $now, array $opts = []): array`
    - `$conv`: linha de `conversations` (`id`, `status`, `department_id`, `group_id`, `created_at`, `closed_at`).
    - `$messages`: lista de `['id'=>int,'direction'=>string,'type'=>string,'user_id'=>?int,'created_at'=>string]` em ordem de `id`.
    - `$timeline`: lista de `['at'=>'Y-m-d H:i:s','status'=>string]`.
    - `$schedule`: grade no formato do `SlaClock` ou `null` (sem grade → tempo de relógio puro).
    - `$scheduleTz`: fuso da grade.
    - `$now`: `'Y-m-d H:i:s'` no fuso da aplicação.
    - `$opts`: `['response_threshold'=>int,'resolution_threshold'=>int,'cycle_started_at'=>?string]`.
    - Retorna **exatamente** esta forma:
      ```php
      [
        'response_seconds'    => int|null,
        'response_at'         => string|null,
        'response_breached'   => bool,
        'response_threshold'  => int,
        'resolution_seconds'  => int,
        'resolution_breached' => bool,
        'resolution_threshold'=> int,
        'period_started_at'   => string|null,
      ]
      ```
  - `App\Services\SlaMeasurement::isHumanReply(array $message): bool` — `direction='outbound' AND user_id !== null AND type NOT IN ('internal_note','system')`.

- [ ] **Step 1: Escreva os testes falhando**

Em `tests/run.php`, helpers tolerantes (o mesmo padrão de `slaInt`/`slaState` já criado):

```php
function slaMeasure(array $args): array
{
    if (!class_exists('App\Services\SlaMeasurement') || !method_exists('App\Services\SlaMeasurement', 'measure')) {
        return [];
    }
    $r = \App\Services\SlaMeasurement::measure(...$args);
    return is_array($r) ? $r : [];
}
```

Grade de teste (seg-sex 08:00-18:00):

```php
$office = [];
foreach ([1,2,3,4,5] as $dow) {
    $office[$dow] = ['is_open' => 1, 'open' => '08:00:00', 'close' => '18:00:00'];
}
$office[0] = ['is_open' => 0, 'open' => '08:00:00', 'close' => '18:00:00'];
$office[6] = ['is_open' => 0, 'open' => '08:00:00', 'close' => '18:00:00'];
```

Mensagens de teste (2026-10-19 é segunda):

```php
$conv = ['id' => 1, 'status' => 'open', 'department_id' => 2, 'group_id' => null, 'created_at' => '2026-10-19 09:00:00', 'closed_at' => null];
$msg = fn(int $id, string $dir, string $type, ?int $uid, string $at) =>
    ['id' => $id, 'direction' => $dir, 'type' => $type, 'user_id' => $uid, 'created_at' => $at];
```

Casos (use `check()` com `($st['chave'] ?? null) === valor`):

1. `sla: a primeira resposta mede da 1a mensagem do cliente` — cliente 09:00 (id 1), cliente 09:10 (id 2), humano 09:20 (id 3). `response_seconds` === 1200 (09:00→09:20, dentro de 08-18).
2. `sla: o pareamento nao conta o mesmo tempo duas vezes` — mesmo caso acima; `resolution_seconds` === 1200 (um único período 09:10→09:20), **não** 1800 nem 2400.
3. `sla: sem resposta humana a conversa nao tem sla de resposta` — só cliente 09:00, sem resposta; `response_seconds` === null e `response_breached` === false (conversa ativa, sem objeto de SLA). → este é o **Review Focus #1** (caso "cliente nunca falou") combinado: use `response_breached` false porque a conversa está ativa.
4. `sla: conversa encerrada sem resposta conta como violacao` — mesma conversa com `status` = `'closed'` e `closed_at` = `'2026-10-19 10:00:00'`; `response_seconds` === null e `response_breached` === true.
5. `sla: o periodo aberto conta ate agora` — cliente 09:00, sem resposta, `now` = `'2026-10-19 10:00:00'`; `resolution_seconds` === 3600 e `period_started_at` === `'2026-10-19 09:00:00'`.
6. `sla: conversa encerrada fecha o periodo no closed_at` — cliente 09:00, `closed_at` = `'2026-10-19 10:00:00'`, `now` = `'2026-10-19 23:00:00'`; `resolution_seconds` === 3600 (não 50400).
7. `sla: a pausa por waiting_internal e descontada` — cliente 09:00, timeline `[['at'=>'2026-10-19 09:30','status'=>'waiting_internal'],['at'=>'2026-10-19 10:30','status'=>'open']]`, `now` = `'2026-10-19 11:00:00'`; `resolution_seconds` === 1800 (09:00→09:30 + 10:30→11:00; os 60 min de `waiting_internal` fora).
8. `sla: sem grade conta tempo de relogio` — `schedule` = null, cliente 09:00, `now` = `'2026-10-19 10:00:00'`; `resolution_seconds` === 3600.
9. `sla: a noite nao conta` — cliente `'2026-10-19 23:00:00'`, `now` = `'2026-10-20 01:00:00'`; `resolution_seconds` === 0.
10. `sla: conversa de grupo nao tem sla` — `$conv['group_id'] = 7`; `measure()` retorna `[]` (array vazio) — sinal de "sem objeto de SLA". → **Review Focus #1**.
11. `sla: mensagem apagada entra na medicao` — cliente 09:00 com `deleted_at` preenchido não muda o resultado: insira `'deleted_at' => '2026-10-19 09:05:00'` no array da mensagem e confirme que `response_seconds` continua 1200 no caso 1. → **Review Focus #3**.
12. `sla: conversa sem departamento conta tempo de relogio` — `$conv['department_id'] = null` com `schedule` = null; `resolution_seconds` === 3600. → **Review Focus #5**.
13. `sla: a reabertura zera o ciclo` — `$opts['cycle_started_at'] = '2026-10-19 09:30:00'`, cliente 09:00 (id 1) e cliente 09:45 (id 2), humano 09:50 (id 3); `response_seconds` mede a partir de 09:45 → 300. → **Review Focus #2**.
14. `sla: o limite gravado e o do momento` — `$opts['response_threshold'] = 600`; `response_breached` === true quando `response_seconds` = 1200.

- [ ] **Step 2: Rode o teste e confirme que falha**

Run: `php tests/run.php`
Expected: todas as 14 linhas novas falham com `FALHOU` (a classe `App\Services\SlaMeasurement` não existe). **Nenhuma** pode falhar com erro fatal — por isso o helper `slaMeasure()`.

- [ ] **Step 3: Implemente `SlaMeasurement::measure()`**

Crie `app/Services/SlaMeasurement.php`, namespace `App\Services`.

**Assinatura:**
```php
public static function measure(array $conv, array $messages, array $timeline, ?array $schedule, string $scheduleTz, string $now, array $opts = []): array
```

**Algoritmo (siga a spec §4.2):**

```
1. Se $conv['group_id'] não é null/vazio → return []        (conversa de grupo: sem SLA)
2. Se $opts['cycle_started_at'] existe → descarta as mensagens com created_at < cycle_started_at
3. Percorre $messages em ordem de id:
     - inbound  → openInbound = msg
     - isHumanReply(msg) → se openInbound: periods[] = [openInbound.at, msg.at];
       openInbound = null;  se é a primeira resposta humana: firstReply = msg
     - guarda firstInbound (primeiro inbound)
4. Se openInbound sobrou:
     - fim = $conv['closed_at'] se preenchido, senão $now
     - periods[] = [openInbound.at, fim]; periodStartedAt = openInbound.at
5. response:
     - se firstReply e firstInbound → response_seconds = elapsedInSchedule(schedule, tz, firstInbound.at, firstReply.at)
     - senão se a conversa está encerrada (closed_at preenchido OU status em resolved/closed/spam) → response_seconds = null, response_breached = true
     - senão → response_seconds = null, response_breached = false
6. resolution_seconds = Σ elapsedInSchedule(schedule, tz, inicio, fim) de cada período
7. Desconta a pausa por waiting_internal:
     - Constrói os breakpoints do período: [inicio] + {at da timeline : inicio < at < fim} + [fim]
     - Para cada segmento [b_i, b_{i+1}]: o status vigente em b_i é o status do ÚLTIMO
       item da timeline com at <= b_i (se nenhum, o status inicial da conversa, que é
       $conv['status'] no instante do primeiro inbound)
     - Se o status vigente NÃO for 'waiting_internal', soma
       elapsedInSchedule(schedule, tz, b_i, b_{i+1}) em resolution_seconds
     - Se for 'waiting_internal', o segmento não soma nada
8. response_breached = response_seconds !== null && response_seconds > response_threshold
   (o caso "encerrada sem resposta" do passo 5 já deixou response_breached = true)
   resolution_breached = resolution_seconds > resolution_threshold
9. Retorna a forma exata da interface.
```

**Regras de borda que os testes fixam:**
- `elapsedInSchedule` com `$schedule === null` **não** pode ser chamado (ele trata `[]` como dia todo aberto, o que daria tempo de relógio — mas o contrato é: schedule null → tempo de relógio puro). Faça: `$elapsed = $schedule === null ? max(0, $fim - $inicio) : SlaClock::elapsedInSchedule($schedule, $scheduleTz, $ini, $fim);` com `$ini`/`$fim` convertidos para `DateTimeImmutable` no fuso `$scheduleTz`.
- O período aberto de uma conversa **encerrada** termina em `closed_at` (não em `now`).
- Mensagens apagadas **entram** na medição (o array não traz `deleted_at` filtrado; não filtre).
- A âncora de `response_seconds` é o **primeiro** inbound, não o do pareamento.

- [ ] **Step 4: Rode o teste e confirme que passa**

Run: `php tests/run.php`
Expected: as 14 linhas novas passam e nenhuma anterior regrediu.

- [ ] **Step 5: Commit**

```bash
git add app/Services/SlaMeasurement.php tests/run.php
git commit -m "feat(sla): motor de medicao dos dois SLAs

Funcao pura que recalcula, a partir do historico de mensagens e da linha
do tempo de status, o SLA de primeira resposta (1a mensagem do cliente
ate a 1a resposta humana) e o SLA de resolucao (segundos uteis com a bola
com a gente), com pausa por waiting_internal.

Sem gancho acumulador: o historico e a unica fonte da verdade, entao
rodar a medicao duas vezes da o mesmo resultado."
```

---

### Task 3: Medição contra o banco + linha do tempo de status

**Files:**
- Modify: `app/Services/SlaMeasurement.php` (add `forConversation()`)
- Modify: `app/Services/ConversationService.php` (`changeStatus()` grava metadata)
- Modify: `app/Models/ConversationSla.php` (segue como está)
- Test: `tests/run.php`, `tests/e2e_sla_measurable.php`

**Interfaces:**
- Consumes: `SlaMeasurement::measure()` (Task 2), `App\Models\ConversationSla::save()` (Task 1), `App\Services\BusinessHoursService::slaConfig(int $departmentId): ?array` (existe, devolve `['schedule' => array, 'timezone' => string]` ou `null`), `App\Services\SlaService::configFor(?int $inboxId)` (existe, devolve `['config' => array, ...]`).
- Produces:
  - `App\Services\SlaMeasurement::forConversation(int $conversationId, ?string $now = null): ?array` — carrega conversa, mensagens, timeline e grade; chama `measure()`; **não grava** nada. Devolve `null` quando não há objeto de SLA (grupo) e o array de medição caso contrário.
  - `App\Services\SlaMeasurement::loadTimeline(int $conversationId): array` — lista de `['at','status']` vinda de `conversation_events` com `event_type = 'status_changed'`, em ordem de `created_at`.
  - `App\Services\SlaMeasurement::saveMeasurement(int $conversationId, array $measurement, ?string $cycleStartedAt = null): void` — grava em `conversation_slas` via `ConversationSla::save()`.

- [ ] **Step 1: Escreva o teste falhando (fiação)**

Em `tests/run.php`:

```php
$convSrc = file_get_contents(__DIR__ . '/../app/Services/ConversationService.php');
check('sla: a mudanca de status grava o status anterior', str_contains($convSrc, "'from' => \$old") && str_contains($convSrc, "'to' => \$status"));
$measSrc = file_get_contents(__DIR__ . '/../app/Services/SlaMeasurement.php') ?: '';
check('sla: a medicao carrega a conversa do banco', str_contains($measSrc, 'function forConversation'));
check('sla: a medicao le a linha do tempo de status', str_contains($measSrc, 'function loadTimeline'));
```

- [ ] **Step 2: Rode o teste e confirme que falha**

Run: `php tests/run.php`
Expected: as 3 linhas novas falham.

- [ ] **Step 3: Faça `changeStatus()` gravar a linha do tempo**

Em `app/Services/ConversationService.php`, método `changeStatus()` (linha 318). Hoje:

```php
Conversation::addEvent($conversationId, 'status_changed', "Status alterado para: {$status}", Auth::id());
```

Troque por:

```php
Conversation::addEvent($conversationId, 'status_changed', "Status alterado para: {$status}", Auth::id(), [
    'from' => $old,
    'to' => $status,
]);
```

`Conversation::addEvent()` já aceita `$metadata` como array e faz `json_encode` (`Conversation.php:926`).

**Reabertura (Review Focus #2).** No mesmo método, o bloco que já detecta a reabertura (`ConversationService.php:363-366`):

```php
$finals = ['resolved', 'closed', 'spam'];
if (in_array($old, $finals, true) && !in_array($status, $finals, true)) {
    Conversation::update($conversationId, ['after_hours_notified' => 0, 'csat_requested' => 0]);
}
```

acrescente, **dentro** do `if`:

```php
    // Reabertura reinicia o ciclo do SLA: zera os acumulados e marca
    // o início do novo ciclo (a medição ignora as mensagens anteriores).
    \App\Models\ConversationSla::reset($conversationId, date('Y-m-d H:i:s'));
```

`ConversationSla::reset()` foi criado na Task 1 e zera `response_*`/`resolution_*` e grava
`metadata.cycle_started_at`. A medição lê esse `cycle_started_at` de
`ConversationSla::find()` e o passa em `$opts['cycle_started_at']` (Task 2, Passo 2).

- [ ] **Step 4: Implemente `forConversation()`, `loadTimeline()` e `saveMeasurement()`**

Em `app/Services/SlaMeasurement.php`:

```php
public static function forConversation(int $conversationId, ?string $now = null): ?array
public static function loadTimeline(int $conversationId): array
public static function saveMeasurement(int $conversationId, array $measurement, ?string $cycleStartedAt = null): void
```

**`forConversation()`:**
1. `$conv = \App\Models\Conversation::find($conversationId)`; se null → return null.
2. Se `$conv['group_id']` não é null → return null.
3. `$messages = Database::fetchAll("SELECT id, direction, type, user_id, created_at FROM messages WHERE conversation_id = ? ORDER BY id", [$conversationId])`.
4. `$timeline = self::loadTimeline($conversationId)`.
5. Grade: `$deptId = $conv['department_id']`; `$deptCfg = $deptId ? BusinessHoursService::slaConfig((int) $deptId) : null`; `$schedule = $deptCfg['schedule'] ?? null`; `$scheduleTz = $deptCfg['timezone'] ?? date_default_timezone_get()`.
6. Limites: `$cfg = SlaService::configFor($conv['inbox_id'] ? (int) $conv['inbox_id'] : null)['config'];` → `response_threshold` = `$cfg['response_minutes'] * 60`, `resolution_threshold` = `$cfg['resolution_minutes'] * 60`.
7. `$opts = ['response_threshold' => ..., 'resolution_threshold' => ..., 'cycle_started_at' => null]`.
8. Chama `measure()` e devolve o resultado (ou `null` se veio `[]`).

**`loadTimeline()`:** query em `conversation_events`:

```sql
SELECT created_at, description, metadata
FROM conversation_events
WHERE conversation_id = ? AND event_type = 'status_changed'
ORDER BY created_at ASC, id ASC
```

Para cada linha, extrai o status destino: se `metadata` decodificado tiver `to`, usa; senão faz `preg_match('/Status alterado para: (\w+)/', $description, $m)`. Devolve `['at' => created_at, 'status' => $status]`. Linhas sem status reconhecível são ignoradas.

**`saveMeasurement()`:** chama `ConversationSla::save($conversationId, [...])` mapeando a forma da medição para as colunas. `metadata` recebe `['cycle_started_at' => $cycleStartedAt]` quando informado.

- [ ] **Step 5: Rode o teste e confirme que passa**

Run: `php tests/run.php`
Expected: as 3 linhas novas passam.

- [ ] **Step 6: Escreva o e2e e confirme que ele falha**

Crie `tests/e2e_sla_measurable.php` seguindo o padrão de `tests/e2e_sla.php` (bootstrap, `$fail`/`check()`, `finally` limpando tudo). Ele cria:
- um usuário, um contato, um canal, uma conversa `open` no inbox 3 e o departamento 2;
- duas mensagens: cliente `'2026-10-19 09:00:00'` e humano `'2026-10-19 09:20:00'` (dentro do horário de seg-sex 08:00-18:00 do departamento 2, que o teste configura e restaura no `finally`);
- chama `SlaMeasurement::forConversation($convId)` e verifica `response_seconds === 1200`;
- chama `saveMeasurement()` e relê com `ConversationSla::find()` verificando `response_seconds === 1200` e `response_breached === 0`;
- **snapshot do limite (Review Focus #4):** com a conversa **encerrada**, grava a linha com `response_threshold` = 900, muda `Setting::set('sla_response_minutes', '60')` (3600s) e confirma que `ConversationSla::find()` **continua** com `response_threshold` = 900 — conversa encerrada não é remedida, então o limite histórico não muda. Restaure a setting no `finally`.

Como o `forConversation()` ainda não existe, o primeiro `check()` deve falhar (não fatal): envolva a chamada em `method_exists()`.

- [ ] **Step 7: Rode o e2e e confirme que passa**

Run: `php tests/e2e_sla_measurable.php`
Expected: todas as linhas `ok`, `cleanup ok`, exit 0.

- [ ] **Step 8: Commit**

```bash
git add app/Services/SlaMeasurement.php app/Services/ConversationService.php app/Models/ConversationSla.php tests/run.php tests/e2e_sla_measurable.php
git commit -m "feat(sla): medicao contra o banco e linha do tempo de status

changeStatus passa a gravar metadata {from,to} no evento status_changed
(sem isso a pausa por waiting_internal nao era reconstruivel) e
SlaMeasurement ganha forConversation/loadTimeline/saveMeasurement, que
carregam conversa, mensagens, timeline e grade do departamento."
```

---

### Task 4: Config dos limites e dos toggles

**Files:**
- Modify: `app/Services/SlaService.php`
- Modify: `app/Controllers/SettingsController.php`
- Modify: `app/Views/settings/notifications.php`
- Modify: `app/Views/settings/inbox_form.php`
- Test: `tests/run.php`

**Interfaces:**
- Consumes: `SlaService::DEFAULT_CONFIG`, `SlaService::INBOX_SLA_FIELDS`, `SlaService::configFor()` (todos já existem).
- Produces:
  - `SlaService::DEFAULT_CONFIG` ganha as chaves: `response_minutes` (`'15'`), `resolution_minutes` (`'1440'`), `escalate_managers` (`'1'`), `escalate_tag` (`'1'`), `escalate_autoassign` (`'0'`), `escalate_whatsapp` (`'0'`), `escalation_message` (texto padrão). `business_hours` já existe (`'1'`).
  - `SlaService::INBOX_SLA_FIELDS` ganha: `sla_response_minutes`, `sla_resolution_minutes`, `sla_business_hours`, `sla_escalate_managers`, `sla_escalate_tag`, `sla_escalate_autoassign`, `sla_escalate_whatsapp`.
  - `SlaService::configFor(?int $inboxId)` já devolve tudo isso em `['config']` — as chaves ficam em `sla_` no banco e sem o prefixo no array.
  - `SlaService::applyClock(array $rows, ?string $now = null): array` passa a respeitar o override por caixa de `business_hours`.

- [ ] **Step 1: Escreva os testes falhando**

Em `tests/run.php`:

```php
$slaSrc = file_get_contents(__DIR__ . '/../app/Services/SlaService.php');
check('sla: a config tem o limite de primeira resposta', str_contains($slaSrc, "'response_minutes'"));
check('sla: a config tem o limite de resolucao', str_contains($slaSrc, "'resolution_minutes'"));
check('sla: a config tem os toggles de escalonamento', str_contains($slaSrc, "'escalate_managers'") && str_contains($slaSrc, "'escalate_autoassign'") && str_contains($slaSrc, "'escalate_whatsapp'"));
check('sla: a caixa pode sobrescrever o horario comercial', str_contains($slaSrc, 'sla_business_hours'));
$notifView = file_get_contents(__DIR__ . '/../app/Views/settings/notifications.php');
check('sla: o painel global expoe os limites de medicao', str_contains($notifView, 'sla_response_minutes') && str_contains($notifView, 'sla_resolution_minutes'));
$inboxView = file_get_contents(__DIR__ . '/../app/Views/settings/inbox_form.php');
check('sla: o formulario da caixa expoe os overrides', str_contains($inboxView, 'sla_response_minutes') && str_contains($inboxView, 'sla_escalate_autoassign'));
```

- [ ] **Step 2: Rode o teste e confirme que falha**

Run: `php tests/run.php`
Expected: as 5 linhas novas falham.

- [ ] **Step 3: Estenda a config do `SlaService`**

Em `app/Services/SlaService.php`:

- `DEFAULT_CONFIG`: acrescente `'response_minutes' => '15'`, `'resolution_minutes' => '1440'`, `'escalate_managers' => '1'`, `'escalate_tag' => '1'`, `'escalate_autoassign' => '0'`, `'escalate_whatsapp' => '0'`, `'escalation_message' => 'SLA de {{sla.type}} violado: {{contact.name}} (protocolo {{conversation.protocol}}) espera há {{sla.wait}} (limite {{sla.limit}}).'`.
- `INBOX_SLA_FIELDS`: acrescente as 7 colunas da Task 1.

`globalConfig()` já prefixa com `sla_` e `configFor()` já mescla os overrides com `NULL` = herdar. **Nenhuma outra mudança é necessária aqui** — é o motivo de o padrão existir.

- [ ] **Step 4: Faça o `applyClock()` respeitar o override por caixa**

Em `SlaService::applyClock()`, hoje:

```php
$useBusinessHours = Setting::get(self::SETTING_BUSINESS_HOURS, '1') === '1';
```

e o `if ($useBusinessHours && $deptId)`. Troque por uma resolução **por linha**:

```php
$inboxId = !empty($row['inbox_id']) ? (int) $row['inbox_id'] : null;
$effective = $inboxId !== null
    ? (self::configFor($inboxId)['config']['business_hours'] ?? '1')
    : (self::globalConfig()['business_hours'] ?? '1');
$useBusinessHours = ((string) $effective) === '1';
```

`configFor()` já tem cache por caixa (adicionado em `17f3886`), então isso custa 1 query por caixa distinta, não por conversa. **A semântica do override:** `NULL` (herda o global) → usa a grade; `0` → a conversa **não** usa a grade (tempo de relógio puro); `1` → usa a grade.

- [ ] **Step 5: Grave a config global no controller**

Em `app/Controllers/SettingsController.php`, método `saveNotifications()` (linha 86), dentro do `SlaService::setAll([...])`, acrescente:

```php
'business_hours' => $request->post('sla_business_hours') ? '1' : '0',   // já existe
'response_minutes' => max(1, (int) $request->post('sla_response_minutes')),
'resolution_minutes' => max(1, (int) $request->post('sla_resolution_minutes')),
'escalate_managers' => $request->post('sla_escalate_managers') ? '1' : '0',
'escalate_tag' => $request->post('sla_escalate_tag') ? '1' : '0',
'escalate_autoassign' => $request->post('sla_escalate_autoassign') ? '1' : '0',
'escalate_whatsapp' => $request->post('sla_escalate_whatsapp') ? '1' : '0',
'escalation_message' => trim((string) $request->post('sla_escalation_message')) ?: <DEFAULT_CONFIG['escalation_message']>,
```

- [ ] **Step 6: Grave os overrides por caixa**

Em `SettingsController::collectInboxSla()` (linha 475), acrescente ao array `$fields`:
`sla_response_minutes`, `sla_resolution_minutes`, `sla_business_hours`, `sla_escalate_managers`, `sla_escalate_tag`, `sla_escalate_autoassign`, `sla_escalate_whatsapp`.

E no corpo (depois de `$data['sla_alert_minutes'] = $alertMin;`):

```php
$data['sla_response_minutes'] = $request->post('sla_response_minutes') !== '' ? max(1, (int) $request->post('sla_response_minutes')) : null;
$data['sla_resolution_minutes'] = $request->post('sla_resolution_minutes') !== '' ? max(1, (int) $request->post('sla_resolution_minutes')) : null;
foreach (['sla_business_hours','sla_escalate_managers','sla_escalate_tag','sla_escalate_autoassign','sla_escalate_whatsapp'] as $flag) {
    $data[$flag] = $request->post($flag) === null ? null : ($request->post($flag) ? 1 : 0);
}
```

**Atenção:** o `sla_use_global` já zera todos os campos para `null` via `array_fill_keys($fields, null)` — por isso as 7 colunas novas precisam entrar em `$fields`, senão o "usar padrão do sistema" não limpa os overrides.

Em `slaFormState()` (linha 524), acrescente ao array `$values` as chaves `response_minutes`, `resolution_minutes`, `business_hours`, `escalate_managers`, `escalate_tag`, `escalate_autoassign`, `escalate_whatsapp`, com o mesmo padrão `$hasOverride ? ($inbox[...] ?? $cfg[...]) : $cfg[...]`.

- [ ] **Step 7: Adicione os campos à UI global**

Em `app/Views/settings/notifications.php`, dentro do card de SLA (depois do bloco de sons de alerta, antes de fechar o `card-body`), acrescente uma subseção "SLA — Medição e escalonamento" com:
- `sla_response_minutes` (`<input type="number" min="1" max="10080" name="sla_response_minutes" value="<?= e($sla['response_minutes'] ?? '15') ?>">`)
- `sla_resolution_minutes` (`min="1" max="43200"`, default `1440`)
- 4 checkboxes: `sla_escalate_managers`, `sla_escalate_tag`, `sla_escalate_autoassign`, `sla_escalate_whatsapp`
- `sla_escalation_message` (`<textarea name="sla_escalation_message">`)

Os checkboxes usam o mesmo markup do `sla_enabled` já existente na linha 146.

- [ ] **Step 8: Adicione os campos à UI por caixa**

Em `app/Views/settings/inbox_form.php`, dentro do card de SLA da caixa, acrescente os mesmos campos usando o array `$sv` que a view já recebe (`$sv['response_minutes']` etc.), respeitando o `slaUseGlobal` que já desabilita os campos da caixa.

- [ ] **Step 9: Rode o teste e confirme que passa**

Run: `php tests/run.php`
Expected: as 5 linhas novas passam; nenhuma anterior regrediu.

- [ ] **Step 10: Commit**

```bash
git add app/Services/SlaService.php app/Controllers/SettingsController.php app/Views/settings/notifications.php app/Views/settings/inbox_form.php tests/run.php
git commit -m "feat(sla): config dos limites de medicao e dos toggles de escalonamento

Estende DEFAULT_CONFIG/INBOX_SLA_FIELDS do SlaService com os limites de
primeira resposta e resolucao e os 4 toggles de escalonamento, de modo
que a config por caixa reaproveita o merge e o cache existentes. O
applyClock passa a respeitar o override por caixa do horario comercial."
```

---

### Task 5: Worker por cron

**Files:**
- Create: `workers/sla_worker.php`
- Modify: `app/Models/ConversationSla.php` (queries de batch)
- Test: `tests/e2e_sla_measurable.php`

**Interfaces:**
- Consumes: `SlaMeasurement::forConversation()`, `SlaMeasurement::saveMeasurement()`, `ConversationSla::find()`, `ConversationSla::save()`.
- Produces:
  - `App\Models\ConversationSla::activeWithoutRow(int $limit): array` — conversas com status ativo e sem linha em `conversation_slas`.
  - `App\Models\ConversationSla::closedWithoutRow(int $limit): array` — conversas encerradas (`closed_at IS NOT NULL`) sem linha.
  - `App\Models\ConversationSla::openPeriods(int $limit): array` — conversas ativas cuja linha tem `period_started_at` no passado (recalcular).
  - `workers/sla_worker.php` — executa uma vez e sai (cron `* * * * *`), no mesmo formato de `workers/flow_worker.php`.

- [ ] **Step 1: Escreva o teste falhando (fiação)**

Em `tests/run.php`:

```php
$workerSrc = file_get_contents(__DIR__ . '/../workers/sla_worker.php') ?: '';
check('sla: o worker existe', $workerSrc !== '');
check('sla: o worker mede as conversas', str_contains($workerSrc, 'forConversation'));
check('sla: o worker detecta a violacao', str_contains($workerSrc, 'response_breached'));
```

- [ ] **Step 2: Rode o teste e confirme que falha**

Run: `php tests/run.php`
Expected: as 3 linhas novas falham com `FALHOU` (o arquivo do worker ainda não existe; o guard `?: ''` evita o `TypeError` do PHP 8).

- [ ] **Step 3: Implemente as queries de batch no `ConversationSla`**

Em `app/Models/ConversationSla.php`:

```php
public static function activeWithoutRow(int $limit = 200): array
public static function closedWithoutRow(int $limit = 200): array
public static function openPeriods(int $limit = 200): array
```

`activeWithoutRow()`:
```sql
SELECT c.id FROM conversations c
LEFT JOIN conversation_slas s ON s.conversation_id = c.id
WHERE s.id IS NULL
  AND c.status IN ('new','open','waiting_customer','waiting_internal')
  AND c.group_id IS NULL
ORDER BY c.created_at ASC
LIMIT ?
```

`closedWithoutRow()`:
```sql
SELECT c.id FROM conversations c
LEFT JOIN conversation_slas s ON s.conversation_id = c.id
WHERE s.id IS NULL
  AND c.closed_at IS NOT NULL
  AND c.group_id IS NULL
ORDER BY c.closed_at ASC
LIMIT ?
```

`openPeriods()`:
```sql
SELECT s.conversation_id FROM conversation_slas s
JOIN conversations c ON c.id = s.conversation_id
WHERE s.period_started_at IS NOT NULL
  AND s.period_started_at < ?
  AND c.status IN ('new','open','waiting_customer','waiting_internal')
  AND c.group_id IS NULL
ORDER BY s.period_started_at ASC
LIMIT ?
```

- [ ] **Step 4: Implemente `workers/sla_worker.php`**

Copie a estrutura de `workers/flow_worker.php`: bootstrap (`require_once __DIR__ . '/../scripts/bootstrap.php';`), `date_default_timezone_set` já vem do bootstrap, `Database::connect()` já vem do bootstrap.

Fluxo (uma execução, depois sai):

```
1. $limit = 200
2. Para cada id de openPeriods():
     $m = SlaMeasurement::forConversation($id)
     se $m === null → continue
     $prev = ConversationSla::find($id)
     SlaMeasurement::saveMeasurement($id, $m)
     se $m['response_breached'] && !($prev['response_breached'] ?? 0):
         Conversation::addEvent($id, 'sla_breached', 'SLA de primeira resposta violado', null, ['response_seconds'=>..., 'threshold'=>...])
         (o escalonamento entra na Task 6 — por enquanto, só o evento)
3. Para cada id de activeWithoutRow():
     $m = SlaMeasurement::forConversation($id); se null → continue
     SlaMeasurement::saveMeasurement($id, $m)
     (mesma detecção 0->1 do passo 2)
4. Para cada id de closedWithoutRow():
     $m = SlaMeasurement::forConversation($id); se null → continue
     SlaMeasurement::saveMeasurement($id, $m)
     (conversa encerrada: a violação de resolução já vem calculada)
5. Log: "SLA: N conversas medidas" + timestamp
6. catch (\Throwable) → error_log() + exit(1)
```

**Idempotência:** rodar duas vezes não muda nada — `saveMeasurement()` regrava o mesmo valor e a detecção 0→1 só dispara uma vez porque o valor anterior já está gravado.

- [ ] **Step 5: Escreva o e2e do worker**

Em `tests/e2e_sla_measurable.php`, acrescente:
- cria uma conversa `open` com uma mensagem do cliente em `2026-10-19 09:00:00` (dentro do horário do departamento 2, configurado no `finally`);
- roda `php workers/sla_worker.php` via `exec()` (ou chama as queries direto);
- verifica que `ConversationSla::find($convId)` não é null, que `period_started_at` === `'2026-10-19 09:00:00'` e que `response_seconds` === null (ainda sem resposta);
- roda o worker de novo e verifica que **nada mudou** (idempotência);
- insere a resposta humana em `2026-10-19 09:20:00`, roda o worker, e verifica `response_seconds` === 1200 e `response_breached` === 0 (limite default 15 min = 900s, e 1200 > 900 → **`response_breached` deve ser `true`**; ajuste o limite do departamento/setting no teste para 1440 min se quiser o caso "não violou", ou asserte `true` com 1200s contra o default de 900s).

**Simplificação:** fixe o limite global no teste com `Setting::set('sla_response_minutes', '15')` (900s) e asserte `response_breached === true` com 1200s de espera. Restaure o valor anterior no `finally`.

- [ ] **Step 6: Rode o e2e e confirme que passa**

Run: `php tests/e2e_sla_measurable.php`
Expected: todas as linhas `ok`, `cleanup ok`, exit 0.

- [ ] **Step 7: Commit**

```bash
git add workers/sla_worker.php app/Models/ConversationSla.php tests/e2e_sla_measurable.php tests/run.php
git commit -m "feat(sla): worker por cron que mede e detecta violacao

workers/sla_worker.php roda uma vez por minuto: recalcula as conversas
ativas, detecta a transicao 0->1 de response_breached (disparando o
evento sla_breached), finaliza as conversas encerradas e faz o backfill
das antigas em lote. Idempotente: rodar duas vezes nao muda nada."
```

---

### Task 6: Escalonamento

**Files:**
- Create: `app/Services/SlaEscalation.php`
- Modify: `app/Services/NotificationService.php` (`notifySystem()`)
- Modify: `workers/sla_worker.php` (chama o escalonamento)
- Test: `tests/e2e_sla_measurable.php`, `tests/run.php`

**Interfaces:**
- Consumes: `SlaService::configFor(?int $inboxId)['config']`, `App\Services\ConversationService::assign()`, `App\Models\Tag::ensureByName(string $name, string $color): int`, `App\Models\Conversation::addTag()`, `App\Services\WhatsAppService::sendToPhone(int $channelId, string $phone, string $message): ?string`, `App\Services\TemplateService::render(string $content, int $conversationId): string`.
- Produces:
  - `App\Services\NotificationService::notifySystem(array $userIds, string $title, string $body, ?int $conversationId = null): array` — cria uma notificação do tipo `system` para cada user id; devolve os ids criados.
  - `App\Services\SlaEscalation::escalate(int $conversationId, array $measurement, array $conv): array` — executa as 5 ações; devolve `['tag'=>bool,'managers'=>int,'autoassign'=>?int,'whatsapp'=>bool]`.

- [ ] **Step 1: Escreva os testes falhando (fiação)**

Em `tests/run.php`:

```php
$escSrc = file_get_contents(__DIR__ . '/../app/Services/SlaEscalation.php') ?: '';
check('sla: o escalonamento notifica os gerentes', str_contains($escSrc, 'notifySystem'));
check('sla: o escalonamento aplica a tag', str_contains($escSrc, 'ensureByName'));
check('sla: o escalonamento autoatribui o menos carregado', str_contains($escSrc, 'assign'));
check('sla: o escalonamento avisa por whatsapp', str_contains($escSrc, 'sendToPhone'));
$notifSvc = file_get_contents(__DIR__ . '/../app/Services/NotificationService.php') ?: '';
check('sla: existe notifySystem no servico de notificacoes', str_contains($notifSvc, 'function notifySystem'));
```

- [ ] **Step 2: Rode o teste e confirme que falha**

Run: `php tests/run.php`
Expected: as 5 linhas novas falham.

- [ ] **Step 3: Implemente `NotificationService::notifySystem()`**

Em `app/Services/NotificationService.php`, ao lado de `notifyAssigned()`:

```php
/**
 * Notificação do tipo `system` (uso interno: escalonamento de SLA,
 * avisos administrativos). Devolve os ids criados.
 */
public static function notifySystem(array $userIds, string $title, string $body, ?int $conversationId = null): array
```

Filtra ids vazios/zero, deduplica, e para cada um chama `Notification::create([...])` com `notification_type = self::TYPE_SYSTEM`, `title`, `body`, `conversation_id` e `metadata` = `json_encode(['source' => 'sla_escalation'])`.

- [ ] **Step 4: Implemente `App\Services\SlaEscalation`**

Crie `app/Services/SlaEscalation.php`, namespace `App\Services`.

```php
public static function escalate(int $conversationId, array $measurement, array $conv): array
```

Fluxo:

```
1. $cfg = SlaService::configFor($conv['inbox_id'] ?? null)['config']
   $result = ['tag'=>false,'managers'=>0,'autoassign'=>null,'whatsapp'=>false]
2. AÇÃO 2 — tag (se $cfg['escalate_tag'] === '1'):
     $tagId = Tag::ensureByName('SLA violado', '#dc2626')
     Conversation::addTag($conversationId, $tagId)
     $result['tag'] = true
3. AÇÃO 3 — gerentes (se $cfg['escalate_managers'] === '1' e $conv['department_id']):
     $managers = Database::fetchAll(
       "SELECT du.user_id FROM department_users du
        JOIN users u ON u.id = du.user_id AND u.is_active = 1
        WHERE du.department_id = ? AND du.is_manager = 1", [$conv['department_id']])
     $ids = array_map(fn($r) => (int) $r['user_id'], $managers)
     $created = NotificationService::notifySystem($ids, 'SLA de primeira resposta violado',
        "A conversa #{$conversationId} furou o SLA de primeira resposta.", $conversationId)
     $result['managers'] = count($created)
4. AÇÃO 4 — auto-atribuição (se $cfg['escalate_autoassign'] === '1'):
     só se a conversa estiver sem responsável (assigned_user_id vazio) ou com responsável inativo
     $candidates = candidatos sem admins:
       "SELECT u.id FROM users u
        LEFT JOIN inbox_users iu ON iu.user_id = u.id AND iu.inbox_id = ?
        LEFT JOIN department_users du ON du.user_id = u.id
        LEFT JOIN inbox_departments idp ON idp.department_id = du.department_id AND idp.inbox_id = ?
        WHERE u.is_active = 1 AND u.role != 'admin'
          AND (iu.id IS NOT NULL OR idp.id IS NOT NULL)
        GROUP BY u.id"
     carga de cada um: contagem de conversas abertas atribuídas
       "SELECT (SELECT COUNT(*) FROM conversations c
         WHERE c.assigned_user_id = ? AND c.status IN ('new','open','waiting_customer','waiting_internal')) AS load"
     $winner = o de menor carga (desempate pelo menor id)
     se houver winner: ConversationService::assign($conversationId, $winner)
     $result['autoassign'] = $winner
5. AÇÃO 5 — WhatsApp (se $cfg['escalate_whatsapp'] === '1'):
     $phone = departments.sla_escalation_phone do departamento
     se vazio → pula (log 'sem telefone de plantao') e $result['whatsapp'] = false
     senão:
       $message = render do template:
         - substitui {{sla.wait}}, {{sla.limit}}, {{sla.type}} pelos valores calculados
           ({{sla.wait}} = "23 min", {{sla.limit}} = "15 min", {{sla.type}} = "primeira resposta")
         - depois TemplateService::render($message, $conversationId) resolve o resto
       $channelId = $conv['channel_id'] ?: primeiro canal whatsapp do departamento
       WhatsAppService::sendToPhone($channelId, $phone, $message)
       $result['whatsapp'] = true
6. Conversation::addEvent($conversationId, 'sla_escalated', 'SLA escalonado', null, $result)
7. return $result
```

- [ ] **Step 5: Ligue o escalonamento no worker**

Em `workers/sla_worker.php`, no ponto onde hoje só dispara o evento `sla_breached`, chame também:

```php
SlaEscalation::escalate($id, $m, $conv);
```

`$conv` vem de `Conversation::find($id)`. O `SlaEscalation` grava o evento `sla_escalated` — **não** duplique o evento no worker.

- [ ] **Step 6: Escreva o e2e do escalonamento**

Em `tests/e2e_sla_measurable.php`, acrescente:
- cria um departamento de teste (ou usa o 2) com `sla_escalation_phone` vazio;
- cria um gerente no departamento (`department_users` com `is_manager = 1`);
- cria uma conversa `open` com cliente em `2026-10-19 09:00:00` e **sem** resposta;
- roda o worker;
- verifica que existe uma notificação do tipo `system` para o gerente, ligada à conversa;
- verifica que a conversa recebeu a tag "SLA violado";
- roda o worker de novo e verifica que **não** duplicou a notificação (idempotência do escalonamento);
- restaura `department_users` e as settings no `finally`.

O limite precisa estar em 15 min (900s) e a espera ser > 900s: use a mensagem do cliente em `2026-10-19 09:00:00` e rode o worker com um `now` em `2026-10-19 10:00:00` (3600s de espera) — o worker usa `date()`, então **injete o instante**: o `forConversation()` aceita `$now`; faça o worker aceitar um `$now` por variável de ambiente `SLA_NOW` (usado só em teste) ou chame `SlaEscalation::escalate()` direto no e2e com uma medição montada à mão.

**Escolha mais simples para o e2e:** chame `SlaEscalation::escalate($convId, $measurement, $conv)` direto, com `$measurement` montado à mão (`['response_seconds'=>3600,'response_breached'=>true,'response_threshold'=>900,'resolution_seconds'=>3600,'resolution_breached'=>false,'resolution_threshold'=>86400,'response_at'=>null,'period_started_at'=>'2026-10-19 09:00:00']`). Isso testa o escalonamento isolado, sem depender do relógio do worker.

- [ ] **Step 7: Rode o e2e e confirme que passa**

Run: `php tests/e2e_sla_measurable.php`
Expected: todas as linhas `ok`, `cleanup ok`, exit 0.

- [ ] **Step 8: Rode a suite e confirme que passa**

Run: `php tests/run.php`
Expected: todas passam.

- [ ] **Step 9: Commit**

```bash
git add app/Services/SlaEscalation.php app/Services/NotificationService.php workers/sla_worker.php tests/run.php tests/e2e_sla_measurable.php
git commit -m "feat(sla): escalonamento automatico na violacao

5 acoes configuraveis: tag 'SLA violado', notificacao in-app aos
gerentes do departamento, autoatribuicao ao atendente menos carregado
(somente quando nao ha responsavel) e aviso por WhatsApp ao plantao
configurado no departamento. A autoatribuicao e o WhatsApp vem desligados
por default."
```

---

### Task 7: Chip de SLA na conversa e KPI no dashboard

**Files:**
- Modify: `app/Views/inbox/panel.php`
- Modify: `public/assets/js/app-enhancements.js`
- Modify: `app/Views/dashboard/index.php`
- Modify: `app/Controllers/Api/DashboardStatsController.php`
- Test: `tests/smoke_sla.php`

**Interfaces:**
- Consumes: `SlaMeasurement::forConversation()` (o chip precisa do valor), `ConversationSla::find()`, `ConversationSla::compliance()`, `ConversationSla::criticalNow()`.
- Produces:
  - `App\Models\ConversationSla::criticalNow(): int` — conversas ativas com `response_breached = 1`.
  - `App\Models\ConversationSla::compliance(int $days, ?int $departmentId = null, ?int $inboxId = null): array` — devolve `['response'=>['measured'=>int,'breached'=>int,'percent'=>?float], 'resolution'=>[...]]`.
  - `App\Models\ConversationSla::summaryForConversation(int $conversationId): ?array` — linha de SLA da conversa (ou null).
  - `App\Models\ConversationSla::forConversations(array $conversationIds): array` — linhas de SLA de várias conversas em **uma** query (`WHERE conversation_id IN (...)`), indexadas por `conversation_id`. Existe para o poll do inbox não virar N+1.
  - O chip: `header-chip header-chip-sla` no header de `app/Views/inbox/panel.php`, re-renderizado pelo mesmo caminho de `updateHeaderChip()`.
  - `api/dashboard-stats` ganha as chaves `slaCriticalNow` (int) e `slaCompliance30` (float|null).

- [ ] **Step 1: Escreva o teste falhando (fiação)**

Em `tests/run.php`:

```php
$panelSrc = file_get_contents(__DIR__ . '/../app/Views/inbox/panel.php');
check('sla: o header da conversa tem o chip de sla', str_contains($panelSrc, 'header-chip-sla'));
$dashSrc = file_get_contents(__DIR__ . '/../app/Views/dashboard/index.php');
check('sla: o dashboard tem o card de sla', str_contains($dashSrc, 'slaCriticalNow'));
$statsSrc = file_get_contents(__DIR__ . '/../app/Controllers/Api/DashboardStatsController.php');
check('sla: a api do dashboard devolve o sla critico', str_contains($statsSrc, 'slaCriticalNow'));
```

- [ ] **Step 2: Rode o teste e confirme que falha**

Run: `php tests/run.php`
Expected: as 3 linhas novas falham.

- [ ] **Step 3: Implemente as queries no `ConversationSla`**

Em `app/Models/ConversationSla.php`:

```php
public static function criticalNow(): int
public static function compliance(int $days, ?int $departmentId = null, ?int $inboxId = null): array
public static function summaryForConversation(int $conversationId): ?array
public static function forConversations(array $conversationIds): array
```

`forConversations()`: uma query com `WHERE conversation_id IN (...)` (uma por lote do poll), devolvendo um array indexado por `conversation_id`. Devolve `[]` para lista vazia.

`criticalNow()`:
```sql
SELECT COUNT(*) c FROM conversation_slas s
JOIN conversations c ON c.id = s.conversation_id
WHERE s.response_breached = 1
  AND c.status IN ('new','open','waiting_customer','waiting_internal')
```

`compliance()`: agrega sobre `conversation_slas` + `conversations` filtrando `c.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)`, contando `measured` (linhas com `response_seconds IS NOT NULL`) e `breached`. `percent` = `measured > 0 ? round((1 - breached/measured) * 100, 1) : null`.

`summaryForConversation()`: `SELECT * FROM conversation_slas WHERE conversation_id = ?`.

- [ ] **Step 4: Adicione o chip de SLA no header da conversa**

Em `app/Views/inbox/panel.php`, no header (perto do `header-chip-priority` da linha 260), acrescente um chip no mesmo padrão:

```php
<?php $slaSummary = \App\Models\ConversationSla::summaryForConversation((int)$conv['id']); ?>
<button type="button" class="header-chip header-chip-sla" title="SLA da conversa" style="...">
    <i class="fas fa-stopwatch" style="color:<?= $slaBreached ? 'var(--danger)' : 'var(--text-secondary)' ?>"></i>
    <span class="chip-text">SLA</span>
</button>
```

Conteúdo do chip: `1ª resposta: 8 min / 15 min` e `Resolução: 2h10 / 24h`. Quando `response_breached` ou `resolution_breached`, use a cor de alerta (`#dc2626`). Quando não houver linha de SLA, o chip mostra "SLA —" em cinza.

- [ ] **Step 5: Re-renderize o chip no poll**

Em `public/assets/js/app-enhancements.js`, o painel já re-renderiza header chips no poll (`updateHeaderChip` em `app/Views/inbox/panel.php:935` é JS **inline na view**, não no app-enhancements). Então o JS do **panel.php** é o lugar certo.

Em `app/Views/inbox/panel.php`, no bloco JS que re-renderiza o chip de prioridade (`priorityBadgeHtml`, linha ~2510), acrescente a função `slaChipHtml(c)` e chame-a no mesmo ponto onde `priorityBadgeHtml` é usada (linha ~2483). A API `/api/conversations` devolve as linhas da conversa; para o SLA, inclua em cada linha os campos `sla_response_seconds`, `sla_response_breached`, `sla_response_threshold`, `sla_resolution_seconds`, `sla_resolution_breached`, `sla_resolution_threshold` — eles vêm de `ConversationSla::summaryForConversation()`.

**Para isso, o `apiConversations` precisa incluir esses campos:** em `InboxController::apiConversations()`, depois de `Conversation::getConversationsForInboxes(...)`, acrescente para cada linha:

```php
$summary = \App\Models\ConversationSla::summaryForConversation((int) $c['id']);
$c['sla_response_seconds'] = $summary['response_seconds'] ?? null;
$c['sla_response_breached'] = (int) ($summary['response_breached'] ?? 0);
$c['sla_response_threshold'] = $summary['response_threshold'] ?? null;
$c['sla_resolution_seconds'] = $summary['resolution_seconds'] ?? null;
$c['sla_resolution_breached'] = (int) ($summary['resolution_breached'] ?? 0);
$c['sla_resolution_threshold'] = $summary['resolution_threshold'] ?? null;
```

**Cuidado com N+1:** `summaryForConversation()` por conversa = 1 query por linha do poll. Faça **uma** query para todas as conversas do poll: `ConversationSla::forConversations(array $ids): array` (um `WHERE conversation_id IN (...)`).

- [ ] **Step 6: Adicione o card de KPI no dashboard**

Em `app/Controllers/Api/DashboardStatsController.php`, dentro do closure de `FileCache::remember`, acrescente:

```php
$slaCriticalNow = \App\Models\ConversationSla::criticalNow();
$slaCompliance30 = \App\Models\ConversationSla::compliance(30);
```

e inclua `'slaCriticalNow' => $slaCriticalNow, 'slaCompliance30' => $slaCompliance30['response']['percent'] ?? null,` no array retornado.

Em `app/Views/dashboard/index.php`, no `stats-grid`, acrescente um `stat-card` no padrão das existentes (linha 20):

```php
<div class="stat-card">
    <div class="stat-icon stat-icon-danger"><i class="fas fa-stopwatch"></i></div>
    <div class="stat-info">
        <span class="stat-value" id="slaCritical"><?= (int)($slaCriticalNow ?? 0) ?></span>
        <span class="stat-label">SLA crítico agora</span>
        <?php if ($slaCompliance30 !== null): ?>
        <span class="stat-sub"><?= $slaCompliance30 ?>% dentro do SLA (30d)</span>
        <?php endif; ?>
    </div>
</div>
```

E no JS de auto-refresh do dashboard (mesmo padrão dos outros cards, `dashboard/index.php:901`), atualize `#slaCritical` com `d.slaCriticalNow`.

O `DashboardController::index()` precisa passar `slaCriticalNow` e `slaCompliance30` para a view — inclua-os no array do `View::renderWithLayout`.

- [ ] **Step 7: Rode o teste e confirme que passa**

Run: `php tests/run.php`
Expected: as 3 linhas novas passam.

- [ ] **Step 8: Estenda o smoke HTTP**

Em `tests/smoke_sla.php`, acrescente:
- `GET /api/dashboard-stats` → o JSON tem `slaCriticalNow`;
- `GET /inbox` → o HTML tem `header-chip-sla` **ou** a string `SLA` no drawer (o chip só aparece quando a conversa está selecionada; para o smoke, verifique a API `/api/conversations` devolvendo `sla_response_seconds`).

- [ ] **Step 9: Rode o smoke e confirme que passa**

Run: (com o servidor rodando em :8099) `php tests/smoke_sla.php`
Expected: todas as linhas `ok`, exit 0.

- [ ] **Step 10: Commit**

```bash
git add app/Views/inbox/panel.php public/assets/js/app-enhancements.js app/Views/dashboard/index.php app/Controllers/Api/DashboardStatsController.php app/Controllers/InboxController.php app/Models/ConversationSla.php tests/run.php tests/smoke_sla.php
git commit -m "feat(sla): chip de sla na conversa e kpi no dashboard

O chip mostra 1a resposta e resolucao com o limite em vigor, no mesmo
padrao de header-chip da prioridade, re-renderizado pelo poll de 10s. O
dashboard ganha o card 'SLA critico agora' com o compliance de 30 dias."
```

---

### Task 8: Relatório `/reports/sla`

**Files:**
- Create: `app/Views/reports/sla.php`
- Modify: `app/Controllers/ReportsController.php`
- Modify: `routes/web.php`
- Modify: `app/Models/ConversationSla.php`
- Test: `tests/run.php`, `tests/smoke_sla.php`

**Interfaces:**
- Consumes: `ConversationSla::compliance()`, `ConversationSla::agentBreakdown(int $days): array`, `ConversationSla::departmentBreakdown(int $days): array`, `ConversationSla::inboxBreakdown(int $days): array`, `ConversationSla::violatedConversations(int $days, int $limit = 200): array`.
- Produces: `GET /reports/sla` (manager+), seguindo o padrão de `GET /reports/timeline`.

- [ ] **Step 1: Escreva o teste falhando (fiação)**

Em `tests/run.php`:

```php
$routesSrc = file_get_contents(__DIR__ . '/../routes/web.php');
check('sla: a rota do relatorio existe', str_contains($routesSrc, "'/reports/sla'"));
$repSrc = file_get_contents(__DIR__ . '/../app/Controllers/ReportsController.php');
check('sla: o controller tem o metodo sla', str_contains($repSrc, 'function sla'));
```

- [ ] **Step 2: Rode o teste e confirme que falha**

Run: `php tests/run.php`
Expected: as 2 linhas novas falham.

- [ ] **Step 3: Implemente as queries de breakdown no `ConversationSla`**

Em `app/Models/ConversationSla.php`, quatro métodos:

```php
public static function agentBreakdown(int $days): array
public static function departmentBreakdown(int $days): array
public static function inboxBreakdown(int $days): array
public static function violatedConversations(int $days, int $limit = 200): array
```

Os três breakdowns devolvem a mesma forma: lista de
`['label' => string, 'total' => int, 'breached' => int, 'percent' => ?float]`, ordenados
por `total` desc. Cada um faz `JOIN` na tabela correspondente (`conversations.assigned_user_id`
→ `users.name`; `department_id` → `departments.name`; `inbox_id` → `inboxes.name`) e
filtra `c.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)`.

`violatedConversations()` devolve as conversas com `response_breached = 1` ou
`resolution_breached = 1` no período, com: `id`, `protocol`, `contact_name`,
`assigned_user_name`, `department_name`, `inbox_name`, `response_seconds`,
`response_threshold`, `resolution_seconds`, `resolution_threshold`, `created_at`.

- [ ] **Step 4: Implemente `ReportsController::sla()`**

Em `app/Controllers/ReportsController.php`, método novo:

```php
public function sla(Request $request): void
```

- `$period = (int) $request->input('period', 30)`, aceito `7|30|90|all` (`all` → 3650 dias, mesmo tratamento do `timeline`).
- `$days = $period === 'all' ? 3650 : $period`.
- Monta o array de dados: `compliance`, `agentBreakdown`, `departmentBreakdown`, `inboxBreakdown`, `violatedConversations`.
- `View::renderWithLayout('reports/sla', 'main', [...])` com `title` = "SLA", `activePage` = "reports", `period`, `days` e os dados.

- [ ] **Step 5: Crie a view `app/Views/reports/sla.php`**

Siga o padrão de `app/Views/reports/index.php`:
- seletor de período (7/30/90/todo) no mesmo formato da linha 14;
- `stats-grid` com 4 `stat-card`: compliance de 1ª resposta (%), compliance de resolução (%), conversas medidas, violações;
- `dashboard-grid` com 3 `chart-card`: um `hbar-row` por breakdown (mesmo markup das linhas 54+ do `reports/index.php`);
- tabela das conversas violadas;
- link para o CSV.

- [ ] **Step 6: Registre a rota**

Em `routes/web.php`, ao lado das outras de relatório (linhas 228-234):

```php
$router->get('/reports/sla', [ReportsController::class, 'sla'], ['manager']);
```

- [ ] **Step 7: Adicione o link no menu de relatórios**

Onde a view de relatórios é listada no menu (sidebar), acrescente o item "SLA" apontando para `url('reports/sla')`.

- [ ] **Step 8: Rode o teste e confirme que passa**

Run: `php tests/run.php`
Expected: as 2 linhas novas passam.

- [ ] **Step 9: Estenda o smoke HTTP**

Em `tests/smoke_sla.php`: `GET /reports/sla` responde 200 e o HTML contém "Compliance".

- [ ] **Step 10: Commit**

```bash
git add app/Views/reports/sla.php app/Controllers/ReportsController.php routes/web.php app/Models/ConversationSla.php tests/run.php tests/smoke_sla.php
git commit -m "feat(sla): relatorio de compliance em /reports/sla

Compliance de primeira resposta e de resolucao no periodo, com quebra
por agente, departamento e caixa, e a lista das conversas violadas."
```

---

### Task 9: Verificação final e documentação

**Files:**
- Modify: `README.md`, `FUNCIONALIDADES_SISTEMA.md`
- Test: todos

**Interfaces:**
- Consumes: tudo.
- Produces: documentação atualizada e a suíte verde.

- [ ] **Step 1: Rode a suíte completa**

Run: `php tests/run.php`
Expected: `0 failed`.

- [ ] **Step 2: Rode o e2e do SLA**

Run: `php tests/e2e_sla_measurable.php`
Expected: todas as linhas `ok`, `cleanup ok`, exit 0.

- [ ] **Step 3: Rode o e2e do SLA do relógio (regressão)**

Run: `php tests/e2e_sla.php`
Expected: exit 0 — o trabalho anterior não regrediu.

- [ ] **Step 4: Rode o e2e das notificações (regressão)**

Run: `php tests/e2e_notifications.php`
Expected: exit 0.

- [ ] **Step 5: Rode o smoke HTTP**

Run: (servidor em :8099) `php tests/smoke_sla.php`
Expected: exit 0.

- [ ] **Step 6: Verifique a sintaxe de tudo**

Run:
```bash
php -l app/Services/SlaMeasurement.php
php -l app/Services/SlaEscalation.php
php -l app/Models/ConversationSla.php
php -l workers/sla_worker.php
node --check public/assets/js/app-enhancements.js
```
Expected: sem erros.

- [ ] **Step 7: Aplique as migrations em base limpa**

Run: `php database/migrate.php`
Expected: `Migration complete.` e rodar de novo não muda nada (idempotente).

- [ ] **Step 8: Atualize a documentação**

Em `README.md`, acrescente na seção de testes o comando `php tests/e2e_sla_measurable.php` e um parágrafo curto sobre o que a feature entrega.

Em `FUNCIONALIDADES_SISTEMA.md`, acrescente uma subseção **6.6 SLA — medição e compliance** descrevendo: a tabela `conversation_slas`, o motor `SlaMeasurement` (histórico como fonte da verdade, pareamento guloso, pausa por `waiting_internal`, limites snapshotados), o worker `workers/sla_worker.php` (cron), as 5 ações de escalonamento e o relatório `/reports/sla`. Atualize também o item 13 do checklist de reimplantação (§22) e a lista de migrations (§17) com `2026_10_20_sla_measurable.sql`.

- [ ] **Step 9: Commit**

```bash
git add README.md FUNCIONALIDADES_SISTEMA.md
git commit -m "docs(sla): documenta a medicao de compliance e o worker"
```

---

## Checklist de cobertura da spec

| Seção da spec | Tarefa |
|---|---|
| §2.1 dois SLAs, só humano conta | Task 2 (passos 1 e 3) |
| §2.2 pausa por `waiting_internal` + metadata no evento | Task 2 (passo 3), Task 3 (passos 3 e 4) |
| §2.3 histórico como fonte da verdade | Task 2, Task 3, Task 5 |
| §2.4 limites snapshotados | Task 4 (passos 1 e 3), Task 2 (teste 14) |
| §2.5 escalonamento só na 1ª resposta | Task 6 |
| §3.1 tabela `conversation_slas` | Task 1 |
| §3.2 colunas em `inboxes`/`departments` | Task 1 |
| §3.3 enum de `conversation_events` | Task 1 |
| §3.4 chaves globais | Task 4 |
| §4.1–4.3 motor de medição | Task 2, Task 3 |
| §4.4 `forConversation()` | Task 3 |
| §5 worker | Task 5 |
| §6.1 config global | Task 4 (passos 5 e 7) |
| §6.2 config por caixa | Task 4 (passos 6 e 8) |
| §7.1 chip na conversa | Task 7 (passos 4 e 5) |
| §7.2 KPI no dashboard | Task 7 (passo 6) |
| §7.3 relatório `/reports/sla` | Task 8 |
| §8 escalonamento (5 ações) | Task 6 |
| §9.1 testes unitários | Task 2, Task 3, Task 4, Task 6, Task 7, Task 8 |
| §9.2 e2e | Task 3, Task 5, Task 6 |
| §9.3 smoke | Task 7, Task 8 |
| §10 riscos | Task 5 (lotes), Task 2 (backfill best-effort) |
