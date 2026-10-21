# SLA mensurável — compliance de primeira resposta e de resolução

**Data:** 2026-10-20
**Escopo:** Bloco 1 da lista de sugestões (itens 1, 2, 3, 4, 6)
**Status:** design aprovado pelo parceiro (3 seções), aguardando revisão da spec

---

## 1. Contexto

O sistema tem SLA desde sempre, mas ele é **apenas visual e sonoro**: o relógio da lista
de conversas pinta a cor (verde/amarelo/vermelho) e toca o som quando o nível sobe.
Não existe nenhum registro de violação, nenhuma métrica, nenhum relatório. O
`PRODUCT.md:51` promete "serviço é mensurável — SLA decide se um workflow fica", e
hoje isso não é verdade: ninguém consegue responder "quantas conversas furaram o SLA
no mês passado?".

O relógio em si já foi corrigido no trabalho anterior (`17f3886`): passou a contar
**segundos úteis** (dentro do horário de atendimento do departamento) em vez de tempo
de relógio. Essa base é o ponto de partida: a medição de compliance reaproveita
`SlaClock::elapsedInSchedule()`.

**Objetivo:** medir os dois SLAs (primeira resposta e resolução), registrar a violação
no momento em que acontece, transformar isso em número no dashboard e no relatório, e
escalar automaticamente quando o SLA de primeira resposta é furado.

### Fora de escopo (Blocos 2 e 3, já priorizados)

- Central de notificações: retenção, mute por tipo, histórico, canal externo, tipos mortos.
- Janela de almoço na grade, rótulos de `waiting_customer`, grade por caixa órfã.

---

## 2. Decisões de design (aprovadas)

### 2.1 O que o compliance mede

**Dois SLAs por conversa:**

| SLA | Âncora | Fim | Mede |
|---|---|---|---|
| **Primeira resposta** | 1ª mensagem do cliente | 1ª resposta **humana** posterior | segundos úteis de espera |
| **Resolução** | 1ª mensagem do cliente | encerramento da conversa | segundos úteis **com a bola com a gente** |

Só mensagem humana conta como resposta: `direction = 'outbound' AND user_id IS NOT NULL
AND type NOT IN ('internal_note','system')`. Resposta de chatbot (`user_id IS NULL`) não
zera o relógio nem satisfaz o SLA. Mensagens `fromMe` do WhatsApp são descartadas no
provider (`UazapiProvider.php:809`), então o agente respondendo pelo celular não entra
no histórico.

### 2.2 Pausa por status

O relógio pausa em `waiting_internal` (a bola está com outro setor). Isso vale para os
dois SLAs.

`conversation_events` hoje **não** registra as mudanças de status de forma consultável:
`changeStatus` grava só a descrição `"Status alterado para: X"`
(`app/Services/ConversationService.php:368`), sem o status anterior; e
`sendMessage`/`receiveMessage` mudam o status **sem evento nenhum**. Para a pausa
funcionar, `changeStatus` passa a gravar `metadata = {from, to}` no evento
`status_changed` (a coluna JSON já existe). Conversas antigas (sem metadata) são
tratadas sem a pausa — backfill best-effort.

### 2.3 Robustez: o histórico é a única fonte da verdade

Não há acumulador em memória nem gancho que possa ser perdido. O valor é sempre
recalculado a partir das mensagens. Consequências:

- Conversa **encerrada** → o valor é calculado uma vez e gravado em `conversation_slas`
  → os relatórios viram um `SELECT` agregado simples.
- Conversa **ativa** → o valor é calculado sob demanda (sweep, badge, KPI).
- Conversas antigas encerradas → **backfill** incremental em lotes, o que dá
  compliance histórico sem custo de implantação.

### 2.4 Limites snapshotados

O limite vigente no momento da medição é gravado na linha da conversa. Mudar a config
depois **não reescreve o histórico** — compliance passado não pode mudar por causa de
uma config nova.

### 2.5 Escalonamento

Dispara **uma vez** por conversa, no momento da violação do SLA de **primeira
resposta** (a acionável). O SLA de resolução é medido e reportado, mas **não escala**:
escalar a cada fechamento lento cutucaria o gerente sem ação útil.

---

## 3. Modelo de dados

### 3.1 Tabela nova `conversation_slas`

Uma linha por conversa, criada sob demanda.

