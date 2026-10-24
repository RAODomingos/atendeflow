# Provedor de WhatsApp self-hosted (whatsapp-web.js / zapo-js) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Avaliar e implantar, dentro do AtendeFlow, um provedor de WhatsApp **self-hosted** (roda no próprio servidor, sem depender de API de terceiro) que cubra **100% das funções que hoje funcionam no UAZAPI** — sem regressão em envio, mídia, grupos, menções, LID, edição/apagamento, reações, vCard e webhook.

**Architecture:** O app PHP fala HTTP com o provedor através de `WhatsAppProviderInterface` (18 métodos). Nenhuma das duas bibliotecas candidatas é um servidor HTTP — são **bibliotecas Node**. Portanto a implantação é: (1) um **sidecar Node** ("bridge") que hospeda as sessões e expõe uma API REST + encaminha eventos para o webhook do app; (2) uma **classe PHP nova** (`WwebjsProvider` / `ZapoProvider`) falando com essa bridge; (3) o **UAZAPI segue como padrão de produção** durante toda a implantação — o provedor novo só entra no seletor depois de passar na matriz de paridade.

**Tech Stack:** PHP 8.1+ (MVC próprio, sem framework), MySQL 8, views server-rendered. Sidecar em Node.js: `whatsapp-web.js@1.34.7` (Node >= 18, Puppeteer + ffmpeg) **ou** `zapo-js@1.10.0` (Node >= 20.9, protocolo direto, sem browser). Nenhuma dependência nova em `composer.json`.

**Spec:** este documento (a matriz de paridade é a spec).

---

## Global Constraints

- **UAZAPI é o provedor de produção e continua sendo.** Nada muda para o cliente em produção até a Fase 3 (ver checklist de aceite).
- **Nenhum provedor novo entra no seletor antes de passar na matriz de paridade.** Provedor novo nasce em `hidden_providers` e só sai de lá com a matriz inteira verde.
- **Idempotência:** toda migration e todo script de verificação precisam ser idempotentes — rodar duas vezes não muda nada.
- **O runner de migrations** (`database/migrate.php`) tolera 1050/1060/1061/1062/1091/1826. Nomes: `database/migrations/YYYY-MM-DD_descricao.sql`.
- **O harness de teste** é `tests/run.php` (`check(string $name, bool $cond): void`, sem banco) e `scripts/tests/smoke_*.php` (exigem API/banco).
- **Toda função nova precisa de teste que falhou antes.** Testes de fiação no padrão `str_contains($src, '...')`.
- **Contrato do webhook é existente e não pode quebrar** (ver "Contrato atual" abaixo): `provider` no payload resolve o provedor; `providerId` resolve a conexão.
- **Segredo do webhook:** `?secret=` (legado) ou header `X-Webhook-Signature` (HMAC-SHA256 do corpo). A bridge precisa assinar.
- **PT-BR** em toda a interface e nas mensagens.
- **Nomes:** bridge em `services/wa-bridge/` (Node, repositório próprio ou submodule — ver Fase 1, Task 1.0), provider PHP em `app/Services/WhatsApp/WwebjsProvider.php` / `ZapoProvider.php`, chave de config `wwebjs` / `zapo`.
- **Não tocar** em `app/Controllers/InboxController.php`, `app/Core/Session.php`, `app/Models/CannedResponse.php`, `app/Models/Macro.php` — têm alterações de outra pessoa no working tree.

---

## 0. Estado verificado hoje (fatos, 2026-10-24)

Levantamento feito no código e no banco local (`atendeflow`):

| Item | Valor encontrado |
|---|---|
| Registry de provedores | `waha => WahaProvider`, `uazapi => UazapiProvider` (`app/Services/WhatsApp/WhatsAppManager.php:20`) |
| Conexões no banco | **1** — `provider=uazapi`, `status=connected`. **Nenhuma conexão WAHA.** |
| `env/.env` | `WHATSAPP_PROVIDER=uazapi`, `UAZAPI_BASE_URL`, `UAZAPI_ADMIN_TOKEN` preenchidos |
| `env/.env.example` | ainda vinha com `WHATSAPP_PROVIDER=waha` + bloco WAHA (corrigido nesta branch) |
| Seletor da UI | `app/Views/settings/channels.php:219` — **100% data-driven** (`$whatsappProviders`), então a lista vem de `WhatsAppManager::availableProviders()` |
| Default do banco | `whatsapp_connections.provider DEFAULT 'waha'` (corrigido nesta branch) |
| Testes | `php tests/run.php` → **171 passed, 0 failed** |

### Contrato atual que a bridge precisa respeitar

1. `WhatsAppService::resolveProviderFromPayload()` (`app/Services/WhatsAppService.php:2079`) lê `payload['provider']` **primeiro** → se a bridge mandar `provider: "wwebjs"`, o roteamento funciona sem tocar no controller.
2. `provider->parseWebhook($payload)` devolve `IncomingMessage` com `providerId`.
3. `WhatsAppConnection::findByProviderId($provider, $providerId)` (`app/Models/WhatsAppConnection.php:17`) casa por **`instance_name` OU `instance_id`**. → o `providerId` da bridge **tem que ser** o mesmo identificador usado no REST da bridge.
4. `validateWebhookSecret()` aceita `?secret=` ou HMAC-SHA256 no header `X-Webhook-Signature`.
5. `resolvePhone($connection, '@lid...')` é chamado quando o remetente é LID → precisa existir no provider novo.

### Os dois links do pedido são o mesmo projeto

