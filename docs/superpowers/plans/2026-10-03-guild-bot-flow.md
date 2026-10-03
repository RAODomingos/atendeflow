# Guild Bot-Flow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Novo nó `guild_select` que identifica loja/unidade do contato no bot e segue o fluxo.

**Architecture:** Runtime no `FlowEngineService` seguindo os cases existentes (apresenta como menu, responde como menu, persiste como collect); builder estende o registro `NODE_TYPES` e o modal por tipo; seed versionada cria o fluxo de teste inativo.

**Tech Stack:** PHP 8.1+, MySQL 8, `GuildService`, tabelas `flow_nodes` (ENUM), `flow_options`, `conversation_flow_states`, `flow_answers`, builder vanilla JS em `flows/form.php`.

**Spec:** `docs/superpowers/specs/2026-10-03-guild-bot-flow-design.md`

## Global Constraints

- PHP >= 8.1 com pdo/mbstring/json/openssl; MySQL 8.
- `flow_nodes.node_type` é ENUM: todo tipo novo exige migration ALTER.
- Token Guild só via `Setting::get('guild_api_token')` no servidor.
- `conversations.unit` recebe só o `store_name`; `contacts.company`, `{{contact.company}}`, macros: inalterados.
- Vocabulário DESIGN.md no builder (Fluent; sem componente novo fora do padrão).
- Todo texto ao cliente via `e()`/escape onde houver interpolação; `store_id` sempre int.
- Nunca commitar `storage/cache/*`.

## Review Focus

- Token Guild ausente no runtime → ramo de erro configurável, nunca fatal (Task 1; teste com token vazio).
- Resposta inválida (texto fora das opções) → repete com `invalid_message`, sem avançar (Task 1; teste com texto livre).
- Lojas somem entre listar e responder (vínculo removido no meio) → trata como lista vazia da etapa e reapresenta ou desvia (Task 1; teste removendo vínculo entre os dois passos).
- `next_node_id`/`no_store_node_id` apontando para nó deletado → finaliza com evento, sem fatal (Task 1; teste com next inexistente).
- Unidade renomeada na Guild entre fetch e resposta (opção obsoleta) → não casa, repete a pergunta (Task 1; teste respondendo nome fora da lista atual).

---
### Task 1: Runtime `guild_select` no engine

