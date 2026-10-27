# Atendeflow / OminiDesk — Documento de Funcionalidades para Implantação em Outro Sistema

> **Objetivo:** descrever todas as funcionalidades do sistema para permitir reimplantação / reimplementação em outro stack.
> **Fonte:** levantamento de `routes/web.php`, `app/Controllers/` (15 raiz + 14 `Api/`), `app/Views/`, `public/`, `database/schema.sql` + `database/migrations/` (42), `config/`, `env/.env.example`, `workers/`, `deploy/`.
> **Stack atual:** PHP 8.1+ puro (MVC procedural próprio, sem framework) + MySQL 8 + views server-rendered + JS vanilla + SSE/polling. UI PT-BR.
> **Nomes:** repo usa `Atendeflow` (`composer.json`) e `OminiDesk` (README/telas). Considerar canônico único ao reimplantar.

---

## 1. Visão Geral

Central omnichannel self-hosted que unifica **WebChat (widget embeddável) + WhatsApp (WAHA/Uazapi)** em inbox única, com **chatbot visual (flows), contatos/CRM leve, departamentos + horário comercial, macros/respostas prontas, tags, CSAT, relatórios, base de conhecimento Wiki com portal público + API-key, SLA, notificações realtime (SSE)**.

**Princípios (PRODUCT.md):**
1. Uma inbox dona de toda conversa — nenhum canal fora de triagem/atribuição/histórico.
2. Automatizar com fallback humano — flows/horário/wiki aceleram, nunca prendem cliente.
3. Self-hosted simples — um deploy PHP/MySQL detém dados e customização.
4. Reuso de conhecimento — wiki, macros, canned são memória compartilhada.
5. Serviço mensurável — SLA, CSAT e reports decidem workflow.

**Usuários:**
- **Primários:** agentes (triagem/resposta em inboxes compartilhadas/pessoais), gestores (configuram flows, macros/tags, SLA, motivos de encerramento), admins (usuários, canais, inboxes, conexões WhatsApp, chaves wiki, departamentos + horário comercial).
- **Secundários (público):** clientes finais via widget WebChat, WhatsApp, link CSAT público, portal wiki hospedado em qualquer lugar.

---

## 2. Requisitos Técnicos Mínimos

| Camada | Requisito |
|---|---|
| PHP | >= 8.1 + `pdo, mbstring, json, openssl` + implícitos `curl` (WhatsApp/transcrição), `gd` (JPEG Uazapi), `fileinfo` (mime). Timezone `America/Sao_Paulo`, `display_errors=0` em prod (não quebrar JSON) |
| Banco | MySQL 8+, `utf8mb4_unicode_ci`, DB default `atendeflow`. PDO `ERRMODE_EXCEPTION, FETCH_ASSOC, EMULATE_PREPARES=false` |
| Servidor | Docroot `public/`. Apache (`.htaccess` reescreve p/ `public/`, bloqueia `env/storage/vendor/database/config/scripts/workers/lib/.git/*.sql/*.log`) ou Nginx (`deploy/nginx/atendeflow.test.conf` como canônico: catch-all, `client_max_body_size 32M`, nega `*.php` em `uploads/`, `X-Frame-Options SAMEORIGIN`, gzip). `APP_URL` vazio = auto-detect (ngrok/host qualquer) |
| Storage | `storage/uploads` (mídias flows/macros/wiki/sons/avatars, `UPLOAD_MAX_SIZE 10MB`), `storage/logs/webhook.log`, `public/uploads` servido direto (nunca executar PHP), `public/wiki-frontend/` estático cache 1h |
| Composer scripts | `serve: php -S localhost:8080 -t public`, `migrate: php database/migrate.php`, `test: php tests/run.php` |
| Cron / Daemon | `workers/flow_worker.php` e `workers/sla_worker.php` via cron `* * * * *` + `workers/process_jobs.php` via systemd/supervisor (ver §13) |
| Seed inicial | `admin@atendeflow.local / Admin@123` (bcrypt no schema — **trocar em prod**), deptos Comercial/Suporte/Financeiro, inboxes Geral+3, channels ChatWeb x3, tags Lead/Cliente/Prioridade/Inadimplente/VIP |

---

## 3. Instalação (passo a passo para outro servidor)

```bash
git clone <repo> && cd <repo>
composer install --no-dev
cp env/.env.example env/.env
# gerar: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;" -> JWT_SECRET, APP_DEBUG=false
mysql -u root -p < database/schema.sql
php database/migrate.php  # idempotente, aplica database/migrations/ em ordem
php -S localhost:8080 -t public  # dev; prod = vhost Nginx/Apache apontando p/ public/
# login: http://localhost:8080/ admin@atendeflow.local / Admin@123
# /settings -> tema, widget, SLA, canais
# cron + daemon (ver §13), configurar webhook WhatsApp HTTPS pública, copiar wiki-frontend
```

**Variáveis obrigatórias em prod:** `DB_HOST/PORT/DATABASE/USERNAME/PASSWORD`, `APP_URL`, `APP_DEBUG=false`, `JWT_SECRET`, `WHATSAPP_PROVIDER=waha|uazapi` + `WAHA_BASE_URL+WAHA_API_KEY` **ou** `UAZAPI_BASE_URL+UAZAPI_ADMIN_TOKEN`, `OPENAI_API_KEY` (se transcrição), `wiki_api_key` (gerada no painel `/wiki/settings`, não `.env`), `UPLOAD_MAX_SIZE/UPLOAD_PATH`, `SESSION_LIFETIME`.

---

## 4. Papéis e Permissões

| Papel | Verificação | Acesso |
|---|---|---|
| Público | sem `auth` | `GET/POST /login`, `GET/POST /csat/{token}`, `GET /widget/demo/{key}`, `GET /widget/chat.js|css`, `POST /api/webchat/*`, `POST /webhooks/whatsapp`, `GET /api/wiki/*` (com `X-API-Key`) |
| `agent` (base) | `['auth','csrf']` | `/`, `/inbox*`, `/contacts` (ver/criar), `/flows` (só lista), `/profile`, `/macros` (listar), `/library` (ver), `/wiki` (ler), `/whatsapp/groups` (ler), `/api/*` internos |
| `manager` | `['manager']` (= manager+admin) | + CRUD flows, edit/delete contatos, store macros/library, `alert|inbox|send` grupos, `/reports*`, upload flows/macros/wiki |
| `admin` | `['admin']` | + `/settings*` (inclui a aba Departamentos), `/departments*` (CRUD + horário + membros), `/users*`, `/channels*`, `/inboxes*`, `connect|disconnect` WhatsApp, `/settings/subjects|close-reasons`, `/wiki/settings*`, `/api/close-reasons*` |
| Wiki externo | header `X-API-Key` | `GET /api/wiki/categories|articles|articles/{slug}|config|chat-config` |

`POST /logout` exige `auth+csrf`. APIs internas via `fetch FormData` ou `X-CSRF-TOKEN`.

---

## 5. Módulo Dashboard (`GET /`)

`DashboardController::index` + `Views/dashboard/index.php`, cache 30s `dashboard:userId:perfil`.

- Contadores globais/pessoais por status `new/open/waiting_customer/waiting_internal`, `unread`, `onlineUsers`.
- Métricas hoje/ontem (conversas/mensagens/resolvidas), CSAT 30d, tendência 7d, tempo médio resposta, por departamento, top5 agentes (só admin vê `totalUsers/totalDepartments/agentData`).
- Breakdown por inbox `openCountsByInbox`, split normal x grupos WhatsApp (`group_id`), `recentConversations[0:10]`.
- Auto-refresh via `GET /api/dashboard-stats`.