`https://docs.wwebjs.dev/index.html` e `https://wwebjs.dev/` são **o mesmo projeto**: **whatsapp-web.js**, na versão **1.34.7** (docs geradas em 24/04/2026). O segundo link do pedido é o **zapo** (`https://zapo.to/pt-br/introduction`), que é outro projeto — implementação TypeScript do protocolo do WhatsApp Web.

---

## 1. Correção já aplicada nesta branch (WAHA fora da lista)

Objetivo pedido: *"na lista aparece o WAHA também, pode retirar"*.

**Decisão:** WAHA sai da **lista de seleção**, mas a classe `WahaProvider` **continua registrada** no `WhatsAppManager`. Motivo: `forConnection()` dá `RuntimeException("Provedor de WhatsApp não registrado")` para conexão cujo `provider` não está no registry — remover a classe quebraria qualquer ambiente que ainda tenha conexão WAHA (aqui não há nenhuma, mas dev/prod podem ter). O bloco de config do WAHA também fica, pelo mesmo motivo. Custo: zero. Benefício: rollback trivial.

| Arquivo | Mudança |
|---|---|
| `config/integrations.php` | `'provider' => env('WHATSAPP_PROVIDER', 'uazapi')`; nova chave `'hidden_providers' => ['waha']`; bloco `waha` mantido e comentado como legado |
| `app/Services/WhatsApp/WhatsAppManager.php` | `availableProviders()` subtrai `hidden_providers`; `defaultProviderName()` cai para `'uazapi'`; registry注释 de compatibilidade |
| `database/migrations/2026_10_24_whatsapp_provider_default_uazapi.sql` | `ALTER TABLE whatsapp_connections MODIFY COLUMN provider VARCHAR(50) NOT NULL DEFAULT 'uazapi'` |
| `database/schema.sql` | default da coluna `provider` → `'uazapi'` (fresh install) |
| `env/.env.example` | bloco UAZAPI como principal; WAHA marcado como legado |

**Verificação feita:** `availableProviders()` → `["uazapi"]`; `defaultProviderName()` → `"uazapi"`; `provider('waha')` e `provider('uazapi')` continuam instanciando (legado não quebra); migration aplicada e registrada em `schema_migrations`; `php tests/run.php` → 171/171.

**Fora do escopo desta branch:** apagar `WahaProvider.php`. Fica como limpeza futura (tarefa separada), depois que ninguém mais usar WAHA.

---

## 2. As duas opções, o que elas são

### 2.1 whatsapp-web.js 1.34.7

- Biblioteca Node que **automatiza o WhatsApp Web via Puppeteer/Chrome** (abre o navegador e clica). Apache-2.0, Node >= 18.
- Dependências: `puppeteer@24.38`, `node-webpmux`, `fluent-ffmpeg` (precisa de **ffmpeg** no host), `mime`.
- Sessão persistente via `LocalAuth` (diretório de user-data do Chrome).
- É a mesma base do WAHA (que expõe `engine: WEBJS`).

### 2.2 zapo-js 1.10.0

- Biblioteca TypeScript que implementa **o protocolo do WhatsApp Web direto** (sem browser), da mesma família do Baileys/Whatsmeow. MIT, Node >= 20.9.
- `npm i zapo-js @zapo-js/store-sqlite better-sqlite3 pino` (store é peer opcional: SQLite, Postgres, MySQL, Redis, MongoDB).
- API "coordinator-first": `client.message`, `client.group`, `client.privacy`, `client.newsletter`; cada operação escopada por `sessionId`.
- Eventos: `auth_qr`, `auth_paired`, `connection`, `message`.
- **Não reconecta sozinho** — o guia `guides/reconnection` mostra o padrão que o app precisa implementar.
- Projeto **novo** (1.0 em jun/2026, já em 1.10.0 em out/2026, releases via changesets).

### 2.3 O ponto crítico: nenhuma das duas é servidor HTTP

As duas são **bibliotecas**, não APIs REST. O app PHP hoje faz `POST https://free.uazapi.com/send/text`. Com as duas candidatas, o PHP precisa de um **bridge HTTP** no meio. Esse bridge é **comum às duas** — é a peça que permite trocar de motor depois sem mexer no PHP.

---

## 3. Matriz de paridade (18 pontos do `WhatsAppProviderInterface`)

> Matriz levantada em 2026-10-24 **contra o código-fonte das duas libs** (clone shallow + `index.d.ts`), não só contra a documentação. Onde a doc e o código divergem, vale o código.

Status: **OK** = suporta nativamente · **RESSALVA** = suporta com adaptação · **NÃO** = não suporta (precisa fallback).