```sql
CREATE TABLE conversation_slas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL UNIQUE,

    -- SLA 1: primeira resposta
    response_seconds    INT NULL,          -- segundos úteis até a 1ª resposta humana
    response_at         DATETIME NULL,     -- quando a 1ª resposta saiu
    response_breached   TINYINT(1) NOT NULL DEFAULT 0,
    response_threshold  INT NULL,          -- limite em vigor (snapshot)

    -- SLA 2: resolução
    resolution_seconds    INT NOT NULL DEFAULT 0,  -- segundos úteis com a bola com a gente
    resolution_breached   TINYINT(1) NOT NULL DEFAULT 0,
    resolution_threshold  INT NULL,               -- limite em vigor (snapshot)

    -- Período em aberto (bola com a gente, aguardando resposta)
    period_started_at   DATETIME NULL,

    metadata JSON NULL,   -- ex.: {"pause_legacy": true} quando o backfill
                          -- não conseguiu reconstruir a linha do tempo de status

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    INDEX idx_slas_breached (response_breached, resolution_breached),
    INDEX idx_slas_updated (updated_at)
) ENGINE=InnoDB;
```

Modelada por `App\Models\ConversationSla` (criar, ler, upsert do snapshot e consultas
agregadas de compliance).

### 3.2 Colunas novas

| Tabela | Coluna | Tipo | Default |
|---|---|---|---|
| `departments` | `sla_escalation_phone` | `VARCHAR(20) NULL` | — (plantão) |
| `inboxes` | `sla_response_minutes` | `INT NULL` | NULL = global |
| `inboxes` | `sla_resolution_minutes` | `INT NULL` | NULL = global |
| `inboxes` | `sla_business_hours` | `TINYINT(1) NULL` | NULL = global |
| `inboxes` | `sla_escalate_managers` | `TINYINT(1) NULL` | NULL = global |
| `inboxes` | `sla_escalate_tag` | `TINYINT(1) NULL` | NULL = global |
| `inboxes` | `sla_escalate_autoassign` | `TINYINT(1) NULL` | NULL = global |
| `inboxes` | `sla_escalate_whatsapp` | `TINYINT(1) NULL` | NULL = global |

`NULL` = herda o global, no mesmo padrão de `sla_use_global` que a SLA por caixa já usa.

### 3.3 Enum de `conversation_events`

Adicionar `sla_breached` e `sla_escalated` ao enum de `event_type` (migration).

### 3.4 Chaves globais em `settings`

| Chave | Default | Significado |
|---|---|---|
| `sla_response_minutes` | `15` | Limite do SLA de primeira resposta |
| `sla_resolution_minutes` | `1440` | Limite do SLA de resolução (24h) |
| `sla_business_hours` | `1` | Já existe (`17f3886`) — agora com override por caixa |
| `sla_escalate_managers` | `1` | Notificar gerentes do departamento |
| `sla_escalate_tag` | `1` | Aplicar tag "SLA violado" |
| `sla_escalate_autoassign` | `0` | Auto-atribuir ao menos carregado |
| `sla_escalate_whatsapp` | `0` | Avisar o plantão por WhatsApp |
| `sla_escalation_message` | `SLA de {{sla.type}} violado: {{contact.name}} (protocolo {{conversation.protocol}}) espera há {{sla.wait}} (limite {{sla.limit}}).` | Template do aviso |

**Default de `sla_escalate_autoassign` e `sla_escalate_whatsapp` é `0`** (desligado):
atribuição automática e aviso externo mudam a operação do time; o gestor liga quando
quiser. `sla_escalate_managers` e `sla_escalate_tag` já vêm `1` porque são internos e
invisíveis.

---

## 4. Motor de medição

Novo serviço `app/Services/SlaMeasurement.php` (puro e testável, sem dependência de
banco nas funções de cálculo).

### 4.1 Entrada

```php
SlaMeasurement::measure(array $conv, array $messages, array $statusTimeline, ?array $schedule, string $scheduleTz, string $now): array
```

- `$conv`: linha de `conversations` (id, status, department_id, inbox_id, created_at, closed_at).
- `$messages`: `id, direction, type, user_id, created_at` em ordem de `id`.
- `$statusTimeline`: lista de `[timestamp, status]` — vinda de `conversation_events`
  (`event_type = 'status_changed'`), usando `metadata.to` quando existir e, como
  fallback, o parse da descrição `"Status alterado para: X"`.
- `$schedule`: grade do departamento no formato do `SlaClock` (`null` = sem grade →
  tempo de relógio puro).
- `$now`: instante de referência.

### 4.2 Algoritmo

