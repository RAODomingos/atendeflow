# Reabertura de atendimento — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dar ao gestor três opções de tratamento de atendimento (único / reabrir sempre / reabrir por janela de tempo), configuráveis por caixa, valendo para mensagens do WhatsApp.

**Architecture:** Uma classe nova `App\Models\AtendimentoPolicy` resolve a regra vigente (override da caixa > padrão global) e decide, de forma pura, se o atendimento anterior do mesmo contato no mesmo canal é reaberto. O ponto único de decisão é `WhatsAppService::findOrCreateConversation()`. A reabertura acontece na **mesma conversa** e reutiliza o bloco de limpeza de fechamento que já existe em `ConversationService::changeStatus()`.

**Tech Stack:** PHP 8.3 (sem framework), MySQL 8, views server-rendered + JS vanilla, suíte de testes própria (`tests/run.php` + e2e em `tests/`).

**Spec:** `docs/superpowers/specs/2026-10-29-atendimento-reabertura-design.md`

## Global Constraints

- **O PHP não está no PATH.** Sempre chamar pelo caminho completo:
  `C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe` (variável `$php` nos comandos).
- **Suíte completa:** `& $php tests/run.php` — sai com código 1 se qualquer check falhar. Rodar **toda** tarefa que mexer em código.
- **Fuso:** o app grava tudo em `America/Sao_Paulo` (`config/app.php`). Todo fixture/script novo precisa de
  `date_default_timezone_set((require config/app.php)['timezone'])` — sem isso o timestamp sai 3h fora.
- **Nunca `git add -A` nem `git add .`.** A árvore tem WIP do parceiro em `app/Services/ConversationService.php`,
  `app/Services/WhatsAppService.php`, `app/Controllers/InboxController.php`, `app/Services/WhatsApp/*Provider.php`,
  `app/Views/inbox/*` e `routes/web.php`. Stagear **um caminho por vez** e conferir com `git diff --cached --name-only`
  antes de commitar.
- **Interface em PT-BR**, HTML server-rendered no padrão visual das views existentes (cards com `var(--bg-card)`,
  `var(--border-soft)`, `form-hint`, ícones Font Awesome).
- **Sem framework de JS.** Toggle de campo em `<script>` inline, no padrão do `slaUseGlobal` já existente em
  `app/Views/settings/inbox_form.php`.
- **Nomes de coluna em português** (`atendimento_modo`, `atendimento_janela_minutos`), igual ao resto do schema.
- **Valores internos de modo em inglês**: `unique`, `always`, `window`.
- **Não mexer** em `app/Views/inbox/*`, `app/Controllers/InboxController.php` nem `routes/web.php` (WIP do parceiro).
- **Modo padrão do sistema = `unique`**: instalar a feature não muda o comportamento de ninguém.

---

## Mapa de arquivos

| Arquivo | Responsabilidade |
|---|---|
| `app/Models/AtendimentoPolicy.php` (novo) | Modos, normalização, resolução da política (caixa > global), candidato a "atendimento anterior", decisão de reabertura. |
| `app/Services/ConversationService.php` (modifica) | `reopenConversation()` — transição "finalizada → ativa" na mesma conversa; `changeStatus()` passa a usá-la. |
| `app/Services/WhatsAppService.php` (modifica) | `findOrCreateConversation()` — consulta a política antes de criar conversa nova. |
| `app/Controllers/SettingsController.php` (modifica) | Persistência do padrão global e do override da caixa; estado inicial do formulário. |
| `app/Views/settings/inbox_form.php` (modifica) | Card "Reabertura de atendimento" por caixa. |
| `app/Views/settings/general.php` (modifica) | Card "Reabertura de atendimento" com o padrão do sistema. |
| `database/migrations/2026_10_29_atendimento_reabertura.sql` (novo) | Colunas em `inboxes` + settings padrão. |
| `database/schema.sql` (modifica) | Colunas em `inboxes` + settings padrão (instalação nova). |
| `tests/e2e_atendimento_reabertura.php` (novo) | Cobertura da regra ponta a ponta. |
| `tests/run.php` (modifica) | Checks da decisão pura + registro do e2e novo. |

---

### Task 1: Banco — migração, schema.sql e base local

**Files:**
- Create: `database/migrations/2026_10_29_atendimento_reabertura.sql`
- Modify: `database/schema.sql` (tabela `inboxes`, linhas ~183-197)

**Interfaces:**
- Consumes: nada (primeira task).
- Produces: colunas `inboxes.atendimento_modo` (VARCHAR(20) NULL) e `inboxes.atendimento_janela_minutos` (INT NULL);
  settings `atendimento_reabertura_modo` (`unique`) e `atendimento_reabertura_janela_minutos` (`120`).

- [ ] **Step 1: Escrever a migração**

Criar `database/migrations/2026_10_29_atendimento_reabertura.sql`:

```sql
-- Reabertura de atendimento: o que acontece quando o mesmo cliente
-- escreve de novo no WhatsApp depois de o atendimento anterior ter
-- sido encerrado.
--
-- NULL nas colunas da caixa = "usar o padrão do sistema" (mesma
-- semântica das colunas de SLA por caixa).
ALTER TABLE inboxes
  ADD COLUMN atendimento_modo VARCHAR(20) NULL DEFAULT NULL,
  ADD COLUMN atendimento_janela_minutos INT NULL DEFAULT NULL;

INSERT INTO settings (`key`, value) VALUES
  ('atendimento_reabertura_modo', 'unique'),
  ('atendimento_reabertura_janela_minutos', '120')
ON DUPLICATE KEY UPDATE `key` = `key`;
```

- [ ] **Step 2: Espelhar no schema.sql (instalação nova)**

Em `database/schema.sql`, dentro de `CREATE TABLE IF NOT EXISTS inboxes` (após a linha `sla_escalate_whatsapp TINYINT(1) NULL DEFAULT NULL,`), inserir:

```sql
    -- Reabertura de atendimento (NULL = usar o padrão do sistema).
    atendimento_modo VARCHAR(20) NULL DEFAULT NULL,
    atendimento_janela_minutos INT NULL DEFAULT NULL,
```

- [ ] **Step 3: Aplicar a migração na base local**

Rodar (o PHP não está no PATH):

```powershell
$ErrorActionPreference = 'Stop'
$php = 'C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe'
$code = @'
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/lib/autoload.php';
require_once __DIR__ . '/app/Core/Helper.php';
\App\Config\Environment::load();
$db = \App\Core\Database::getInstance();
$cols = array_column($db->fetchAll("SHOW COLUMNS FROM inboxes"), 'Field');
if (in_array('atendimento_modo', $cols, true)) {
    echo "colunas ja existem\n";
    exit(0);
}
$db->query(file_get_contents(__DIR__ . '/database/migrations/2026_10_29_atendimento_reabertura.sql'));
echo "migracao aplicada\n";
'@
Set-Content -Path 'D:\laragon\www\atendeflow\storage\apply_migration_atendimento.php' -Value $code -Encoding UTF8
& $php 'D:\laragon\www\atendeflow\storage\apply_migration_atendimento.php'
```

Esperado: `migracao aplicada` (ou `colunas ja existen` se já tiver sido aplicada).

- [ ] **Step 4: Conferir o resultado**