**Reimplantar:** cards KPI + gráficos 7d + tabela recentes + filtros por perfil.

---

## 6. Módulo Inbox / Conversas (núcleo)

Controller `InboxController.php` (~1550 linhas). Views `inbox/{index,show,panel,new,_conv_list,pdf,panel/_drawer,panel/_modals}.php`.

### 6.1 Navegação e triagem
- `GET /inbox` (lista + filtros + SLA + sons + busca), `GET /inbox/mine` (só atribuídas a mim, via inboxes pessoais), `GET /inbox/unassigned` (`assigned IS NULL AND status IN (new,open)`, respeita snooze), `GET /inbox/chatbot` (redirect `/inbox` — aba removida), `GET /inbox/new` + `POST /inbox/new` (criação manual exige `contact_id + channel_id`, `inbox=resolveInboxForChannel()`, `assigned=self`, `status=open`), `GET /inbox/{id}` (detalhe + marca lido + marca notificações/menções lidas), `GET /inbox/{id}/panel` (fragmento drawer), `GET /inbox/{id}/pdf` (PDF conversa), `POST /inbox/contact-chat` (abrir/criar chat por telefone), `POST /inbox/personal` (criar inbox pessoal).
- **Controle de acesso:** admin vê tudo; demais só se `assigned=self OU tem acesso à inbox OU membro do departamento`. AJAX → `403 JSON`, web → flash+redirect.
- **Listagem SQL:** `latest_msg`, `last_inbound`, `msg_stats` (count + unread `inbound+is_read=0` excluindo próprias), `has_active_flow`, `waiting_seconds` (0 se final/sem pendência, senão `NOW-last_inbound`). Filtros `unassigned/mine/status(active/waiting/resolved_closed)/search(nome/email/assunto/protocolo só dígitos)/snooze`. Pertinência: `personal → assigned OU channel em inbox_channels`; `department → inbox_id=? OU (inbox NULL + channel vinculado)` (inbox autoritativo). `UNION ALL` por caixa + dedup + `ORDER COALESCE(last_msg,created) DESC`.
- **Três abas na caixa** (partição por status, sem sobreposição): **Em atendimento** (`open`+`waiting_customer`+`waiting_internal` — a bola está com a equipe), **Aguardando** (`new` — fila de chegada: conversas novas, as que **saíram do bot** e as **transferidas de setor**) e **Concluído** (`resolved`+`closed`). `Conversation::listFilterSql()` monta o filtro; `statusMapForInboxes()` + `GET /api/conversations-status` dão o status real das conversas para o LiveFeed decidir entre "saiu do filtro" e "conversa finalizada" (só `closed/resolved/spam` geram toast e fecham o painel).
- **Contador das abas:** "Em atendimento" e "Aguardando" mostram a quantidade (`Conversation::countsByTabForInboxes()` — COUNT por status com o mesmo critério de pertinência e os mesmos filtros da listagem, respeitando a caixa e a busca); "Concluído" **não** tem contador. O contador some quando 0. O título da lista ("Caixa de Entrada") não tem mais contador. Atualização ao vivo: `GET /api/conversations-counts` (JSON `{active, waiting}`), buscado pelo LiveFeed no mesmo ciclo do poll de 10s. Como a coluna da lista tem 380px, as abas são só texto (sem ícone) para caber com os contadores.
- **Ciclo de vida na caixa:** conversa chega → `new` (**Aguardando**) → atendente assume (`assign` → `open`) ou responde (auto-atribui + `waiting_customer`) → **Em atendimento** → bot assume/encerra (`waiting_*` → `new` ao final do fluxo, tag `Aberto`) → volta para **Aguardando**; transferida de setor → `new` (**Aguardando**) de novo.
- **Atualização da listagem (caixa):** LiveFeed faz **poll a cada 10s** (`GET /api/conversations?fstatus=…`) e re-renderiza só os itens que mudaram; a lista inteira é re-renderizada a cada **60s** (`GET /api/conversations-list`) e quando a aba volta ao foco (`visibilitychange`); o SSE (`/realtime/events`) empurra `conversation_new`/`message_incoming` na hora; o SLA repinta os chips a cada **15s**; contadores de não lidas a cada **15s** (badge por caixa) e resumo a cada **30s**.
- **Item da lista é renderizado em dois lugares — e precisa ser idêntico:** PHP (`app/Views/inbox/_conv_list.php`) e JS (`LiveFeed._createItem()` em `public/assets/js/app-enhancements.js`). Antes, o JS mostrava "nome — empresa" e escondia o protocolo enquanto o PHP mostrava "nome #protocolo" + a loja da conversa no preview: a linha **mudava sozinha** quando o LiveFeed atualizava o item. Agora os dois geram o mesmo HTML (protocolo, loja/unidade da conversa, canal, grupo, tags, status, responsável e link preservando os filtros da aba). O texto de preview também é o mesmo (`conversation_preview_text()` no PHP × `_previewText()` no JS, com extração de payload JSON).

### 6.2 Operações na conversa (`POST /inbox/{id}/*`)
- `messages` (enviar texto/mídia/canned/macro, outbound), `settings` (silenciar/assinatura), `subject`, `unit` (loja/unidade), `assign` (assumir), `transfer` (departamento/usuário + resolve inbox destino, **devolve a conversa para a fila como `new` — entra em "Aguardando"**, reseta aviso fora-horário, para fluxo, notifica), `status` (`new/open/waiting_customer/waiting_internal/resolved/closed/spam` + `close_reason/description`; grupo não pode `resolved/closed/spam`; `resolved/closed` dispara CSAT), `priority` (`baixa/normal/alta/urgente`), `tags`/`untag`, `notes` (nota interna amarela, suporta `@numero` p/ menção — match por nome removido), `messages/{mid}/edit|delete|reaction|retry`, `read`, `snooze` (`until` ou cancela), `merge` (funde 2 conversas: move msgs+tags, origem=closed), `macro` (aplica macro multi-mídia + ações), `bulk` (massa: atribuir/fechar/spam/prioridade/snooze, ignora grupos em close).

### 6.3 Envio, mídia, assinatura, grupos
- Exige texto OU arquivo; valida antes de salvar upload (não deixa órfão). Arquivo+texto = 1 msg (texto vira caption). Nota interna nunca leva anexo. Texto puro: nota sem assinatura; outbound com assinatura se canal WhatsApp + `signature_enabled` (custom ou `*Nome*`, normaliza 1 quebra). Suporta `reply_to` (valida mesma conversa), `mentions[]` (só outbound texto), vCard (`type=contact {name,phone}` validado antes).
- Grupo: só texto com `@menções` via `sendGroupConversationMessage()`; demais tipos retornam erro.
- 1:1 não-WhatsApp = `delivered=true` direto; WhatsApp via `sendOutbound()`; falha → `delivery_status=failed` + msg (`Reconecte...` se desconectado senão `Provedor não confirmou...Use Tentar de novo`). `retry` só `outbound+!internal+sem channel_message_id`.
- Gravar/enviar áudio: `public/assets/js/audio_recorder.js` + transcrição sob demanda.

### 6.4 Edição / exclusão / reação
- Agente: só própria msg (`user_id=self`), `!deleted`, `type text|internal_note`; delete = soft `deleted_at`; tenta espelhar no WhatsApp com `try/log` (falha graciosa). Reação: append `{emoji,user_id,created_at}`, keep 20, `updated_at=NOW` p/ polls.
- Cliente webchat: só `mesma conversa+inbound+text+!deleted`; reação toggle por `{emoji,from=contact}`; bloqueado em `system|internal_note`.

