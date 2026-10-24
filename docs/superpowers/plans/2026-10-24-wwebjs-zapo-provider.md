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
| `app/Services/WhatsApp/WhatsAppManager.php` | `availableProviders()` subtrai `hidden_providers`; `defaultProviderName()` cai para `'uazapi'`; comentário de compatibilidade no registry |
| `database/migrations/2026_10_24_whatsapp_provider_default_uazapi.sql` | `ALTER TABLE whatsapp_connections MODIFY COLUMN provider VARCHAR(50) NOT NULL DEFAULT 'uazapi'` |
| `database/schema.sql` | default da coluna `provider` → `'uazapi'` (fresh install) |
| `env/.env.example` | bloco UAZAPI como principal; WAHA marcado como legado |

**Verificação feita:** `availableProviders()` → `["uazapi"]`; `defaultProviderName()` → `"uazapi"`; `provider('waha')` e `provider('uazapi')` continuam instanciando (legado não quebra); migration aplicada e registrada em `schema_migrations`; `php tests/run.php` → 171/171.

**Fora do escopo desta branch:** apagar `WahaProvider.php`. Fica como limpeza futura (tarefa separada), depois que ninguém mais usar WAHA.

### 1.1 Contrato compartilhado (menu/lista + número do cliente)

Preparado nesta branch para que **os dois provedores novos** entreguem as mesmas capacidades do UAZAPI:

| Arquivo | Mudança |
|---|---|
| `app/Services/WhatsApp/WhatsAppProviderInterface.php` | `sendButton()` e `sendList()` passam a ser **obrigatórios** no contrato (os dois providers existentes já implementavam com a mesma assinatura) |
| `app/Services/WhatsApp/WhatsAppInteractive.php` (novo) | rendering única do menu/lista como texto numerado (`renderButtonsAsText`, `renderListAsText`) — mesmo formato do fallback que o `WhatsAppService` já usava |
| `app/Services/WhatsApp/WhatsAppIds.php` (novo) | normalização compartilhada de identificadores: `digits`, `phone`, `isLid`, `isPhone`, `splitLidAndPhone` (separa o par LID↔telefone) e `mapParticipants` (shape único de participante) |
| `app/Services/WhatsApp/UazapiProvider.php` | `mapParticipantList()` passa a delegar para `WhatsAppIds::mapParticipants()` — comportamento idêntico, sem duplicar regra |
| `tests/wa_contract.php` (novo) | 19 verificações do contrato (roda sozinho ou pelo runner) |

**Por que isso é o alicerce do "funcionar nos dois":** menu/lista e número do cliente passam a ser **regra do contrato**, não acidente de implementação. O provider novo que não tem interativo nativo degrada para texto numerado — que o `FlowEngineService::handleMenuResponse()` já responde por posição — e o número do cliente é normalizado igual em qualquer motor, seja ele `getContactLidAndPhone` (wwebjs) ou o par `remoteJid`/`remoteJidAlt` (zapo).

**Verificação feita:** `php tests/wa_contract.php` → 19/19; `php tests/run.php` → **190/190** (171 + 19); `scripts/tests/smoke_group_members.php` → PASS (o `mapParticipantList` continua igual).

### 1.2 Provider whatsmeow + bridge Go (implementado nesta branch)

Decisão do dono: implementar o **whatsmeow** junto com o UAZAPI (que segue como padrão de produção). O whatsmeow é **biblioteca Go**, não API HTTP — então a implantação é uma **bridge** (sidecar) que hospeda as sessões e expõe REST para o PHP.

| Peça | Onde | Estado |
|---|---|---|
| Provider PHP (20 métodos da interface + `sendSeen`/`startTyping`/`stopTyping`) | `app/Services/WhatsApp/WhatsmeowProvider.php` | **pronto e testado** (36 checks) |
| Registro no manager | `app/Services/WhatsApp/WhatsAppManager.php` | `whatsmeow` registrado e **visível** na lista |
| Config + env | `config/integrations.php`, `env/.env.example` | `WHATSMEOW_BRIDGE_URL`, `WHATSMEOW_BRIDGE_TOKEN` |
| Bridge Go (sessões, QR, envio, eventos, webhook) | `services/whatsmeow-bridge/` | **compila, veta e passa nos testes** (8 testes Go) + smoke test HTTP |
| Testes do provider | `tests/whatsmeow_provider.php` | 36 checks, wired no runner |

