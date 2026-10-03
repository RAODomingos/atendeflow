# Guild Stores Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Vincular lojas/unidades da Guild ao contato e tornar a unidade da conversa um select por loja.

**Architecture:** Proxy server-side (`ContactController::apiGuildStores` + `GuildService` com curl/timeout, espelho de `WhatsAppHttpClient`) para nunca expor o token; persistência em `contact_stores` (1 linha por unidade, sync transacional espelho de tags); UI Fluent com os componentes já existentes.

**Tech Stack:** PHP 8.1+, MySQL 8, router procedural-MVC próprio, views server-rendered + vanilla JS, `Setting::get/set`, `impeccable detect` como gate de qualidade.

**Spec:** `docs/superpowers/specs/2026-10-02-guild-stores-design.md`

## Global Constraints

- PHP >= 8.1 com pdo/mbstring/json/openssl; MySQL 8.
- Token Guild jamais chega ao browser — só via `Setting::get('guild_api_token')` no servidor.
- `contacts.company`, `{{contact.company}}`, `{empresa}`, macros e fluxos: backend inalterado.
- `conversations.unit` continua VARCHAR e recebe só o `store_name`.
- Vocabulário visual DESIGN.md: superfícies planas, borda 1px `#edebe9`, raios app 4–8px, azul só em ação, Segoe UI no desk.
- Todo `echo` de dado externo usa `e()`; `store_id` sempre int.
- Nunca commitar `storage/cache/*` nem `public/wiki-frontend` sujo pré-existente.

## Review Focus

- Token ausente em Configurações → proxy responde `success:false` PT-BR e a UI orienta o admin (Task 1; teste no passo de verificação do proxy sem token).
- `customer_id` vazio ou com caracteres inválidos → 422 local sem chamar a Guild (Task 1; teste com `customer_id='!!'`).
- Timeout/5xx/JSON inválido da Guild → toast PT-BR, tela não trava (Tasks 1 e 3; teste com base URL inválida temporária).
- Contato sem lojas vinculadas → unidade mantém input texto legado no inbox (Task 3; teste com contato sem vínculos).
- Merge de contatos com lojas sobrepostas → une sem duplicar `(contact_id, store_id)` (Task 1; teste com loja comum aos dois).

---

## File Structure

- `database/migrations/2026_10_02_contact_stores.sql` (novo) — DDL de `contact_stores`; segue o estilo das migrations existentes.
- `app/Models/Contact.php` (alterar) — `find()` inclui `stores`; novos `syncStores()`, `removeStore()`; `merge()` une lojas.
- `app/Services/GuildService.php` (novo) — única fronteira com a Guild; `getStores(string $customerId): array`.
- `app/Controllers/ContactController.php` (alterar) — novo `apiGuildStores()`; `store()`/`update()` persistem vínculos.
- `routes/web.php` (alterar) — `GET /api/guild/stores` no grupo JSON interno (auth, sem CSRF), ao lado de `/api/contacts/search`.
- `app/Controllers/SettingsController.php` + `app/Views/settings/general.php` (alterar) — campos `guild_api_base` / `guild_api_token` via `saveGeneral()`.
- `app/Views/contacts/form.php`, `contacts/show.php` (drawer), `contacts/index.php` (modal) (alterar) — bloco "Loja (Guild)": código + Buscar + checkboxes.
- `app/Views/contacts/show.php` card `cd-info`, `contacts/pdf.php`, `contacts/pdf_full.php`, `inbox/pdf.php` (alterar) — rename Empresa→Loja, exibir networks/unidades.
- `app/Models/Conversation.php` (alterar) — selects que projetam `ct.company as contact_company` passam a projetar primeira network (`ORDER BY network_name LIMIT 1`).
- `app/Views/inbox/panel.php`, `_conv_list.php`, `inbox/show.php` (alterar) — exibir network; unidade vira Loja→Unidade selects com fallback texto.

### Task 1: Dados, serviço, proxy e token