```powershell
$php = 'C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe'
$code = @'
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/lib/autoload.php';
require_once __DIR__ . '/app/Core/Helper.php';
\App\Config\Environment::load();
$db = \App\Core\Database::getInstance();
$cols = array_column($db->fetchAll("SHOW COLUMNS FROM inboxes"), 'Field');
echo "colunas: " . implode(', ', array_filter($cols, fn($c) => str_starts_with($c, 'atendimento_'))) . "\n";
$s = $db->fetchAll("SELECT `key`, value FROM settings WHERE `key` LIKE 'atendimento%'");
echo "settings: " . json_encode($s, JSON_UNESCAPED_UNICODE) . "\n";
'@
Set-Content -Path 'D:\laragon\www\atendeflow\storage\check_migration_atendimento.php' -Value $code -Encoding UTF8
& $php 'D:\laragon\www\atendeflow\storage\check_migration_atendimento.php'
```

Esperado:
`colunas: atendimento_modo, atendimento_janela_minutos` e
`settings: [{"key":"atendimento_reabertura_janela_minutos","value":"120"},{"key":"atendimento_reabertura_modo","value":"unique"}]`

- [ ] **Step 5: Commitar**

```powershell
git add -- 'database/migrations/2026_10_29_atendimento_reabertura.sql' 'database/schema.sql'
git diff --cached --name-only
git commit -m "feat(atendimento): colunas e padroes da regra de reabertura"
```

Conferir que `git diff --cached --name-only` mostra **apenas** os dois arquivos do banco antes do commit.

---

### Task 2: `AtendimentoPolicy` — modos, resolução e decisão

**Files:**
- Create: `app/Models/AtendimentoPolicy.php`
- Modify: `tests/run.php` (bloco novo de checks da decisão pura)

**Interfaces:**
- Consumes: colunas e settings da Task 1.
- Produces:
  - `AtendimentoPolicy::MODES` = `['unique', 'always', 'window']`
  - `AtendimentoPolicy::MODE_UNIQUE|MODE_ALWAYS|MODE_WINDOW`
  - `AtendimentoPolicy::DEFAULT_WINDOW_MINUTES` = `120`
  - `AtendimentoPolicy::SETTING_MODE` = `'atendimento_reabertura_modo'`
  - `AtendimentoPolicy::SETTING_WINDOW` = `'atendimento_reabertura_janela_minutos'`
  - `AtendimentoPolicy::normalizeMode(?string $mode): string`
  - `AtendimentoPolicy::normalizeWindowMinutes($minutes): int`
  - `AtendimentoPolicy::decide(string $mode, int $windowMinutes, ?string $referenceAt, ?int $now = null): bool` (puro)
  - `AtendimentoPolicy::globalConfig(): array{mode:string, window_minutes:int}`
  - `AtendimentoPolicy::forInbox(?int $inboxId): array{mode:string, window_minutes:int, source:string}`
  - `AtendimentoPolicy::previousConversation(int $channelId, int $contactId): ?array`
  - `AtendimentoPolicy::shouldReopen(?array $previous, array $policy): bool`
  - `AtendimentoPolicy::hasColumns(): bool`

- [ ] **Step 1: Escrever o teste que falha**

Em `tests/run.php`, adicionar o bloco abaixo **antes** de `echo "\n$passes passed, $failures failed\n";`:

```php
// ─────────────────────────────────────────────────────────────
// Reabertura de atendimento: a decisão é pura (sem banco)
// ─────────────────────────────────────────────────────────────
if (file_exists(__DIR__ . '/../app/Models/AtendimentoPolicy.php')) {
    require_once __DIR__ . '/../app/Models/AtendimentoPolicy.php';
}

function atendPolicy(array $args): bool
{
    if (!class_exists('App\Models\AtendimentoPolicy') || !method_exists('App\Models\AtendimentoPolicy', 'decide')) {
        return false;
    }
    $r = \App\Models\AtendimentoPolicy::decide(...$args);
    return $r === true;
}

$agora = '2026-10-29 12:00:00';
$tsAgora = strtotime($agora);

check('atendimento: modo unique nunca reabre', atendPolicy(['unique', 120, $agora, $tsAgora]) === false);
check('atendimento: modo always reabre sem olhar o tempo', atendPolicy(['always', 120, '2020-01-01 00:00:00', $tsAgora]) === true);
check('atendimento: modo window reabre dentro da janela', atendPolicy(['window', 120, '2026-10-29 11:00:00', $tsAgora]) === true);
check('atendimento: modo window reabre no limite exato', atendPolicy(['window', 120, '2026-10-29 10:00:00', $tsAgora]) === true);
check('atendimento: modo window cria novo fora da janela', atendPolicy(['window', 120, '2026-10-29 09:00:00', $tsAgora]) === false);
check('atendimento: modo window sem referencia nao reabre', atendPolicy(['window', 120, null, $tsAgora]) === false);
check('atendimento: modo desconhecido cai em unique', atendPolicy(['modo_bizarro', 120, $agora, $tsAgora]) === false);

$policyPath = __DIR__ . '/../app/Models/AtendimentoPolicy.php';
$policySrc = is_file($policyPath) ? (string) file_get_contents($policyPath) : '';
check('atendimento: a politica ignora conversa de grupo', str_contains($policySrc, 'group_id IS NULL'));
check('atendimento: a politica ignora spam', str_contains($policySrc, "'resolved', 'closed'"));
check('atendimento: a politica tem guarda de coluna ausente', str_contains($policySrc, 'hasColumns'));
```

- [ ] **Step 2: Rodar o teste para ver falhar**

```powershell
$php = 'C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe'
& $php tests/run.php
```

Esperado: os 7 checks `atendimento: ...` falham (`FALHOU`), porque `app/Models/AtendimentoPolicy.php` ainda não existe.
Os demais checks continuam passando.

- [ ] **Step 3: Implementar a classe**

Criar `app/Models/AtendimentoPolicy.php`:

```php
<?php

namespace App\Models;

use App\Core\Database;

/**
 * Regra de reabertura de atendimento.
 *
 * Decide o que acontece quando o mesmo cliente escreve de novo no
 * WhatsApp depois de o atendimento anterior ter sido encerrado:
 *
 *  - unique: todo atendimento é único (comportamento padrão);
 *  - always: a nova mensagem reabre o atendimento anterior;
 *  - window: reabre o anterior só se a última mensagem dele foi há
 *    menos de X minutos.
 *
 * A regra vigente é a da CAIXA do atendimento anterior (é ela que
 * volta para a fila e que tem SLA). Sem override na caixa, vale o
 * padrão do sistema.
 */
class AtendimentoPolicy
{
    public const MODE_UNIQUE = 'unique';
    public const MODE_ALWAYS = 'always';
    public const MODE_WINDOW = 'window';

    public const MODES = [self::MODE_UNIQUE, self::MODE_ALWAYS, self::MODE_WINDOW];

    public const DEFAULT_WINDOW_MINUTES = 120;

    public const SETTING_MODE = 'atendimento_reabertura_modo';
    public const SETTING_WINDOW = 'atendimento_reabertura_janela_minutos';

    /**
     * Modo desconhecido (ou ausente) cai no padrão seguro: único.
     */
    public static function normalizeMode(?string $mode): string
    {
        $mode = strtolower(trim((string) $mode));
        return in_array($mode, self::MODES, true) ? $mode : self::MODE_UNIQUE;
    }

    /**
     * Janela em minutos. Zero ou negativo não faz sentido: volta ao
     * padrão do sistema.
     */
    public static function normalizeWindowMinutes($minutes): int
    {
        $n = (int) $minutes;
        return $n > 0 ? $n : self::DEFAULT_WINDOW_MINUTES;
    }

    /**
     * Decisão pura: a mesma entrada devolve sempre a mesma saída.
     *
     * $referenceAt é o instante de referência do atendimento anterior
     * (a última mensagem). Sem referência, o modo janela não reabre.
     */
    public static function decide(string $mode, int $windowMinutes, ?string $referenceAt, ?int $now = null): bool
    {
        $mode = self::normalizeMode($mode);

        if ($mode === self::MODE_UNIQUE) {
            return false;
        }

        if ($mode === self::MODE_ALWAYS) {
            return true;
        }

        // Modo janela: reabre se a última mensagem do atendimento
        // anterior está dentro da janela.
        $referenceAt = trim((string) $referenceAt);
        if ($referenceAt === '') {
            return false;
        }

        $reference = strtotime($referenceAt);
        if ($reference === false) {
            return false;
        }

        $now ??= time();
        $window = self::normalizeWindowMinutes($windowMinutes) * 60;

        return ($now - $reference) <= $window;
    }

    /**
     * Padrão do sistema (settings).
     *
     * @return array{mode:string, window_minutes:int}
     */
    public static function globalConfig(): array
    {
        return [
            'mode' => self::normalizeMode(Setting::get(self::SETTING_MODE, self::MODE_UNIQUE)),
            'window_minutes' => self::normalizeWindowMinutes(
                Setting::get(self::SETTING_WINDOW, self::DEFAULT_WINDOW_MINUTES)
            ),
        ];
    }

    /**
     * Regra vigente para uma caixa: override da caixa > padrão global.
     *
     * @return array{mode:string, window_minutes:int, source:string}
     *         source = 'inbox' (override da caixa) | 'global' (padrão)
     */
    public static function forInbox(?int $inboxId): array
    {
        $global = self::globalConfig();

        if ($inboxId === null || !self::hasColumns()) {
            return $global + ['source' => 'global'];
        }

        $row = Inbox::find($inboxId);
        if (!$row) {
            return $global + ['source' => 'global'];
        }

        $mode = null;
        if (array_key_exists('atendimento_modo', $row) && $row['atendimento_modo'] !== null && $row['atendimento_modo'] !== '') {
            $mode = self::normalizeMode((string) $row['atendimento_modo']);
        }

        if ($mode === null) {
            // Caixa sem override: herda o padrão do sistema.
            return $global + ['source' => 'global'];
        }

        $window = $global['window_minutes'];
        if (array_key_exists('atendimento_janela_minutos', $row)
            && $row['atendimento_janela_minutos'] !== null
            && $row['atendimento_janela_minutos'] !== '') {
            $window = self::normalizeWindowMinutes((int) $row['atendimento_janela_minutos']);
        }

        return [
            'mode' => $mode,
            'window_minutes' => $window,
            'source' => 'inbox',
        ];
    }

    /**
     * O atendimento anterior do mesmo contato no mesmo canal.
     *
     * Só conversa encerrada de verdade (resolved/closed) e nunca
     * conversa de grupo: grupo é permanente e fica sempre em aberto.
     *
     * @return array|null linha de `conversations`
     */
    public static function previousConversation(int $channelId, int $contactId): ?array
    {
        return Database::getInstance()->fetch(
            "SELECT * FROM conversations
              WHERE contact_id = ? AND channel_id = ?
                AND group_id IS NULL
                AND status IN ('resolved', 'closed')
              ORDER BY COALESCE(last_message_at, created_at) DESC
              LIMIT 1",
            [$contactId, $channelId]
        );
    }

    /**
     * A conversa anterior deve ser reaberta?
     *
     * @param array|null $previous linha de `conversations`
     * @param array      $policy   saída de forInbox()
     */
    public static function shouldReopen(?array $previous, array $policy): bool
    {
        if (!$previous) {
            return false;
        }

        // A janela é contada da ÚLTIMA MENSAGEM do atendimento anterior.
        $reference = $previous['last_message_at'] ?: ($previous['created_at'] ?? null);

        return self::decide(
            (string) ($policy['mode'] ?? self::MODE_UNIQUE),
            (int) ($policy['window_minutes'] ?? self::DEFAULT_WINDOW_MINUTES),
            $reference
        );
    }

    /**
     * A base tem as colunas de reabertura em `inboxes`?
     *
     * Instalação que ainda não rodou a migração fica no modo único —
     * o comportamento de hoje — em vez de quebrar em runtime.
     */
    private static ?bool $columnsCache = null;

    public static function hasColumns(): bool
    {
        if (self::$columnsCache !== null) {
            return self::$columnsCache;
        }

        try {
            $row = Database::getInstance()->fetch("SHOW COLUMNS FROM inboxes LIKE 'atendimento_modo'");
            self::$columnsCache = !empty($row);
        } catch (\Throwable $e) {
            self::$columnsCache = false;
        }

        return self::$columnsCache;
    }
}
```

- [ ] **Step 4: Rodar o teste para ver passar**

```powershell
$php = 'C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe'
& $php tests/run.php
```

Esperado: os 7 checks `atendimento: ...` passam (`ok -`), a suíte inteira fica verde e o runner sai com código 0.

- [ ] **Step 5: Commitar**

```powershell
git add -- 'app/Models/AtendimentoPolicy.php'
git diff --cached --name-only
git commit -m "feat(atendimento): politica de reabertura por caixa"
```

Stagear **só** `app/Models/AtendimentoPolicy.php`; o `tests/run.php` entra no commit da Task 6 (junto do e2e),
porque o bloco de checks e o registro do e2e são a mesma entrega.

---

### Task 3: `ConversationService::reopenConversation()`

**Files:**
- Modify: `app/Services/ConversationService.php` (método novo + `changeStatus()`)

**Interfaces:**
- Consumes: `Conversation::update()`, `Conversation::addEvent()`, `ConversationSla::reset()`.
- Produces: `ConversationService::reopenConversation(int $conversationId, string $motivo = '', ?string $targetStatus = null): array`
  — devolve a conversa atualizada.

- [ ] **Step 1: Escrever o teste que falha**

Em `tests/run.php`, adicionar ao bloco da Task 2 (mesmo lugar, antes do `echo` final):

```php
$convServicePath = __DIR__ . '/../app/Services/ConversationService.php';
$convServiceSrc = is_file($convServicePath) ? (string) file_get_contents($convServicePath) : '';
check('atendimento: existe o metodo de reabertura', str_contains($convServiceSrc, 'function reopenConversation'));
check('atendimento: a reabertura limpa o fechamento', str_contains($convServiceSrc, "'close_reason' => null"));
check('atendimento: a reabertura reinicia o ciclo de sla', str_contains($convServiceSrc, 'ConversationSla::reset'));
check('atendimento: a reabertura grava o evento reopened', str_contains($convServiceSrc, "'reopened'"));
check('atendimento: a mudanca de status usa a reabertura', preg_match('/changeStatus[\s\S]{0,4000}reopenConversation/', $convServiceSrc) === 1);
```

- [ ] **Step 2: Rodar o teste para ver falhar**

```powershell
$php = 'C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe'
& $php tests/run.php
```

Esperado: os 5 checks novos falham (`FALHOU - atendimento: ...`).

- [ ] **Step 3: Implementar `reopenConversation()`**

Em `app/Services/ConversationService.php`, inserir o método **antes** de `changeStatus()`:

```php
    /**
     * Reabre um atendimento encerrado, na MESMA conversa.
     *
     * O atendimento anterior volta para a fila com o histórico junto:
     * o status sai de "finalizada" para um status ativo, o fechamento
     * é limpo e o ciclo do SLA recomeça (a medição passa a ignorar as
     * mensagens do ciclo anterior).
     *
     * Usado por dois gatilhos:
     *  - a reabertura manual (changeStatus) — que já limpava o
     *    fechamento antes, aqui com uma regra só;
     *  - a reabertura automática (nova mensagem do cliente no
     *    WhatsApp, conforme a política da caixa).
     *
     * @param string|null $targetStatus Status alvo. null = 'new' (sem
     *        responsável) ou 'waiting_customer' (com responsável).
     * @return array a conversa atualizada
     */
    public function reopenConversation(int $conversationId, string $motivo = '', ?string $targetStatus = null): array
    {
        $conv = Conversation::find($conversationId);
        if (!$conv) {
            throw new \RuntimeException('Conversa não encontrada para reabrir.');
        }

        $status = $targetStatus;
        if ($status === null) {
            // Sem responsável a conversa volta para a fila de chegada;
            // com responsável ela fica aguardando o cliente.
            $status = !empty($conv['assigned_user_id']) ? 'waiting_customer' : 'new';
        }

        Conversation::update($conversationId, [
            'status' => $status,
            'closed_at' => null,
            'close_reason' => null,
            'close_description' => null,
            'csat_requested' => 0,
            'after_hours_notified' => 0,
        ]);

        // Reabertura reinicia o ciclo do SLA: zera os acumulados e marca
        // o início do novo ciclo (a medição ignora as mensagens anteriores).
        \App\Models\ConversationSla::reset($conversationId, date('Y-m-d H:i:s'));

        Conversation::addEvent(
            $conversationId,
            'reopened',
            $motivo !== '' ? $motivo : 'Atendimento reaberto',
            null,
            ['from' => $conv['status'] ?? null, 'to' => $status]
        );

        return Conversation::find($conversationId) ?? $conv;
    }
```

- [ ] **Step 4: Fazer `changeStatus()` usar o método**

Em `app/Services/ConversationService.php`, dentro de `changeStatus()`, **remover** o bloco atual:

```php
        // Reabertura (saída de resolved/closed/spam): permite nova validação
        // de horário do departamento e novo pedido de CSAT ao resolver de novo.
        $finals = ['resolved', 'closed', 'spam'];
        if (in_array($old, $finals, true) && !in_array($status, $finals, true)) {
            Conversation::update($conversationId, ['after_hours_notified' => 0, 'csat_requested' => 0]);
            // Reabertura: limpa o fechamento anterior. Sem isso a conversa
            // continua com `closed_at` preenchido e o SLA a trata como
            // encerrada (primeira resposta nula e "violacao" consumada
            // numa conversa que esta aberta).
            Conversation::update($conversationId, [
                'closed_at' => null,
                'close_reason' => null,
                'close_description' => null,
            ]);
            // Reabertura reinicia o ciclo do SLA: zera os acumulados e marca
            // o início do novo ciclo (a medição ignora as mensagens anteriores).
            \App\Models\ConversationSla::reset($conversationId, date('Y-m-d H:i:s'));
        }
```

e **substituir** por:

```php
        // Reabertura (saída de resolved/closed/spam): a mesma regra que
        // a reabertura automática usa — limpa o fechamento anterior,
        // permite nova validação de horário do departamento e novo
        // pedido de CSAT ao resolver de novo, e reinicia o ciclo do SLA.
        $finals = ['resolved', 'closed', 'spam'];
        if (in_array($old, $finals, true) && !in_array($status, $finals, true)) {
            $this->reopenConversation($conversationId, "Atendimento reaberto (status: {$status})", $status);
        }
```

> O `reopenConversation()` grava o status antes de limpar o fechamento; o status alvo é o mesmo que o
> `changeStatus()` já tinha gravado, então o resultado final é idêntico ao de hoje — com o evento
> `reopened` a mais na linha do tempo.

- [ ] **Step 5: Rodar o teste para ver passar**

```powershell
$php = 'C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe'
& $php tests/run.php
```

Esperado: os 5 checks novos passam e a suíte continua verde.

- [ ] **Step 6: Commitar**

```powershell
git add -- 'app/Services/ConversationService.php'
git diff --cached --name-only
```

> **Atenção:** este arquivo tem WIP do parceiro. Conferir o diff staged e confirmar que só o bloco novo
> (`reopenConversation` + a substituição em `changeStatus`) entrou. Se aparecerem hunks do WIP, usar
> `git restore --staged app/Services/ConversationService.php` e stagear por patch (`git apply --cached`).

```powershell
git commit -m "feat(atendimento): reabertura na mesma conversa com reinicio do sla"
```

---

### Task 4: O ponto de decisão no WhatsApp

**Files:**
- Modify: `app/Services/WhatsAppService.php` (`findOrCreateConversation()`, linha ~2230)

**Interfaces:**
- Consumes: `AtendimentoPolicy::previousConversation()`, `AtendimentoPolicy::forInbox()`,
  `AtendimentoPolicy::shouldReopen()`, `ConversationService::reopenConversation()`.
- Produces: `WhatsAppService::findOrCreateConversation(int $channelId, int $contactId): array` — devolve a conversa
  aberta, a reaberta ou a nova.

- [ ] **Step 1: Escrever o teste que falha**

Em `tests/run.php`, adicionar ao mesmo bloco:

```php
$waServicePath = __DIR__ . '/../app/Services/WhatsAppService.php';
$waServiceSrc = is_file($waServicePath) ? (string) file_get_contents($waServicePath) : '';
check('atendimento: o whatsapp consulta a politica antes de criar conversa', str_contains($waServiceSrc, 'AtendimentoPolicy::shouldReopen'));
check('atendimento: o whatsapp acha o atendimento anterior', str_contains($waServiceSrc, 'AtendimentoPolicy::previousConversation'));
check('atendimento: o whatsapp reabre a conversa anterior', str_contains($waServiceSrc, 'reopenConversation'));
```

- [ ] **Step 2: Rodar o teste para ver falhar**

```powershell
$php = 'C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe'
& $php tests/run.php
```

Esperado: os 3 checks novos falham.

- [ ] **Step 3: Implementar a decisão**

Em `app/Services/WhatsAppService.php`, dentro de `findOrCreateConversation()`, inserir **após** o bloco que
devolve a conversa aberta (`if ($existing) { return $existing; }`) e **antes** do `$channel = ...`:

```php
        // Nenhuma conversa aberta para este contato neste canal: a regra de
        // reabertura decide se o atendimento ANTERIOR volta para a fila em
        // vez de abrir um atendimento novo.
        //
        // A regra vigente é a da CAIXA do atendimento anterior (é ela que
        // volta para a fila e que tem SLA).
        $previous = \App\Models\AtendimentoPolicy::previousConversation($channelId, $contactId);
        if ($previous) {
            $policy = \App\Models\AtendimentoPolicy::forInbox(
                $previous['inbox_id'] !== null ? (int) $previous['inbox_id'] : null
            );
            if (\App\Models\AtendimentoPolicy::shouldReopen($previous, $policy)) {
                $reopened = (new ConversationService())->reopenConversation(
                    (int) $previous['id'],
                    'Atendimento reaberto por nova mensagem do cliente'
                );
                if (!empty($reopened['id'])) {
                    return $reopened;
                }
            }
        }
```

> `ConversationService` já está no mesmo namespace (`App\Services`), então `new ConversationService()` resolve
> sem `use`.

- [ ] **Step 4: Rodar o teste para ver passar**

```powershell
$php = 'C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe'
& $php tests/run.php
```

Esperado: os 3 checks novos passam e a suíte fica verde.

- [ ] **Step 5: Commitar**

```powershell
git add -- 'app/Services/WhatsAppService.php'
git diff --cached --name-only
git commit -m "feat(atendimento): aplica a regra de reabertura nas mensagens do whatsapp"
```

> Arquivo com WIP do parceiro: conferir o diff staged antes do commit (mesmas precautions da Task 3).