**Contrato entre os dois lados** (mesmo formato do webhook do UAZAPI, para o app não ter duas traduções): o envelope leva `provider: "whatsmeow"` (o `resolveProviderFromPayload()` do app já lê esse campo), `session` (casa com `instance_name`) e `message` com `from_phone`/`from_lid` **sempre separados** — o app nunca recebe `@lid` no lugar do número.

**Menus/listas:** o provider chama `/interactive/buttons` e `/interactive/list`; a bridge responde `native:false` quando o WhatsApp recusa o interativo e o app manda o texto numerado (que o motor de fluxos responde por posição). Nenhum fluxo é reescrito.

**Gate do build (fechado):** o Go 1.27 foi instalado (winget) e a bridge foi compilada contra a API real do whatsmeow `v0.0.0-20261017175727-b2b1e71d45e2` — as assinaturas foram conferidas com `go doc` no módulo, não de memória. O que o build revelou e o que foi corrigido está no commit `bridge do whatsmeow: compila, veta e passa nos testes`:

- `ContextInfo.MentionedJID` é `[]string` (não existia `waE2E.MentionedJID`) e a citação é `StanzaID` (não `MessageID`).
- `Client.Upload` devolve `UploadResponse` — a mensagem de mídia é montada a partir do upload, não mais como se o retorno fosse uma mensagem pronta.
- `ListMessage` é plano (`Title`/`Description`/`Sections`/`RowID`); `ButtonsMessage_Button` usa `ButtonID` + `ButtonText{DisplayText}` + `Type`.
- `MarkRead` exige `[]types.MessageID` + timestamp: a sessão guarda a última mensagem de cada chat para emitir o recibo de leitura.
- SQLite migrou para o driver **modernc.org/sqlite** (Go puro): o build sai com `CGO_ENABLED=0` e a imagem Docker não precisa de `gcc`/`build-base`.
- Sessão ainda não pareada respondia com pânico (`Store.LIDs`/`Store.Contacts` nulos) — agora responde `409` rápido e sem derrubar a conexão.

Verificação: `go build ./...`, `go vet ./...`, `go test ./...` (8 testes) e um smoke test HTTP real da API (health, criação de sessão, QR, webhook, resolve LID↔telefone, interactive, grupos, delete) — **zero pânico**.

**Gaps declarados da bridge:** foto de perfil (404 — o app usa o avatar do contato).

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
| 12 | `resolvePhone` (LID→telefone) | ✅ `client.getContactLidAndPhone(userIds)` → `{lid, pn}[]` | ⚠️ **não existe API LID→PN**; só o inverso `client.profile.getLidsByPhoneNumbers()`; o par LID↔telefone sai do evento (`key.remoteJid`/`key.remoteJidAlt`, `key.participant`/`key.participantAlt`) | Bridge mapeia o par no envelope (`phone` + `lid`) usando `WhatsAppIds::splitLidAndPhone()`; a bridge guarda o mapa e responde `resolvePhone`. O app já persiste em `whatsapp_lid_map` (`learnLidMappings`). |
| 13 | `sendReaction` | `message.react(emoji)` / `client.sendReaction(id, emoji)` — **remover reação não confirmado** | `client.message.send(jid, { type:'reaction', emoji, target: event })`; remover = `emoji: ''` | Bridge expõe `POST .../react` com `emoji` vazio = remover |
| 14 | `sendGroupText` (menções) | `options.mentions[]` + `options.groupMentions[]` (usa `@c.us`) | `options.mentions` (Pn ou LID) | Bridge preserva `@g.us` e digítos |
| 15 | `fetchGroupParticipants` | `groupChat.participants` → `{id, isAdmin, isSuperAdmin}` | `client.group.queryGroupMetadata(gid)` → `{subject, owner, participants[], flags}` | ⚠️ **ambas não garantem `name`** → cruzar com contatos (`getContactById` / store `contacts`) para montar `{phone, lid, name, is_admin}` |
| 16 | `sendContact` (vCard) | `options.parseVCards: true` ou `chat.sendMessage(new Contact(...))` | raw proto: `{ contactMessage: { displayName, vcard } }` — **sem builder tipado**; incluir `waid=<dígitos>` no `TEL` | Bridge monta o vCard (o app já tem `IncomingMessage::buildVcard`) |
| 17 | `getProfilePicture` | `client.getProfilePicUrl(contactId)` → `string\|null` | `client.profile.getProfilePicture(jid, 'preview'\|'image')` → pode vir **sem `url`** (só `directPath`/`id`) | Bridge normaliza: sem `url` → tenta re-download; senão `null` (o app aceita null) |
| 18 | `sendSeen` / digitando | `client.sendSeen(chatId)` / `chat.sendSeen()`; `chat.sendStateTyping()` (25 s), `chat.clearState()` (parar) | `client.message.sendReceipt(chatJid, [ids], { type:'read' })`; `client.presence.sendChatstate(jid, {state:'composing'\|'recording'\|'paused'})` | Bridge normaliza; `paused`/`clearState` = parar de digitar |
| 19 | `sendButton` — menu de botões (**entra no contrato nesta branch**) | ❌ a biblioteca depreciou: `sendMessage` com botão emite `console.warn` e **não envia** | ⚠️ raw proto `buttonsMessage`, **sem builder tipado** | **Implementado nos dois** (ver §3.1): zapo tenta o nativo e cai para texto numerado; wwebjs vai direto para o texto numerado. O fluxo responde pela **posição** da opção, então o menu funciona igual nos dois. |
| 20 | `sendList` — lista interativa (**entra no contrato nesta branch**) | ❌ idem | ⚠️ raw proto `listMessage` / `interactiveMessage` | idem |