**Files:**
- Create: `database/migrations/2026_10_02_contact_stores.sql`
- Create: `app/Services/GuildService.php`
- Modify: `app/Models/Contact.php` (`find`, `syncStores`, `removeStore`, `merge`)
- Modify: `app/Controllers/ContactController.php` (novo `apiGuildStores()`)
- Modify: `routes/web.php` (linha ~245, grupo JSON interno)
- Modify: `app/Controllers/SettingsController.php` (`saveGeneral`)
- Modify: `app/Views/settings/general.php` (campos base/token)

**Interfaces:**
- Consumes: `Setting::get/set`, `Database::getInstance()`, padrão curl de `WhatsAppHttpClient`.
- Produces: `GuildService::getStores(string $customerId): array` → `['network_name' => string, 'stores' => [['id' => int, 'name' => string]]]`; `GET /api/guild/stores?customer_id=` → `{ success, network_name, stores }` ou `{ success:false, error }`; `Contact::syncStores(int $contactId, array $stores): void` onde cada item é `['customer_id'=>string,'network_name'=>string,'store_id'=>int,'store_name'=>string]`.

- [ ] **Step 1: Escrever migration e aplicar em banco local de teste**
- [ ] **Step 2: Verificar tabelas via `SHOW COLUMNS`** — rode `php database/migrate.php` (ou o fluxo local equivalente) e confirme `contact_stores` com UNIQUE `(contact_id, store_id)` e FK cascade; esperado: tabela existe, segunda aplicação não duplica.
- [ ] **Step 3: Implementar `GuildService::getStores()`** com curl + fallback `stream_context`, timeout 8s, `Authorization: Bearer` do `Setting`, validação de `customer_id` (`/^[A-Za-z0-9_-]{1,64}$/`, senão exceção `invalid_customer_id` sem HTTP).
- [ ] **Step 4: Verificar com script descartável** — `php -r` chamando `getStores('!!')`: esperado FAIL local `invalid_customer_id` sem requisição; depois aponte `guild_api_base` para URL inválida e confirme exceção `timeout/unreachable` PT-BR. Apague o script.
- [ ] **Step 5: Implementar `Contact::syncStores()/removeStore()` + `find()` com `stores` + `merge()` unindo lojas** (transação; `INSERT IGNORE`-like via checagem de existência, espelho de phones).
- [ ] **Step 6: Verificar merge sem duplicar** — script descartável: dois contatos com mesmo `store_id`, `merge()`, conte linhas em `contact_stores`: esperado 1 linha no destino. Apague o script.
- [ ] **Step 7: Implementar `ContactController::apiGuildStores()` + rota + campos em `saveGeneral()`/`general.php`** (sem token: `success:false` "Token da Guild não configurado em Configurações").
- [ ] **Step 8: Verificar ponta a ponta** — `php -l` nos 4 PHP tocados (esperado: sem erros); `GET /api/guild/stores?customer_id=!!` logado: esperado JSON `success:false`; com token vazio: mensagem de token. `impeccable detect --json` nos tocados: esperado 0 `warning`.
- [ ] **Step 9: Commit** — `git add` só dos arquivos da Task 1; `git commit -m "feat(guild): contact_stores, GuildService, proxy e token em Settings"`.

### Task 2: UI do contato (buscar, vincular, exibir Loja)

**Files:**
- Modify: `app/Views/contacts/form.php`, `app/Views/contacts/show.php` (drawer + card `cd-info`), `app/Views/contacts/index.php` (modal + `data-company`)
- Modify: `app/Controllers/ContactController.php` (`store`, `update`)
- Modify: `app/Models/Conversation.php` (subquery primeira network)
- Modify: `app/Views/contacts/pdf.php`, `contacts/pdf_full.php`, `inbox/pdf.php`

**Interfaces:**
- Consumes: `GET /api/guild/stores?customer_id=` e `Contact::syncStores()` da Task 1.
- Produces: bloco "Loja (Guild)" reutilizável nos 3 formulários; card `cd-info` exibindo networks + unidades; `contact_company` = primeira network alfabética.