---

### Task 5: Configuração — padrão global e override por caixa

**Files:**
- Modify: `app/Controllers/SettingsController.php`
- Modify: `app/Views/settings/general.php`
- Modify: `app/Views/settings/inbox_form.php`

**Interfaces:**
- Consumes: `AtendimentoPolicy::normalizeMode()`, `normalizeWindowMinutes()`, `globalConfig()`, `hasColumns()`.
- Produces:
  - POST de `/settings`: `atendimento_reabertura_modo`, `atendimento_reabertura_janela_minutos`
  - POST de `/inboxes` e `/inboxes/{id}`: `atendimento_use_global`, `atendimento_modo`, `atendimento_janela_minutos`
  - view `settings/inbox_form.php` recebe `$reabertura` = `['use_global'=>bool,'mode'=>string,'window_minutes'=>int]`

- [ ] **Step 1: Escrever o teste que falha**

Em `tests/run.php`, adicionar ao mesmo bloco:

```php
$settingsCtlSrc = (string) file_get_contents(__DIR__ . '/../app/Controllers/SettingsController.php');
check('atendimento: o controller grava o padrao global', str_contains($settingsCtlSrc, 'atendimento_reabertura_modo'));
check('atendimento: o controller grava o override da caixa', str_contains($settingsCtlSrc, 'atendimento_use_global'));
check('atendimento: o controller guarda coluna ausente', str_contains($settingsCtlSrc, 'AtendimentoPolicy::hasColumns'));
$inboxFormSrc = (string) file_get_contents(__DIR__ . '/../app/Views/settings/inbox_form.php');
check('atendimento: a caixa tem o card de reabertura', str_contains($inboxFormSrc, 'atendimento_modo'));
$generalSrc = (string) file_get_contents(__DIR__ . '/../app/Views/settings/general.php');
check('atendimento: as configuracoes gerais tem o padrao', str_contains($generalSrc, 'atendimento_reabertura_modo'));
```

- [ ] **Step 2: Rodar o teste para ver falhar**

```powershell
$php = 'C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe'
& $php tests/run.php
```

Esperado: os 5 checks novos falham.

- [ ] **Step 3: Controller — estado inicial e persistência do override da caixa**

Em `app/Controllers/SettingsController.php`:

(a) No método que renderiza o formulário da caixa (o que monta `$slaForm = $this->slaFormState($inbox, $sla);`),
adicionar logo abaixo:

```php
        $reabertura = $this->reaberturaFormState($inbox);
```

e incluir `'reabertura' => $reabertura,` no array `$data` do `View::renderWithLayout('settings/inbox_form', ...)`.

(b) No `createInbox()` e no `updateInbox()`, logo depois de
`$data = array_merge($data, $this->collectInboxSla($request));`, adicionar:

```php
        // Reabertura de atendimento (NULL = usar o padrão do sistema).
        if (\App\Models\AtendimentoPolicy::hasColumns()) {
            $data = array_merge($data, $this->collectInboxReabertura($request));
        }
```

(c) Adicionar os dois métodos privados (ao lado de `collectInboxSla()` / `slaFormState()`):

```php
    /**
     * Lê o POST e devolve os campos de reabertura por caixa.
     * "Usar padrão do sistema" marcado zera os overrides (NULL).
     */
    private function collectInboxReabertura(Request $request): array
    {
        if ($request->post('atendimento_use_global')) {
            return [
                'atendimento_modo' => null,
                'atendimento_janela_minutos' => null,
            ];
        }

        return [
            'atendimento_modo' => \App\Models\AtendimentoPolicy::normalizeMode(
                $request->post('atendimento_modo')
            ),
            'atendimento_janela_minutos' => $request->post('atendimento_janela_minutos') !== ''
                ? max(1, (int) $request->post('atendimento_janela_minutos'))
                : null,
        ];
    }

    /**
     * Estado inicial do card de reabertura no formulário da caixa.
     * Sem override, mostra o "usar padrão" marcado e os valores globais
     * como preview.
     *
     * @return array{use_global:bool, mode:string, window_minutes:int}
     */
    private function reaberturaFormState(?array $inbox): array
    {
        $global = \App\Models\AtendimentoPolicy::globalConfig();

        $hasOverride = !empty($inbox)
            && array_key_exists('atendimento_modo', $inbox)
            && $inbox['atendimento_modo'] !== null
            && $inbox['atendimento_modo'] !== '';

        $mode = $hasOverride
            ? \App\Models\AtendimentoPolicy::normalizeMode((string) $inbox['atendimento_modo'])
            : $global['mode'];

        $window = $global['window_minutes'];
        if ($hasOverride && !empty($inbox['atendimento_janela_minutos'])) {
            $window = (int) $inbox['atendimento_janela_minutos'];
        }

        return [
            'use_global' => !$hasOverride,
            'mode' => $mode,
            'window_minutes' => $window,
        ];
    }
```

(d) No `saveGeneral()`, adicionar (antes do flash de sucesso):

```php
        // Reabertura de atendimento — padrão do sistema.
        \App\Models\Setting::set(
            \App\Models\AtendimentoPolicy::SETTING_MODE,
            \App\Models\AtendimentoPolicy::normalizeMode($request->post('atendimento_reabertura_modo'))
        );
        \App\Models\Setting::set(
            \App\Models\AtendimentoPolicy::SETTING_WINDOW,
            \App\Models\AtendimentoPolicy::normalizeWindowMinutes(
                $request->post('atendimento_reabertura_janela_minutos')
            )
        );
```

- [ ] **Step 4: View — card por caixa**

Em `app/Views/settings/inbox_form.php`, no topo do arquivo, logo depois de `$useGlobal = $sf['use_global'];`,
adicionar:

```php
/** @var array $reabertura estado inicial do card de reabertura (use_global + mode + window) */
$rb = $reabertura;
```

Depois do card de SLA (ou seja, depois do `</div>` que fecha o card do SLA e **antes** de `<div class="form-actions">`),
inserir:

```html
                <div class="card" style="border-radius:12px;overflow:hidden;margin-top:18px;background:var(--bg-content);border:1px solid var(--border-soft)">
                    <div class="card-header" style="background:transparent;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
                        <h3 style="margin:0;font-size:15px;display:flex;align-items:center;gap:8px">
                            <i class="fas fa-recycle" style="color:var(--primary)"></i>
                            Reabertura de atendimento
                        </h3>
                        <div class="form-check" style="display:flex;align-items:center;gap:8px;margin:0;padding:8px 12px;background:var(--bg-card);border-radius:8px">
                            <input type="checkbox" name="atendimento_use_global" value="1" id="atendimentoUseGlobal" <?= $rb['use_global'] ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--primary)">
                            <label for="atendimentoUseGlobal" style="font-weight:500;cursor:pointer;margin:0;font-size:13px">
                                Usar padrão do sistema
                            </label>
                        </div>
                    </div>
                    <div class="card-body">
                        <p id="atendimentoUseGlobalHint" style="margin:0 0 14px;font-size:13px;color:var(--text-muted)">
                            <i class="fas fa-info-circle"></i>
                            Os valores abaixo são ignorados: esta caixa usará a regra definida em
                            <a href="<?= url('settings') ?>">Configurações Gerais</a>.
                        </p>

                        <div id="atendimentoCustomFields" style="<?= $rb['use_global'] ? 'display:none' : '' ?>">
                            <div class="form-group">
                                <label><i class="fas fa-random"></i> Novo atendimento do mesmo cliente</label>
                                <select name="atendimento_modo" class="form-control" id="atendimentoModo">
                                    <option value="unique" <?= $rb['mode'] === 'unique' ? 'selected' : '' ?>>Sempre novo (único)</option>
                                    <option value="always" <?= $rb['mode'] === 'always' ? 'selected' : '' ?>>Reabrir o atendimento anterior</option>
                                    <option value="window" <?= $rb['mode'] === 'window' ? 'selected' : '' ?>>Reabrir se for em até X minutos</option>
                                </select>
                                <p class="form-hint">
                                    Vale para as mensagens do WhatsApp. O WebChat do site e o "Novo Atendimento"
                                    manual continuam abrindo um atendimento novo.
                                </p>
                            </div>
                            <div class="form-group" id="atendimentoJanelaGroup" style="<?= $rb['mode'] === 'window' ? '' : 'display:none' ?>">
                                <label><i class="fas fa-clock"></i> Janela de reabertura</label>
                                <div style="display:flex;align-items:center;gap:8px">
                                    <input type="number" min="1" max="10080" name="atendimento_janela_minutos" class="form-control"
                                           id="atendimentoJanela" value="<?= e((string) $rb['window_minutes']) ?>" style="width:100px">
                                    <span style="color:var(--text-muted)">minutos</span>
                                </div>
                                <small class="text-muted" style="display:block;margin-top:4px;font-size:12px">
                                    Contada da última mensagem do atendimento anterior. Passado esse tempo,
                                    o novo atendimento é único.
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
```