### 6.5 SLA
`Services/SlaService.php`, `Services/SlaClock.php`, `GET /api/sla/config?inbox_id`, admin global `POST /settings/notifications` + por caixa `/inboxes/*`.
- Default: `enabled=1, business_hours=1, attention=5min, alert=10min`, cores `normal #dcfce7/#166534, attention #fef3c7/#92400e, alert #fee2e2/#991b1b`, sons `default/soft/sharp/silent`.
- `levelFor(waiting,status)`: `disabled/final→none; >=alert→alert; >=attention→attention; new→normal else none`. Por caixa: `sla_use_global` (NULL=tudo global) senão valida `attention>=1, alert>=attention+1`.
- **Relógio em segundos úteis** (`SlaClock::elapsedInSchedule`, puro e sem banco): o relógio só conta o tempo dentro da grade do **departamento** (`business_hours` + `departments.business_hours_timezone`). `SlaService::applyClock()` reescreve `waiting_seconds` nas listagens e acrescenta `sla_frozen`, `sla_paused_reason` (`customer`/`internal`/`closed`/`out_of_hours`), `sla_boundary_at` e `sla_sync_at`.
  - Âncora: `last_inbound_at` (ou `created_at` se o cliente nunca escreveu).
  - Pausa: **atendente HUMANO** falou por último (`last_human_id > last_inbound_id` → `customer`); status `waiting_internal` (→ `internal`); status fora de `new/open/waiting_customer/waiting_internal` (→ `closed`); fora do horário (→ `out_of_hours`).
    - "Humano" = mensagem `outbound` com `user_id` preenchido. Mensagem de **bot/fluxo**, de automação ou de sistema **não** conta (mesma regra de `SlaMeasurement::isHumanReply`): sem isso, a conversa que chega na fila por um fluxo ficava com o relógio congelado desde a última fala do bot e nunca entrava no estado "SLA estourado". A âncora é calculada em SQL por `Conversation::listBaseSql` (`last_in.last_human_id`) e exposta como `sla_last_human_id`.
  - Sem grade (departamento sem `business_hours_enabled` ou sem regras) => tempo de relógio puro. Toggle global `sla_business_hours` (default ligado) desliga o uso da grade.
  - Tick do navegador: `data-sla-sync-at` + `data-sla-frozen` + `data-sla-boundary` em `app/Views/inbox/_conv_list.php`; `SlaManager._elapsedSinceSync()` só soma tempo enquanto o relógio corre e limita na próxima virada da grade.

### 6.6 SLA — medição e compliance

Além das cores e sons (6.5), o sistema **mede** os dois SLAs de cada conversa e guarda o resultado.

- **Dois SLAs:** *primeira resposta* (1ª mensagem do cliente → 1ª resposta **humana**; bot com `user_id` nulo não conta) e *resolução* (segundos úteis com a bola com a gente, até o encerramento).
- **`app/Services/SlaClock.php`** — matemática pura, sem banco: `elapsedInSchedule($grade, $tz, $from, $to)` (segundos dentro das janelas abertas), `isOpenAt()`, `nextBoundary()`.
- **`app/Services/SlaMeasurement.php`** — o motor. `measure()` é **puro** (o histórico é a única fonte da verdade: rodar duas vezes dá o mesmo número). `forConversation()` carrega conversa/mensagens/linha do tempo/grade do banco; `loadTimeline()` lê os eventos `status_changed`; `saveMeasurement()` grava.
  - Âncoras: `last_inbound_at` (1ª mensagem do cliente) e a última inbound desde a última resposta humana (o período em aberto).
  - Pausa: `waiting_internal` (bola com outro setor) desconta o segmento do relógio — vale para os dois SLAs.
  - Limites **snapshotados** na linha: mudar a config não reescreve o histórico.
  - Reabertura (`resolved|closed|spam` → ativo) zera o ciclo (`ConversationSla::reset()`, `metadata.cycle_started_at`).
  - A espera em aberto **conta** e pode violar em tempo real (é o que o worker detecta).
- **`app/Models/ConversationSla.php`** — tabela `conversation_slas` (uma linha por conversa): `response_seconds/at/breached/threshold`, `resolution_seconds/breached/threshold`, `period_started_at`, `pending_message_id/pending_alerted_message_id/pending_seconds/pending_breached/pending_threshold`, `metadata`. Consultas: `criticalNow()`, `compliance($dias)`, `agentBreakdown()/departmentBreakdown()/inboxBreakdown()`, `violatedConversations()`.
  - **Reservas atômicas:** `claimResponseBreach()` (0→1 da 1ª resposta, uma vez por conversa) e `claimPendingBreach()` (alerta de cliente sem resposta, uma vez por **mensagem do cliente**). Dependem do retorno de `Database::execute()` (`rowCount()`): sem ele a reserva nunca confirmava e o escalonamento **não disparava** em conversas que já tinham linha de SLA.
- **Worker `workers/sla_worker.php`** (cron `* * * * *`, junto do `flow_worker`): mede as conversas ativas, detecta a transição `0→1` de `response_breached` (grava o evento `sla_breached`) e a violação do ciclo pendente, finaliza as encerradas e faz o backfill em lote. **Idempotente.** Sem o worker, nada é medido.
- **Escalonamento (`app/Services/SlaEscalation.php`)** — dispara na violação da **1ª resposta** (atendimento novo sem resposta) e na violação do **ciclo pendente** (cliente sem resposta em conversa já atendida): (1) tag "SLA violado" (`#dc2626`) ou "Sem resposta do cliente" (`#f59e0b`), (2) notificação in-app aos gerentes do departamento (`NotificationService::notifySystem()`), (3) autoatribuição ao atendente **menos carregado** (só quando não há responsável — nunca rouba a conversa de quem atende), (4) aviso por WhatsApp aos **telefones de alerta do departamento** (`departments.sla_escalation_phone`, lista). Cada ação tem toggle próprio (global + por caixa). O SLA de resolução **não** escala.
  - O alerta pendente só vale para conversas que **já tiveram resposta humana** — as que nunca tiveram são cobertas pela violação da 1ª resposta (evita alerta duplo).
  - O WhatsApp avisa **todos** os telefones cadastrados no departamento e devolve quantos receberam (`whatsapp_sent`).
- **Telefones de alerta do departamento** — card "Telefones de Alerta do SLA" em `/departments/{id}` (um número por linha; vírgula também separa) + campo opcional no modal de novo departamento. `POST /departments/{id}/sla-alerts` valida (mínimo 10 dígitos, sem duplicatas) e grava a lista em `departments.sla_escalation_phone` (`VARCHAR(255)`). O envio por WhatsApp precisa do toggle "Avisar o plantão por WhatsApp" em `/settings/notifications`.
- **Timeout de fluxo não avisa o cliente** — `FlowEngineService::handleTimeout()` devolve a conversa para `status=new` em **silêncio** (evento `flow_timeout` na linha do tempo, visível só para a equipe): nenhuma mensagem "Tempo esgotado…" é enviada ao cliente.
- **Superfícies:** chip de SLA no header da conversa (`inbox/panel.php`, re-renderizado no poll de 10s), card "SLA crítico agora" no dashboard, relatório `/reports/sla` (+ `/reports/sla/csv`).
- **Config:** `sla_response_minutes` (15), `sla_resolution_minutes` (1440), `sla_pending_response_minutes` (0 = herda o limite da 1ª resposta), `sla_business_hours` (1), `sla_escalate_managers` (1), `sla_escalate_tag` (1), `sla_escalate_autoassign` (0), `sla_escalate_whatsapp` (0), `sla_escalation_message` — globais em `settings` e sobrescritíveis por caixa em `inboxes` (NULL = herda).

