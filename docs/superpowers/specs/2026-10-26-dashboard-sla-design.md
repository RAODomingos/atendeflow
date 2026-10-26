# Dashboard com SLA — reestruturação da informação e bloco de SLA dedicado

**Data:** 2026-10-26
**Escopo:** `app/Views/dashboard/index.php`, `DashboardController`, `DashboardStatsController`, novo `DashboardSlaService`
**Status:** design aprovado pelo parceiro (3 seções: arquitetura, dados, interação/testes), aguardando revisão da spec

---

## 1. Contexto

O dashboard atual (`app/Views/dashboard/index.php`) tem nove faixas e funciona, mas o SLA
aparece espremido em **um** card: "SLA crítico agora", com o compliance de 1ª resposta
escrito no texto de apoio. Ninguém consegue responder, olhando o dashboard, "onde o SLA
está furando?" nem "quantas conversas furaram no mês?".

O problema não é falta de dado — o sistema mede tudo:

| Eixo | Onde vive | O que responde |
|---|---|---|
| **SLA de medição** (histórico) | `conversation_slas` (`response_*`, `resolution_*`) | "conversas furaram SLA?" |
| **SLA de relógio** (fila viva) | `SlaService::levelFor()` + `applyClock()` | "quem está estourando agora?" |

O segundo eixo é o que pinta a fila de vermelho na inbox e hoje **não aparece em lugar
nenhum do dashboard**. São populações diferentes:

- *SLA de medição* olha para trás: a 1ª resposta já saiu (ou não) e passou do limite.
- *SLA de relógio* olha para agora: a bola está com a gente há mais tempo que o limite
  de atenção, dentro do horário comercial do departamento.

Um dashboard que só mostra o primeiro esconde o problema operacional do dia.

**Objetivo:** reestruturar a informação do dashboard por ordem de decisão e dar ao SLA
duas faixas próprias — uma de agora (6 fichas) e uma de detalhe (quebras + violações) —
reaproveitando o que o sistema já mede.

### Fora de escopo

- Filtro de SLA na caixa de entrada (não existe hoje; não será inventado).
- Relatório de SLA novo — `/reports/sla` continua sendo o relatório completo.
- Exportação (CSV) a partir do dashboard.
- Alertas ou escalonamentos novos de SLA.
- Migração de banco: a tabela `conversation_slas` já existe (`2026_10_20_sla_measurable.sql`).
- Reestilização visual ampla: a linguagem visual atual (cards, faixas, gráficos SVG inline)
  é preservada. O que muda é **o que** aparece e **em que ordem**.

---

## 2. Decisões de design (aprovadas)

### 2.1 Escopo do conteúdo de SLA

O bloco de SLA traz os dois eixos:

- **Histórico** — compliance de 1ª resposta e de resolução, no período selecionado.
- **Fila ao vivo** — distribuição das conversas abertas pelo nível do relógio
  (normal / atenção / alerta), calculada com o mesmo código da inbox.
- **Quebras** — por departamento, por caixa e (só admin) por atendente.
- **Violações** — as conversas que furaram, para agir agora.

### 2.2 Regras de semântica (importante)

**O período** (`7`, `30` ou `90` dias, default `30`) filtra pela **data de criação da
conversa** (`c.created_at`), não pela data em que a violação aconteceu. É o mesmo
critério já usado por `ConversationSla::compliance()` e por `breakdown()`. A spec de
2026-10-20 fixou esse critério; a implementação segue o que já está em produção.

**As fichas de compliance medem conversas 1:1 (`sla_kind = 'standard'`).** Conversa de
grupo tem SLA próprio, baseado em menção (`sla_kind = 'group'`) — o relógio do grupo
começa quando alguém marca o número e para na 1ª resposta humana depois da marcação.
`compliance()`, `breakdown()` e `violatedConversations()` já filtram `standard`, e o
dashboard herda esse filtro. Ou seja: as porcentagens de SLA do dashboard descrevem o
atendimento 1:1, **não** o de grupos.