**Correção importante (validado no código):** o UAZAPI **envia menu e lista nativos** hoje — `UazapiProvider::sendButton()` usa `POST /send/menu` com `type=button` e `sendList()` usa `POST /send/menu` com `type=list`; o `WahaProvider` tem os mesmos dois métodos. O nó de fluxo `button_list`/`list_menu` chega ao provedor por `WhatsAppService::sendOutbound()`, que hoje escolhe o caminho nativo com `method_exists($provider, 'sendButton')`. nesta branch `sendButton`/`sendList` viraram **método obrigatório da interface** — o `method_exists` vira redundante e nenhum provider novo pode "esquecer" o menu.

### 3.1 As duas garantias que os fluxos dependem

Os fluxos do AtendeFlow usam três coisas que não podem quebrar em nenhum provider: **menu/lista**, **número do cliente** e **resposta por posição**.

**a) Menu e lista funcionam nos dois provedores**

O `FlowEngineService::handleMenuResponse()` já resolve a resposta do menu por **label, value ou posição** (`$option['sort_order'] + 1 === (int) $resp`). Ou seja: se as opções forem apresentadas como texto numerado, o cliente responde `2` e o fluxo segue exatamente igual ao botão nativo.

- `UazapiProvider` → mantém o nativo (`/send/menu`), como hoje.
- `ZapoProvider` → tenta o nativo (protobuf `buttonsMessage`/`listMessage`); se o motor recusar, cai para o texto numerado.
- `WwebjsProvider` → a biblioteca não envia interativo, então usa direto o texto numerado.
- **Todos** usam a mesma rendering em `WhatsAppInteractive::renderButtonsAsText()` / `renderListAsText()` — o mesmo formato que o fallback do `WhatsAppService` já produzia.

Resultado: **nenhum fluxo precisa ser reescrito** e o menu funciona nos três providers (UAZAPI, wwebjs, zapo).

**b) O número do cliente é sempre disponível**

`WhatsAppIds` centraliza a normalização (fora dos providers, para que os três falem a mesma língua):

- `splitLidAndPhone($primary, $alt)` — separa o par dos dois JIDs que o WhatsApp entrega; **telefone tem prioridade**, e quando só há LID o número fica `null` em vez de inventar um id.
- `digits()`, `phone()`, `isLid()`, `isPhone()` — limpeza de `@c.us`, `@s.whatsapp.net`, `@lid`, `@g.us` e sufixo de dispositivo.
- `mapParticipants()` — shape único de participante (`phone`, `lid`, `name`, `is_admin`) para qualquer provider; o `UazapiProvider::mapParticipantList()` passou a delegar para cá (comportamento idêntico, coberto pelo smoke de grupos).

Por motor:

| | Como o número chega |
|---|---|
| UAZAPI | `resolvePhone()` do provedor (já existe) |
| wwebjs | `client.getContactLidAndPhone()` — resolução autoritativa, direto do WhatsApp Web |
| zapo | a bridge lê `remoteJid` + `remoteJidAlt` (e `participant`/`participantAlt`) de **todo** evento, separa com `splitLidAndPhone()` e guarda o mapa; `resolvePhone()` consulta esse mapa; o app persiste em `whatsapp_lid_map` |

O envelope do webhook (§6.1) por isso carrega **`phone` e `lid` separados** — a bridge nunca manda um `@lid` no lugar do número.