**Files:**
- Create: `database/migrations/2026_10_03_flow_guild_select.sql`
- Modify: `app/Services/FlowEngineService.php` (`executeNode`, `handleCustomerMessage`, `refreshInteractionTimeoutIfNeeded`)
- Test: scripts descartáveis em `C:\Users\romul\AppData\Local\Temp\opencode\` (fora do repo; apagar ao final da task)

**Interfaces:**
- Consumes: `Contact::getStores(int): [['customer_id','network_name']]`, `GuildService::getStores(string): ['network_name', 'stores'=>[['id','name']]]`, `Flow::saveAnswer(int $conversationId, int $nodeId, ?int $optionId = null, ?string $text = null)`, `Flow::saveFlowState(int,int,int,?string)`, `Conversation::update(int, ['unit'=>string])`.
- Produces: `case 'guild_select'` em `executeNode` e ramo em `handleCustomerMessage`; contrato do `config`: `no_store_node_id:int`, `next_node_id:int`, `store_prompt/unit_prompt/invalid_message/error_message:string`, `presentation:'buttons'|'list'|'text'`, `save_unit:bool=1`, `max_attempts:int=3`; parcial da etapa (loja escolhida + contador) via `saveAnswer($conversationId, $nodeId, null, $text)`.

- [ ] **Step 1: Escrever migration ENUM**
`ALTER TABLE flow_nodes MODIFY COLUMN node_type ENUM(...existentes..., 'guild_select')` (lista exata de `2026_07_31_flow_node_types_extended.sql` + `'guild_select'`).
- [ ] **Step 2: Aplicar e verificar** — rode `php database/migrate.php`; esperado: migration OK; `SHOW COLUMNS` contém `guild_select`.
- [ ] **Step 3: RED — 0 lojas desvia sem perguntar** — script descartável: cria contato sem stores + conversa + fluxo com nó `guild_select` (`no_store_node_id` → collect CNPJ); chama `start()`; esperado FAIL: nó CNPJ não executado / pergunta de loja enviada.
- [ ] **Step 4: Implementar `case 'guild_select'`** (0 lojas → executa nó de `no_store_node_id`; 1 loja → `GuildService` + `dispatchMenuPresentation` com options em runtime; N → lojas `ID - Nome`; salva estado no próprio nó).
- [ ] **Step 5: Re-rodar script** — esperado PASS.
- [ ] **Step 6: RED — resposta inválida repete e falha Guild desvia** — script: responde texto livre (esperado FAIL: avançou); simula `GuildService` sem token (esperado FAIL: fatal em vez de `error_message` + 3ª tentativa `handoff`).
- [ ] **Step 7: Implementar ramo em `handleCustomerMessage`** (casa label/value/número como `handleMenuResponse`; parcial loja→unidades; final salva answer + `unit` se `save_unit` + avança a `next_node_id`; inválida repete; erro Guild conta tentativas na parcial e na 3ª executa `handoff`; inclui `guild_select` em `refreshInteractionTimeoutIfNeeded`).
- [ ] **Step 8: Re-rodar + casos restantes** — PASS em: 1 loja lista unidades; N lojas lista → unidades; unidade salva em `conversations.unit`; next inexistente finaliza sem fatal; vínculo removido entre passos não quebra. Limpar fixtures e apagar scripts.
- [ ] **Step 9: Verificar** — `php -l` no service; `impeccable detect --json` nos tocados: esperado 0 `warning`.
- [ ] **Step 10: Commit** — `git commit -m "feat(flow): runtime do no guild_select Guild"`.

### Task 2: Builder editável

**Files:**
- Modify: `app/Views/flows/form.php` (`NODE_TYPES`, modal por tipo, `saveNodeModal`, `showContent`, derivação de conexões PHP ~142-152 e JS, `OPTION_NODE_TYPES` — NÃO incluir `guild_select` nas listas de options estáticas; conexões derivadas de `config.no_store_node_id`/`config.next_node_id`)

**Interfaces:**
- Consumes: contrato `config` da Task 1.
- Produces: tipo visível na paleta (grupo `interact`, ícone/cor Fluent), modal com os 8 campos, conexões desenhadas dos 2 nexts, validação de nexts existentes.

- [ ] **Step 1: RED — tipo ausente no builder** — script: FAIL se `form.php` não contém `guild_select` em `NODE_TYPES` nem `neGuildNoStore`/`neGuildNext`.
- [ ] **Step 2: Implementar** — entrada `NODE_TYPES`, bloco de campos no modal (textareas/inputs + 2 selects de nós do fluxo + checkbox `save_unit`), persistência em `saveNodeModal()`, `showContent` + derivação de conexões (PHP e JS) a partir dos 2 nexts, validação (nexts existem; `max_attempts` >= 1).
- [ ] **Step 3: Re-rodar script** — PASS; `php -l form.php`; `detect` 0 `warning`.
- [ ] **Step 4: Verificação manual** — abrir `flows/create`, adicionar nó Guild, ligar `no_store`→CNPJ e saída→próximo, salvar e reabrir (conexões e campos intactos).
- [ ] **Step 5: Commit** — `git commit -m "feat(flow): builder editavel para guild_select"`.

### Task 3: Seed do fluxo de teste + verificação final

**Files:**
- Create: `database/migrations/2026_10_03_flow_seed_guild_test.php` (PHP, segue runner `migrate.php`; cria fluxo inativo + 7 nós + options com `next_node_id` encadeados; idempotente por nome)
- Test: manual + scripts da Task 1 reaproveitados contra o fluxo seedado

**Interfaces:**
- Consumes: runtime (Task 1) e tipos do builder (Task 2).
- Produces: fluxo "Atendimento Loja/Unidade (teste)" inativo: start → guild_select (`next` → confirmação, `no_store` → CNPJ collect `save_field=document` → message → handoff; confirmação → handoff).

- [ ] **Step 1: RED — seed ausente** — script: FAIL se não existe fluxo com esse nome.
- [ ] **Step 2: Implementar seed** — nós/options exatos da spec §5, `is_active=0`, idempotente (pula se nome existir).
- [ ] **Step 3: Aplicar e verificar** — `php database/migrate.php` OK; re-rodar 2x sem duplicar; abrir no construtor (nó Guild renderiza com conexões).
- [ ] **Step 4: Ponta a ponta manual** — ativar em ambiente de teste: contato sem loja (CNPJ→document→handoff), 1 loja (unidades→unit→segue), N lojas (loja→unidades→segue); Guild sem token (erro→handoff na 3ª).
- [ ] **Step 5: Verificação final** — `git status --short` sem `storage/cache/*`; `detect` em todos os tocados do plano: 0 `warning`.
- [ ] **Step 6: Commit** — `git commit -m "feat(flow): seed do fluxo de teste Loja/Unidade"`.

## Self-Review

1. **Spec coverage:** §3 runtime→Task 1; §4 builder→Task 2; §5 seed→Task 3; §6 compat (canais via `dispatchOutboundMessage`, unit texto, timeouts, token)→Tasks 1–3; §7 verificação→steps; §8 fora de escopo respeitado (sem busca por CNPJ, sem cache, sem correção no meio).
2. **Step scan:** cada step produz uma coisa (arquivo, comando com saída esperada); sem corpos transcritos — implementador escreve o corpo idiomático do engine.
3. **Type consistency:** `saveAnswer(int,int,?int,?string)`, `GuildService::getStores(string): array`, config keys idênticos nas Tasks 1–2, `unit` = `store_name` nas Tasks 1 e 3.
4. **Review Focus:** as 5 linhas têm teste na Task 1 (token, inválida, vínculo sumido, next morto, unidade obsoleta).
5. **Proportion:** decisões e verificações, não o código; mais curto que a soma do que implementa.