**A ficha "Em risco agora" mede outra coisa.** Ela vem do relógio de fila, que é
aplicado a toda conversa aberta cuja bola esteja com a gente — **incluindo grupos com
menção pendente**. As duas fichas não somam: medem populações e eixos diferentes
(§2.3).

**"Resposta média" é a média medida** (`AVG(response_seconds)` de `conversation_slas`),
não a média bruta de `messages` — ver §7.

### 2.3 As 6 fichas da faixa "SLA agora"

Cada número tem uma definição única. Nenhum é repetido em outro lugar da faixa.

| Ficha | Valor | Origem | Texto de apoio |
|---|---|---|---|
| **Em risco agora** | nº de conversas com nível `attention` + `alert` | `applyClock()` + `levelFor()` | "N em alerta · N em atenção" |
| **SLA já furado** | conversas **ativas** cuja 1ª resposta furou | `ConversationSla::criticalNow()` | "1ª resposta furada, ainda abertas" |
| **1ª resposta no prazo** | % de compliance de 1ª resposta | `ConversationSla::compliance($p)['response']['percent']` | "N furaram" |
| **Resolução no prazo** | % de compliance de resolução | `ConversationSla::compliance($p)['resolution']['percent']` | "N furaram" |
| **Violações no período** | conversas com **qualquer** SLA furado | `ConversationSla::violatedCount($p)` (novo) | "no período selecionado" |
| **Conversas medidas** | conversas com linha de SLA no período | `compliance($p)['response']['measured']` | "com SLA registrado" |

Regras de estado:

- Porcentagem sem medição (`percent === null`) → mostra "—" e o apoio vira
  "sem medições ainda" (mesmo texto já usado hoje).
- "Em risco agora" é o número que mais muda: é recalculado a cada 30s (TTL do cache),
  e o chip "Atualizado X" do header comunica a defasagem.

**Por que "SLA já furado" e "Em risco agora" são fichas separadas:** a primeira é o
passado que continua aberto (a 1ª resposta já estourou); a segunda é o presente (a bola
está com a gente além do limite). Uma fila com 3 conversas em alerta e 9 conversas
com 1ª resposta furada são problemas diferentes, com ações diferentes.

### 2.4 Arquitetura da informação — header + nove faixas

Ordem de leitura: o que exige ação, depois o contexto.

| # | Faixa | Destino |
|---|---|---|
| 1 | Header — saudação + data \| atualizado, refresh, ir para a caixa | título restaurado |
| 2 | **SLA agora** — 6 fichas | nova |
| 3 | Minha fila — 6 mini stats | fica |
| 4 | Operação agora — 5 fichas | fica |
| 5 | Movimento de hoje — 4 fichas | fica |
| 6 | **SLA em detalhe** — quebras + violações | nova |
| 7 | Tendência e distribuição — gráfico 7d + donut de status | fica |
| 8 | Grupos de WhatsApp — barras 7d normal × grupos + lista | funde duas faixas |
| 9 | Conversas recentes | fica |
| 10 | Performance dos atendentes (admin) | fica |

Mudanças de conteúdo:

- **Header**: a view calcula `$greetName`, `$greeting`, `$todayName` e `$todayPt` e
  **nunca usa**; o CSS de `.dash-title` / `.dash-subtitle` / `.dash-eyebrow` está no
  arquivo, órfão. O título volta à esquerda, com as ações à direita.
- **Operação agora**: perde a ficha "SLA crítico agora" (que vira "SLA já furado", na
  faixa de SLA). Fica com 5 fichas: Normal em aberto, Grupos em aberto, Novos
  aguardando, Atendentes online, Resolvidos hoje.
- **Movimento de hoje**: fica com 4 fichas — Conversas hoje, Mensagens hoje, Resposta
  média e CSAT 30d.