| # | Método da interface | whatsapp-web.js 1.34.7 | zapo-js 1.10.0 | Decisão de projeto |
|---|---|---|---|---|
| 1 | `initConnection` | sessão local por `clientId` (`LocalAuth`) | sessão por `sessionId` no store | Bridge cria a sessão e devolve `provider_id` = `instance_name` |
| 2 | `connect` (QR) | `client.on('qr')`; `client.requestPairingCode(phone, showNotification, intervalMs)` → evento `code` | `client.on('auth_qr', {qr, ttlMs})`; `client.auth.requestPairingCode(phone, showNotif?, customCode?)` → `auth_pairing_code` | Bridge expõe `GET /sessions/{id}/qr` (data-URL) |
| 3 | `getStatus` | `client.getState()` (`CONNECTED`/`PAIRING`/`CONFLICT`/`UNPAIRED`…); número em `client.info.wid._serialized` | `client.auth.getState()` → `{connected, registered, hasQr, hasPairingCode}`; evento `connection` `{status:'open'\|'close', reason, isNewLogin, isLogout}`; número em `credentials.meJid`/`meLid` | Bridge normaliza para `connected/waiting_qr/error` |
| 4 | `disconnect` | `client.logout()` / `client.destroy()` | `disconnect()` (mantém credenciais) / `logout()` (desvincula) | Bridge `POST /sessions/{id}/disconnect` |
| 5 | `deleteConnection` | `client.logout()` + apagar `LocalAuth` | `logout()` + apagar sessão do store | Bridge `DELETE /sessions/{id}` |
| 6 | `setWebhook` | ❌ não existe (é event emitter) | ❌ não existe (é event emitter) | **Bridge** assina e encaminha (HMAC). Nenhum dos dois "registra webhook" — o app já guarda a URL no banco. |
| 7 | `send` (texto/mídia) | `chat.sendMessage(content, options)`; mídia por `MessageMedia.fromFilePath()` / `.fromUrl()` — **não existe `fromBuffer` em 1.34.7** | `client.message.send(to, {type:'image'\|'video'\|'audio'\|'document', media: Uint8Array\|ArrayBuffer\|Readable\|string, mimetype?, caption?, fileName?})` — **sem `mimetype` e sem `@zapo-js/media-utils` lança erro** | Bridge grava o arquivo num path e envia; wwebjs → `fromFilePath`, zapo → `Uint8Array` + `mimetype` |
| 8 | `parseWebhook` | evento → payload | evento → payload | **Bridge** normaliza para um envelope único (§6.1) |
| 9 | `downloadMedia` | `message.downloadMedia()` → `MessageMedia` (Buffer base64) | `client.message.download()` / `.downloadToFile()` / `.downloadBytes()` | Bridge baixa e serve em `/media/{id}` |
| 10 | `editMessage` | `message.edit(content, options)` | `client.message.send(jid, novoTexto, { editKey: event })` — **exige que a original seja `fromMe`** | Bridge guarda o `editKey`/evento por `messageId` para editar depois |
| 11 | `deleteMessage` | `message.delete(true)` (for everyone) | `client.message.send(jid, { type:'revoke', target: event })` — distingue admin vs próprio por `target.fromMe` | Bridge guarda o `target` por `messageId` |
| 12 | `resolvePhone` (LID→telefone) | ✅ `client.getContactLidAndPhone(userIds)` → `{lid, pn}[]` | ⚠️ **não existe API LID→PN**; só o inverso `client.profile.getLidsByPhoneNumbers()`; LID→PN sai do evento via `key.participantAlt` / `key.remoteJidAlt` | Bridge (zapo) **mapeia `remoteJid`↔`remoteJidAlt`** no envelope; o app já aprende o mapa (`learnLidMappings`). wwebjs resolve direto. |
| 13 | `sendReaction` | `message.react(emoji)` / `client.sendReaction(id, emoji)` — **remover reação não confirmado** | `client.message.send(jid, { type:'reaction', emoji, target: event })`; remover = `emoji: ''` | Bridge expõe `POST .../react` com `emoji` vazio = remover |
| 14 | `sendGroupText` (menções) | `options.mentions[]` + `options.groupMentions[]` (usa `@c.us`) | `options.mentions` (Pn ou LID) | Bridge preserva `@g.us` e digítos |
| 15 | `fetchGroupParticipants` | `groupChat.participants` → `{id, isAdmin, isSuperAdmin}` | `client.group.queryGroupMetadata(gid)` → `{subject, owner, participants[], flags}` | ⚠️ **ambas não garantem `name`** → cruzar com contatos (`getContactById` / store `contacts`) para montar `{phone, lid, name, is_admin}` |
| 16 | `sendContact` (vCard) | `options.parseVCards: true` ou `chat.sendMessage(new Contact(...))` | raw proto: `{ contactMessage: { displayName, vcard } }` — **sem builder tipado**; incluir `waid=<dígitos>` no `TEL` | Bridge monta o vCard (o app já tem `IncomingMessage::buildVcard`) |
| 17 | `getProfilePicture` | `client.getProfilePicUrl(contactId)` → `string\|null` | `client.profile.getProfilePicture(jid, 'preview'\|'image')` → pode vir **sem `url`** (só `directPath`/`id`) | Bridge normaliza: sem `url` → tenta re-download; senão `null` (o app aceita null) |
| 18 | `sendSeen` / digitando | `client.sendSeen(chatId)` / `chat.sendSeen()`; `chat.sendStateTyping()` (25 s), `chat.clearState()` (parar) | `client.message.sendReceipt(chatJid, [ids], { type:'read' })`; `client.presence.sendChatstate(jid, {state:'composing'\|'recording'\|'paused'})` | Bridge normaliza; `paused`/`clearState` = parar de digitar |
| — | Botões e listas interativas | ❌ depreciado — `Client.sendMessage` emite `console.warn` e **não envia** | ⚠️ raw proto `buttonsMessage` / `listMessage` / `interactiveMessage`, **sem builder tipado** | **Sem regressão:** `UazapiProvider` não implementa `sendButton`/`sendList` (só o `WahaProvider` tem) e o `WhatsAppService` já degrada para texto. Nenhum botão/lista funciona hoje no UAZAPI. |
| — | Servidor HTTP/REST pronto | ❌ não existe no pacote | ❌ não existe no pacote (só `@zapo-js/mcp-server` dev-only e `@zapo-js/fake-server` p/ teste) | Bridge é obrigatória nos dois casos |