### 6.7 Realtime, sons, notificações, unread
- Prefs `GET/POST /api/user-preferences` (`sound_enabled, sound_new_message/conversation/mention, browser_notif_enabled`). Sons custom `GET /api/sounds`, `POST /api/sounds/upload` (`mp3/wav/ogg/m4a ≤1MB`, máx 10/usuário), `POST /api/sounds/{id}/delete` (só dono).
- `Notification`: `mention/assigned/unread`; `NotificationService::notifyNewMessage()` escolhe `new_message x new_conversation` (grupos: menção vira `notification`, nunca `conversation_new`).
- **SSE** `GET /realtime/events` (`text/event-stream`, 55s max, poll 5s): `connected, notification, conversation_new` (filtra grupo/fechada + vê-conversa), `message_incoming` (só inbound não-grupo), `conversation_updated` (flow/tag), `unread_count`, `inbox_counts`, `typing` (≤6s), `heartbeat`. Boot em `public/assets/js/app.js` (sino, badges `unread-summary|inbox-counts|unread-conversations`, digitando).
- Polling/fragmentos: `GET /api/conversations-list` (HTML `_conv_list.php`), `GET /api/conversations|/{id}[/messages]?before=&limit=50`, `POST /api/messages/read-all|{id}/read|{id}/typing`, `GET /api/dashboard-stats|whatsapp-status|close-reasons|notifications/dropdown|user-preferences|sounds`, `POST /api/messages/{id}/transcribe`.
- Unread: `markMessagesAsRead` (`inbound+is_read=0+user!=self`), `markConversationNotificationsRead`, `markAllMessagesAsRead` (escopo inbox), contadores por inbox/departamento.

**Reimplantar:** 3 colunas (sidebar 240px + lista 380px + chat fluido), filtros status/busca/inbox, drawer lateral (contato/assunto/unidade/tags/transfer/snooze/merge/macro/PDF), composer com anexo/reply/menção/áudio/emoji, bolhas (incoming branca / outgoing azul / nota ambar tracejada / sistema pill), indicadores digitando/entrega/leitura, sons/SLA por cor.

**Cabeçalho do atendimento (`inbox/panel.php`):** só foto, nome e **protocolo** abaixo do nome. Conexão (canal) e setor (departamento) foram removidos da linha de meta — canal e departamento já aparecem nos chips do painel (seletor de assunto/unidade) e o responsável continua na meta.

---

## 7. Contatos / CRM leve

`ContactController.php`, `Models/Contact.php`, `Views/contacts/{index,form,show,pdf,pdf_full}.php`.

- `GET /contacts?search=&page=` (pag 24, stats `total/with/without/new_today`), `GET/POST /contacts/create` (exige `name`; cria phones/emails principal + tags + vínculos lojas Guild), `GET /contacts/{id}` (ficha 360º + conversas com filtros `year/month/department/status`), `GET /contacts/{id}/edit` (manager) / `POST /contacts/{id}/update` (qualquer auth atualiza; `company` só se postada p/ não apagar Guild), `POST /contacts/{id}/merge {target_id}` (move conversas, unifica phones/emails/tags/stores sem duplicar, preenche vazios, deleta origem), `POST /contacts/{id}/delete` (manager, cascata conversas), `GET /contacts/{id}/pdf` (PDF completo ou filtrado via DomPDF).
- Telefones/emails múltiplos (`contact_phones/emails {label,is_primary}`), tags N:N, `contact_stores {customer_id(cód.Guild), network_name}` (busca ao vivo Guild, vínculo aditivo).
- APIs: `GET /api/contacts/search?q=`, `GET /api/guild/stores?customer_id=`, `GET/POST /contacts/{id}/stores`.

---

## 8. Departamentos + Horário Comercial

`DepartmentController.php`, `Models/Department.php`, `Services/BusinessHoursService.php`.

- Listagem em **Configurações → Departamentos** (`GET /settings/departments`, admin) com stats `total/users/open/closed`; `GET /departments` redireciona para ela. `GET /departments/{id}` (admin) mostra membros/não-membros/conversas + `bh{Enabled,Timezone,Absence,Schedule,IsOpen}` + o card **Telefones de Alerta do SLA**. CRUD admin (`store/update` exige `name`, `color default #4A90D9`), `addUser{user_id,is_manager}/removeUser/destroy`. `GET /api/departments/{id}/users` continua liberado (dropdown transferência no inbox).
- **Telefones de alerta:** `POST /departments/{id}/sla-alerts` grava a lista em `departments.sla_escalation_phone` (`VARCHAR(255)`, um número por linha; vírgula/ponto-e-vírgula/quebra de linha separam). `Department::splitAlertPhones()/normalizeAlertPhones()/invalidAlertPhones()` normalizam (só dígitos, mínimo 10, sem duplicatas); número inválido volta como erro de formulário. Todos os números recebem o aviso de WhatsApp do SLA.
- `POST /{id}/business-hours {enabled,timezone(default America/Sao_Paulo),absence_message,bh[0..6]{is_open,open_time,close_time}}` (substitui 7 linhas, default `08-18h`).
- Regra `isOpen(dept)`: sem depto/desativado/sem regra → `true`; senão `is_open + H:i entre open/close` no fuso do depto. Aviso ausência: só com depto, 1x (`after_hours_notified`), pula se aberto ou fluxo ativo; envia `absenceMessage` (custom ou default); reset em troca depto/reabertura. `Flow::getBestFlowForChannel()` respeita `business_hours_only`.

---

## 9. Fluxos Chatbot (builder + engine)

`FlowController.php`, `Views/flows/{index,form}.php`, `Services/FlowEngineService.php`, `Models/Flow.php`, `workers/flow_worker.php`.

### 9.1 Builder
- `GET /flows` (lista + `node_count`), `GET/POST /flows/create|{id}/edit` (manager; `update` apaga e recria nós), `POST /{id}/publish|duplicate|delete`, `POST /api/flows/upload-media` (mídia → `uploads/flows/`, retorna `{url,name,size,mime,path}`; `path` obrigatório p/ Uazapi ler disco).
- Validação: exige `start`; `menu|button_list|list_menu` ≥1 opção com label; `condition` exige `variable+operator+≥2 opções`; `delay 0-3600s`; `day_of_week days[]+≥2`; `time_range start+end+≥2`; `assign_*|add_tag` exige id; `notify` ≥1 fone ≥10 dígitos + template + canal; `image|audio|video|send_file` exige `file_url` válida.
- Frontend: canvas 6000x4000 pan/zoom/drag, 23 tipos em 6 grupos (`flow: start/end/finish`, `msg: message/button_list/list_menu`, `media: image/audio/video/send_file`, `interact: menu/question/collect_field/guild_select`, `logic: condition/day_of_week/time_range/delay`, `action: assign_department/assign_user/add_tag/handoff/notify`), modal por tipo, conexões `options.next + guildEdges`, seed `start→message Olá!...`.