- **"Equipe e caixas" deixa de existir como faixa.** O volume de conversas ativas por
  departamento e por caixa passa a ser a coluna "ativas" das linhas da faixa de SLA.
- **"Atendimento × Grupos" + "Grupos WhatsApp" viram uma faixa** ("Grupos de WhatsApp"):
  o gráfico de barras de 7 dias normal × grupos ao lado da lista de grupos. O donut
  "abertos normal × grupos" sai — ele repetia a informação do donut de status.

**Grade de colunas.** A view já tem as classes `.dash-stats-6` (6 colunas em telas
largas) e `.dash-stats-4`, com um comentário no CSS avisando que a contagem de colunas
divide o número de cartões para não sobrar um cartão órfão na segunda linha. Com
"Operação agora" passando a 5 fichas, precisa de uma `.dash-stats-5` (5 colunas em
telas largas, 3 em ≤1400px, 2 em ≤768px — 5 não deixa órfão em nenhum desses cortes).

### 2.5 A faixa "SLA em detalhe"

**Card "Por departamento"**, **"Por caixa"** e **"Por atendente"** (este último só
para admin). Cada linha:

```
Nome  [barra = % dentro do SLA]  N furaram / Y medidas   ·   Z ativas
```

A barra segue o código do relatório de SLA: verde ≥ 90%, âmbar ≥ 70%, vermelho abaixo.
A coluna "ativas" usa o volume de conversas abertas da dimensão — que é a informação
que a faixa "Equipe e caixas" trazia, agora junto do SLA em vez de numa faixa à parte.

**Card "Conversas com violação no período"**: as 8 mais recentes (por `created_at`
descendente), com protocolo (link para `/inbox?conv={id}`), contato, responsável,
departamento, caixa, 1ª resposta e resolução — reaproveitando
`ConversationSla::violatedConversations($p, 8)`. Botão "Ver relatório completo" →
`/reports/sla?sla={periodo}`.

### 2.6 Permissões

| Dado | Admin | Atendente |
|---|---|---|
| 6 fichas de SLA | sim | sim |
| Risco ao vivo | sim | sim |
| Quebra por departamento / caixa | sim | sim |
| Quebra por **atendente** | sim | **não** |
| Tabela de violações | sim | sim |
| Performance dos atendentes | sim | não (já era assim) |

- A query de `agentBreakdown()` **não é executada** para não-admin — a restrição é no
  controller, não só na view.
- A coluna "Responsável" da tabela de violações **permanece** para não-admin: é dado
  operacional de uma conversa, que qualquer atendente já vê na caixa. O que se restringe
  é a comparação de desempenho entre colegas.
- `violatedConversations()` não tem breakdown por atendente — a coluna "Responsável" é
  contexto da conversa, não ranking.

### 2.7 Período

- `?sla=7|30|90`, default `30`. Valor inválido cai no default.
- Seletor no cabeçalho da faixa "SLA em detalhe", no mesmo padrão do `/reports/sla`
  (`<select>` que submete GET).
- Só a faixa de SLA responde ao período. O resto do dashboard é fixo.
- O período entra na chave de cache (ver 3.4) e é enviado ao endpoint de auto-refresh.

---

## 3. Camada de dados

### 3.1 Reuso integral

Nenhuma mudanca no motor de SLA. Reusados como estão:

- `ConversationSla::compliance(int $days, ?int $dept, ?int $inbox)`
- `ConversationSla::criticalNow()`
- `ConversationSla::departmentBreakdown()`, `inboxBreakdown()`, `agentBreakdown()`
- `ConversationSla::violatedConversations()`
- `SlaService::levelFor()`, `SlaService::applyClock()`, `SlaService::configFor()`
- `Conversation::openCountsByInbox()` (ativas por caixa) e a query de `deptData`
  (ativas por departamento) que o controller já faz.