**Leitura da matriz:** as duas cobrem os 18 pontos. Os gaps são **conhecidos, pequenos e contornáveis**:
- zapo sem API LID→telefone → a bridge mapeia `remoteJid`↔`remoteJidAlt` (o app já tem `learnLidMappings` para isso).
- `name` do participante de grupo não garantido em nenhuma das duas → cruzar com a agenda de contatos.
- foto de perfil no zapo pode não vir com `url` → normalizar para `null`.
- remoção de reação no wwebjs não confirmada → testar na Fase 0.

**Por que os botões/listas não são regressão:** `UazapiProvider` **não implementa** `sendButton`/`sendList` (só o `WahaProvider` tem) e o `WhatsAppService` já degrada para texto quando o método não existe. Nenhum botão/lista funciona hoje no UAZAPI.

---

## 4. Riscos

| Risco | whatsapp-web.js 1.34.7 | zapo-js 1.10.0 | Mitigação |
|---|---|---|---|
| Memória/RAM | **1 Chromium por sessão** (~300–500 MB) | Processo Node por sessão, store compartilhado | Dimensionar por nº de canais; `zapo` escala muito melhor |
| Vídeo | ⚠️ o Chromium embarcado **não tem AAC/H.264** → exige **Chrome estável separado** no host | ffmpeg só com `@zapo-js/media-utils` | Instalar Chrome estável no container do wwebjs |
| Instabilidade do WhatsApp | Quebra quando o WhatsApp Web muda o DOM/internals (`window.require`) | Quebra quando o WhatsApp muda o protocolo (`failure_client_too_old` → `recoverFromClientTooOld`) | Alerta + reconexão + **fallback para UAZAPI**; fixar versão do motor |
| Ban da conta | Automação de browser = fingerprint mais detectável | Protocolo direto = menos fingerprint, mas同样 uso não oficial | Não colocar 100% do tráfego em self-hosted; UAZAPI como contingência |
| Complexidade | Chrome + ffmpeg + libs de sistema + `--no-sandbox` ⇒ **container Linux praticamente obrigatório** | Só Node; roda em Linux/Windows/macOS | `zapo` é bem mais simples de operar |
| Maturidade | 1.34.7 (24/04/2026), comunidade grande, anos de uso | 1.10.0 (07/10/2026), criado em 17/03/2026, ~7 meses, 1 autor | `wwebjs` é mais battle-tested; `zapo` compensa com release diário e docs **pt-br** |
| Reconexão | Automática | **Não** — precisa implementar (há guia `reconnection`) | Bridge implementa backoff + `connection` event p/ os dois |
| Testes sem conta real | Não tem fake-server oficial | ✅ `@zapo-js/fake-server` (E2E sem tocar conta real) | Usar no CI da bridge |
| Sessão persistente | `LocalAuth` = filesystem (incompatível com FS efêmero) | Store durável: SQLite (1 host) ou **MySQL/Postgres/Redis** (multi-processo) | `zapo` com store MySQL reaproveita o banco que o app já tem |

---

## 5. Recomendação

**A ordem correta é: bridge primeiro (agnóstico de motor), depois o motor. E o motor primário é o `zapo-js`, com o `whatsapp-web.js` como segundo motor.**

1. **Bridge primeiro** — é a peça obrigatória nos dois casos (nenhuma das duas libs é servidor HTTP). Escrever o contrato REST + o envelope de webhook **antes** de escolher o motor evita refazer o PHP quando trocar de lib. A bridge abstrai `SessionManager`, `Sender`, `MediaStore` e `EventForwarder` atrás de uma interface — o motor vira um `require` dinâmico.
2. **`zapo-js` como motor primário** — sem browser (memória e complexidade menores), multi-sessão no mesmo processo por `sessionId`, store MySQL que o app **já tem**, releases ativos (último commit 23/10/2026), docs em **pt-br**, `@zapo-js/fake-server` para E2E sem conta real e `recoverFromClientTooOld` para o caso de o WhatsApp Older. O gap do LID→telefone é resolvido na bridge pelo mapeamento `remoteJid`↔`remoteJidAlt`.
3. **`whatsapp-web.js` como segundo motor** — entra se/sem o zapo falhar num requisito, ou se o time já tiver Chrome+ffmpeg provisionados. Vantagem exclusiva: `getContactLidAndPhone()` resolve LID→telefone **sem depender do payload**; `MessageMedia.fromUrl` aceita URL remota direto (útil quando a mídia é pública).
4. **UAZAPI permanece como padrão de produção e rota de contingência.** Nenhum canal migra sem passar pela matriz de paridade da Task 3.2.

**O que não recomendo:** trocar o UAZAPI por self-hosted como padrão agora. O ganho (independência de terceiro, custo por mensagem) é real; o custo (operação, ban, manutenção a cada mudança do WhatsApp) é do tipo que precisa de alguém responsável. Por isso: fases, com UAZAPI sempre no ar como saída.

**Se a decisão for "só um":** **zapo-js** — desde que o servidor aceite Node 20+ e você aceite operar um processo Node. Só escolha `whatsapp-web.js` se o servidor já tiver Chrome estável + ffmpeg e você quiser a API LID→telefone mais direta.

---

## 6. Arquitetura proposta