**c) Resposta por posição**

Como o `handleMenuResponse()` aceita a posição, o fallback em texto numerado é **transparente**: o cliente nem percebe a diferença, e o fluxo grava a mesma resposta (`Flow::saveAnswer`).
| — | Servidor HTTP/REST pronto | ❌ não existe no pacote | ❌ não existe no pacote (só `@zapo-js/mcp-server` dev-only e `@zapo-js/fake-server` p/ teste) | Bridge é obrigatória nos dois casos |

**Leitura da matriz:** as duas cobrem os 18 pontos. Os gaps são **conhecidos, pequenos e contornáveis**:
- zapo sem API LID→telefone → a bridge mapeia `remoteJid`↔`remoteJidAlt` (o app já tem `learnLidMappings` para isso).
- `name` do participante de grupo não garantido em nenhuma das duas → cruzar com a agenda de contatos.
- foto de perfil no zapo pode não vir com `url` → normalizar para `null`.
- remoção de reação no wwebjs não confirmada → testar na Fase 0.

**Menus/listas e número do cliente são requisitos de negócio, não opcionais:** o `FUNCIONALIDADES_SISTEMA.md` lista `Uazapi /send/text|media|menu|contact` como capacidade em produção e os nós de fluxo `button_list`/`list_menu` dependem dela. Por isso `sendButton`/`sendList` entraram no contrato da interface e a normalização de LID/telefone virou camada compartilhada (§3.1) — **sem isso, trocar de provider quebraria menu e a identificação do cliente**.

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
2. **`zapo-js` como motor primário** — sem browser (memória e complexidade menores), multi-sessão no mesmo processo por `sessionId`, store MySQL que o app **já tem**, releases ativos (último commit 23/10/2026), docs em **pt-br**, `@zapo-js/fake-server` para E2E sem conta real e `recoverFromClientTooOld` para o caso de o WhatsApp Older. O número do cliente chega junto no evento (`remoteJid` + `remoteJidAlt`) e a bridge o separa — resolve para todo contato que já interagiu.
3. **`whatsapp-web.js` como segundo motor** — entra se/sem o zapo falhar num requisito. Vantagem exclusiva: `getContactLidAndPhone()` resolve LID→telefone **de forma autoritativa, mesmo para contato que nunca escreveu** — se "sempre ter o número" for requisito duro (e não só para quem já interagiu), ele passa na frente. Hiperlinks: `MessageMedia.fromUrl` aceita URL remota direto.
4. **UAZAPI permanece como padrão de produção e rota de contingência.** Nenhum canal migra sem passar pela matriz de paridade da Task 3.2 (22 cenários, incluindo menu/lista e número do cliente).

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
| POST | `/sessions/{id}/interactive/buttons` | `{ to, text, buttons:[{id,label}] }` → `{ provider_message_id, native:bool }` (`native:false` = a bridge já mandou o texto numerado) |
| POST | `/sessions/{id}/interactive/list` | `{ to, text, title, items:[{id,label}] }` → idem |
| GET | `/sessions/{id}/groups/{jid}/participants` | → `[{ phone, lid, name, is_admin }]` |
| GET | `/sessions/{id}/contacts/{id}/avatar` | → URL da foto |
| GET | `/sessions/{id}/resolve/{contactId}` | → `{ phone:?string, lid:?string }` (LID→telefone; wwebjs consulta `getContactLidAndPhone`, zapo consulta o mapa aprendido) |
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
    "from_phone": "5511999999999",
    "from_lid": "225962742546599",
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
  - **`from_phone` e `from_lid` sempre separados** — a bridge aplica a mesma regra do `WhatsAppIds::splitLidAndPhone()` (telefone tem prioridade; se só houver LID, `from_phone` fica vazio e `from_lid` preenchido). O app nunca recebe `@lid` no lugar do número.
  - Eventos: `message`, `message_edit`, `message_revoke`, `message_reaction`, `connection`, `qrcode`.
  - A bridge assina o corpo com HMAC-SHA256 no header `X-Webhook-Signature` (o app já aceita).
  - **No zapo, a bridge inclui `lid`**: `key.remoteJid` e `key.remoteJidAlt` (e `participant`/`participantAlt`) são mapeados para os campos acima — é assim que o app aprende o par LID↔telefone (`learnLidMappings`) já que o zapo não expõe API LID→PN.
  - **No zapo, `key.fromMe` precisa de filtro**: evento de sync multi-device chega como mensagem — a bridge descarta `fromMe`/`senderDevice` conforme a regra que o app já aplica (`parseWebhook` do UAZAPI já filtra `fromMe`/`wasSentByApi`).

