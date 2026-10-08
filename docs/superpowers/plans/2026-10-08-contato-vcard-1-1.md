# Contato vCard 1:1 (Outbound + Inbound) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enviar um contato da base como cartão clicável no inbox 1:1 e receber/exibir cartões inbound.

**Architecture:** Novo método `sendContact` nos providers (vCard gerado local) + novo `messages.type='contact'` (JSON `{name,phone,organization?}`) roteado via `sendOutbound`. Inbound parseia `contactMessage/vCard` nos dois providers.

**Tech Stack:** PHP 8.x (`App\*`), MySQL, WAHA `POST /api/sendContactVcard`, Uazapi `POST /send/contact`, views `inbox/show.php`.

**Spec:** `docs/superpowers/specs/2026-10-08-grupos-membros-contato-design.md` (seções 5, 6, 7, 8)

## Global Constraints

- Uma mensagem = um contato; origem é contato existente da base (`contacts.id`) — sem digitação livre de vCard.
- `messages.content` para `type='contact'` é JSON `{"name":string,"phone":string(dígitos),"organization":?string}`; preview/notificação usa `"📇 Contato: {name}"`.
- vCard gerado localmente: `BEGIN:VCARD\nVERSION:3.0\nFN:{name}\nTEL;TYPE=CELL:{phone}\n[ORG:{org}\n]END:VCARD`.
- `messages.type` hoje é ENUM sem `'contact'` (`2026_07_23_extend_messages_type_enum.sql`, `schema.sql:284`) — migração obrigatória antes do código.
- `dispatchWhatsApp`/`sendOutbound` mantêm fallback: sem `provider_message_id` → `delivery_status='failed'` + toast, sem mensagem fantasma.

## Review Focus

- Telefone inválido/vazio: espera-se 422 antes de chamar o provedor, sem criar mensagem.
- Contato sem nome: espera-se usar `contacts.phone` como FN, nunca vCard vazio.
- Provedor desconectado: espera-se `delivery_status='failed'` + "Reconecte e use Tentar de novo", com retry funcionando.
- Inbound com vCard gigante/malformado: espera-se truncar em 2000 chars e ainda exibir cartão com nome+telefone.
- Retry de mensagem `contact`: espera-se reenviar pelo mesmo `sendContact`, não como texto.

---

### Task 1: Migração + providers (sendContact + parse inbound)

**Files:**
- Create: `database/migrations/2026_10_08_messages_type_contact.sql`
- Modify: `app/Services/WhatsApp/WhatsAppProviderInterface.php` (adicionar `sendContact`)
- Modify: `app/Services/WhatsApp/WahaProvider.php` (`sendContact` + parse `contactVcard`)
- Modify: `app/Services/WhatsApp/UazapiProvider.php` (`sendContact` + parse contato)
- Modify: `app/Services/WhatsApp/IncomingMessage.php` (helper `contact()` opcional)
- Test: `scripts/tests/smoke_contact_send.php` (novo)

**Interfaces:**
- Consumes: `WhatsAppHttpClient::post`, `extractMessageId`, `normalizePhone` (WAHA), `instanceAuthHeaders`/`guard` (Uazapi).
- Produces:
  - `sendContact(array $connection, string $to, array $contact): array` → `array{provider_message_id:?string, raw:mixed}`; `$contact=['name'=>string,'phone'=>string,'organization'=>?string]`
  - `parseWebhook` passa a retornar `IncomingMessage type='contact'`, `content=json{name,phone,organization?}` quando `messageType`/payload for contato.

- [ ] **Step 1: Write the failing test**

```php
// scripts/tests/smoke_contact_send.php
check($waha->sendContact($conn, '5511999998888', ['name' => 'Maria', 'phone' => '5511988887777'])['provider_message_id'] !== null, 'waha sendContact');
$in = $uaz->parseWebhook($payloadContact);
check($in && $in->type === 'contact', 'parse inbound contact');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php scripts/tests/smoke_contact_send.php`
Expected: FAIL (`Call to undefined method sendContact` / parse retorna null/text)

- [ ] **Step 3: Create migration `database/migrations/2026_10_08_messages_type_contact.sql`**

```sql
ALTER TABLE messages MODIFY COLUMN `type` ENUM('text','image','audio','video','file','system','internal_note','csat_request','button_list','list_menu','contact') NOT NULL DEFAULT 'text';
```

Manter demais valores existentes (inclui `button_list`,`list_menu` da migração 2026_07_23).

- [ ] **Step 4: Implement `sendContact` nos dois providers**