```
                    ┌──────────────────────── AtendeFlow (PHP) ────────────────────────┐
   Admin (UI) ───► │ SettingsController → WhatsAppService → WhatsAppManager::provider() │
                    │                                       ├─ UazapiProvider ────────┼──► https://free.uazapi.com
                    │                                       ├─ WwebjsProvider ────────┼──► http://wa-bridge:8080
                    │                                       └─ ZapoProvider ──────────┼──► http://wa-bridge:8080
                    │                                                                     │
   POST /webhooks/whatsapp ◄── (bridge assina HMAC e encaminha) ◄──┴─ wa-bridge (Node)
                    └─────────────────────────────────────────────────────────────────────┘
```

### 6.1 A bridge (`services/wa-bridge/`)

- Node.js (>= 20 para suportar os dois motores), sem framework web pesado — `fastify` ou `express` + `pino` (o zapo já usa `pino`).
- **Uma sessão por canal**, identificada pelo `instance_name` do AtendeFlow (mesmo valor gravado em `whatsapp_connections.instance_name` → casa com `findByProviderId`).
- **Motor plugável**: `WA_BRIDGE_ENGINE=wwebjs|zapo` — o resto da bridge é idêntico. Isso é o que permite os dois conviverem.
- **Estado**: `wwebjs` → diretório de sessão local (volume); `zapo` → store MySQL (já existe no projeto) ou SQLite por sessão.
- **Endpoint REST** (contrato estável, o PHP só conhece este):

| Método | Rota | Body / resposta |
|---|---|---|
| POST | `/sessions` | `{ instance_name }` → `{ provider_id, status }` |
| GET | `/sessions/{id}/qr` | → `{ qr_code: "data:image/png;base64,...", status }` |
| GET | `/sessions/{id}/status` | → `{ status, phone_number, qr_code }` |
| POST | `/sessions/{id}/disconnect` | → `{ ok }` |
| DELETE | `/sessions/{id}` | → `{ ok }` |
| POST | `/sessions/{id}/send` | `{ to, type, content, options }` → `{ provider_message_id, raw }` |
| POST | `/sessions/{id}/messages/{mid}/edit` | `{ text }` → `{ ok }` |
| POST | `/sessions/{id}/messages/{mid}/delete` | → `{ ok }` |
| POST | `/sessions/{id}/messages/{mid}/react` | `{ reaction }` → `{ ok }` |
| POST | `/sessions/{id}/messages/{mid}/seen` | → `{ ok }` |
| GET | `/sessions/{id}/groups/{jid}/participants` | → `[{ phone, lid, name, is_admin }]` |
| GET | `/sessions/{id}/contacts/{id}/avatar` | → URL da foto |
| GET | `/sessions/{id}/media/{mid}` | → arquivo (a bridge baixa da origem e serve) |
| GET | `/health` | → `{ ok, engines }` |

- **Autenticação** entre PHP e bridge: header `X-Bridge-Token` (valor em `env/.env`, o mesmo lugar do `UAZAPI_ADMIN_TOKEN`).
- **Webhook de saída**: a bridge `POST` em `{APP_URL}/webhooks/whatsapp?secret={webhook_secret}` com **envelope único**:

```json
{
  "provider": "wwebjs",
  "event": "message",
  "session": "canal-vendas",
  "message": {
    "id": "...",
    "from": "5511999999999@c.us",
    "from_me": false,
    "chat_id": "5511999999999@c.us",
    "is_group": false,
    "type": "text",
    "body": "olá",
    "timestamp": 1761290000,
    "sender_name": "Maria",
    "media": { "url": "http://wa-bridge:8080/sessions/canal-vendas/media/...", "mime": "image/jpeg", "filename": "foto.jpg" }
  }
}
```

  - `provider` faz o roteamento existir hoje (`resolveProviderFromPayload`).
  - `session` vira o `providerId` do `IncomingMessage` → casa com `instance_name`.
  - Eventos: `message`, `message_edit`, `message_revoke`, `message_reaction`, `connection`, `qrcode`.
  - A bridge assina o corpo com HMAC-SHA256 no header `X-Webhook-Signature` (o app já aceita).
  - **No zapo, a bridge inclui `lid`**: `key.remoteJid` e `key.remoteJidAlt` (e `participant`/`participantAlt`) são mapeados para campos separados — é assim que o app aprende o par LID↔telefone (`learnLidMappings`) já que o zapo não expõe API LID→PN.
  - **No zapo, `key.fromMe` precisa de filtro**: evento de sync multi-device chega como mensagem — a bridge descarta `fromMe`/`senderDevice` conforme a regra que o app já aplica (`parseWebhook` do UAZAPI já filtra `fromMe`/`wasSentByApi`).

### 6.2 O provider PHP

- `app/Services/WhatsApp/WwebjsProvider.php` e `app/Services/WhatsApp/ZapoProvider.php` implementam `WhatsAppProviderInterface` **apenas** falando HTTP com a bridge (mesmo estilo do `WhatsAppHttpClient` que já existe).
- `parseWebhook()` traduz o envelope da bridge → `IncomingMessage` (text/media/contact).
- `resolvePhone()` pergunta à bridge (que usa `getContactLidAndPhone` no wwebjs; no zapo devolve o par já aprendido no envelope).
- `send()` com `options.path` → lê o arquivo local e manda o conteúdo (o servidor do provedor não alcança a URL pública do app — mesmo cuidado que o UAZAPI já tem). No wwebjs a bridge grava o arquivo em disco e usa `MessageMedia.fromFilePath()`; no zapo manda `Uint8Array` + `mimetype` (obrigatório).
- Registro: `'wwebjs' => WwebjsProvider::class` / `'zapo' => ZapoProvider::class` + bloco em `config/integrations.php` (`base_url` + `bridge_token`).
- **Nasce em `hidden_providers`** — só aparece no seletor depois da Fase 3.