### 6.2 O provider PHP

- `app/Services/WhatsApp/WwebjsProvider.php` e `app/Services/WhatsApp/ZapoProvider.php` implementam `WhatsAppProviderInterface` **apenas** falando HTTP com a bridge (mesmo estilo do `WhatsAppHttpClient` que já existe).
- `parseWebhook()` traduz o envelope da bridge → `IncomingMessage` (text/media/contact).
- `resolvePhone()` pergunta à bridge (`GET /sessions/{id}/resolve/{contactId}`) e cai no `WhatsAppIds` para limpar o retorno.
- `send()` com `options.path` → lê o arquivo local e manda o conteúdo (o servidor do provedor não alcança a URL pública do app — mesmo cuidado que o UAZAPI já tem). No wwebjs a bridge grava o arquivo em disco e usa `MessageMedia.fromFilePath()`; no zapo manda `Uint8Array` + `mimetype` (obrigatório).
- `sendButton()` / `sendList()` → chamam `/interactive/buttons` e `/interactive/list`. A bridge responde `native:false` quando não tem interativo nativo (wwebjs) — nesse caso o **provider** manda o texto numerado (`WhatsAppInteractive::renderButtonsAsText()` / `renderListAsText()`) e devolve o id da mensagem. Se a chamada nativa falhar no zapo, o mesmo fallback entra.
- `fetchGroupParticipants()` → normaliza com `WhatsAppIds::mapParticipants()` (mesmo shape do UAZAPI).
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
- [ ] **Task 2.3** — `parseWebhook` dos dois → `IncomingMessage` (text/media/contact) + `provider`/`session` do envelope, lendo `from_phone`/`from_lid`.
- [ ] **Task 2.4** — `resolvePhone`, `fetchGroupParticipants`, `getProfilePicture`, `sendReaction`, `editMessage`, `deleteMessage`, `downloadMedia`, `sendContact`.
- [ ] **Task 2.5** — `sendButton`/`sendList` nos dois providers (obrigatórios pela interface): chamam `/interactive/*`; quando a bridge responde `native:false` ou falha, mandam o texto numerado via `WhatsAppInteractive` e devolvem o id da mensagem.
- [ ] **Task 2.6** — Testes de fiação em `tests/run.php`: classes existem, implementam a interface (incluindo `sendButton`/`sendList`), `hidden_providers` contém os novos, `availableProviders()` **não** os expõe.
- [ ] **Task 2.7** — `scripts/tests/smoke_wa_bridge.php`: sobe a bridge local, manda texto/mídia real, manda um menu e confere a resposta por posição, confere o webhook chegando no PHP e a conversa criada.

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
| 19 | **Menu de botões (`button_list`)** — o cliente responde `2` e o fluxo segue | ☐ |
| 20 | **Lista (`list_menu`)** — idem | ☐ |
| 21 | **Número do cliente sempre preenchido** (LID → telefone, inclusive em grupo) | ☐ |
| 22 | **Número preenchido no primeiro contato** (sem histórico prévio) | ☐ |

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
5. **Menus/listas e número do cliente:** já decididos — **têm que funcionar nos dois provedores**. O contrato da interface agora exige `sendButton`/`sendList` e a normalização de LID/telefone é compartilhada (§3.1). O que fica em aberto é só **qual motor entra primeiro**:
   - `wwebjs` resolve o número de forma autoritativa (`getContactLidAndPhone`), inclusive para contatos que nunca escreveram — é o mais seguro para a identificação do cliente, ao custo de 1 Chromium por sessão e de não enviar interativo nativo (cai para texto numerado).
   - `zapo` entrega o número junto no evento (`remoteJid` + `remoteJidAlt`), que a bridge separa e o app aprende — na prática resolve para todo contato que já interagiu, que é o caso do fluxo de atendimento; em troca, roda leve, com N sessões num processo e store MySQL. Interativo nativo sai pelo protobuf cru.
6. **Escopo dos botões/listas interativas:** para o UAZAPI (em produção) e para o zapo, dá para enviar o botão/lista **nativo**. Para o wwebjs, não dá — a biblioteca depreciou o envio. A degradação para texto numerado mantém o fluxo funcionando (o cliente responde `2`), mas se **native importa** (ex.: o cliente precisa tocar no botão), o wwebjs fica atrás.

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