**Passo 0 — mensagens consideradas.**
Mensagens `deleted_at IS NULL`? **Não** — o relógio da lista (`Conversation.php:199`) não
filtra mensagens apagadas, e a medição precisa concordar com ele. Usa-se a mesma regra:
todas as mensagens da conversa entram na medição.

**Passo 1 — resposta humana (primeira).**
Percorre as mensagens em ordem de `id`. A primeira mensagem que satisfaz
`direction='outbound' AND user_id IS NOT NULL AND type NOT IN ('internal_note','system')`
é a `firstReply`. A primeira mensagem `direction='inbound'` é a `firstInbound`.

**Passo 2 — pareamento guloso (bola com a gente).**

```
openInbound = null
periods = []
para cada mensagem em ordem de id:
    se inbound:  openInbound = msg
    se resposta humana:
        se openInbound != null:
            periods[] = [openInbound.created_at, msg.created_at]
            openInbound = null
```

O emparelhamento é guloso: cada resposta humana fecha o **último** inbound anterior.
Isso garante que os períodos **não se sobrepõem** (sem dupla contagem). Ao final, se
`openInbound != null`, há um **período aberto** `[openInbound.created_at, X]`, onde `X`
é:

- `closed_at` da conversa, se ela estiver encerrada (`resolved`/`closed`);
- `now`, se a conversa estiver ativa.

**Passo 3 — primeira resposta.**
`response_seconds = elapsedInSchedule(schedule, tz, firstInbound.created_at, firstReply.created_at)`.

Três casos distintos, que **não** podem ser confundidos:

| Situação | `response_seconds` | `response_breached` | Escala? |
|---|---|---|---|
| Cliente falou e houve resposta humana | o valor medido | conforme o limite | se violou |
| Cliente falou, conversa encerrada **sem** resposta humana | `NULL` | `1` | sim |
| Cliente **nunca** falou (conversa criada manualmente) | `NULL` | `0` | **não** — não há objeto de SLA |

**Passo 4 — resolução.**
`resolution_seconds = Σ elapsedInSchedule(schedule, tz, inicio, fim)` sobre `periods`
(+ o período aberto).

**Passo 5 — pausa por `waiting_internal`.**
A linha do tempo de status é interpolada sobre cada período: cada período é dividido
nos segmentos de status, e os segmentos com status `waiting_internal` são
**subtraídos** (também em segundos úteis).

**Passo 6 — avaliação dos limites.**
Compara com `response_threshold` / `resolution_threshold` (snapshot do momento) e
marca `*_breached`.

**Passo 7 — override por caixa do relógio (item 6).**
O `applyClock()` do `SlaService` passa a resolver o override por caixa: para cada
`inbox_id` distinto presente nas linhas, lê `inboxes.sla_business_hours` (cacheado por
caixa na requisição, no mesmo padrão do cache de config já existente). `0` → a
conversa **não** usa a grade do departamento (tempo de relógio puro); `NULL` → herda o
global; `1` → usa a grade.

### 4.3 Saída

```php
[
    'response_seconds'    => int|null,
    'response_at'         => string|null,   // 'Y-m-d H:i:s'
    'response_breached'   => bool,
    'response_threshold'  => int,
    'resolution_seconds'  => int,
    'resolution_breached' => bool,
    'resolution_threshold'=> int,
    'period_started_at'   => string|null,
]
```

### 4.4 Onde a medição roda

- `SlaMeasurement::forConversation(int $conversationId, ?string $now = null): array` —
  carrega a conversa, as mensagens, a linha do tempo de status e a grade do
  departamento, e chama a função pura. É a porta de entrada usada pelo worker, pelo
  badge e pelo relatório.

---

## 5. Worker

Novo `workers/sla_worker.php` (cron `* * * * *`, junto com o `flow_worker`).

Por execução, em lotes:

1. **Medir ativas** — conversas com status ativo e sem linha em `conversation_slas`, ou
   com `period_started_at` no passado: recalcula e grava.
2. **Detectar violação** — o worker lê o valor **anterior** de `response_breached` e
   escala **apenas na transição `0 → 1`** (nunca re-escala uma conversa já escalada).
   Na transição: grava, dispara o evento `sla_breached` e **aciona o escalonamento**.
3. **Finalizar** — conversas encerradas (`closed_at` preenchido) sem linha: calcula,
   grava, avalia `resolution_breached`.
4. **Backfill** — conversas encerradas antigas ainda sem linha, em lote limitado por
   execução, para não estourar o tempo do cron.