---

## 7. Fases de implantação

### Fase 0 — Spike (1 a 2 dias, sem código de produção)

- [ ] **Task 0.1** — `zapo-js` primeiro (motor primário): number de teste descartável + store SQLite; sessão, QR, pareamento por código, texto, mídia (`mimetype` obrigatório!), PTT, citação, grupo com menção, reação (e remover), **editar**, **revogar**, vCard raw proto, foto de perfil (com e sem `url`), participantes de grupo, LID via `remoteJidAlt`.
- [ ] **Task 0.2** — `whatsapp-web.js` na mesma bateria (QR, `requestPairingCode`, mídia via `fromFilePath`/`fromUrl`, `sendAudioAsVoice`, citação, menções, reação, `message.edit`, `message.delete(true)`, vCard com `parseVCards`, `getContactLidAndPhone`, `getProfilePicUrl`, participantes).
- [ ] **Task 0.3** — Medir RAM por sessão e tempo de `connect` de cada motor; confirmar o comportamento de vídeo (wwebjs exige Chrome estável; zapo exige `@zapo-js/media-utils`).
- [ ] **Task 0.4** — Montar o envelope da §6.1 com o evento real de cada lib (isso é o que fixa o contrato do webhook).
- [ ] **Task 0.5** — **Go/No-Go** com o dono. Saída: matriz real, RAM por sessão e a lista de gaps aceitos.

### Fase 1 — Fundação da bridge (sem tocar no PHP do app)

- [ ] **Task 1.0** — Decidir onde a bridge vive: repo próprio (`wa-bridge`) ou `services/wa-bridge/` neste repo. Se neste repo: `package.json` isolado, `node_modules` fora do versionamento, e **não** misturar com o `package-lock.json` da raiz.
- [ ] **Task 1.1** — `services/wa-bridge/package.json`, `Dockerfile` (node:20-alpine; **+ffmpeg** e **Chrome estável** só quando o motor `wwebjs` estiver ativo), `docker-compose` de desenvolvimento.
- [ ] **Task 1.2** — `SessionManager`: registry `instance_name → sessão`, com a **mesma interface** para os dois motores (`zapo` por `sessionId` + store; `wwebjs` por `clientId` + `LocalAuth`).
- [ ] **Task 1.3** — Endpoints da §6.1 + auth `X-Bridge-Token`.
- [ ] **Task 1.4** — Emissor de webhook: HMAC-SHA256 + retry exponencial (3 tentativas) + fila local (não perder evento se o PHP estiver fora).
- [ ] **Task 1.5** — `GET /health` com estado por sessão + versão do motor.
- [ ] **Task 1.6** — Testes da bridge (`node --test`): QR, send texto, send mídia, webhook assinado, reconexão (principalmente no `zapo`, que não reconecta sozinho) e E2E com `@zapo-js/fake-server` (sem conta real).

### Fase 2 — Provider PHP (a bridge ainda não entra no seletor)

- [ ] **Task 2.1** — `WwebjsProvider` implementando a interface; `config/integrations.php` com `wwebjs` em `hidden_providers`.
- [ ] **Task 2.2** — `ZapoProvider` idem, com `zapo` em `hidden_providers`.
- [ ] **Task 2.3** — `parseWebhook` dos dois → `IncomingMessage` (text/media/contact) + `provider`/`session` do envelope.
- [ ] **Task 2.4** — `resolvePhone`, `fetchGroupParticipants`, `getProfilePicture`, `sendReaction`, `editMessage`, `deleteMessage`, `downloadMedia`, `sendContact`.
- [ ] **Task 2.5** — Testes de fiação em `tests/run.php`: classes existem, implementam a interface, `hidden_providers` contém os novos, `availableProviders()` **não** os expõe.
- [ ] **Task 2.6** — `scripts/tests/smoke_wa_bridge.php`: sobe a bridge local, manda texto/mídia real, confere o webhook chegando no PHP e a conversa criada.

### Fase 3 — Canal de teste ponta a ponta (sem tocar no cliente)

- [ ] **Task 3.1** — Canal de teste no painel usando o provedor novo (temporariamente fora de `hidden_providers` via env `WA_PROVIDER_VISIBLE=1`).
- [ ] **Task 3.2** — **Matriz de paridade executada de verdade**, comparando UAZAPI × novo na mesma conta de teste:

| # | Cenário | Igual ao UAZAPI? |
|---|---|---|
| 1 | Texto | ☐ |
| 2 | Imagem (legenda) | ☐ |
| 3 | Áudio (PTT) | ☐ |
| 4 | Vídeo | ☐ |
| 5 | Documento | ☐ |
| 6 | Responder/citar | ☐ |
| 7 | Grupo: enviar com menção | ☐ |
| 8 | Grupo: participantes (admin) | ☐ |
| 9 | Editar mensagem | ☐ |
| 10 | Apagar p/ todos | ☐ |
| 11 | Reagir | ☐ |
| 12 | Enviar vCard | ☐ |
| 13 | Receber mídia | ☐ |
| 14 | Receber edição/revoke/reação | ☐ |
| 15 | LID → telefone | ☐ |
| 16 | Foto de perfil | ☐ |
| 17 | Visto / digitando | ☐ |
| 18 | Reconexão após queda | ☐ |

