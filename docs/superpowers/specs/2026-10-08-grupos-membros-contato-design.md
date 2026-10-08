# Design: Grupos WhatsApp (membros tempo-real + menções) + Envio de Contato 1:1

Data: 2026-10-08
Status: aguardando revisão do usuário
Opção escolhida: A — tempo-real sem persistir membros

## 1. Entendimento (brief validado)

- Grupos: manter modelo atual. Grupo = `whatsapp_groups` + conversa espelhada
  permanente (`conversations.group_id`, `source='whatsapp_group'`, não pode
  fechar/encerrar) + sino `group_mention` só quando marcam o número da conexão
  ou `@todos`. Não transformar grupo em inbox normal.
- Membros: ver lista de participantes na tela do grupo (`whatsapp/group_show`)
  buscando em tempo real via API do provedor, e poder mencionar `@membro`
  ao enviar mensagem ao grupo.
- Contato: botão no inbox 1:1 para enviar um contato da base como cartão
  clicável (vCard nome + telefone). Escopo: inbox 1:1, não grupos.

O que existe hoje:
- `whatsapp_groups` só tem `participant_count`, sem tabela de membros.
- `UazapiProvider::fetchGroupParticipants()` existe mas só para self-healing
  de LID, não aparece na UI. WAHA não tem fetch.
- Envio a grupo é só `sendGroupText(connection, groupJid, text)` sem `mentions`.
- `send()` só aceita `text|image|audio|video|file`. Não há tipo `contact`
  inbound nem outbound.
- Viabilidade: WAHA tem `POST /api/sendContactVcard` + `sendText` com
  `mentions: ["xxx@c.us"]` ou `["all"]`. Uazapi tem `POST /send/contact`
  + `POST /group/info` com `Participants`.

## 2. Abordagens consideradas

### A. Tempo-real sem persistir (escolhida)
- Membros buscados na hora, sem tabela nova, sem cron, sem polling.
- Menção outbound passando `mentions` ao provedor.
- vCard 1:1 com novo método `sendContact` + tipo `contact` em `messages`.
- Prós: menor risco, sem migração pesada, sem risco de ban por polling.
- Contras: sem histórico entrou/saiu, lista indisponível se provedor fora
  (degrada para comportamento atual).

### B. Persistente com sync (descartada por ora)
- Nova `whatsapp_group_members` + cron 1h + webhook de update.
- Prós: lista instantânea offline, histórico.
- Contras: migração, worker, rate-limit/ban, manutenção. YAGNI para
  "manter só alertas". Pode evoluir a partir de A sem quebra.

## 3. Design — Arquitetura e escopo

- Nenhuma mudança em `ConversationService::changeStatus` (grupo continua
  sem fechar), nem no fluxo inbound de menção (`handleGroupMessage`,
  `notifyGroupMention`).
- Duas frentes isoladas:
  1. `Grupos`: leitura de participantes + envio com menções.
  2. `Contato 1:1`: envio + recebimento de vCard.
- Falha de provedor degrada: membros mostram "indisponível", envio segue
  como texto normal; contato com erro mostra mensagem amigável e não
  cria mensagem fantasma.

## 4. Design — Grupos: membros tempo-real + mencionar

### Interface (contrato)
```php
// WhatsAppProviderInterface (adições)
public function fetchGroupParticipants(array $connection, string $groupJid): array;
// @return array<int, array{phone:string, lid:?string, name:?string, is_admin:bool}>

public function sendGroupText(array $connection, string $groupJid, string $text, array $mentions = []): array;
// $mentions: dígitos ou JIDs; cada provider converte para o formato nativo.
// Suporta 'all' para @todos.
```

### Providers
- `UazapiProvider`: reusar `fetchGroupParticipants()` atual, estendendo o
  retorno para incluir `name` e `is_admin` quando disponíveis no
  `/group/info`; `sendGroupText` passa `mentions` no `/send/text`.
- `WahaProvider`: implementar `fetchGroupParticipants()` via
  `GET /api/{session}/groups/{groupJid}` (lista `participants`);
  `sendGroupText` passa `mentions` no `/api/sendText`
  (`["xxx@c.us"]` ou `["all"]`, com `@numero` no texto).

### Service + Controller + View
- `WhatsAppService::sendGroupMessage(int $groupId, string $text, array $mentions = [])`:
  valida, monta `@telefone` no texto quando necessário, chama provider,
  espelha outbound igual hoje.
- `WhatsAppGroupController::members(int $id)`: chama fetch na hora, resolve
  LID→telefone via `WhatsAppLidMap::resolve()` + `resolvePhone()`, retorna
  JSON `{members:[...], fetched_at}`. Sem persistir.
- `WhatsAppGroupController::send()`: aceita `mentions[]` do form.
- `app/Views/whatsapp/group_show.php`: seção "Participantes" com botão
  atualizar + checkboxes/autocomplete `@`. Erro → "lista indisponível".
- Rate-limit: só busca ao abrir a seção/clicar. Sem cache, sem polling.

## 5. Design — Contato vCard no 1:1