### 3.2 Novidade 1 — `Conversation::slaAnchorsSql()`

O cálculo da âncora do relógio (`last_in`: última inbound, última resposta **humana**)
está hoje embutido e privado em `Conversation::listBaseSql()`. A regra é sutil e já
causou bug antes: mensagem de bot/fluxo (`user_id` nulo) **não** zera o relógio.

**Decisão:** extrair esse fragmento para um método público
`Conversation::slaAnchorsSql(): string`, usado tanto por `listBaseSql()` quanto pela
nova query do dashboard. Assim inbox e dashboard compartilham **exatamente** a mesma
âncora — o dashboard não pode divergir da inbox.

### 3.3 Novidade 2 — `Conversation::openConversationsAwaitingReply()`

Uma query que traz só o que o relógio precisa, com pré-filtro no SQL para o cálculo em
PHP ficar barato:

- status em `('new','open','waiting_customer')` — `waiting_internal` tem o relógio
  congelado por definição (`SlaService::clockState`), então não entra;
- `last_human_id <= last_inbound_id` — a bola está com a gente;
- colunas: `id`, `status`, `inbox_id`, `department_id`, `created_at`,
  `sla_last_inbound_at`, `sla_last_inbound_id`, `sla_last_human_id`.

Sem os JOINs pesados de `listBaseSql()` (última mensagem, contadores, fluxo): o dashboard
precisa só do relógio.

### 3.4 Novidade 3 — `App\Services\DashboardSlaService`

Service novo, separado do `SlaService` (que é o motor) e do controller (que já carrega
15+ queries inline). Uma unidade com uma responsabilidade: **montar o payload de SLA do
dashboard**.

```php
final class DashboardSlaService
{
    /** Payload completo da faixa "SLA agora" + dados da faixa de detalhe. */
    public static function payload(int $period, bool $isAdmin): array;

    /** Buckets do relógio vivo: normal / atenção / alerta. */
    public static function liveRisk(): array;
}
```

`payload()` devolve:

```php
[
  'visible'     => bool,        // renderizar a faixa de SLA
  'reason'      => ?string,     // 'disabled' | 'unavailable' | null
  'risk'        => ['now' => int, 'alert' => int, 'attention' => int],
  'breachedOpen'=> int,         // criticalNow()
  'response'    => ['percent' => ?float, 'breached' => int, 'measured' => int],
  'resolution'  => ['percent' => ?float, 'breached' => int, 'measured' => int],
  'violated'    => int,
  'byDepartment'=> [...],       // label, percent, breached, measured, active
  'byInbox'     => [...],
  'byAgent'     => [...],       // só quando isAdmin
  'violations'  => [...],       // até 8 linhas
]
```

`visible` é a decisão de render, e `reason` diz por quê quando ela é negativa —
`disabled` = SLA desligado na configuração; `unavailable` = a tabela de medição não pôde
ser lida. São causas diferentes e o usuário precisa de mensagens diferentes.

`liveRisk()` roda `SlaService::applyClock()` sobre as linhas de
`openConversationsAwaitingReply()` e classifica cada uma com
`SlaService::levelFor($waiting, $status, null, $inboxId)`. Reusa a mesma matemática de
segundos úteis e os mesmos limites por caixa da inbox — o número do dashboard e a cor da
fila não podem divergir.

### 3.5 Novidade 4 — `ConversationSla::violatedCount(int $days)`

`COUNT(*)` de conversas com `response_breached = 1 OR resolution_breached = 1` no
período (`sla_kind = 'standard'`). `violatedConversations()` devolve linhas com `LIMIT`
e serve a lista; o total da ficha precisa de uma contagem.

### 3.6 Cache

- Chave atual: `dashboard:{userId}:{admin|user}`.
- Chave nova: `dashboard:{userId}:{admin|user}:sla{period}`.
- TTL 30s, inalterado.
- `/api/dashboard-stats` passa a ler o mesmo parâmetro `?sla=` e a devolver os campos
  novos. Sem isso, o auto-refresh de 20s manteria o SLA antigo na tela.