O worker é **idempotente**: rodar duas vezes não muda nada.

Guardas: `max 200` conversas por lote; `try/catch` por conversa para que uma
conversa problemática não derrube o restante do lote; log com timestamp no mesmo
formato do `flow_worker`.

---

## 6. Configuração

### 6.1 Global — `/settings/notifications`

Nova seção "SLA — Medição e escalonamento" no card de SLA existente:
checkbox "Considerar horário de atendimento" (já existe), campos de minutos para
primeira resposta e resolução, e os 4 checkboxes de escalonamento + o template da
mensagem de aviso.

### 6.2 Por caixa — `/inboxes/{id}/edit`

Espelha os mesmos campos no formulário de SLA por caixa que já existe, com o padrão
"Usar padrão do sistema" (NULL = global).

---

## 7. Superfícies

### 7.1 Badge na tela da conversa

`app/Views/inbox/panel.php`, no header, no padrão do `header-chip` de prioridade
(`panel.php:260`): novo chip "SLA" mostrando primeira resposta (X / limite) e
resolução (Y / limite). Vermelho quando violado; cinza quando não há SLA aplicável.

Atualização: o panel já tem um mecanismo de re-render de header chips a partir do
poll (`updateHeaderChip()` em `panel.php:935` e `priorityBadgeHtml()` em
`panel.php:2510`, alimentados por `/api/conversations` a cada 10s). O chip de SLA
entra no mesmo caminho, para não criar um segundo polling.

### 7.2 KPI no dashboard

Novo card no `stats-grid` do dashboard: **"SLA crítico agora: N"** (conversas ativas
com SLA de primeira resposta já violado) e, como `stat-sub`, **"compliance 30d: NN%"**.
Entra em `api/dashboard-stats`, que já faz auto-refresh.

Base do período: conversas com `created_at` nos últimos 30 dias que tenham linha em
`conversation_slas` (ou seja, que já foram medidas). O compliance é
`1 - (violadas / medidas)`, calculado sobre as duas SLAs separadamente.

### 7.3 Relatório `/reports/sla` (novo, `manager`+)

- Período: `7 | 30 | 90 | all` (mesmo seletor dos outros relatórios).
- Cards: compliance de 1ª resposta (%), compliance de resolução (%), total medido,
  violações.
- Base do período: conversas com `created_at` no período, mesma base do dashboard
  (§7.2) e dos demais relatórios.
- Quebras: por **agente**, por **departamento**, por **caixa** (mesmo formato de
  `hbar-row` do `reports/index.php`).
- Lista das conversas violadas (protocolo, contato, tempo de espera, limite, quem
  atendia).
- Export CSV, no padrão de `reports/timeline.php` (limite 5000, BOM Excel, proteção
  contra fórmula).

---

## 8. Escalonamento

Dispara uma vez por conversa, quando o SLA de primeira resposta é violado.

| # | Ação | Condição |
|---|---|---|
| 1 | Grava `response_breached = 1` + evento `sla_breached` | sempre |
| 2 | Tag "SLA violado" (tag de sistema, padrão "Fluxo"/"Aberto") | `sla_escalate_tag` |
| 3 | Notifica os gerentes do departamento (in-app, via `NotificationService`) | `sla_escalate_managers` |
| 4 | Auto-atribui ao atendente menos carregado | `sla_escalate_autoassign` **e** conversa sem responsável (ou responsável inativo) |
| 5 | Avisa o plantão por WhatsApp | `sla_escalate_whatsapp` **e** `departments.sla_escalation_phone` preenchido |

Ao final, o escalonamento grava o evento `sla_escalated` com o detalle das ações
executadas (`metadata = {tag, managers, autoassign, whatsapp}`), para auditoria.

**3 — gerentes:** `department_users.is_manager = 1` com `users.is_active = 1`. Notificação
do tipo `system` (o enum já existe e ainda não é emitido), com `conversation_id`
apontando para a conversa, para o clique abrir o inbox. O `NotificationService` ganha
um `notifySystem(array $userIds, string $title, string $body, ?int $conversationId)`
— hoje não existe nenhuma função que emita esse tipo.

**4 — auto-atribuição:** candidatos = `inbox_users` ∪ `department_users` ativos
(**sem admins** — eles aparecem em `Inbox::getUsers` por visibilidade, não por escala).
Carga = nº de conversas abertas atribuídas (`new`/`open`/`waiting_*`). Vence o de menor
carga; desempate pelo menor `id`. **Regra de conservatism:** nunca arrancar a conversa
de quem já está atendendo.