### 9.2 Execução
- `start(conv,flow)`: só `is_active`; `timeout=NOW+flow_timeout_minutes(default 30)`; salva estado + evento `flow_started` + executa `start→goToNext`.
- `handleCustomerMessage()`: sem estado ignora; expirado → `handleTimeout()`; roteia `menu|button_list|list_menu→handleMenuResponse` (match `label==resp OU value==resp OU sort_order+1==int`, inválido reapresenta), `guild_select→handleGuildSelectResponse`, `question|collect_field→handleQuestionResponse`.
- `executeNode`: `message` (template+envia+avança), `menu|button_list|list_menu|question|collect_field|guild_select` (apresenta + aguarda), `image|audio|video|send_file` (monta meta `file_path` prioritário senão `uploads/...` + caption template), `delay` (grava `delayUntil` em `timeout_at`, não avança — worker retoma), `condition/day_of_week/time_range` (decisão binária `options[0]:options[1]`), `assign_department/user` (atualiza + evento + resolve inbox), `add_tag`, `notify` (envia template via WhatsApp por fone), `handoff` (encerra p/ humano), `end|finish` (encerra; `finish` marca `Aberto` se não final).
- Qualquer ação humana (responder/atribuir/transferir/mudar status) para fluxo (`completeFlowState(false)` + remove tag `Fluxo`).

### 9.3 16 operadores de condição
`equals, not_equals, contains, not_contains, starts_with, ends_with, greater_than, less_than, greater_equal, less_equal, regex, in, not_in, empty, not_empty` (+ `default→equals`) = 16 comportamentos (`trim`, `equals` case-insens, `in` lista vírgula).

### 9.4 Templates, timeouts, versionamento, async
- Templates: `{{contact.name,first_name,email,phone,company,document,department.name/color,agent.name/email,conversation.subject/id/protocol,date,time,greeting,csat.link}}` + legados `{nome,email,telefone,departamento,atendente,empresa,protocolo,data,hora,saudacao}`; mídia só caption.
- Timeouts: `flow_timeout_minutes` em `flow_settings`; `handleTimeout()` loga + evento `flow_timeout` + encerra. Tags sistema `Fluxo(#8b5cf6)/Aberto(#22c55e)` gerenciadas em `saveFlowState/completeFlowState`.
- Versionamento: `createNewVersion` (MAX+1, desativa antiga, copia nós com `nodeMap`), `publishVersion` (despublica irmãs) — **sem rota/UI exposta**, só `duplicate (cópia)` + `publish`. Reimplantar UI se necessário.
- Async: `processPendingDelays()` (`is_active + timeout<=NOW` + `current==delay` → avança + renova timeout), `processPendingRetries()` (backoff `60*2^retry`, `max_retries`), `cleanupOldStates()` (só segunda, `90d` states/logs, `answers 180d`).

---

## 10. WhatsApp (provedor + grupos)

`WhatsAppController.php`, `WhatsAppGroupController.php`, `Services/WhatsApp/* (Manager, WahaProvider, UazapiProvider, Service)`, `Views/whatsapp/{groups,group_show}.php`.

- **Providers:** registry `waha=>WahaProvider, uazapi=>UazapiProvider`; `evolution/twilio` só citados (adicionar classe + registrar se preciso). **Evolution API não implementada.**
- **Conexão (admin):** `POST /whatsapp/{channelId}/connect {instance_name?}` → cria sessão (WAHA `POST /api/sessions`, Uazapi `POST /instance/create` + `fetchInstances` p/ token), retorna `{qr_code dataURI, status:waiting_qr}`; polling `GET /whatsapp/{id}/status` (WAHA `WORKING→connected, STARTING/STOPPED/SCAN_QR→waiting_qr, FAILED→error` + `me.id→phone`; Uazapi `owner` prioritário); `POST /whatsapp/{id}/disconnect`.
- **Webhook público sem auth:** `POST /webhooks/whatsapp` (rate-limit 120/min/IP, log só bytes, sempre `200 {ok:true}`). Cadastro: WAHA `PUT /api/sessions/{s} {webhooks:[{url: APP_URL+/webhooks/whatsapp, events: message,reaction,edited,revoked,session.status}]}`; Uazapi `POST /webhook {url,enabled,events: messages,connection,qrcode}`. Expor HTTPS pública (ngrok/prod).
- **Envio:** WAHA `/api/sendText|sendImage|sendVoice|sendVideo|sendFile` (+ `/send/buttons|List|Buttons`, vCard `/sendContactVcard`), fallback `@c.us→@lid`, anti-ban `sendSeen/typing`; Uazapi `/send/text|media|menu|contact` (base64 data-URI, prefere `path` local), `replyid`, `mentions`. Citação `reply_to`, botões/listas, vCard (trunc 2000), contexto grupo `{group_jid,participant,mentioned,group_name}`.
- **Recebimento:** filtra `fromMe/wasSentByApi`, normaliza `@c.us/@lid/@g.us/:device`, extrai mídia/reação/edição/revoke/vCard/citação, aprende `lid<->phone` (`whatsapp_lid_map`) + nomes (`whatsapp_contact_names`).
- **Grupos:** aprendizado passivo + `fetchGroupParticipants`; lista `GET /whatsapp/groups` (+ `?search=`), detalhe `GET /whatsapp/groups/{id}` (timeline), `GET .../members`, `POST .../read|alert|inbox|send|monitor|topic-window|refresh-members` (leitura auth, gestão manager). Sem `inbox` vinculado usa caixa do canal (`Inbox::resolveProfileFor`); conversa espelho `group_id` + `sender_name/phone` em `messages`; menções geram `whatsapp_group_mentions` (com `message_id`) + alerta. `openCountsByInbox` conta grupo só se `inbound is_read=0`. Lista de grupos: colunas Grupo/Conexão/Caixa/Menções(total + novas)/Alerta/Monitorado/Última msg/Ações (`WhatsAppGroup::allWithDetails` → `total_mentions` + `unread_mentions`).
- **Histórico visível, alerta só na menção:** a conversa do grupo fica na caixa como as outras (todo o tráfego aparece); **só** menção ao número da conexão ou `@todos` gera sino (destinatários: membros da caixa do grupo → caixa do canal → departamento → admins) e fica **não lida**; o resto entra como histórico lido. O grupo **não é cliente**: o contato sintético (`phone` = JID) tem `contacts.is_group = 1` e fica fora da lista/busca de clientes.
- **Gestão do grupo:** caixa onde a conversa aparece (por grupo), **Monitorado/Arquivado** (`whatsapp_groups.is_active`: arquivado deixa de espelhar e de avisar, mas mantém o histórico), **janela de silêncio** (`topic_window_hours`: após X h sem mensagem a próxima abre **nova conversa** = novo tópico), foto do grupo (`avatar_url`, exibida na caixa) e nº de participantes (`participant_count`, preenchido pelo provedor). Transferir a conversa **move a caixa do grupo** (`whatsapp_groups.inbox_id`), então o sino passa a avisar a caixa nova.
- **SLA da menção (`sla_kind = 'group_mention'`):** em grupo não existe "1ª resposta" nem "resolução" — o que se mede é o tempo entre a **menção** e a 1ª resposta humana (`messages.is_mention` marca a mensagem). Limite: `sla_group_mention_minutes` (global; 0 = herda o da 1ª resposta) + override por caixa (`inboxes.sla_group_mention_minutes`). Cada menção tem o **seu** alerta (reserva por `pending_alerted_message_id`): tag "Menção em grupo" + gerentes + autoatribuição + WhatsApp aos telefones de alerta. O relatório de compliance ignora linhas `sla_kind != 'standard'`.

---

## 11. WebChat Widget (embeddável)

`WebChatController.php`, `public/widget/chat.js` (1213 linhas) + `chat.css`, `Views/settings/demo.php`.