### 3.7 Degradação

| Situação | Comportamento |
|---|---|
| `sla_enabled = 0` | a faixa "SLA agora" não é renderizada, com `reason = 'disabled'` (é configuração desligada, não dado faltando) |
| `conversation_slas` ausente (migração não aplicada) ou erro na query | `try/catch` no service → `visible = false`, `reason = 'unavailable'` → a página renderiza sem as faixas de SLA, sem erro fatal |
| Nenhuma medição no período | "—" nas porcentagens, "sem medições ainda" no apoio, estado vazio nas quebras ("Nenhuma medição no período.") |
| Nenhuma conversa em risco | ficha "Em risco agora" mostra 0, sem estado de erro |

É o mesmo padrão que o controller já usa para `group_id` (`hasGroups`): a coluna pode
não existir numa base antiga e a página continua de pé.

---

## 4. Interação

### 4.1 Auto-refresh de 20s

O JS inline da view passa a atualizar as 6 fichas de SLA. Os ids:

`slaRiskNow`, `slaBreachedOpen`, `slaResponsePct`, `slaResolutionPct`,
`slaViolations`, `slaMeasured`.

- As porcentagens usam `data-decimals="1"` — o `animateValue()` existente já formata com
  uma casa decimal em `pt-BR`.
- O estado vazio "—" é preservado: `data-empty="1"` impede a animação de sobrescrever.
- O período vive em `data-sla-period` no container `#dashboardApp`; o JS envia
  `?sla={periodo}` para `/api/dashboard-stats`.

### 4.2 Links

- "Em risco agora" → `/inbox` (a fila). **Sem filtro novo de SLA** — não existe na inbox.
- Linhas da tabela de violações → `/inbox?conv={id}` (mesmo padrão do `/reports/sla`).
- "Ver relatório completo" → `/reports/sla?sla={periodo}`.

### 4.3 Header

Título à esquerda ("Bom dia, Romulo" + "segunda-feira, 26 de outubro"), ações à direita
(atualizado, refresh, ir para a caixa). Reusa as variáveis já computadas na view e o CSS
já existente no arquivo.

---

## 5. Testes

### 5.1 `tests/run.php` — checks estáticos (sem banco)

- O controller chama `agentBreakdown()` **apenas** dentro de uma guarda de admin.
- `DashboardSlaService` expõe `payload()` e `liveRisk()`.
- O controller tem guarda `try/catch` no bloco de SLA (não pode fatalar).
- A view renderiza o id `slaRiskNow` e o parâmetro `data-sla-period`.
- A chave de cache inclui o período.

### 5.2 `tests/e2e_dashboard_sla.php` — novo, com banco

Fixture mínima (cria, verifica, apaga no `finally`; backup das settings tocadas):

1. Uma conversa `new` com mensagem do cliente de X minutos atrás, dentro do limite de
   atenção → deve sair em `normal`, **não** em risco.
2. Uma conversa `new` com mensagem do cliente além do limite de alerta → deve sair em
   `alert`.
3. `liveRisk()` devolve os buckets esperados.
4. `compliance()` devolve percentuais coerentes com as linhas de SLA inseridas.
5. `violatedCount()` bate com `violatedConversations()`.
6. `payload($period, isAdmin: false)` **não** devolve `byAgent`; `isAdmin: true` devolve.

**Determinismo do relógio.** O fixture precisa de três coisas para não depender do
relógio da máquina:

- uma mensagem **inbound** do contato na conversa (é ela que ancora o relógio; e o
  `last_human_id` precisa ficar menor que o `last_inbound_id`, senão a conversa não
  entra em `openConversationsAwaitingReply()`);
- limites explícitos de atenção e alerta (`sla_attention_minutes`, `sla_alert_minutes`)
  gravados no fixture e restaurados no `finally`, para o caso "dentro do limite" não
  depender do default do sistema;