WAHA: `POST /api/sendContactVcard {session, chatId: phone@c.us, contacts:[{vcard}], reply_to?}`. Uazapi: `POST /send/contact {number: to, fullName, phoneNumber: contact.phone, organization?, ...}`. Validar `phone` (dígitos ≥8) antes; `name` vazio → usa phone. `guard()` + `extractMessageId()` iguais ao `send()`.

- [ ] **Step 5: Implement parse inbound de contato**

WAHA: detectar `contactVcard`/`contactMessage` em `parseWebhook:440` → `IncomingMessage type='contact'`. Uazapi: detectar `messageType` de contato em `parseWebhook:738-816` → mesmo DTO. `content=json_encode(['name','phone','vcard'=>substr(0,2000)])`, `caption=null`.

- [ ] **Step 6: Run tests to verify they pass**

Run: `php scripts/tests/smoke_contact_send.php`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_10_08_messages_type_contact.sql app/Services/WhatsApp/WhatsAppProviderInterface.php app/Services/WhatsApp/WahaProvider.php app/Services/WhatsApp/UazapiProvider.php app/Services/WhatsApp/IncomingMessage.php scripts/tests/smoke_contact_send.php
git commit -m "feat(contact): providers sendContact + parse inbound vcard"
```

### Task 2: Outbound/inbound no service + inbox UI

**Files:**
- Modify: `app/Services/WhatsAppService.php` (`sendOutbound:1464-1619` roteia `contact`; `handleWebhook:242-520` salva `type='contact'`)
- Modify: `app/Controllers/InboxController.php` (`sendMessage:342-460` aceita `contact_id`; `dispatchWhatsApp:751-784` já genérico)
- Modify: `app/Views/inbox/show.php` (botão anexar + modal + render cartão)
- Test: `scripts/tests/smoke_contact_send.php` (fluxo ponta-a-ponta) + manual

**Interfaces:**
- Consumes: Task 1 (`sendContact`, parse `type='contact'`), `ConversationService::sendMessage($cid,$content,$type='contact')`, `Contact::find($id)`.
- Produces:
  - `POST /inbox/{id}/messages` aceita `contact_id` (alternativo a `content`/`file`) → cria `messages.type='contact'` + dispatch.
  - Inbox renderiza `type='contact'` como cartão (`tel:` + `https://wa.me/{phone}`).

- [ ] **Step 1: Write the failing test**

```php
// fluxo: ConversationService::sendMessage($convId, json_encode(['name'=>'Maria','phone'=>'5511988887777']), 'contact', $uid)
// + $svc->sendOutbound($convId, $msgId, 'contact', $json) retorna provider id
check($providerId !== null, 'outbound contact entrega');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php scripts/tests/smoke_contact_send.php`
Expected: FAIL (`sendOutbound` trata como mídia/texto ou ENUM rejeita `contact`)

- [ ] **Step 3: Implement roteamento `contact` em `sendOutbound` + salvamento inbound em `handleWebhook`**

Em `sendOutbound`: `if ($type==='contact') { $c=json_decode($content,true); $result=$provider->sendContact($connection,$contact['phone'],$c); }` (+ reply_to igual texto). Em `handleWebhook`: `if ($message->type==='contact') { $type='contact'; $content=$message->content; }` + preview `"📇 Contato: {nome}"` para notificação. `TemplateService` não altera `contact`.

- [ ] **Step 4: Implement `contact_id` em `InboxController::sendMessage` + UI em `inbox/show.php`**

`sendMessage`: se `contact_id>0`, carrega `Contact::find`, monta JSON, `sendMessage($id,$json,'contact',...)`, dispatch, retorna mesmo envelope AJAX (`messages`, `delivery_failed`). Validação: sem `content`/`file`/`contact_id` → 422 igual hoje. UI: botão 📇 ao lado do anexo → modal com busca em `contacts` → POST `contact_id`; balão `contact` com nome/telefone + links `tel:`/`wa.me`; retry reutiliza `dispatchWhatsApp` com `type='contact'`.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php scripts/tests/smoke_contact_send.php`
Expected: PASS. Manual: inbox 1:1 → anexar contato → chega cartão no celular; celular → enviar contato → aparece cartão no inbox; retry com provedor off → falha amigável.

- [ ] **Step 6: Commit**

```bash
git add app/Services/WhatsAppService.php app/Controllers/InboxController.php app/Views/inbox/show.php scripts/tests/smoke_contact_send.php
git commit -m "feat(contact): envio e recebimento de contato no inbox 1:1"
```
