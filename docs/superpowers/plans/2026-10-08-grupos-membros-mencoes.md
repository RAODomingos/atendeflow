# Grupos: Membros Tempo-Real + Menções Outbound Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Exibir participantes do grupo em tempo real e permitir enviar mensagem mencionando membros.

**Architecture:** Sem tabela nova nem cron. `fetchGroupParticipants` na hora via provedor + `sendGroupText` com `mentions`. Falha degrada para comportamento atual.

**Tech Stack:** PHP 8.x (sem framework, `App\*` PSR-4), MySQL, APIs HTTP Uazapi (`/group/info`, `/send/text`) e WAHA (`/api/{session}/groups/{id}`, `/api/sendText`), smoke scripts PHP.

**Spec:** `docs/superpowers/specs/2026-10-08-grupos-membros-contato-design.md` (seções 4, 6, 7, 8)

## Global Constraints

- Manter modelo "só alertas": grupo continua conversa permanente, sem fechar/encerrar (`ConversationService::changeStatus` bloqueia).
- Sem polling/cron; busca de membros só sob demanda (abrir seção/clicar atualizar).
- `sendGroupText` mantém assinatura compatível: 4º param opcional `array $mentions = []`.
- Texto + `mentions`: WAHA exige `@numero` no texto E `mentions: ["xxx@c.us"]` ou `["all"]`; Uazapi usa campo `mentions`.
- Logs de diagnóstico seguem padrão `GROUP_*` via `WhatsAppService::logWebhook`.

## Review Focus

- Grupo com 200+ participantes: lista pagina/limita e não trava a página; espera-se render com scroll e limite.
- Provedor fora/grupo inexistente: espera-se "lista indisponível" e envio normal ainda funciona.
- Menção a LID sem mapeamento: espera-se resolver via `LidMap`/API ou enviar como texto sem quebrar.
- `mentions=["all"]`: espera-se `@todos` + `mentions all` nativo, sem explodir texto com 500 números.
- Duplo submit de envio ao grupo: espera-se sem mensagem duplicada (dedup por `provider_message_id` já existente).

---

### Task 1: Contrato + providers (fetch + mentions)

**Files:**
- Modify: `app/Services/WhatsApp/WhatsAppProviderInterface.php` (adicionar 1 método, estender 1 assinatura)
- Modify: `app/Services/WhatsApp/WahaProvider.php` (implementar fetch + mentions em `sendGroupText`)
- Modify: `app/Services/WhatsApp/UazapiProvider.php` (estender `sendGroupText` com mentions; enriquecer `fetchGroupParticipants` com name/is_admin)
- Test: `scripts/tests/smoke_group_mention.php` (estender) ou novo `scripts/tests/smoke_group_members.php`

**Interfaces:**
- Consumes: `WhatsAppHttpClient::get/post`, `WhatsAppLidMap::resolve`, config base_url/api_key (WAHA), instance token (Uazapi).
- Produces:
  - `fetchGroupParticipants(array $connection, string $groupJid): array` → `array<int, array{phone:string, lid:?string, name:?string, is_admin:bool}>`
  - `sendGroupText(array $connection, string $groupJid, string $text, array $mentions = []): array` → `array{provider_message_id:?string, raw:mixed}`

- [ ] **Step 1: Write the failing test**

```php
// scripts/tests/smoke_group_members.php
check(method_exists($waha, 'fetchGroupParticipants'), 'waha tem fetchGroupParticipants');
$res = $waha->sendGroupText($conn, '120363012345678@g.us', 'oi @5511999998888', ['5511999998888']);
// assert payload contém mentions ["5511999998888@c.us"]
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php scripts/tests/smoke_group_members.php`
Expected: FAIL (`method not found` / mentions ausente no payload)

- [ ] **Step 3: Implement `fetchGroupParticipants` no `WahaProvider` via `GET /api/{session}/groups/{groupJid}`**

Mapear `participants[]` → `{phone, lid, name, is_admin}`; normalizar via `normalizePhone()`; LID preservado em `lid`; erro/timeout retorna `[]` sem throw.

- [ ] **Step 4: Estender `sendGroupText` nos dois providers com `array $mentions = []`**

WAHA: `POST /api/sendText {session, chatId: groupJid, text, mentions: ["xxx@c.us"|"all"]}`. Uazapi: `POST /send/text {number: groupJid, text, mentions}`. `mentions` vazio = chamada idêntica a hoje.

- [ ] **Step 5: Enriquecer `UazapiProvider::fetchGroupParticipants` com name/is_admin quando presentes, mantendo pares [lid,phone] compatíveis**

- [ ] **Step 6: Run tests to verify they pass**