- [ ] **Task 3.3** — Nenhum item ☐ sem justificativa aceita pelo dono. Item que falhar = **gap declarado** (fallback documentado) ou provider não entra em produção.
- [ ] **Task 3.4** — Só então: tirar o provedor novo de `hidden_providers` (nova migration, não editando config direto).

### Fase 4 — Produção (gradual, reversível)

- [ ] **Task 4.1** — `deploy/`: serviço `systemd`/`supervisor` da bridge, `nginx` só o `/health`, **sem** expor a bridge publicamente.
- [ ] **Task 4.2** — Alerta: sessão desconectada há > N min → notification + e-mail (o app já tem `notificacoes`).
- [ ] **Task 4.3** — Migrar **1 canal** para o provedor novo; monitorar 7 dias (entrega, erro, latência,Reconexão).
- [ ] **Task 4.4** — Migrar mais canais em lotes; UAZAPI vira o padrão só se a taxa de erro do novo for ≤ a do UAZAPI por 30 dias.
- [ ] **Task 4.5** — `FUNCIONALIDADES_SISTEMA.md` e `PRODUCT.md` atualizados com o provedor novo e o status do WAHA (legado).

---

## 8. Rollback

| Nível | Como |
|---|---|
| Provider novo | `WHATSAPP_PROVIDER=uazapi` no `env/.env` + reload. **O canal volta para o UAZAPI sem recriar nada** — o histórico fica no banco (a conversa é da conta, não do provedor). |
| Bridge fora do ar | `docker compose stop wa-bridge` / `systemctl stop wa-bridge`. O app só sente a indisponibilidade nos canais que usam o provedor novo. |
| Código da bridge | Imagem Docker com tag de versão; `docker compose up -d --force-recreate` na versão anterior. |
| Migration | Migrations deste plano só mudam `hidden_providers` (config) e defaults — nenhuma é destrutiva; reverter é editar a config / `MODIFY COLUMN` de volta. |

---

## 9. Critérios de aceite do trabalho (Fase 3 é o que vale)

1. Nenhum cenário da matriz da Task 3.2 quebra de forma **diferente** do UAZAPI (mesma mensagem na tela, mesmo `channel_message_id`, mesma mídia baixada).
2. Nenhum erro novo no `storage/logs/` durante 7 dias no canal migrado.
3. A bridge sobe e desce sem perder sessão (QR não é pedido de novo).
4. O UAZAPI continua funcionando em paralelo durante toda a fase 4.
5. `php tests/run.php` verde; smoke tests novos verdes.

---

## 10. Decisões que dependem do dono (abertas)

1. **Onde a bridge roda:** mesmo servidor do app, ou servidor separado? (com `zapo` como motor, RAM deixa de ser o problema — a decisão vira operação/contabilidade)
2. **Volume de canais:** quantos números vão rodar self-hosted? Decide `zapo` (recomendado) vs `wwebjs` (Chrome por sessão).
3. **Aceita o risco de ToS/ban** de self-hosted em parte do tráfego? (Recomendação: começar por tráfego interno/teste.)
4. **Prazo:** o UAZAPI está com algum problema (custo, instabilidade, limite) que motive urgência? Nenhum foi relatado até agora.
5. **Escopo dos botões/listas interativas:** o WhatsApp depreciou o modelo antigo; se o negócio precisa de menus rico, avaliar `guides/interactive-messages` do zapo (raw proto) — mas **hoje já não funciona no UAZAPI**, então não é regressão.

---

## Apêndice A — APIs exatas (verificadas no código-fonte em 2026-10-24)

### A.1 whatsapp-web.js 1.34.7 (repo `wwebjs/whatsapp-web.js`)

| Operação | API |
|---|---|
| Sessão | `new Client({ authStrategy: new LocalAuth({ clientId, dataPath }) })` (`.wwebjs_auth`) |
| QR / pareamento | `client.on('qr', cb)` · `client.requestPairingCode(phone, showNotification = true, intervalMs = 180000)` → evento `code` · `cancelPairingCode()` |
| Estado / número | `client.getState()` → `WAState` (`CONNECTED`, `PAIRING`, `CONFLICT`, `UNPAIRED`…) · `client.info.wid._serialized` (não existe `getMeInfo()` nesta versão) |
| Enviar | `client.sendMessage(chatId, content, options?)` ou `chat.sendMessage(content, options?)` |
| Mídia | `MessageMedia.fromFilePath(path)` · `MessageMedia.fromUrl(url, options?)` — **`fromBuffer` não existe em 1.34.7**; campos: `mimetype`, `data` (base64), `filename`, `filesize` |
| Opções de envio | `caption`, `sendMediaAsSticker`, `sendMediaAsDocument`, `sendAudioAsVoice`, `sendVideoAsGif`, `isViewOnce`, `sendMediaAsHd`, `mentions[]`, `groupMentions[]`, `quotedMessageId`, `parseVCards`, `sendSeen`, `linkPreview`, `stickerName/Author/Categories`, `waitUntilMsgSent`, `ignoreQuoteErrors`, `extra` |
| vCard | `chat.sendMessage(contact)` com `Contact` (`contactCard`) ou texto com `options.parseVCards = true` |
| Editar | `message.edit(content, options?)` — `MessageEditOptions {linkPreview, mentions, extra}` |
| Revogar | `message.delete(everyone = true, clearMedia = true)` |
| Reagir | `message.react(emoji)` · `client.sendReaction(messageId, emoji)` |
| Participantes | `groupChat.participants` → `Array<{id: ContactId, isAdmin: boolean, isSuperAdmin: boolean}>` (via `client.getChatById(groupJid)`) |
| LID → telefone | `client.getContactLidAndPhone(userIds: string[])` → `{lid, pn}[]` |
| Visto / digitando | `client.sendSeen(chatId)` / `chat.sendSeen()` · `chat.sendStateTyping()` (25 s) · `chat.sendStateRecording()` · `chat.clearState()` |
| Eventos | `message` → `(Message)` · `message_edit` → `(message, newBody, prevBody)` · `message_revoke_everyone` → `(message, revoked_msg?)` · `message_revoke_me` → `(message)` · `message_reaction` → `(reaction: Reaction)` com `{id, orphan, timestamp, reaction, read, msgId, senderId, ack?}` |

