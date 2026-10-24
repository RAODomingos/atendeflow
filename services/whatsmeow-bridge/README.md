# Bridge do whatsmeow (AtendeFlow)

Service em Go que hospeda as sessões de WhatsApp usando a biblioteca
[whatsmeow](https://github.com/tulir/whatsmeow) e expõe uma API REST para o
AtendeFlow (PHP).

O whatsmeow é **biblioteca**, não servidor HTTP — por isso existe esta bridge.
O contrato entre os dois lados é:

- PHP: `app/Services/WhatsApp/WhatsmeowProvider.php` (consome a API)
- Go: `services/whatsmeow-bridge/` (serve a API)

## Por que whatsmeow

- Protocolo direto do WhatsApp Web: **sem Chrome**, sem automação de browser.
- Multi-sessão: uma sessão por canal do AtendeFlow.
- Edição de mensagem, revogação, reação, LID↔telefone, grupos e mídia.
- Licença MPL-2.0 (a bridge é do AtendeFlow; a biblioteca é usada como
  dependência, sem alteração de código).

## Requisitos

- **Go 1.26+** (o módulo do whatsmeow exige `go 1.26.0`)
- Docker (recomendado) ou Go local

O SQLite das sessões usa o driver **modernc.org/sqlite** (Go puro), então o
build sai com `CGO_ENABLED=0` e a imagem final não precisa de `gcc`/`build-base`.

## Subindo com Docker

```bash
cd services/whatsmeow-bridge
export WHATSMEOW_BRIDGE_TOKEN="um-token-compartilhado"
docker compose up -d --build
curl -s http://127.0.0.1:8090/health
```

## Compilando local

```bash
cd services/whatsmeow-bridge
go mod tidy      # gera o go.sum
go build -o bridge .
go vet ./...
go test ./...
./bridge
```

Variáveis:

| Variável | Padrão | O que faz |
|---|---|---|
| `BRIDGE_ADDR` | `:8090` | endereço do HTTP |
| `BRIDGE_DATA_DIR` | `./data` | onde ficam as sessões (um SQLite por canal) |
| `BRIDGE_TOKEN` | vazio | token do header `X-Bridge-Token` (vazio = API aberta) |
| `BRIDGE_PUBLIC_URL` | vazio | URL pública da bridge (usada no envelope do webhook) |

## Ligando no AtendeFlow

`env/.env`:

```
WHATSAPP_PROVIDER=uazapi          # UAZAPI segue como padrão
WHATSMEOW_BRIDGE_URL=http://127.0.0.1:8090
WHATSMEOW_BRIDGE_TOKEN=um-token-compartilhado
```

O provedor `whatsmeow` aparece na lista de provedores do painel (Canais >
WhatsApp). Ao criar o canal, o app chama `POST /sessions`, mostra o QR e
registra o webhook.

## API

| Método | Rota | O que faz |
|---|---|---|
| `GET` | `/health` | estado de todas as sessões |
| `POST` | `/sessions` | cria/retoma a sessão `{name}` |
| `GET` | `/sessions/{id}/qr` | QR em data-URL + status |
| `GET` | `/sessions/{id}/status` | status + número conectado |
| `POST` | `/sessions/{id}/disconnect` | desconecta (mantém a sessão) |
| `DELETE` | `/sessions/{id}` | desvincula e apaga a sessão |
| `POST` | `/sessions/{id}/webhook` | `{url, secret}` do webhook do app |
| `POST` | `/sessions/{id}/send` | `{to, type, text?, media?, options?}` |
| `POST` | `/sessions/{id}/interactive/buttons` | menu de botões (nativo) |
| `POST` | `/sessions/{id}/interactive/list` | lista interativa (nativa) |
| `POST` | `/sessions/{id}/messages/{mid}/edit` | editar mensagem |
| `POST` | `/sessions/{id}/messages/{mid}/delete` | apagar para todos |
| `POST` | `/sessions/{id}/messages/{mid}/react` | reagir |
| `POST` | `/sessions/{id}/messages/seen` | marcar como visto |
| `POST` | `/sessions/{id}/typing` | `{to, state: composing\|paused}` |
| `POST` | `/sessions/{id}/groups/{jid}/text` | `{text, mentions[]}` |
| `GET` | `/sessions/{id}/groups/{jid}/participants` | participantes (phone/lid/name/is_admin) |
| `POST` | `/sessions/{id}/contact` | cartão de contato (vCard) |
| `GET` | `/sessions/{id}/resolve/{contact}` | `{phone, lid}` |
| `GET` | `/sessions/{id}/media/{mid}` | `{url, mime, name}` da mídia recebida |
| `GET` | `/sessions/{id}/contacts/{id}/avatar` | **não implementado** (404) |

### Webhook (bridge → app)

`POST {APP_URL}/webhooks/whatsapp` com o header `X-Webhook-Secret` e a
assinatura `X-Webhook-Signature` (HMAC-SHA256 do corpo).

```json
{
  "provider": "whatsmeow",
  "event": "message",
  "session": "canal-vendas",
  "message": {
    "id": "ABC123",
    "chat_id": "5511999998888@s.whatsapp.net",
    "from": "5511999998888@s.whatsapp.net",
    "from_phone": "5511999998888",
    "from_lid": "",
    "is_group": false,
    "from_me": false,
    "type": "text",
    "body": "olá",
    "timestamp": 1761290000,
    "sender_name": "Maria"
  }
}
```

O campo `provider` é o que permite o roteamento existente do app: o
`WhatsAppService::resolveProviderFromPayload()` lê esse campo e o
`session` casa com `whatsapp_connections.instance_name`.

## Menus e listas

O provedor PHP manda o menu para `/interactive/buttons` e `/interactive/list`.
A bridge responde `native: true` quando o WhatsApp aceitou o interativo e
`native: false` quando recusou — nesse caso o **app envia a apresentação em
texto numerado**, que o motor de fluxos responde por posição (o
`FlowEngineService::handleMenuResponse()` aceita o número da opção). Nenhum
fluxo precisa ser reescrito.

## Estados de erro

| Status | Quando acontece | O que o app faz |
|---|---|---|
| `401` | header `X-Bridge-Token` ausente ou errado | loga e não envia |
| `404` | sessão não existe na bridge | erro no painel do canal |
| `409` | sessão ainda não foi pareada com o WhatsApp | erro no painel do canal |
| `502` | o WhatsApp recusou a operação | erro no envio da mensagem |

A sessão responde `409` (e não espera) enquanto o QR não foi lido: assim o
app não fica com requisição pendurada até o timeout.

## Número do cliente (LID)

O whatsmeow guarda o par LID↔telefone no store da sessão. A bridge devolve
sempre os dois separados (`from_phone` / `from_lid`) e o endpoint
`/resolve/{contact}` consulta o par. O app persiste o que aprendeu em
`whatsapp_lid_map`.

## Limitações conhecidas

1. **Foto de perfil**: o endpoint de avatar responde 404 — o app usa o avatar
   que já tem no contato. Implementar depende do endpoint do whatsmeow para
   perfil, que muda entre versões.
2. **Interativos nativos**: dependem de o WhatsApp aceitar o protobuf. Se
   recusar, o app degrada para texto numerado (funciona igual).
3. **Build verificado**: o código foi escrito contra a API do whatsmeow em
   `v0.0.0-20261017175727-b2b1e71d45e2` (17/10/2026). Se a assinatura mudar
   numa versão nova, o ajuste fica concentrado em `driver.go` e `interactive.go`.
4. **Uso não oficial do WhatsApp**: risco de ban — mantenha o UAZAPI como
   contingência.

## Segurança

- `BRIDGE_TOKEN` protege a API (header `X-Bridge-Token`). **Nunca deixe vazio
  em produção.**
- A bridge não deve ser exposta na internet: fale com ela pela rede interna.
- O webhook usa header de segredo (não query string), então o segredo não
  aparece no log do nginx/apache.