- [ ] **Step 1: Bloco Loja no `form.php`** — input código + Buscar (fetch proxy) + `network_name` + checkboxes `store_id`; submit envia `guild_stores_json`; `store()` persiste via `syncStores()` após `create()`.
- [ ] **Step 2: Repetir bloco no drawer do `show.php` e no modal do `index.php`** (mesmos names/ids com sufixo por contexto para não colidir).
- [ ] **Step 3: Verificar vínculo somando** — crie contato, busque código, marque 1 unidade, salve, reabra, busque outro código e marque outra: esperado 2 linhas em `contact_stores` (nenhuma apagada). Desmarque 1 da tela: esperado remove só ela.
- [ ] **Step 4: Card `cd-info` + rename Empresa→Loja** — `show.php` (card + header), `form.php`, modal `index.php`, `data-company` com networks, `panel.php:187`, `inbox/show.php:55`, `_conv_list.php:66`, `Conversation.php` selects, 3 PDFs.
- [ ] **Step 5: Verificar exibição** — `php -l` nos PHP tocados (sem erros); abra contato com 2 lojas e contato sem loja: esperado networks/unidades no card e "Não informado" onde faltar; `impeccable detect --json` nos tocados: 0 `warning`.
- [ ] **Step 6: Commit** — `git commit -m "feat(guild): vincular lojas no contato e exibir Loja na UI"`.

### Task 3: Unidade como select no inbox + verificação final

**Files:**
- Modify: `app/Views/inbox/panel.php` (Loja→Unidade selects + fallback texto, reaproveita `POST /inbox/{id}/unit`)
- Test: manual nos cenários abaixo + `impeccable detect --json` em todos os tocados do plano

**Interfaces:**
- Consumes: `contact.stores` (Task 1), `POST /inbox/{id}/unit` existente (body `unit=<store_name>`).
- Produces: conversa com `unit = store_name` da unidade escolhida.

- [ ] **Step 1: Substituir `convUnitInput` por selects** — Loja (networks distintas do contato) → Unidade (optgroups por network); ao trocar a loja, a unidade reseta para a primeira da loja; salvar chama o endpoint existente; erro → toast PT-BR existente.
- [ ] **Step 2: Fallback texto** — contato sem `stores`: esperado input texto legado idêntico ao atual; com lojas: selects; unidade antiga em texto livre: pré-seleciona por `store_name` igual ou cai no texto.
- [ ] **Step 3: Verificação final** — repita a seção 9 da spec (código válido/inválido, 2 lojas, unidade por loja, fallback, merge, PDF); `git status --short` sem `storage/cache/*`; `impeccable detect --json` em todos os tocados: 0 `warning`.
- [ ] **Step 4: Commit** — `git commit -m "feat(guild): unidade como select por loja no inbox"`.

## Self-Review

1. **Spec coverage:** §3 dados→Task 1; §4 serviço/proxy/token→Task 1; §5 UI contato→Task 2; §6 inbox→Task 3; §7 compat (company legado, merge, PDFs, fallback)→Tasks 1–3; §8 erros→Tasks 1 e 3; §9 verificação→Steps 8/5/3. Sem gaps.
2. **Step scan:** cada step produz uma coisa verificável (arquivo, comando com saída esperada); sem corpos transcritos — implementador escreve o corpo idiomático.
3. **Type consistency:** `syncStores(int, array): void` com item `customer_id/network_name/store_id/store_name` usado igual nas Tasks 1–2; proxy retorna `network_name/stores{id,name}` consumido igual nas Tasks 2–3; `unit = store_name` consistente.
4. **Review Focus:** as 5 linhas têm teste no task dono (token→T1S8; customer inválido→T1S4; timeout→T1S4/T3S1; sem lojas→T3S2; merge→T1S6).
5. **Proportion:** plano traz decisões e verificações, não o código; corpos ficam com o implementador.