### Interface
```php
public function sendContact(array $connection, string $to, array $contact): array;
// $contact: ['name'=>string, 'phone'=>string(dígitos), 'organization'=>?string]
// WAHA: POST /api/sendContactVcard {session, chatId, contacts:[{vcard}]}
// Uazapi: POST /send/contact {number, ...}
// vCard gerado localmente: BEGIN:VCARD / VERSION:3.0 / FN / TEL / END:VCARD
```

### Outbound (inbox 1:1)
- `ConversationService::sendMessage(type='contact', content=json{name,phone,organization?})`.
- `InboxController::sendMessage`: aceita `contact_id` (contato da base
  `contacts`); cria mensagem `contact` + roteia via novo
  `dispatchWhatsApp` → `provider->sendContact()`.
- UI `app/Views/inbox/show.php`: botão "anexar contato" → modal busca
  contato existente → envia. Renderiza cartão clicável (`tel:` + `wa.me`).
- Uma mensagem = um contato. Fora de escopo: múltiplos contatos por
  mensagem, edição manual de vCard.

### Inbound
- `UazapiProvider::parseWebhook` e `WahaProvider::parseWebhook` reconhecem
  `contactMessage`/`contactVcard` → `IncomingMessage type='contact'`
  com `content=json{name,phone,vcard?}`.
- `WhatsAppService::handleWebhook` salva `messages.type='contact'`,
  preview "📇 Contato: nome". Clique abre detalhe / oferece salvar.

### Migração
- Verificar ENUM/coluna `messages.type`: se restritivo, `ALTER` para incluir
  `'contact'`. Caso contrário, nenhuma migração obrigatória.

## 6. Fluxos

### Ver membros + mencionar
1. Atendente abre `whatsapp/groups/{id}` → seção Participantes → JS chama
   `GET whatsapp/groups/{id}/members`.
2. Backend chama `fetchGroupParticipants` no provedor, resolve LIDs, retorna.
3. Atendente marca 1..n membros → textarea insere `@telefone` → submit com
   `mentions[]`.
4. `sendGroupMessage` envia com `mentions`, espelha outbound na conversa.

### Enviar contato 1:1
1. Atendente no inbox → "anexar contato" → escolhe contato da base.
2. POST `inbox/{id}/send` com `contact_id` → cria `messages.type='contact'`
   → `sendContact` no provedor.
3. Sucesso: atualiza `channel_message_id`; falha: marca erro e mostra toast.

### Receber contato
1. Webhook com vCard → parse → `type='contact'` → salva mensagem.
2. Inbox renderiza cartão; notificação usa preview texto.

## 7. Erros

- Membros: timeout/401/grupo não encontrado → JSON 502 com
  `error='lista indisponível'`; UI mantém envio normal. Log `GROUP_MEMBERS_ERROR`.
- Menção: provedor que não suporta `mentions` → envia só texto (fallback).
- Contato: telefone inválido → 422 antes de chamar provedor; erro de
  provedor → não cria mensagem ou marca `failed`, toast com motivo.
  Log `CONTACT_SEND_ERROR` / `CONTACT_PARSE_MISS`.

## 8. Testes

- Estender `scripts/tests/smoke_group_mention.php`: fetch mock + `send`
  com `mentions` (verifica `mentionedJID`/`mentions` no payload).
- Novo `scripts/tests/smoke_contact_send.php`: vCard gerado, `sendContact`
  WAHA/Uazapi (mock HTTP), parse inbound de `contactMessage`, render `type='contact'`.
- Manual: grupo real (Uazapi + WAHA) — ver membros, enviar com 1 menção e
  `@todos`; inbox 1:1 — enviar contato da base, receber contato no celular.

## 9. Arquivos tocados (estimativa)

- `app/Services/WhatsApp/WhatsAppProviderInterface.php` (+2 métodos)
- `app/Services/WhatsApp/WahaProvider.php` (fetch + mentions + sendContact)
- `app/Services/WhatsApp/UazapiProvider.php` (mentions + sendContact, reusar fetch)
- `app/Services/WhatsAppService.php` (`sendGroupMessage` + `sendContactMessage`)
- `app/Services/ConversationService.php` (aceitar `type='contact'`)
- `app/Controllers/WhatsAppGroupController.php` (`members()` + `send()` com mentions)
- `app/Controllers/InboxController.php` (`sendMessage` com `contact_id`)
- `app/Views/whatsapp/group_show.php` (participantes + menções)
- `app/Views/inbox/show.php` (botão + modal + cartão contato)
- `routes/*` (GET members)
- `scripts/tests/smoke_*` (testes)

## 10. Self-review da spec

- [x] Sem TBD/TODO: endpoints WAHA/Uazapi verificados via docs/busca.
- [x] Consistência: mantém "só alertas", tempo-real, 1:1 — sem contradição.
- [x] Escopo: uma spec, sem tabela nova, sem cron. B fica como evolução futura.
- [x] Sem ambiguidade: um contato por mensagem; `mentions` como dígitos;
  fallback sem mentions definido; migração só se ENUM restritivo.