- Embed: `<script src="/widget/chat.js">` + `window.ATENDIMENTO_CONFIG={widgetId,apiUrl,color,position,title,welcomeMessage,avatarUrl,quickReplies,fields{name,email,phone,cnpj:{ask,require}},resumeHours}`. Painel flutuante 384px (mobile full bottom-sheet), balão, pré-atendimento (fields), `quickReplies`, resume 24h `localStorage afw_sess_`, polling/SSE, edit/delete/reaction, CSAT estrelas, avatar/cor/posição.
- APIs públicas (CORS `*`, preflight `OPTIONS`): `POST /api/webchat/session {widget_key,name,email?,phone?,cnpj?}` (cria `contact+conversation`, notifica, inicia `flow_id` ou `welcome`, retorna `{session_id=public_id,conversation_id,contact_id}`), `POST /api/webchat/resume {widget_key,session_id}` (valida não `closed/resolved`), `GET/POST /api/webchat/messages?session_id[&since]` (GET incremental + `reply_to_data`; POST inbound texto+file+reply_to), `POST /api/webchat/messages/{id}/edit|delete|reaction` (só dono), `POST /api/webchat/csat {session_id,rating 1-5}` (1 voto, `409` se repetido).
- Demo: `GET /widget/demo/{key}` (página teste com config). Config admin `/channels` (widget `{title,dept,flow,is_active,ask/require_*,avatar,color,position}`, `regenerateWidgetKey`).

---

## 12. Produtividade: Macros, Biblioteca, Assuntos, Motivos

- **Macros** (`/macros`, leitura auth, escrita manager; `Views/macros/index.php`): `Macro::getForContext(dept,user)`, `GET /api/macros`, `POST /api/macros/upload` (mídia → `macros/`). Itens multi-mídia `text/image/video/audio/file` (whitelist, pula vazios, **limite 10**), `content=primeiro texto`, ações `{status,tag_id,assign_me,transfer_inbox_id}`. `POST /inbox/{id}/macro {macro_id}` dispara N msgs + ações (status ignora grupo com try). **`transfer_inbox_id` deixa a conversa como `new`** — entra na aba **Aguardando** do destino, igual à transferência manual.
- **Biblioteca** (`/library`, escrita manager; `Views/library/index.php` 3 abas): tags (`name≤50, sem <>&"'`, única, `color`), canned (`title+content+dept+user`; `GET /api/canned-responses`), subjects (assuntos pré-definidos `name≤255 + dept + sort/is_active`). `Settings/subjects` redireciona p/ `/library`.
- **Templates:** `Services/TemplateService.php` (`render`, `renderMediaContent` só caption, `availableVariables` p/ UI).
- **Motivos de encerramento** (`/settings/close-reasons` admin + `GET/POST /api/close-reasons`): `{code slug lower alnum_,label,description,icon,color,sort,is_active}`; troca de `code` propaga em `conversations.close_reason`. Usados em `POST /status {reason,description}` (grava `closed_at` + nota `Motivo:...`).

---

## 13. CSAT

`CsatController.php`, `Services/ConversationService.php:maybeSendCsat`, `Views/csat/public.php`, `Views/reports/csat.php`.

- Global admin `POST /settings` (`csat_enabled=1, csat_message default Olá {{contact.name}}...{{csat.link}}`).
- Disparo ao transitar p/ `resolved|closed` (não spam/final/`csat_requested`/avaliado): webchat → msg `csat_request {title,prompt}` (estrelas no widget); outros → texto com `{{csat.link}}=base_url(csat/public_id)`.
- Resposta `1-5` **estrito** `^([1-5])$` só se `csat_requested` e sem avaliação → `addCsat` + inbound + zera flag + `sendCsatThanks()` (só não-webchat, convida comentário próxima msg, janela 24h).
- Público `GET/POST /csat/{token=public_id}` sem auth, rate-limit 30/10min (429 bloqueia 10min); 1 avaliação/conversa (não sobrescreve), `rating+comment` → `conversation_csats`.
- Modelo: `addCsat` upsert + evento, `getCsat`, `csatSummary{total,average}`, `csatDistribution{1..5}`, `csatRecent(25)`.

---

## 14. Relatórios (manager+)

`ReportsController.php`, `Views/reports/{index,conversations,agents,csat,timeline,timeline_pdf}.php`.

- `GET /reports?period=7|30|90|all(30)`: `{totalConversations,resolvedCount,totalMessages,avgCsat,csatCount,channelData,statusData}`.
- `/conversations`: tendência diária `{labels d/m,values}`, breakdowns status/canal/depto, top10 contatos.
- `/agents`: por ativo `{active_convos,total_messages,msgs_7d,total_resolved,resolved_30d,avg_csat,avg_response_min (inbound→outbound ≤30d)}`.
- `/csat`: `summary+distribution+recent`.
- `/timeline?granularity=day|week|month|year&from=&to=&status=&channel_id=&department_id=&contact_id=&page=&per_page=`: defaults `14/84/365/1825d`, pag `20-200(100)`, buckets `{key,label,from,to,total,open,closed,top_agent/channel,csat_avg,avg_response_min}` em lote (2 queries), comparação período anterior %, heatmap 7x24+peak; `/timeline/pdf?ids=` (limite 500, **>100 sem ids bloqueia** p/ DomPDF) + tags em lote; `/timeline/csv` (5000, BOM Excel, anti-formula-injection `'`).

---

## 15. Wiki / Base de Conhecimento

`WikiController.php`, `Api/WikiApiController.php`, `Models/WikiCategory/Article.php`, `Views/wiki/*`, `public/wiki-frontend/` (estático).

### 15.1 Admin interno
- `GET /wiki` (dashboard `categories+articles+countAll+apiBase+apiKey+portalUrl`), categorias (`/wiki/categories`, leitura auth, escrita manager: `store/update {title,slug=slugify uniq,description,icon_svg,sort}`, `delete` desvincula artigos, `reorder`), artigos (`/wiki/articles?q=&cat=&featured=`: `store/update {title,slug uniq,category_id?,description,content HTML,cover=normalizeCover,featured,sort,published_at}`, `delete`, `reorder`), upload `POST /api/wiki/upload` (manager, imagens `jpg/png/gif/webp` → `wiki/`, retorna URL absoluta).
- `GET /wiki/settings` (admin): `{apiBase,apiKey,totals,portalUrl,chat,widgets}`; `POST .../regenerate-key` (`bin2hex(32)`, lazy-cria), `POST .../chat` (`{enabled,widget_key existente, title default Atendimento, color #2f6fed, position left|right}` → `wiki_chat_*`), `POST .../portal` (`wiki_portal_url` ou `base_url/wiki-frontend/index.html`).
- Sugestão no inbox: `GET /api/wiki/suggest?q=` (auth, top 10 `{slug,title,description,category,url=portal#article/slug}`).

### 15.2 API pública (portal externo)
- CORS `*`, `GET,OPTIONS`; auth **só header `X-API-Key`** (`?api_key=` removido p/ não vazar em log), `hash_equals` com `settings.wiki_api_key`, `401` se inválida.
- `GET /api/wiki/categories` → `{slug,title,description,icon,sort,article_count}`; `GET /api/wiki/articles?category=&featured=&search=&slug=`; `GET /api/wiki/articles/{slug}` (`404` se não, registra `wiki_article_views{ip,ua,referer}`, `cover` absoluta + `content_absolute` com uploads reescritos); `GET /api/wiki/config` (`{api_base,uploads_base,counts}`); `GET /api/wiki/chat-config` (balão ChatWeb).
- **Portal estático** `public/wiki-frontend/{index.html,config.js,css/style.css,js/app,router,data,render,search,share,chat,ui.js}` + `README.md`: copiar p/ Vercel/Netlify/S3/cPanel, preencher `window.WIKI_CONFIG={API_URL sem barra final, API_KEY}`. Consome APIs com `X-API-Key`, injeta ChatWeb se `enabled`. Troubleshooting: `Portal indisponível=config`, `401=key`, `404=API_URL`, `imagens=API_URL`, `CORS=*`.