- uma caixa/departamento **sem grade de horário comercial** (ou com
  `sla_business_hours = 0`), para o relógio ser tempo puro e não "minutos úteis de hoje".

**Fuso horário (armadilha já paga uma vez):** o fixture **precisa** de
`date_default_timezone_set((require config/app.php)['timezone'])` logo após o
bootstrap. O PHP CLI roda em UTC por padrão, o app web grava em `America/Sao_Paulo`;
sem essa linha o fixture sai 3h fora e o relógio lê "âncora no futuro" — o SLA não
aplica e o teste falha sem erro visível. É o mesmo cuidado de `tests/e2e_*.php`.

### 5.3 Gates

```
& $php tests/run.php
& $php tests/e2e_dashboard_sla.php
```

Ambos precisam passar. Sem migration nova.

---

## 6. Arquivos tocados

| Arquivo | Mudança |
|---|---|
| `app/Services/DashboardSlaService.php` | **novo** |
| `app/Models/Conversation.php` | extrai `slaAnchorsSql()`; nova `openConversationsAwaitingReply()` |
| `app/Models/ConversationSla.php` | nova `violatedCount()` |
| `app/Controllers/DashboardController.php` | bloco de SLA, período, chave de cache, header |
| `app/Controllers/Api/DashboardStatsController.php` | campos de SLA + período |
| `app/Views/dashboard/index.php` | reestruturação completa das faixas |
| `tests/run.php` | checks estáticos |
| `tests/e2e_dashboard_sla.php` | **novo** |

Sem migration. Sem mudança em `SlaService`, `SlaClock`, `SlaMeasurement` — o motor de
SLA não é tocado.

---

## 7. Nota sobre a fonte do "Resposta média"

A ficha "Resposta média" (hoje em "Movimento de hoje") vem desta query no
`DashboardController` (linhas 116-129):

```sql
SELECT AVG(TIMESTAMPDIFF(MINUTE, inbound_time, outbound_time)) as avg_minutes
FROM (
  SELECT m.conversation_id,
         MAX(CASE WHEN m.direction = 'inbound' THEN m.created_at END) as inbound_time,
         MIN(CASE WHEN m.direction = 'outbound' AND m.type = 'text' THEN m.created_at END) as outbound_time
  FROM messages m
  WHERE m.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
  GROUP BY m.conversation_id
) sub
```

Três diferenças em relação ao motor de medição (`SlaMeasurement`):

1. **O `outbound_time` é o `MIN`** de todas as mensagens outbound de texto da janela de
   30 dias — sem garantia de ser **posterior** à última inbound. `TIMESTAMPDIFF` pode
   sair **negativo**.
2. **Conta resposta de bot.** O filtro é só `type = 'text'`; `SlaMeasurement::isHumanReply()`
   exige `user_id` preenchido e exclui `internal_note`/`system`. O bot zera o relógio na
   medição e **não** zera no número do dashboard.
3. **Âncora invertida.** A medição vai da **1ª** inbound do cliente até a **1ª**
   resposta **humana**; a query do dashboard vai da **última** inbound até a **primeira**
   outbound da janela.

Ou seja: o número exibido hoje **não é o mesmo conceito** do SLA de 1ª resposta, pode
ser negativo, e vai aparecer ao lado das porcentagens de compliance — o que convida a
uma comparação indevida entre dois números que não medem a mesma coisa.

**Decisão:** o "Resposta média" passa a ser a média **medida** —
`AVG(response_seconds)` de `conversation_slas` no período, em minutos —, que é a mesma
base das porcentagens de compliance. A query antiga em `messages` sai do controller.

**Consequência:** o número exibido muda em magnitude (é outro cálculo, mais correto) e
passa a responder ao seletor de período. Isso é intencional e deve ser comunicado na
entrega.
