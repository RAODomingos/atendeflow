# Design: Transcrição de Áudios Sob Demanda (Whisper API)

Data: 2026-10-08
Status: aguardando revisão do usuário
Path: architectural (subsistema novo)

## 1. Entendimento

- **Objetivo:** permitir ao agente clicar em "Transcrever" numa mensagem de áudio (WhatsApp inbound + WebChat inbound/outbound) e ler o texto abaixo do player.
- **Quem usa:** agentes/managers/admins no inbox (`inbox/panel.php`, `inbox/show.php`) + leitura em PDF (`inbox/pdf.php`, `contacts/pdf.php`, `reports/timeline_pdf.php`). Cliente não vê transcrição.
- **Sucesso:** clique → texto PT-BR em <60s para áudios curtos; 2º clique vem do cache sem custo; sem chave → UI explica; áudio >25MB → erro claro; inbox e PDF exibem o mesmo texto.
- **Fora do escopo:** transcrição automática no webhook, fila/worker async, diarização, resumo IA, busca full-text na transcrição.

## 2. Decisões aprovadas

- Modo: **sob demanda** (botão por mensagem).
- Provedor: **OpenAI Whisper API** (`whisper-1`, `language=pt`).
- Exibição: **abaixo do player**, persistida para reuso.
- Estratégia: **A + C leve** — endpoint síncrono + `TranscriptionProviderInterface` para trocar para local depois.
- Storage: **coluna em `messages`** (mais viável que tabela separada para este escopo — ver §3).

## 3. Storage: por que coluna em `messages`

Opção escolhida:
```sql
ALTER TABLE messages
  ADD COLUMN transcription TEXT NULL,
  ADD COLUMN transcribed_at DATETIME NULL;
```

Por que não tabela separada `message_transcriptions` agora:
- Leitura já é `SELECT ... FROM messages WHERE conversation_id=?`; coluna evita JOIN/N+1 em `apiMessages` e no render `panel.php/show.php`.
- `migrate.php` já é idempotente via `SHOW COLUMNS` — segue padrão de `005_add_reply_to.php` e `2026_10_08_messages_type_contact.sql`.
- `messages` já guarda `content TEXT` com JSON do áudio; transcrição é atributo 1:1 da mensagem, sem histórico de versões necessário no MVP.
- Evolução futura (async com status/error/model) justifica tabela própria; hoje seria over-engineering (YAGNI).

Contrato: se `transcription IS NOT NULL`, endpoint retorna cache sem chamar OpenAI. `transcribed_at` serve para auditoria/LGPD.

## 4. Arquitetura

```
[panel.php/show.php <audio> + botão Transcrever]
  -> POST /api/messages/{id}/transcribe (fetch, CSRF, auth agent+)
  -> TranscriptionController::transcribe()
  -> TranscriptionService::transcribeMessage(id)
     -> valida type=audio via message_file_meta()
     -> resolve arquivo: message_attachments.file_path OU baixa media_url (WAHA/Uazapi) p/ tmp
     -> valida mime audio/* + size <= TRANSCRIPTION_MAX_MB (default 25, = limite rígido Whisper API, sem compressão/chunking)
     -> OpenAiWhisperProvider::transcribe(path) via curl multipart
     -> UPDATE messages SET transcription, transcribed_at
  <- { text, cached: bool }
  -> JS injeta <div class="msg-transcription">
```

Arquivos novos (3):
- `app/Services/Transcription/TranscriptionProviderInterface.php`
- `app/Services/Transcription/OpenAiWhisperProvider.php`
- `app/Controllers/Api/TranscriptionController.php`
- migration `database/migrations/2026_10_08_message_transcription.sql`

Arquivos alterados:
- `routes/web.php` (1 rota POST)
- `app/Views/inbox/panel.php` (~linhas 130-132) + `app/Views/inbox/show.php` (~598) + JS em `app-enhancements.js` (botão + fetch + render cache)
- PDFs: `app/Views/inbox/pdf.php`, `app/Views/contacts/pdf_full.php` + `pdf.php`, `app/Views/reports/timeline_pdf.php` — abaixo do bloco "Áudio", imprimir `<div class="transcription">Transcrição: ...</div>` com escape; se sem transcrição, não imprimir nada
- `env/.env.example` (documenta chaves)
- Chave OpenAI também editável no painel `/settings` (`settings.openai_api_key`, password nunca reexibido; DB tem prioridade sobre `.env`)

## 5. Data flow detalhado

1. Render inicial: se `messages.transcription` preenchida, servidor já imprime `<div class="msg-transcription">` + botão vira "Ver transcrição"; senão imprime botão "Transcrever".
2. Clique: desabilita botão, mostra `Transcrevendo…`, `fetch POST` com CSRF.
3. Backend: `SELECT id, type, content FROM messages WHERE id=? AND conversation_id IN (conversas acessíveis)` → checa permissão inbox.
4. Resolve mídia: `message_file_meta(content)` → `url`; se path local em `storage/uploads`, usa direto; se URL remota do provedor, baixa com timeout 30s p/ `sys_get_temp_dir()`, checa `mime_content_type` e `filesize`.
5. Whisper: `curl POST https://api.openai.com/v1/audio/transcriptions` com `Authorization: Bearer OPENAI_API_KEY`, fields `file`, `model=whisper-1`, `language=pt`, `response_format=json`. Timeout 60s.
6. Salva: `UPDATE messages SET transcription=?, transcribed_at=NOW() WHERE id=?`, apaga tmp, retorna texto.
7. Frontend: injeta texto com `e()`/escape, mantém `<audio>` intacto.

## 6. Erros, custo, LGPD

- Sem `OPENAI_API_KEY`: `501 { error: 'Transcrição desabilitada.' }`, botão mostra tooltip config.
- Não-áudio / inexistente: `404/422`.
- >25MB (limite app = limite Whisper, `TRANSCRIPTION_MAX_MB=25`, sem ffmpeg/chunking): `422 'Áudio muito longo (limite 25MB).'`.
- Falha OpenAI/timeout: `502`, mantém botão "Tentar novamente", loga sem PII em `error_log` (nunca em `webhook.log`).
- Rate-limit: 10 req/min por usuário (reuso de `RateLimiter`), CSRF + role `agent/manager/admin`.
- Custo: 1 chamada por mensagem (cache); documentar preço Whisper/min no `.env.example`.
- LGPD: aviso na UI "Ao transcrever, o áudio é enviado à OpenAI"; sem retenção de tmp; `transcribed_at` permite auditoria/exclusão via edição de mensagem existente.

## 7. Testes

- `php tests/run.php` existente deve continuar verde.
- Novo smoke `scripts/tests/smoke_transcription.php`: mocka provider (sem rede), assert: 1ª chamada grava, 2ª vem do cache; sem chave → 501; não-áudio → 422.
- Manual: áudio WhatsApp ogg/opus + WebChat webm; clique 2x; reload mantém texto; PDF (`inbox/pdf.php`, `contacts/pdf_full.php`, `reports/timeline_pdf.php`) imprime transcrição abaixo de "Áudio".

## 8. Self-review da spec

- [x] Sem TBD/TODO vagos.
- [x] Arquitetura (§4) condiz com fluxo (§5) e storage (§3).
- [x] Escopo único (sob demanda síncrona); async explicitamente fora.
- [x] Sem ambiguidade: rota, colunas, modelo Whisper, limite e cache definidos.