### A.2 zapo-js 1.10.0 (docs `zapo.to/pt-br/*.md`, repo `vinikjkkj/zapo`)

| Operação | API |
|---|---|
| Sessão | `new WaClient({ store, sessionId, connectTimeoutMs, nodeQueryTimeoutMs, history }, logger)` · store: `createStore({ backends, providers })` com `@zapo-js/store-{sqlite,postgres,mysql,redis,mongo}` |
| QR / pareamento | `client.on('auth_qr', ({qr, ttlMs}))` · `client.on('auth_pairing_code', ({code}))` · `client.auth.requestPairingCode(phone, showNotif?, customCode?)` |
| Estado / número | `client.auth.getState()` → `{connected, registered, hasQr, hasPairingCode}` · `client.on('connection', ({status:'open'\|'close', reason, isNewLogin, isLogout}))` · `credentials.meJid` / `meLid` |
| Enviar | `client.message.send(to, content, options?)` |
| Mídia | `content = { type:'image'\|'video'\|'audio'\|'document'\|'sticker'\|'sticker-pack'\|'ptv', media: Uint8Array\|ArrayBuffer\|Readable\|string, mimetype?, caption?, fileName?, ptt?, gifPlayback? }` · pre-upload: `client.message.upload(source, options)` — **sem `mimetype` e sem `@zapo-js/media-utils` lança erro** |
| PTT | `{ type:'audio', media, mimetype:'audio/ogg; codecs=opus', ptt: true }` |
| vCard | raw proto `{ contactMessage: { displayName, vcard } }` · múltiplos `{ contactsArrayMessage: { displayName, contacts:[{displayName, vcard}] } }` — **sem builder tipado**; incluir `waid=<dígitos>` no `TEL` |
| Botões / listas | raw proto `{ buttonsMessage }`, `{ listMessage }`, `{ interactiveMessage: { nativeFlowMessage } }` — **sem builder tipado** |
| Editar | `client.message.send(jid, novoTexto, { editKey: event \| event.key \| {id, participant?, timestampMs?} })` — exige original `fromMe` |
| Revogar | `client.message.send(jid, { type:'revoke', target: event })` — admin vs próprio por `target.fromMe` |
| Reagir | `client.message.send(jid, { type:'reaction', emoji:'👍', target: event })`; remover = `emoji: ''` |
| Participantes | `client.group.queryGroupMetadata(groupJid)` → `{subject, owner, participants[], flags}` · `client.group.queryAllGroups()` |
| LID → telefone | ⚠️ **não existe API LID→PN**; só o inverso `client.profile.getLidsByPhoneNumbers(phones)`; LID→PN via `key.participantAlt` / `key.remoteJidAlt` do evento |
| Visto / digitando | `client.message.sendReceipt(event \| chatJid, [ids], { type:'delivered'\|'read'\|'played' })` · `client.presence.sendChatstate(jid, {state:'composing'\|'recording'\|'paused'})` · `client.presence.send('available'\|'unavailable')` |
| Eventos | `message` → `{key:{remoteJid, remoteJidAlt, id, fromMe, participant, participantAlt, isGroup, senderDevice}, message, timestampSeconds, pushName}` · `message_protocol` → edições **e** revogações (discriminar por `protocolMessage.type`) · `message_addon` → reações/votos · `receipt` · `presence` · `chatstate` · `group` · `auth_qr` · `auth_pairing_code` · `auth_paired` · `connection` |

### A.3 Fontes consultadas

- whatsapp-web.js: `https://docs.wwebjs.dev/index.html` · `https://wwebjs.dev/` · `https://raw.githubusercontent.com/wwebjs/whatsapp-web.js/main/index.d.ts` · clone shallow de `https://github.com/wwebjs/whatsapp-web.js` (`src/Client.js`, `src/structures/{Message,Chat,MessageMedia,GroupChat}.js`, `src/util/Constants.js`).
- zapo-js: `https://zapo.to/llms.txt` · `https://zapo.to/pt-br/{introduction,installation,quickstart}.md` · `https://zapo.to/pt-br/guides/{sending-messages,media,groups,receiving-messages,interactive-messages,presence-status,raw-sends,multi-session,production,reconnection}.md` · `https://zapo.to/pt-br/{reference/client,reference/message-types,concepts/events,concepts/identities,concepts/authentication}.md` · clone shallow de `https://github.com/vinikjkkj/zapo` · `https://registry.npmjs.org/zapo-js` (1.10.0, MIT, Node >= 20.9) · `https://registry.npmjs.org/whatsapp-web.js` (1.34.7, Apache-2.0, Node >= 18).