**5 — WhatsApp:** `WhatsAppService::sendToPhone($channelId, $phone, $message)`, com o
canal da conversa e fallback para o primeiro canal WhatsApp do departamento. Sem
telefone cadastrado → **pula com log**, no mesmo padrão do nó `notify` do flow
(`FlowEngineService.php:760`). A mensagem é o template `sla_escalation_message`
renderizado com `TemplateService::render()`, acrescido dos tokens do SLA:

| Token | Valor |
|---|---|
| `{{sla.wait}}` | tempo de espera (ex.: `23 min`) |
| `{{sla.limit}}` | limite (ex.: `15 min`) |
| `{{sla.type}}` | `primeira resposta` |

---

## 9. Testes

O harness é `check()` puro (`tests/run.php`, sem banco). A matemática de medição é
**pura** justamente para ser testada ali.

### 9.1 `tests/run.php` (sem banco)

- `SlaMeasurement::measure()` com mensagens sintéticas:
  - primeira resposta: cliente 1× → resposta → 1 período de N segundos úteis;
  - cliente 3× → resposta → `response_seconds` mede desde a **1ª** mensagem, e o
    pareamento gera **1** período (não 3) — sem dupla contagem;
  - sem resposta humana → `response_seconds = null`, `response_breached = true`;
  - pausa por `waiting_internal` subtrai o segmento;
  - conversa encerrada → sem período aberto;
  - `schedule = null` → tempo de relógio puro.
- Fiação (padrão de asserção por fonte já usado no repo): `conversation_slas` no schema,
  colunas em `inboxes`, `departments.sla_escalation_phone`, `sla_breached` no enum de
  `conversation_events`, worker existe, rota `/reports/sla` existe, chip no header.

### 9.2 `tests/e2e_sla_measurable.php` (MySQL, autolimpante)

Segue o padrão de `tests/e2e_sla.php`: cria dados temporários, verifica e limpa.
Cobre: medição real contra o banco, gravação em `conversation_slas`, backfill, o worker
rodando de verdade, o escalonamento disparando uma única vez, e a volta do valor.

### 9.3 `tests/smoke_sla.php` (HTTP)

Estende o smoke existente: a página da conversa traz o chip de SLA, o dashboard traz o
card, `/reports/sla` responde 200 e `/api/dashboard-stats` devolve o número de SLA
crítico.

---

## 10. Riscos e pontos de atenção

| Risco | Mitigação |
|---|---|
| Backfill em base grande pode estourar o tempo do cron | Lote limitado por execução; o worker é idempotente e retoma de onde parou |
| Recalcular a medição a cada minuto custa queries por conversa ativa | Só conversas ativas entram no lote; a medição usa `idx_messages_conv_created_id` |
| `conversation_events` legado sem `metadata` → backfill sem a pausa de `waiting_internal` | Sinalizado em `conversation_slas` (coluna `metadata` opcional) e o backfill é best-effort por natureza |
| Auto-atribuição pode unfairly sobrecarregar um atendente | Desligado por default; desempate determinístico; nunca rouba conversa em atendimento |
| Mudança de config não deve reescrever o histórico | Limites snapshotados em `conversation_slas` |
| A tag "SLA violado" acumula em conversas antigas | Filtro por tag no inbox; a tag só é aplicada na violação |

---

## 11. Ordem de implementação sugerida

1. Migration (`conversation_slas`, colunas, enum de eventos) + `SlaMeasurement` puro
   com testes.
2. `SlaMeasurement::forConversation()` + gravação em `conversation_slas`.
3. Worker + backfill + detecção de violação.
4. Config (global + por caixa).
5. Superfícies: chip na conversa, KPI no dashboard, `/reports/sla`.
6. Escalonamento (5 ações).
7. Testes e2e/smoke + documentação.

---

## 12. Referências

- `app/Services/SlaClock.php` — matemática dos segundos úteis (do trabalho anterior).
- `app/Services/SlaService.php` — `clockState()` e `applyClock()` (relógio da lista).
- `app/Services/BusinessHoursService.php` — grade do departamento.
- `app/Services/TemplateService.php` — variáveis de template.
- `app/Services/WhatsAppService.php::sendToPhone()` — envio de aviso.
- `app/Services/NotificationService.php` — notificações in-app.
- `app/Controllers/ReportsController.php` + `app/Views/reports/*` — padrão dos relatórios.
- `workers/flow_worker.php` — padrão do worker por cron.
