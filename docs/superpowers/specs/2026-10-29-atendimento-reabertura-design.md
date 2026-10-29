# Reabertura de atendimento — regra por caixa com janela de tempo

**Data:** 2026-10-29
**Escopo:** novo `app/Models/AtendimentoPolicy.php`, `ConversationService`, `WhatsAppService`, `SettingsController`, views `settings/inbox_form.php` e `settings/general.php`, migração de banco, `database/schema.sql`, `tests/e2e_atendimento_reabertura.php`
**Status:** design aprovado pelo parceiro (escopo, semântica, janela e pontos de aplicação), aguardando revisão da spec

---

## 1. Contexto

Hoje um atendimento é uma conversa (`conversations`) e o comportamento é **único**: quando o
cliente volta a escrever no WhatsApp depois de o atendimento anterior ter sido encerrado
(`resolved`/`closed`), o sistema cria uma **conversa nova**. A anterior fica no histórico
como atendimento encerrado e o cliente recomeça do zero — inclusive para a equipe, que vê
um ticket sem o contexto do que já foi resolvido.

O pedido é dar **mais opções** ao gestor, sem perder o comportamento atual como padrão:

| Modo | Regra |
|---|---|
| **Único** (atual) | Toda mensagem nova abre um atendimento novo. |
| **Reabrir sempre** | A mensagem nova reabre o atendimento anterior do mesmo contato no mesmo canal. |
| **Reabrir por janela** | Único, mas se a mensagem chegar até **X minutos** da última mensagem do atendimento anterior, ele é reaberto; depois disso, atendimento novo. |

Decisões fechadas com o parceiro:

- **Onde a regra vive:** por **caixa (inbox)**, com **padrão global** — o mesmo desenho que o
  SLA já usa (`sla_use_global` + campos por caixa).
- **O que "reabrir" significa:** a **mesma conversa** (mesmo `id`, histórico junto). Não é
  uma conversa nova apontando para a antiga.
- **De onde a janela de 2 horas é contada:** da **última mensagem** do atendimento anterior.
- **Onde a regra vale:** **apenas em mensagens do WhatsApp**. O WebChat do site e a criação
  manual ("Novo Atendimento") seguem como hoje.

### Fora de escopo

- WebChat do site: cada sessão abre sua conversa, como hoje (`WebChatController::session`).
- Criação manual de atendimento (`InboxController::createConversation`): sempre cria
  conversa nova, como hoje.
- Conversas de **grupo** do WhatsApp: são permanentes e ficam sempre "em aberto"
  (`ensureGroupConversation`) — a regra não as toca.
- Relatórios, CSAT e SLA: não mudam de forma; apenas o ciclo de SLA da conversa reaberta é
  reiniciado (comportamento já existente para reabertura).

---

## 2. Decisões de design (aprovadas)

### 2.1 Três modos

Nomes internos (persistidos) e rótulos de tela:

| Interno | Rótulo | Comportamento |
|---|---|---|
| `unique` | Sempre novo (único) | Comportamento atual: cria atendimento novo. |
| `always` | Reabrir o anterior | Reabre o atendimento anterior do contato no canal. |
| `window` | Reabrir se for em até X minutos | Reabre se a última mensagem do anterior for há menos de X minutos (padrão 120). |

`unique` é o padrão do sistema — instalar a feature **não muda** o comportamento de
ninguém.

### 2.2 Onde a regra é configurada

**Padrão do sistema** — `Configurações Gerais` (`app/Views/settings/general.php`), card novo
"Reabertura de atendimento": modo + janela em minutos.

**Por caixa** — `Configurações → Caixas → Editar caixa` (`app/Views/settings/inbox_form.php`),
card novo "Reabertura de atendimento" com o par:

- checkbox **"Usar padrão do sistema"** (marcado = a caixa hereda o global);
- select de modo;
- input de janela em minutos (habilitado só no modo `window`).