Run: `php scripts/tests/smoke_group_members.php` e `php scripts/tests/smoke_group_mention.php`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Services/WhatsApp/WhatsAppProviderInterface.php app/Services/WhatsApp/WahaProvider.php app/Services/WhatsApp/UazapiProvider.php scripts/tests/smoke_group_members.php
git commit -m "feat(groups): fetch participantes tempo-real + mentions no envio"
```

### Task 2: Service + controller + rota (members + send com menções)

**Files:**
- Modify: `app/Services/WhatsAppService.php` (`sendGroupMessage` + novo `resolveGroupMemberPhones` se preciso)
- Modify: `app/Controllers/WhatsAppGroupController.php` (novo `members()`, `send()` aceita `mentions[]`)
- Modify: `routes/web.php:157-162` (adicionar `GET /whatsapp/groups/{id}/members`)
- Test: `scripts/tests/smoke_group_mention.php` (caso send com mentions + members endpoint via curl interno)

**Interfaces:**
- Consumes: Task 1 (`fetchGroupParticipants`, `sendGroupText` com mentions), `WhatsAppLidMap::resolve`, `WhatsAppManager::forConnection`.
- Produces:
  - `WhatsAppService::sendGroupMessage(int $groupId, string $text, array $mentions = []): array`
  - `WhatsAppGroupController::members(Request $req, int $id): void` → JSON `{members, fetched_at}`
  - `POST whatsapp/groups/{id}/send` aceita `message` + `mentions[]` (dígitos ou `all`)

- [ ] **Step 1: Write the failing test**

```php
// estender smoke: send com mentions persiste outbound + members JSON tem phone
$svc->sendGroupMessage($groupId, 'oi @tel', [$phone]);
check(count($db->fetchAll("SELECT id FROM messages WHERE conversation_id=? AND content LIKE '%@%'", [$convId])) >= 1, 'outbound com mencao espelhado');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php scripts/tests/smoke_group_mention.php`
Expected: FAIL (`sendGroupMessage` não aceita 3º arg / members 404)

- [ ] **Step 3: Implement `sendGroupMessage($groupId, $text, $mentions=[])` em `app/Services/WhatsAppService.php:1042`**

Validar grupo/conexão/texto (igual hoje); normalizar mentions (dígitos, `all`); garantir `@digitos` no texto para cada mention (se ausente, anexar); chamar `sendGroupText($conn, $jid, $text, $mentions)`; espelhar outbound igual hoje.

- [ ] **Step 4: Implement `members()` + rota + `send()` com mentions em `app/Controllers/WhatsAppGroupController.php` e `routes/web.php`**

`members()`: group→connection→`fetchGroupParticipants`→resolve LID→JSON; exceção→JSON 502 `lista indisponível`. `send()`: lê `mentions[]`, filtra dígitos/`all`, chama service. Rota: `$router->get('/whatsapp/groups/{id}/members', [WhatsAppGroupController::class, 'members']);`

- [ ] **Step 5: Run tests to verify they pass**

Run: `php scripts/tests/smoke_group_mention.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Services/WhatsAppService.php app/Controllers/WhatsAppGroupController.php routes/web.php
git commit -m "feat(groups): endpoint members + envio com mencoes"
```

### Task 3: UI grupo (participantes + mencionar)

**Files:**
- Modify: `app/Views/whatsapp/group_show.php` (seção Participantes + checkboxes + envio com mentions)
- Test: manual (abrir grupo, atualizar lista, enviar com 1 menção e com @todos)

**Interfaces:**
- Consumes: Task 2 (`GET whatsapp/groups/{id}/members`, `POST .../send` com `mentions[]`).
- Produces: UI com lista + seleção de mentions (sem API nova).

- [ ] **Step 1: Write the failing check (manual/automatizado leve)**

```js
// espera-se: #group-members carrega via fetch, checkbox adiciona @telefone no textarea e hidden mentions[]
```

- [ ] **Step 2: Implement seção Participantes em `group_show.php`**

Fetch sob demanda (botão Atualizar + auto na 1ª abertura da seção); lista com nome/telefone/admin; checkbox → insere `@telefone` + hidden `mentions[]`; opção `@todos` → `mentions=["all"]`; limite de render (ex.: 200 + "e mais N"); erro → aviso sem bloquear envio.

- [ ] **Step 3: Verify manually**

Passos: grupo real → Ver membros → marcar 1 → enviar → chega com menção no WhatsApp; repetir com @todos; desligar provedor → lista indisponível mas envio funciona.
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add app/Views/whatsapp/group_show.php
git commit -m "feat(groups): UI participantes tempo-real + mencoes"
```