E no `<script>` do final do arquivo, **dentro** do IIFE atual, adicionar (antes do `})();`):

```javascript
    // Reabertura: mostrar/esconder os campos e a janela conforme o modo.
    var reabToggle = document.getElementById('atendimentoUseGlobal');
    var reabHint = document.getElementById('atendimentoUseGlobalHint');
    var reabFields = document.getElementById('atendimentoCustomFields');
    var reabModo = document.getElementById('atendimentoModo');
    var reabJanela = document.getElementById('atendimentoJanelaGroup');

    function applyReabertura() {
        if (reabToggle && reabFields) {
            reabFields.style.display = reabToggle.checked ? 'none' : '';
            if (reabHint) reabHint.style.display = reabToggle.checked ? '' : 'none';
        }
        if (reabModo && reabJanela) {
            reabJanela.style.display = reabModo.value === 'window' ? '' : 'none';
        }
    }

    if (reabToggle) reabToggle.addEventListener('change', applyReabertura);
    if (reabModo) reabModo.addEventListener('change', applyReabertura);
    applyReabertura();
```

> Atenção: o `<script>` atual começa com `var toggle = ...; if (!toggle || !fields) return;` — o `return` sai do IIFE
> inteiro. Mover o código da reabertura **para antes** desse `return` (ou trocar o `return` por um bloco
> `if (toggle && fields) { ... }`) para o toggle de reabertura funcionar mesmo quando o card de SLA não existir.

- [ ] **Step 5: View — card do padrão global**

Em `app/Views/settings/general.php`, inserir um novo card **depois** do card do CSAT (antes do card do Painel Guild):

```html
        <div class="card" style="border-radius:12px;overflow:hidden;margin-top:16px">
            <div class="card-header" style="background:var(--bg-card)">
                <h3><i class="fas fa-recycle" style="color:var(--primary)"></i> Reabertura de atendimento</h3>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label>Novo atendimento do mesmo cliente</label>
                    <select name="atendimento_reabertura_modo" class="form-control" id="atendimentoModoGlobal">
                        <option value="unique" <?= (string) ($settings['atendimento_reabertura_modo'] ?? 'unique') === 'unique' ? 'selected' : '' ?>>Sempre novo (único)</option>
                        <option value="always" <?= (string) ($settings['atendimento_reabertura_modo'] ?? 'unique') === 'always' ? 'selected' : '' ?>>Reabrir o atendimento anterior</option>
                        <option value="window" <?= (string) ($settings['atendimento_reabertura_modo'] ?? 'unique') === 'window' ? 'selected' : '' ?>>Reabrir se for em até X minutos</option>
                    </select>
                    <small class="text-muted" style="display:block;margin-top:4px;font-size:12px">
                        Padrão do sistema: o que acontece quando o mesmo cliente escreve de novo no WhatsApp
                        depois de o atendimento anterior ter sido encerrado. Cada caixa pode ter a sua própria
                        regra (Configurações → Caixas). Vale para as mensagens do WhatsApp.
                    </small>
                </div>
                <div class="form-group" id="atendimentoJanelaGlobalGroup" style="<?= (string) ($settings['atendimento_reabertura_modo'] ?? 'unique') === 'window' ? '' : 'display:none' ?>">
                    <label>Janela de reabertura</label>
                    <div style="display:flex;align-items:center;gap:8px">
                        <input type="number" min="1" max="10080" name="atendimento_reabertura_janela_minutos" class="form-control"
                               id="atendimentoJanelaGlobal" value="<?= e((string) ($settings['atendimento_reabertura_janela_minutos'] ?? '120')) ?>" style="width:120px">
                        <span style="color:var(--text-muted)">minutos</span>
                    </div>
                    <small class="text-muted" style="display:block;margin-top:4px;font-size:12px">
                        Contada da última mensagem do atendimento anterior (padrão: 120 minutos).
                    </small>
                </div>
            </div>
        </div>
```

E no fim do arquivo (depois do `</form>`), adicionar o script:

```html
<script>
(function() {
    var modo = document.getElementById('atendimentoModoGlobal');
    var janela = document.getElementById('atendimentoJanelaGlobalGroup');
    if (!modo || !janela) return;
    function apply() {
        janela.style.display = modo.value === 'window' ? '' : 'none';
    }
    modo.addEventListener('change', apply);
    apply();
})();
</script>
```

- [ ] **Step 6: Rodar o teste para ver passar**

```powershell
$php = 'C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe'
& $php tests/run.php
```

Esperado: os 5 checks novos passam e a suíte fica verde.

- [ ] **Step 7: Conferir a tela no navegador**

```powershell
$php = 'C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe'
& $php -S localhost:8080 -t public
```

- [ ] **Step 8: Commitar**

```powershell
git add -- 'app/Controllers/SettingsController.php'
git add -- 'app/Views/settings/general.php'
git add -- 'app/Views/settings/inbox_form.php'
git diff --cached --name-only
git commit -m "feat(atendimento): configura a regra de reabertura por caixa e no padrao do sistema"
```

---

### Task 6: E2E da regra + suíte completa

**Files:**
- Create: `tests/e2e_atendimento_reabertura.php`
- Modify: `tests/run.php` (registro do e2e)

**Interfaces:**
- Consumes: `AtendimentoPolicy`, `WhatsAppService::findOrCreateConversation()`,
  `ConversationService::reopenConversation()`.
- Produces: suíte `tests/run.php` verde e um e2e que roda standalone (`php tests/e2e_atendimento_reabertura.php`).

- [ ] **Step 1: Escrever o e2e**

Criar `tests/e2e_atendimento_reabertura.php`:

```php
<?php
/**
 * E2E da regra de reabertura de atendimento (2026-10-29).
 *
 * Cobre o que a spec 2026-10-29-atendimento-reabertura-design.md definiu:
 *  - modo unique: a mensagem nova abre um atendimento novo;
 *  - modo always: a mensagem nova reabre o atendimento anterior;
 *  - modo window: reabre dentro da janela, cria novo fora dela;
 *  - spam e grupo nunca são reabertos;
 *  - a caixa sobrepõe o padrão global; sem override, herda o global.
 *
 * Roda junto do runner principal (tests/run.php) e também standalone:
 *   php tests/e2e_atendimento_reabertura.php
 *
 * Cria dados temporários, verifica e limpa tudo no fim.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/autoload.php';
require_once __DIR__ . '/../app/Core/Helper.php';
\App\Config\Environment::load();

// Mesmo fuso do app: o banco guarda os horários em America/Sao_Paulo.
$appConfig = require __DIR__ . '/../config/app.php';
date_default_timezone_set($appConfig['timezone'] ?? 'America/Sao_Paulo');

use App\Core\Database;
use App\Models\AtendimentoPolicy;
use App\Models\Conversation;
use App\Services\WhatsAppService;

// Modo do arquivo, decidido ANTES de definir o check(): em standalone o
// próprio arquivo define o helper.
$standalone = !function_exists('check');

if ($standalone) {
    $GLOBALS['failures'] = 0;
    $GLOBALS['passes'] = 0;
    function check(string $name, bool $cond, string $detail = ''): void
    {
        $suffix = $detail !== '' ? " ($detail)" : '';
        if ($cond) {
            $GLOBALS['passes']++;
            echo "ok - $name$suffix\n";
        } else {
            $GLOBALS['failures']++;
            echo "FALHOU - $name$suffix\n";
        }
    }
}

$db = Database::getInstance();

if (!AtendimentoPolicy::hasColumns()) {
    check('reabertura: as colunas da regra existem na base', false, 'roda a migration antes do e2e');
    if ($standalone) {
        exit(1);
    }
    return;
}

$inboxId = null;
$contactId = null;
$channelId = null;
$otherChannelId = null;
$createdConvIds = [];

// Backup do estado global e do override das caixas: o e2e mexe neles e
// precisa devolver tudo como estava.
$globalBackup = [
    'mode' => \App\Models\Setting::get(AtendimentoPolicy::SETTING_MODE),
    'window' => \App\Models\Setting::get(AtendimentoPolicy::SETTING_WINDOW),
];
$inboxOverridesBackup = [];

try {
    // ── Fixture ──────────────────────────────────────────────────
    $channel = $db->fetch("SELECT id FROM channels WHERE type = 'whatsapp' ORDER BY id LIMIT 1");
    if (!$channel) {
        $channel = $db->fetch("SELECT id FROM channels ORDER BY id LIMIT 1");
    }
    $channelId = (int) $channel['id'];

    $other = $db->fetch("SELECT id FROM channels WHERE id <> ? ORDER BY id LIMIT 1", [$channelId]);
    $otherChannelId = $other ? (int) $other['id'] : null;

    $inboxId = (int) $db->insert('inboxes', [
        'name' => 'TESTE REABERTURA',
        'type' => 'department',
        'is_active' => 1,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $inboxOverridesBackup[$inboxId] = null;

    $contactId = (int) $db->insert('contacts', [
        'name' => 'TESTE REABERTURA',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    /**
     * Cria uma conversa encerrada (o "atendimento anterior").
     *
     * A conversa anterior SEMPRE leva a caixa do teste (inbox_id): é a
     * caixa do atendimento anterior que define a regra vigente — sem
     * inbox, a política cairia no padrão global e o override da caixa
     * nunca seria exercitado.
     */
    $makePrevious = function (string $status, ?string $lastMessageAt, ?int $channel = null) use ($db, $contactId, $channelId, $inboxId, &$createdConvIds) {
        $id = (int) $db->insert('conversations', [
            'public_id' => bin2hex(random_bytes(16)),
            'contact_id' => $contactId,
            'channel_id' => $channel ?? $channelId,
            'inbox_id' => $inboxId,
            'department_id' => null,
            'assigned_user_id' => null,
            'subject' => 'atendimento anterior',
            'status' => $status,
            'source' => 'whatsapp',
            'last_message_at' => $lastMessageAt,
            'closed_at' => $lastMessageAt,
            'created_at' => $lastMessageAt ?? date('Y-m-d H:i:s'),
        ]);
        $createdConvIds[] = $id;
        return $id;
    };

    $service = new WhatsAppService();

    // ── 1) modo unique: atendimento novo ────────────────────────
    \App\Models\Setting::set(AtendimentoPolicy::SETTING_MODE, 'unique');
    \App\Models\Setting::set(AtendimentoPolicy::SETTING_WINDOW, '120');
    $db->update('inboxes', ['atendimento_modo' => null, 'atendimento_janela_minutos' => null], 'id = ?', [$inboxId]);

    $prev1 = $makePrevious('closed', date('Y-m-d H:i:s', time() - 60));
    $result = $service->findOrCreateConversation($channelId, $contactId);
    check('reabertura: unique abre um atendimento novo', (int) $result['id'] !== $prev1, 'id=' . $result['id']);
    $createdConvIds[] = (int) $result['id'];
    $stillClosed = Conversation::find($prev1);
    check('reabertura: unique deixa o anterior encerrado', ($stillClosed['status'] ?? '') === 'closed');

    // ── 2) modo always: reabre o anterior ────────────────────────
    $db->update('inboxes', ['atendimento_modo' => 'always'], 'id = ?', [$inboxId]);
    $prev2 = $makePrevious('resolved', date('Y-m-d H:i:s', time() - 60));
    $result = $service->findOrCreateConversation($channelId, $contactId);
    check('reabertura: always reabre o atendimento anterior', (int) $result['id'] === $prev2, 'id=' . $result['id']);
    $reopened = Conversation::find($prev2);
    check('reabertura: a conversa reaberta volta para a fila', ($reopened['status'] ?? '') === 'new', 'status=' . ($reopened['status'] ?? ''));
    check('reabertura: a conversa reaberta limpa o fechamento', empty($reopened['closed_at']));
    $event = $db->fetch(
        "SELECT COUNT(*) AS c FROM conversation_events WHERE conversation_id = ? AND event_type = 'reopened'",
        [$prev2]
    );
    check('reabertura: a reabertura deixa rastro no historico', (int) $event['c'] === 1, 'eventos=' . $event['c']);

    // ── 3) modo window: dentro da janela reabre ──────────────────
    $db->update('inboxes', ['atendimento_modo' => 'window', 'atendimento_janela_minutos' => 120], 'id = ?', [$inboxId]);
    $prev3 = $makePrevious('closed', date('Y-m-d H:i:s', time() - 60));
    $result = $service->findOrCreateConversation($channelId, $contactId);
    check('reabertura: window reabre dentro da janela', (int) $result['id'] === $prev3, 'id=' . $result['id']);

    // ── 4) modo window: fora da janela abre novo ─────────────────
    $prev4 = $makePrevious('closed', date('Y-m-d H:i:s', time() - 3 * 3600));
    $result = $service->findOrCreateConversation($channelId, $contactId);
    check('reabertura: window abre novo fora da janela', (int) $result['id'] !== $prev4, 'id=' . $result['id']);
    $createdConvIds[] = (int) $result['id'];

    // ── 5) spam nunca é reaberto ─────────────────────────────────
    $prev5 = $makePrevious('spam', date('Y-m-d H:i:s', time() - 60));
    $result = $service->findOrCreateConversation($channelId, $contactId);
    check('reabertura: spam nunca e reaberto', (int) $result['id'] !== $prev5, 'id=' . $result['id']);
    $createdConvIds[] = (int) $result['id'];

    // ── 6) a caixa sobrepoe o padrao global ──────────────────────
    \App\Models\Setting::set(AtendimentoPolicy::SETTING_MODE, 'unique');
    $db->update('inboxes', ['atendimento_modo' => 'always'], 'id = ?', [$inboxId]);
    $prev6 = $makePrevious('closed', date('Y-m-d H:i:s', time() - 60));
    $result = $service->findOrCreateConversation($channelId, $contactId);
    check('reabertura: a caixa sobrepoe o padrao global', (int) $result['id'] === $prev6, 'id=' . $result['id']);

    // ── 7) sem override, herda o padrao global ───────────────────
    $db->update('inboxes', ['atendimento_modo' => null, 'atendimento_janela_minutos' => null], 'id = ?', [$inboxId]);
    \App\Models\Setting::set(AtendimentoPolicy::SETTING_MODE, 'always');
    $prev7 = $makePrevious('closed', date('Y-m-d H:i:s', time() - 60));
    $result = $service->findOrCreateConversation($channelId, $contactId);
    check('reabertura: sem override herda o padrao global', (int) $result['id'] === $prev7, 'id=' . $result['id']);

    // ── 8) conversa de outro canal nao e candidata ───────────────
    if ($otherChannelId) {
        // Global em 'unique': a mensagem no canal A nao pode tocar no
        // atendimento do canal B (que fica no canal B).
        \App\Models\Setting::set(AtendimentoPolicy::SETTING_MODE, 'unique');
        $prev8 = $makePrevious('closed', date('Y-m-d H:i:s', time() - 60), $otherChannelId);
        $result = $service->findOrCreateConversation($channelId, $contactId);
        check('reabertura: atendimento de outro canal nao e reaberto', (int) $result['id'] !== $prev8, 'id=' . $result['id']);
        $untouched = Conversation::find($prev8);
        check('reabertura: o atendimento do outro canal continua encerrado', ($untouched['status'] ?? '') === 'closed');
        $createdConvIds[] = (int) $result['id'];
    }

    // ── 9) grupo nunca e candidato ───────────────────────────────
    // conversations.group_id tem FK para whatsapp_groups: usa um grupo
    // que ja existe (a linha do grupo NAO e mexida).
    $groupRow = $db->fetch("SELECT id FROM whatsapp_groups ORDER BY id LIMIT 1");
    if ($groupRow) {
        // Global em 'always': se a conversa de grupo fosse candidata,
        // ela seria reaberta. Com o filtro group_id IS NULL ela nao e.
        \App\Models\Setting::set(AtendimentoPolicy::SETTING_MODE, 'always');
        $groupConv = (int) $db->insert('conversations', [
            'public_id' => bin2hex(random_bytes(16)),
            'contact_id' => $contactId,
            'channel_id' => $channelId,
            'inbox_id' => $inboxId,
            'group_id' => (int) $groupRow['id'],
            'status' => 'closed',
            'source' => 'whatsapp',
            'last_message_at' => date('Y-m-d H:i:s', time() - 60),
            'closed_at' => date('Y-m-d H:i:s', time() - 60),
            'created_at' => date('Y-m-d H:i:s', time() - 3600),
        ]);
        $createdConvIds[] = $groupConv;
        $candidate = AtendimentoPolicy::previousConversation($channelId, $contactId);
    check(
        'reabertura: conversa de grupo nao e candidata',
        $candidate === null || (int) $candidate['id'] !== $groupConv,
        'candidato=' . ($candidate['id'] ?? 'null')
    );
    } // fim if ($groupRow)
} finally {
    // ── Limpeza ──────────────────────────────────────────────────
    foreach (array_unique($createdConvIds) as $cid) {
        $db->delete('conversation_events', 'conversation_id = ?', [$cid]);
        $db->delete('messages', 'conversation_id = ?', [$cid]);
        $db->delete('conversation_slas', 'conversation_id = ?', [$cid]);
        $db->delete('conversation_tags', 'conversation_id = ?', [$cid]);
        $db->delete('conversations', 'id = ?', [$cid]);
    }
    if ($contactId) {
        $db->delete('contacts', 'id = ?', [$contactId]);
    }
    if ($inboxId) {
        $db->update('inboxes', ['atendimento_modo' => null, 'atendimento_janela_minutos' => null], 'id = ?', [$inboxId]);
        $db->delete('inboxes', 'id = ?', [$inboxId]);
    }
    if ($globalBackup['mode'] === null) {
        $db->execute("DELETE FROM settings WHERE `key` = ?", [AtendimentoPolicy::SETTING_MODE]);
    } else {
        \App\Models\Setting::set(AtendimentoPolicy::SETTING_MODE, (string) $globalBackup['mode']);
    }
    if ($globalBackup['window'] === null) {
        $db->execute("DELETE FROM settings WHERE `key` = ?", [AtendimentoPolicy::SETTING_WINDOW]);
    } else {
        \App\Models\Setting::set(AtendimentoPolicy::SETTING_WINDOW, (string) $globalBackup['window']);
    }
}

if ($standalone) {
    echo "\n{$GLOBALS['passes']} passed, {$GLOBALS['failures']} failed\n";
    exit($GLOBALS['failures'] > 0 ? 1 : 0);
}
```