Espelhamento exato do card de SLA (`app/Views/settings/inbox_form.php:100`), inclusive o
comportamento do checkbox e do preview dos valores globais.

### 2.3 De qual caixa vem a regra

A política aplicável é a da **caixa da conversa anterior** (`conversations.inbox_id`):

- reabrir devolve a conversa para **a mesma caixa** a que o atendimento anterior pertencia,
  e é essa caixa que tem fila e SLA;
- se a conversa anterior não tiver caixa (`inbox_id` nulo), vale o **padrão global**;
- a caixa do canal **não** decide: quem decide a reabertura é a caixa onde o atendimento
  anterior vive.

### 2.4 Qual atendimento é "o anterior"

Candidato: a conversa mais recente do **mesmo contato** e **mesmo canal** que esteja
encerrada:

```sql
SELECT * FROM conversations
 WHERE contact_id = ? AND channel_id = ?
   AND group_id IS NULL
   AND status IN ('resolved', 'closed')
 ORDER BY COALESCE(last_message_at, created_at) DESC
 LIMIT 1
```

Regras:

- **spam nunca é reaberto** (e nem é candidato);
- **grupo nunca é candidato** (`group_id IS NULL`);
- conversa de **outro canal** não é candidata — a política é por canal;
- se `last_message_at` for nulo, a referência de tempo é `created_at`.

---

## 3. Onde a decisão acontece

O ponto único de decisão é `WhatsAppService::findOrCreateConversation()`
(`app/Services/WhatsAppService.php:2230`), chamado por `handleMessage()` na linha 372.
Nenhum outro caminho de WhatsApp escolhe conversa.

Fluxo novo:

```
1. Há conversa ABERTA do contato no canal?  → usa (inalterado em todos os modos)
2. Senão: anterior = candidate(contact, channel)
3. Se anterior existe:
     política = AtendimentoPolicy::forInbox(anterior.inbox_id)
     unique  → cria conversa nova (hoje)
     always  → reabre a anterior
     window  → reabre se (agora - última mensagem da anterior) <= janela
               senão cria conversa nova
4. Senão → cria conversa nova (hoje)
```

O passo 1 permanece idêntico: **enquanto houver atendimento em aberto, a mensagem entra
naquele atendimento**, em qualquer modo. A regra só decide o destino quando o atendimento
anterior já foi encerrado.

---

## 4. O que a reabertura faz

Reabertura é **a mesma conversa**, com o estado de "finalizada" desfeito:

- `status` volta para `new` (sem responsável) ou `waiting_customer` (com responsável) —
  exatamente a semântica que `ConversationService::receiveMessage()` já usa ao reativar
  uma conversa encerrada (`app/Services/ConversationService.php:189`);
- `closed_at`, `close_reason` e `close_description` são limpos;
- `csat_requested` volta a 0 (a reabertura permite novo pedido de avaliação ao resolver de
  novo) e `after_hours_notified` volta a 0 (permite nova validação de horário);
- o **ciclo de SLA é reiniciado** (`ConversationSla::reset()`), para que a reabertura seja
  medida como um ciclo novo;
- histórico, tags, responsável, departamento e caixa **permanecem**;
- evento **`reopened`** gravado na linha do tempo (`conversation_events.event_type` já
  aceita `'reopened'` — `database/schema.sql:330`);
- o **bot não volta sozinho**: `shouldAutoStartFlow()` ignora conversa com histórico de
  fluxo e as que estão em atendimento.

### 4.1 Reuso da lógica existente

O bloco de reabertura **já existe** dentro de `ConversationService::changeStatus()`
(`app/Services/ConversationService.php:399-416`). A implementação extrai esse bloco para um
método público e o usa nos dois caminhos:

```php
public function reopenConversation(int $conversationId, string $motivo): array
```