---

## 16. Configurações, Canais, Inboxes, Usuários

`SettingsController.php`, `UserController.php`, `Views/settings/*`, `Views/users/*`.

- `/settings` (admin): gerais (`csat_enabled/message, guild_api_base+token, openai_api_key` — tokens só se não-branco).
- `/settings/notifications`: prefs + SLA global + sons custom (valida `attention>=1, alert>=attention+1`).
- `/channels`: lista `channels+dept`, conexões WhatsApp, widgets webchat, flows, providers; `createChannel` (webchat→`webchat_widgets key random16`; whatsapp→`whatsapp_connections disconnected`), `updateChannel` (nome/depto/flow + widget `{title,active,flow,ask_require,avatar}` ou conexão `{provider,instance}`), `deleteChannel` (tenta `deleteConnection` gracioso), `regenerateWidgetKey`.
- `/inboxes`: CRUD + `syncInboxLinks(channels/departments/users)` + SLA por caixa (`sla_use_global`); `personal` auto-dono. Tipos `general/department/personal` + `timezone/away/greeting/sla_*`.
- Usuários (`/users*` admin; `/profile` self): `index/create/store/edit/update/destroy`; `store` valida `name>=3,email único,password>=10 + força`; `update` anti-lockout self (`admin+ativo`), senha opcional, `signature/avatar` (`avatars jpg/png/gif/webp`), sync deptos. Papéis `admin/manager/agent/viewer` (default `agent`).

---

## 17. Modelo de Dados (resumo por domínio)

Fonte `database/schema.sql` (~31 tabelas) + 44 migrations. Runner `php database/migrate.php` (`schema_migrations`, tolera `1050/1060/1061/1062/1091/1826`).

- **Identidade:** `users(id,name,email UNIQUE,password bcrypt,role,is_active)`, `user_preferences(user_id,key,value UNIQUE)`, `departments(id,name,color,is_active,business_hours_*)`, `department_users(dept,user,is_manager UNIQUE)`, `sessions(id,data,last_accessed)`, `settings(key,value: wiki_api_key,openai_api_key,SLA,CSAT)`, `audit_logs(user,action,entity,old/new JSON,ip)`, `jobs(queue,payload{handler,method,params},attempts,priority,available/reserved_at)`.
- **Contatos:** `contacts(name,email,phone,company,document,last_contact/activity_at)`, `contact_phones/emails(contact FK,label,is_primary)`, `contact_stores(contact,customer_id Guild,network_name UNIQUE)`, `tags(name UNIQUE)`, `contact_tags`, `conversation_tags`, `whatsapp_lid_map(lid<->phone)`, `whatsapp_contact_names(phone->pushName)`.
- **Canais/Inboxes:** `channels(type whatsapp/webchat,name,config JSON,dept,flow_id)`, `whatsapp_connections(channel,provider waha/uazapi,instance/token/base_url,phone,status,qr,webhook,last_*)`, `webchat_widgets(channel,widget_key UNIQUE,title,welcome,color,position,dept,flow,ask/require_*)`, `inboxes(name,type,sla_*,timezone,away/greeting)`, `inbox_departments/users/channels/business_hours/away_messages`, `business_hours`.
- **Conversacional:** `conversations(public_id UUID,protocol YYMMDD+id UNIQUE,contact,group_id,dept,assigned,channel,inbox,subject,unit,substatus,status,priority,source,closed_at/reason/description,csat_requested,msg_count_cache,last_message_at)`, `messages(conv,message_id,type,content JSON,direction,channel_msg_id,user,is_read,delivery_status,retry/next_retry,sender_name/phone,reply_to,transcription)`, `message_attachments`, `message_receipts`, `conversation_events(created/assigned/transferred/status/priority/note/flow/tag/dept/reopened/timeout + metadata JSON)`, `internal_notes`, `conversation_assignments`, `conversation_csats(rating 1-5)`, `conversation_subjects/substatuses`, `close_reasons`, `user_presence`, `notifications`, `notification_sounds`.
- **Grupos:** `whatsapp_groups(connection,group_jid UNIQUE,name,mention_alert,inbox,open_conversation_id)`, `whatsapp_group_mentions(group,message,participant,mentioned_at,is_read)`.
- **Flows:** `flows(name,channel_scope,active,priority,version/parent/is_draft,config,start_node,created_by)`, `flow_nodes(flow,node_key UNIQUE,type,title,content,config,x/y)`, `flow_options(node,label/value/sort/next)`, `conversation_flow_states(conv,flow,current,is_active,timeout/last_activity/retry)`, `flow_answers/logs/settings`.
- **Produtividade/Wiki:** `macros(+macro_items multi-mídia)`, `canned_responses`, `wiki_categories(slug,title,icon,sort)`, `wiki_articles(slug,category,title,description,content,cover,featured,views,published)`, `wiki_article_views(ip,ua,referer)`.

Tipos `messages.type`: `text/image/audio/video/file/system/internal_note/csat_request/button_list/list_menu/contact/sticker/transcription`. Status: `new/open/waiting_customer/waiting_internal/resolved/closed/spam`. Nós flow: `start/message/menu/question/collect_field/condition/assign_department/assign_user/add_tag/handoff/end/button_list/list_menu/send_file/delay/image/audio/video/finish/notify/day_of_week/time_range/guild_select`.

---

## 18. Integrações Externas

| Integração | Como funciona | O que reimplantar |
|---|---|---|
| WhatsApp WAHA | `base_url+api_key`, sessões `/api/sessions`, envio `/api/sendText|Image|Voice|Video|File|buttons|List|ContactVcard`, webhook `message/reaction/edited/revoked/session.status` | cadastro sessão + QR + status + webhook + envio com fallback LID |
| WhatsApp Uazapi | `base_url+admin_token`, `POST /instance/create`, `GET /instance/fetchInstances|status`, `POST /webhook`, envio `/send/text|media|menu|contact` (base64, prefere `path` local) | idem + leitura disco p/ base64 (URL ngrok inacessível) |
| Evolution API | **não implementada** — só ponto de extensão `Manager::$registry` | criar classe `EvolutionProvider` se preciso |
| OpenAI Whisper | `POST /api/messages/{id}/transcribe` → cache `messages.transcription`, limite `TRANSCRIPTION_MAX_MB`, `MODEL/LANG`, chave `settings.openai_api_key > OPENAI_API_KEY`, `POST https://api.openai.com/v1/audio/transcriptions` | endpoint transcrição + cache + limite |
| Guild (lojas) | `GET /api/guild/stores`, vínculo `contact_stores`, nó `guild_select` (busca ao vivo, sem credencial em `.env.example`) | ver `GuildService.php` p/ endpoint/chave |
| Mobile JWT | `JWT_SECRET`, header `Authorization: Bearer` | ver `Middleware/` |
| WebChat/Widget | chave `widget_key` = capability, CORS `*` | session/resume/messages/csat + loader JS/CSS |
| Wiki portal | `X-API-Key` + CORS `*` | categories/articles/config/chat-config + frontend estático |

---

## 19. Workers, Cron, Scripts, Deploy