- [ ] **Step 2: Rodar o e2e isolado**

```powershell
$php = 'C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe'
& $php tests/e2e_atendimento_reabertura.php
```

Esperado: todos os checks `reabertura: ...` passam (`ok -`) e o script sai com código 0.

- [ ] **Step 3: Registrar o e2e no runner principal**

Em `tests/run.php`, antes de `echo "\n$passes passed, $failures failed\n";`, adicionar:

```php
// 15) Reabertura de atendimento: regra por caixa (único / sempre / janela)
require_once __DIR__ . '/e2e_atendimento_reabertura.php';
```

- [ ] **Step 4: Rodar a suíte completa**

```powershell
$php = 'C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe'
& $php tests/run.php
```

Esperado: suíte verde, com os checks `atendimento: ...` e `reabertura: ...` passando; código de saída 0.

- [ ] **Step 5: Conferir que a base ficou limpa**

```powershell
$php = 'C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe'
$code = @'
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/lib/autoload.php';
require_once __DIR__ . '/app/Core/Helper.php';
\App\Config\Environment::load();
$db = \App\Core\Database::getInstance();
$n = $db->fetch("SELECT COUNT(*) AS c FROM inboxes WHERE name = 'TESTE REABERTURA'");
echo "inboxes de teste restantes: " . $n['c'] . "\n";
$s = $db->fetchAll("SELECT `key`, value FROM settings WHERE `key` LIKE 'atendimento%' ORDER BY `key`");
echo "settings: " . json_encode($s, JSON_UNESCAPED_UNICODE) . "\n";
'@
Set-Content -Path 'D:\laragon\www\atendeflow\storage\check_limpeza_atendimento.php' -Value $code -Encoding UTF8
& $php 'D:\laragon\www\atendeflow\storage\check_limpeza_atendimento.php'
```

Esperado: `inboxes de teste restantes: 0` e os settings de volta ao valor original (`unique` / `120`).

- [ ] **Step 6: Commitar**

```powershell
git add -- 'tests/e2e_atendimento_reabertura.php'
git add -- 'tests/run.php'
git diff --cached --name-only
git commit -m "test(atendimento): e2e da regra de reabertura por caixa"
```

---

## Checklist final (após a Task 6)

- [ ] `& $php tests/run.php` sai com código 0.
- [ ] `& $php tests/e2e_atendimento_reabertura.php` sai com código 0.
- [ ] `git status --short` mostra apenas o WIP do parceiro (nada deste trabalho fora do commit).
- [ ] `git log --oneline -7` mostra os commits deste trabalho (banco, policy, reabertura, whatsapp, config, e2e) e
  nada do WIP do parceiro.
- [ ] A tela de Configurações → Caixas mostra o card "Reabertura de atendimento" e o de Configurações Gerais
  mostra o padrão do sistema.