`changeStatus()` passa a chamá-lo quando sai de `resolved`/`closed`/`spam`; o novo caminho
do WhatsApp chama o mesmo método. Uma regra só, dois gatilhos — sem duplicar a limpeza de
fechamento nem o reset do SLA.

---

## 5. Arquitetura e componentes

### 5.1 `app/Models/AtendimentoPolicy.php` (novo)

Classe pequena e sem estado, com uma responsabilidade: **qual é a regra vigente e ela se
aplica a um atendimento anterior**.

```php
AtendimentoPolicy::MODES = ['unique', 'always', 'window'];

AtendimentoPolicy::forInbox(?int $inboxId): array
    // → ['mode' => string, 'window_minutes' => int, 'source' => 'inbox'|'global']

AtendimentoPolicy::previousConversation(int $channelId, int $contactId): ?array

AtendimentoPolicy::shouldReopen(?array $previous, array $policy): bool

AtendimentoPolicy::hasColumns(): bool
    // guarda para instalação sem a migração (mesmo espírito do
    // Inbox::hasScopeColumn(), app/Models/Inbox.php:271)
```

Resolução da política:

1. se a base não tem as colunas em `inboxes` → `unique` + janela global (comportamento
   atual, sem quebrar);
2. se a caixa tem override (`atendimento_modo` não nulo) → usa a caixa;
3. senão → usa o global (`settings.atendimento_reabertura_modo` /
   `atendimento_reabertura_janela_minutos`).

### 5.2 `ConversationService`

- novo `reopenConversation(int $conversationId, string $motivo): array` — executa a
  transição descrita na seção 4 e devolve a conversa atualizada;
- `changeStatus()` passa a delegar a esse método no caminho final → ativo.

### 5.3 `WhatsAppService`

- `findOrCreateConversation()` passa a consultar `AtendimentoPolicy` entre o passo "não há
  conversa aberta" e o passo "criar conversa nova";
- nada mais no arquivo muda.

### 5.4 `SettingsController`

- `collectInboxSla()`-equivalente para a reabertura: `collectInboxReabertura()` — com
  `atendimento_use_global` marcado, grava `NULL` nos dois campos (a caixa herda o global);
- `reaberturaFormState()` para o estado inicial do card da caixa (mesmo formato do
  `slaFormState()`, `app/Controllers/SettingsController.php:568`);
- `saveGeneral()` grava o padrão global.

---

## 6. Dados

### 6.1 Migração `database/migrations/2026_10_29_atendimento_reabertura.sql`

```sql
ALTER TABLE inboxes
  ADD COLUMN atendimento_modo VARCHAR(20) NULL DEFAULT NULL,
  ADD COLUMN atendimento_janela_minutos INT NULL DEFAULT NULL;

INSERT INTO settings (`key`, value) VALUES
  ('atendimento_reabertura_modo', 'unique'),
  ('atendimento_reabertura_janela_minutos', '120')
ON DUPLICATE KEY UPDATE `key` = `key`;
```

`NULL` = "usar padrão do sistema" (mesma semântica das colunas de SLA por caixa).

### 6.2 `database/schema.sql`

- `inboxes`: as duas colunas novas (a tabela está em `database/schema.sql:183`).

A tabela `settings` **não existe** no `schema.sql` — ela é criada pela migração
`2026_07_23_fix_missing_tables.sql`. Por isso os valores padrão de reabertura
(`unique` / `120`) ficam só na migração acima e no fallback do código
(`AtendimentoPolicy::globalConfig()`), que devolve `unique`/`120` mesmo numa base
sem as linhas em `settings`.

### 6.3 Compatibilidade

Instalação que ainda não rodou a migração: `AtendimentoPolicy::hasColumns()` detecta a
ausência das colunas e o sistema fica em `unique` — ou seja, **exatamente o comportamento
de hoje**. A feature não bloqueia deploy nem exige backfill.

---

## 7. Interface

### 7.1 Por caixa (`app/Views/settings/inbox_form.php`)

Card "Reabertura de atendimento", abaixo do card de SLA:

- "Usar padrão do sistema" (checkbox) — marcado mostra os valores globais como preview;
- select "Como tratar um novo atendimento":
  - `Sempre novo (único)`
  - `Reabrir o anterior`
  - `Reabrir se for em até X minutos`
- input "Janela" em minutos — visível/habilitado só quando o modo é `window` (mesmo
  padrão do JS que controla `slaCustomFields`).

Rótulo de apoio: "Um novo atendimento do mesmo cliente no WhatsApp reabre o atendimento
anterior em vez de abrir um novo."

### 7.2 Padrão global (`app/Views/settings/general.php`)

Card "Reabertura de atendimento" com os mesmos dois campos, rotulados como "padrão do
sistema".

---

## 8. Efeitos colaterais conhecidos

1. **Conversa reaberta volta para a fila ativa** com histórico antigo — é o objetivo, mas
   muda a leitura da fila para quem tem a caixa em modo `always`/`window`.
2. **SLA reinicia**: a reabertura é medida como ciclo novo (1ª resposta e resolução contadas
   a partir dali). O histórico anterior fica fora da medição do ciclo — é o mesmo tratamento
   que a reabertura manual já tem (`ConversationSla::reset()`).
3. **Novo CSAT**: ao resolver de novo, a conversa pode gerar nova avaliação
   (`csat_requested` volta a 0).
4. **Responsável e tags**: a conversa reaberta mantém quem atendia e as tags do atendimento
   anterior.
5. **Modo `always` sem limite**: um cliente que escreve meses depois reabre o ticket antigo
   em vez de abrir um novo — por isso o padrão do sistema é `unique` e o modo `window` é o
   meio-termo para a operação.

---

## 9. Testes

Novo `tests/e2e_atendimento_reabertura.php` (segue o padrão de `tests/e2e_sla_reopen.php`:
cria dados temporários, verifica e limpa no fim), registrado em `tests/run.php`.

Cobertura:

| Caso | Esperado |
|---|---|
| modo `unique` | mensagem nova cria **conversa nova**; a anterior continua encerrada |
| modo `always` | reabre: **mesmo id**, status ativo, `closed_at` vazio, SLA reiniciado, evento `reopened` gravado |
| modo `window` dentro da janela | reabre (mesmo id) |
| modo `window` fora da janela | cria conversa nova |
| anterior em `spam` | nunca reabre; cria conversa nova |
| anterior de outro canal | não é candidata |
| caixa com override | sobrepõe o padrão global |
| caixa sem override | herda o padrão global |
| base sem as colunas | cai em `unique` (sem erro) |
| grupo | intocado |

Execução: `& $php tests/run.php` (a suíte inteira) e o e2e novo isolado.

---

## 10. Arquivos tocados

| Arquivo | Mudança |
|---|---|
| `app/Models/AtendimentoPolicy.php` | **novo** — modos, resolução da política, candidato, decisão |
| `app/Services/ConversationService.php` | `reopenConversation()`; `changeStatus()` delega |
| `app/Services/WhatsAppService.php` | `findOrCreateConversation()` consulta a política |
| `app/Controllers/SettingsController.php` | persistência do global e do override da caixa; estado do form |
| `app/Views/settings/inbox_form.php` | card por caixa |
| `app/Views/settings/general.php` | card do padrão global |
| `database/migrations/2026_10_29_atendimento_reabertura.sql` | **novo** — colunas + settings padrão |
| `database/schema.sql` | colunas em `inboxes` + settings padrão |
| `tests/e2e_atendimento_reabertura.php` | **novo** — cobertura da regra |
| `tests/run.php` | registro do e2e novo |

`ConversationService.php` e `WhatsAppService.php` já estavam com trabalho do parceiro em
andamento: as alterações deste trabalho entram **por cima**, sem tocar no WIP existente, e o
commit é feito **arquivo a arquivo**, sem `git add -A`.