- `workers/flow_worker.php` — **cron cada minuto:** `* * * * * cd /var/www/atendeflow && php workers/flow_worker.php >> storage/logs/flow_worker.log 2>&1`. Faz `processPendingDelays()` + `processPendingRetries()` + `cleanupOldStates()` (só segunda). Sai `1` em erro, log com timestamp.
- `workers/sla_worker.php` — **cron cada minuto:** `* * * * * cd /var/www/atendeflow && php workers/sla_worker.php >> storage/logs/sla_worker.log 2>&1`. Mede as conversas ativas, detecta a violação do SLA de 1ª resposta (grava o evento `sla_breached` e dispara o escalonamento), finaliza as conversas encerradas e faz o backfill em lote. Idempotente. **Sem este worker nada é medido** (nada aparece no relatório nem no KPI).
- `workers/process_jobs.php` — **daemon** (systemd/supervisor `Restart=always`, 1 réplica): loop `SELECT ... WHERE reserved_at IS NULL AND available_at<=NOW() AND attempts<max ... ORDER BY priority DESC LIMIT 1; reserva; handler->method(params); DELETE; sleep 2`. Fila genérica `jobs`.
- `scripts/bootstrap.php`: bootstrap CLI p/ scripts/migrations/tests.
- `deploy/nginx/atendeflow.test.conf`: vhost versionado; Laragon `sites-enabled/atendeflow.test.conf` só `include` ele; `nginx -t && nginx -s reload`. Prod: `root→/var/www/atendeflow/public`, `fastcgi_pass→unix:/run/php/php8.3-fpm.sock`, SSL Let's Encrypt, remover `localhost/ngrok`.
- Deploy típico: `git pull, composer install --no-dev, cp env/.env.example env/.env (DB/JWT/WAHA|UAZAPI/OPENAI/APP_URL), php database/migrate.php, chown www-data storage/public/uploads, nginx reload, cron + supervisor`.

---

## 20. APIs (referência rápida)

**Públicas sem sessão:** `POST /webhooks/whatsapp` (sem auth, 120/min/IP, sempre `200 {ok:true}`), `POST /api/webchat/session|resume`, `GET/POST /api/webchat/messages`, `POST /api/webchat/messages/{id}/edit|delete|reaction`, `POST /api/webchat/csat`, `GET /widget/chat.js|css`, `GET /widget/demo/{key}`, `GET/POST /csat/{token}`, `GET /api/wiki/*` (com `X-API-Key`).

**Internas (`auth+csrf`):** `GET /api/conversations|conversations-list|conversations/{id}[/messages]|contacts/search|global-search|guild/stores|unread-summary|inbox-counts|unread-conversations|dashboard-stats|sla/config|whatsapp-status|close-reasons|notifications/dropdown|user-preferences|sounds|canned-responses|macros|departments/{id}/users|wiki/suggest`, `POST /api/conversations|messages/read-all|messages/{id}/read|messages/{id}/transcribe|conversations/{id}/typing|template/render|flows/upload-media|macros/upload|wiki/upload|sounds/upload|sounds/{id}/delete|close-reasons*`, `GET /realtime/events` (SSE).

**Busca global (topbar, Ctrl K):** `GET /api/global-search?q=termo` → `{contacts[], conversations[]}`. Clientes por nome/e-mail/telefone/documento/empresa/loja; atendimentos por protocolo (aceita `2610-21342` → `261021342`), assunto, nome do cliente e conteúdo das mensagens. Respeita o escopo de acesso da caixa (admin vê tudo; demais: responsável, caixa que acessa ou departamento).

---

## 21. Telas (51 views)

`auth/login`, `dashboard/index`, `inbox/{index,_conv_list,show,panel,panel/_drawer,panel/_modals,new,pdf}`, `contacts/{index,form,show,_edit_drawer,pdf,pdf_full}`, `departments/{index,show}`, `flows/{index,form}`, `users/{index,form,profile}`, `macros/index`, `library/index` (tags/canned/subjects), `settings/{_tabs,channels,departments,general,notifications,inboxes,inbox_form,subjects,close_reasons,demo}`, `whatsapp/{groups,group_show}`, `wiki/{index,categories,category_form,articles,article_form,settings}`, `reports/{index,conversations,agents,csat,timeline,timeline_pdf}`, `csat/public`, `layouts/main` (sidebar inboxes+badges, topbar busca global + sino/SSE, alerta WhatsApp, sons, prefs), `errors/error`. Assets `public/assets/{css/app.css, js/app.js, js/app-enhancements.js, js/audio_recorder.js, img/*}`.

Design: Fluent desk (azul `#0078d4`, Segoe UI, 240px sidebar + 380px lista + chat fluido, cantos 4-8px, flat-by-default) + portal wiki verde serifado (`#006400`, Jakarta + DM Serif, 12px radius, 1140px) — ver `DESIGN.md`. Não misturar os dois mundos.

---

## 22. Checklist de Reimplementação em Outro Sistema

1. [ ] Modelar tabelas §17 + migrations idempotentes + seeds (admin/deptos/inboxes/tags/widget).
2. [ ] Auth sessão + papéis `agent/manager/admin` + CSRF + matriz §4 + JWT bridge se app mobile.
3. [ ] CRUD usuários/departamentos (com business-hours + `isOpen` + ausência 1x) / contatos (merge/PDF/lojas) / canais / inboxes (+SLA por caixa) / macros / library / close-reasons / wiki (categorias/artigos/chave/chat/portal).
4. [ ] Inbox: listagem com `waiting_seconds` + pertinência + contadores + triagem + todas ações §6.2 + envio com assinatura + grupos só-texto + edit/delete/reaction + snooze/merge/bulk + PDF.
5. [ ] SLA engine + sons custom + prefs + notificações + SSE + polling + unread.
6. [ ] Flows: builder 23 tipos + validação + engine por tipo + 16 operadores + templates + timeouts/tags + delays/retries (cron) + versionamento (expor UI se preciso).
7. [ ] WhatsApp: Manager + 2 providers + connect/QR/status/disconnect + webhook sem auth + envio/recebimento + LID map + grupos (aprende/vincula/alerta/envia).
8. [ ] WebChat: widget JS/CSS embed + session/resume/messages/csat + demo + config canais.
9. [ ] CSAT: disparo em `resolved/closed` + resposta estrita 1-5 + link público rate-limited + thanks + relatórios.
10. [ ] Reports: overview/conversations/agents/csat/timeline(+pdf/csv com limites) + heatmap + comparação.
11. [ ] Wiki API-key + portal estático copiável + `content_absolute` + chat-config + suggest.
12. [ ] Workers: cron `flow_worker` + cron `sla_worker` (medicao de SLA) + daemon `process_jobs` + logs + deploy Nginx + storage perms + webhook HTTPS.
13. [ ] Testes: `php tests/run.php` + manuais (inbox realtime, fluxo ponta-a-ponta, webhook, CSAT, wiki portal com key regenerada). O SLA tem suíte própria: `php tests/e2e_sla_measurable.php` (medição, worker, escalonamento — autolimpante) e `php tests/smoke_sla.php` (HTTP).. O SLA tem suíte própria: `php tests/run.php` (matemática do relógio, sem banco) e `php tests/e2e_sla.php` (end-to-end no MySQL, autolimpante).

---

## 23. Ausências / Não fabricar

- Sem `FLOW_IMPROVEMENTS.md` / `WIKI_MODULE.md` no disco (só citados em `PRODUCT.md`/`wiki/settings.php`).
- Sem `app/Controllers/Conversation*|Message*` (tudo em `InboxController` + `Api/MessagesController`) nem `public/assets/js/inbox*` (JS inline nas views).
- Sem testimonials, clientes, benchmarks, pricing, licenciamento — não constam no repo.
- Evolution API citada mas sem código.

---

*Gerado em 13/10/2026 a partir do código em `C:\laragon\www\atendeflow`. Para dúvidas de regra fina, consultar arquivos citados em cada seção.*
