# Auditoria do OminiDesk

> Varredura completa de `C:\xampp2\htdocs\atendeflow` (PHP 8.x procedural-MVC, MySQL/XAMPP, vanilla JS, sem framework JS).
>
> **Escopo:** ~17.000 linhas PHP em `app/`, 23 migrations, 17 models, 9 services, 21 controllers, 9 controllers de API.
> **Achados:** 17 críticos · 25 altos · 30 médios · 25 baixos.

---

## Estatísticas gerais

| Item | Valor |
|---|---|
| Maior controller | `app/Controllers/InboxController.php` — 941 linhas, 30+ métodos |
| Maior model | `app/Models/Conversation.php` — 763 linhas |
| Maior view | `app/Views/inbox/panel.php` — 2.273 linhas (128 KB) |
| Maior service | `app/Services/FlowEngineService.php` — 923 linhas |
| Maior arquivo JS | `public/assets/js/app-enhancements.js` — 67 KB |
| Maior arquivo CSS | `public/assets/css/app.css` — 124 KB |
| `onclick=` inline em views | ~30 ocorrências |
| `try/catch` silenciosos | ~10 |
| Endpoints sem rate limit | login + todos os POSTs autenticados |
| Endpoints sem checagem de role | ~10 (channels, inboxes, settings, flows, reports) |
| Linhas PHP totais (`app/`) | ~17.000 |

---

## 🔴 CRÍTICO

### Segurança

| # | Problema | Onde | Impacto |
|---|---|---|---|
| **S1** | `JWT_SECRET` tem default conhecido (`atendeflow-jwt-bridge-secret`) e o middleware aceita token forjado se o secret vazar | `app/Middleware/JwtMiddleware.php:54`, `env/.env:13` | Impersonation de qualquer user |
| **S2** | Token de admin do provedor WhatsApp (Uazapi) commitado em `env/.env` | `env/.env:17-20` | Controle da conta Uazapi exposto |
| **S3** | Webhook do WhatsApp valida segredo via query string `?secret=…` em vez de HMAC no header | `app/Services/WhatsAppService.php:273-281` | Injeção de mensagens falsas se URL for observada |
| **S4** | CSRF não cobre endpoints JSON autenticados (`/api/close-reasons/*`, `/api/conversations`, etc.) | `routes/web.php:194-235` | Site externo força mutações na sessão da vítima |
| **S5** | `AuthMiddleware` não checa `is_active` — usuário desativado continua logado até próxima chamada de `Auth::user()` | `app/Middleware/AuthMiddleware.php:11` | Janela de risco para ações privilegiadas |
| **S6** | XSS stored: `node.title`/`content` em template literal JS no form de fluxos | `app/Views/flows/form.php:359-381` | JS injection em outros admins |
| **S7** | XSS via `reactions` JSON em painel de conversa — JSON malicioso re-renderizado em `innerHTML` | `app/Views/inbox/panel.php:386-398` | XSS persistente |
| **S8** | Sem rate limit no login + seed `Admin@123` no schema.sql | `app/Controllers/AuthController.php`, `database/schema.sql:500-503` | Brute force viável |
| **S13** | `APP_DEBUG=true` em produção vaza stack trace com paths absolutos | `env/.env:4`, `app/Core/Router.php:87-99` | Reconnaissance |
| **S15** | `Webhook log` grava body bruto em `storage/logs/webhook.log` — sem `.htaccess Deny from all` em `storage/` | `app/Controllers/WhatsAppController.php:67-69` | Vazamento de mensagens de clientes |

### Banco de dados

| # | Problema | Onde | Impacto |
|---|---|---|---|
| **DB1** | `schema.sql` cria `conversations` sem 10+ colunas que o código usa (`unit`, `substatus`, `signature_enabled`, `snoozed_until`, etc.) — adicionadas por migrations separadas sem documentação de ordem | `database/schema.sql:210-242` | Setup do zero quebra se rodar só schema.sql |
| **DB4** | Tabela `sessions` declarada mas nunca usada (projeto usa sessões PHP nativas) | `schema.sql:474-479` | Espaço desperdiçado |
| **DB18** | Tabela `inbox_channels` referenciada em 11+ queries mas não criada em schema nem migration | `schema.sql` | Setup do zero pode falhar |
| **DB23** | Duas tabelas de horário (`business_hours` e `inbox_business_hours`) — código mistura ambas | `schema.sql` + `migrations/2026_07_27_*` | Inconsistência grave |
| **DB24** | `users.signature` é gravada/lida mas `User::find` não seleciona — `$user['signature']` sempre vazio | `app/Models/User.php:12` | **Bug**: assinatura nunca aparece |

### Bugs funcionais

| # | Problema | Onde | Impacto |
|---|---|---|---|
| **B2** | `getConversationsForInboxes` faz N+1: para 10 inboxes = 40-50 queries em série | `app/Models/Conversation.php:310-325` | Inbox com 10 caixas = >2s de DB |
| **B3** | `sendMessage` faz upload ANTES de validar — gera arquivo órfão se validação falhar | `app/Controllers/InboxController.php:300-307` | Disco cheio por lixo |
| **B12** | `RealtimeController::userSeesConversation` faz 3 queries por mensagem — em rajada de 50 = 150 queries | `app/Controllers/Api/RealtimeController.php:170-217` | SSE lento |
| **B1** | Erros fatais antigos em `storage/logs/error.log`: 20+ `Request::post()` sem argumentos no webhook (já corrigido, mas o log não foi limpo) | `storage/logs/error.log:1-30` | Cliente (Uazapi) entrou em backoff |

---

## 🟠 ALTO

### Performance

| # | Problema | Onde | Impacto |
|---|---|---|---|
| **P1** | Dashboard faz 6+ queries não cacheadas a cada load + auto-refresh de 20s. `CacheHelper` já existe e está subutilizado. | `app/Controllers/DashboardController.php:43-145` | 50 atendentes × refresh 20s = 30 q/s só do dashboard |
| **P2** | `getConversationsForInboxes` N+1 (mesmo do B2) | `app/Models/Conversation.php:310-325` | Cada user no inbox principal paga o custo |
| **P3** | `conversationsListFragment` roda o mesmo N+1 a cada 5-10s | `app/Controllers/InboxController.php:567-593` | Carga extra do polling |
| **P4** | SSE faz 6 queries/2s por user logado | `app/Controllers/Api/RealtimeController.php:90-115` | 100 atendentes = 300 q/s contínuos |
| **P5** | `sendMessage` faz upload antes da validação (mesmo do B3) | `app/Controllers/InboxController.php:300-307` | Arquivos órfãos |
| **P7** | Assets 67KB JS + 124KB CSS sem minificação nem `?v=hash` | `public/assets/` | Re-download a cada release |
| **P8** | `uploads/` sem `Cache-Control` | `public/.htaccess` | Avatares revalidados a cada GET |
| **P10** | `ReportsController::agents` faz 4 subqueries correlacionadas por agente | `app/Controllers/ReportsController.php:175-188` | Relatório de agentes lento |
| **P11** | `getMessages` faz `array_slice(-30)` em PHP — query retorna 1000 msgs para descartar 970 | `app/Controllers/InboxController.php:131` | "Carregar anteriores" lento em conversas grandes |
| **P12** | `openCountsByInbox` faz UNION ALL de N queries | `app/Models/Conversation.php:282-303` | N+1 na contagem por caixa |
| **P15** | `app-enhancements.js` carrega em todas as páginas (até no login) | `app/Views/layouts/main.php:231` | JS desnecessário no login |
| **P17/P18** | `Contact::all()`, `Reports::index()` retornam tudo sem paginação | `app/Models/Contact.php:100-121` | Página trava com 5k contatos |
| **P20** | `RealtimeController` segura um PHP-FPM worker por 55s | `app/Controllers/Api/RealtimeController.php:54` | 100 atendentes = 100 workers travados |

### Segurança

| # | Problema | Onde | Impacto |
|---|---|---|---|
| **S9** | Senha do seed `Admin@123` em `schema.sql` com hash bcrypt conhecido | `database/schema.sql:500-503` | Takeover imediato se instalador não rotacionar |
| **S10** | JWT aceita `alg: none` se secret vazio, sem `iss/aud/exp` curto | `app/Middleware/JwtMiddleware.php:48-66` | Impersonation se secret vazar |
| **S11** | Upload valida só por extensão (não por MIME real) | `app/Core/Helper.php:239-298` | RCE se servidor interpretar extensão errada |
| **S12** | Cookie de sessão sem `Secure` flag | `app/Core/Session.php:12-17` | MITM em HTTP |
| **S14** | Zero CSP / X-Frame-Options / X-Content-Type-Options | `public/.htaccess` | XSS stored vira stored XSS explorável |
| **S17** | Senha validada só com `min:6` | `app/Controllers/UserController.php:46-50` | "123456" aceito |
| **S19** | Rotas de `/channels/*`, `/inboxes/*`, `/settings/*`, `/flows/*` só passam por `auth+csrf` — qualquer agente mexe em SLA global, motivos, etc. | `routes/web.php:128-185` | Escalada horizontal de privilégio |
| **S20** | `InboxController::applyMacro` aceita `macro_id` arbitrário sem checagem de permissão | `app/Controllers/InboxController.php:856-887` | Execução de macro alheia |
| **S16** | Token CSRF é o mesmo para a sessão toda (sem rotação) | `app/Core/Helper.php:99-107` | Sessão sequestrada mantém token válido |
| **S18** | Email único validado com lookup antes do insert (race condition) | `app/Controllers/UserController.php:59-62` | Duplicação em concorrência |
| **S21** | `e()` aplicado em `onclick=` inline é frágil (depende de `ENT_QUOTES` em todas as interpolações) | várias views | XSS latente |
| **S24** | `var_export` em `error_log` vaza tokens | `app/Services/WhatsApp/UazapiProvider.php:395` | Token em log |

### Banco

| # | Problema | Onde | Impacto |
|---|---|---|---|
| **DB3** | FK `conversations.contact_id REFERENCES contacts(id)` sem `ON DELETE` | `schema.sql:237` | Dead lock em delete de contato |
| **DB5** | `whatsapp_connections.session_data TEXT NULL` — backup de sessão WhatsApp em texto puro | `schema.sql:144` | Tokens em backup de banco |
| **DB7** | Falta índice em `conversations.snoozed_until` (filtrado em toda query de inbox) | `db_optimization.sql:6-12` | Scan em conversas adiadas |
| **DB9** | `flow_execution_logs` cresce sem job de cleanup | `migrations/2026_07_23_flow_improvements.sql:27-43` | Disco cheio |
| **DB11** | `notifications.metadata` JSON + `JSON_EXTRACT` no WHERE sem índice funcional | `Notification.php:28`, `NotificationService.php:208-210` | Filtro de notif sem índice |
| **DB15** | `flow_nodes.node_key CHAR(36)` em schema mas controller gera `bin2hex(random_bytes(16))` (32 chars) | `schema.sql:376` vs `FlowController` | Inconsistência de tipo |
| **DB16** | `conversation_assignments.assigned_by` FK sem `ON DELETE` | `schema.sql:266-277` | Cascade de logs quebrado |
| **DB19** | Índice composto `(status, updated_at)` duplica `idx_conversation_status` | `db_optimization.sql:6-12` | Índice redundante |
| **DB20** | `Inbox::getUsers` faz LEFT JOIN complexo sem `DISTINCT` real | `app/Models/Inbox.php:81-89` | Usuários duplicados em queries |
| **DB25** | `last_contact_at` e `last_activity_at` em `contacts` com propósitos similares | `schema.sql:69` + `migrations/2026_07_23_*` | Colunas redundantes |
| **DB39** | `idx_msg_read` é `(conversation_id, direction, read_at)` mas `markMessagesAsRead` filtra por `direction, is_read` | `migrations/2026_07_31_message_receipts.sql:17` | Índice não usado |

### Bugs

| # | Problema | Onde | Impacto |
|---|---|---|---|
| **B4** | `InboxController` 941 linhas — 30+ métodos misturando inbox, mensagens, tags, macros, panel, API | `app/Controllers/InboxController.php` | Manutenção dolorosa |
| **B5** | `SettingsController` 674 linhas — channels, inboxes, SLA, subjects, motivos, business hours | `app/Controllers/SettingsController.php` | Acoplamento |
| **B6** | `FlowController::saveNodes` faz 180+ queries para fluxo de 30 nós × 5 opções | `app/Controllers/FlowController.php:183-258` | Salvamento lento |
| **B7** | `FlowController::update` deleta TODOS os nodes e recria sem transação | `app/Controllers/FlowController.php:117-119` | Fluxo fica vazio se inserção falhar |
| **B8** | `dashboard/index.php` 691 linhas com JS de chart + CSS inline | `app/Views/dashboard/index.php` | Mistura de camadas |
| **B9** | `inbox/panel.php` 2.273 linhas (128 KB) com ~700 linhas de JS inline | `app/Views/inbox/panel.php` | Carregamento lento, difícil de manter |
| **B10** | `getUnreadConversationsCount` monta SQL enorme com OR concatenado em loop | `app/Models/Conversation.php:682-762` | Plano ruim, possível `max_allowed_packet` |
| **B11** | `NotificationService::resolveRecipients` notifica admin 2x em alguns casos | `app/Services/NotificationService.php:136-178` | Duplicação |
| **B13** | `RealtimeController::events` segura conexão por 55s + 6 queries a cada 2s | `app/Controllers/Api/RealtimeController.php:90-115` | Carga do DB |
| **B15** | `ReportsController::agents` faz 4 subqueries correlacionadas por agente | `app/Controllers/ReportsController.php:175-188` | 50 atendentes = ~250 subqueries |
| **B17** | `Notification::getByUser` usa `JSON_UNQUOTE(JSON_EXTRACT(...))` no JOIN | `app/Models/Notification.php:28` | 50 notifs = 50 parseamentos JSON |
| **B18** | `MessagesController::markRead` valida outbound mas não checa ownership da conversa | `app/Controllers/Api/MessagesController.php:31-52` | Info disclosure via ID probing |

---

## 🟡 MÉDIO

### UX/UI

| # | Problema | Onde |
|---|---|---|
| **U1** | Confirmações de exclusão com `confirm()` nativo do navegador em 6+ views | `contacts/index.php:108`, `users/index.php:112`, `library/index.php:71,155`, `flows/index.php:75`, `inboxes.php:67`, `channels.php:98` |
| **U2** | Erros de validação no formulário sem feedback inline — `Session::setFlash('errors', $errors)` é gravado mas templates (`users/form.php`) não renderizam | `UserController.php:52-57`, `ContactController` |
| **U3** | `sendMessage` retorna erro genérico sem distinguir tipo/tamanho/extensão | `InboxController.php:310-315` |
| **U6** | Nenhuma página tem breadcrumb visível | todas |
| **U7** | Dashboard auto-refresh pisca a cada 20s (animação `stat-pulse`) | `dashboard/index.php:689` |
| **U8** | Modais sem `aria-modal`/`role="dialog"`/focus trap/Esc | `inbox/panel.php:685-811` |
| **U10** | `alert('Erro ao enviar mensagem…')` em vez de `toast()` | `inbox/panel.php:2059` |
| **U11** | `inbox/show.php` (50 KB) duplica boa parte de `inbox/panel.php` | `InboxController.php:226-265` |
| **U14** | `contacts/index.php` e `inbox/index.php` não têm empty state bom quando busca não retorna | várias |
| **U15** | Notificação nativa do browser sem fallback se permissão negada | `inbox/panel.php:591-595` |

### Banco

| # | Problema | Onde |
|---|---|---|
| **DB2** | `close_reasons` em migration conflita com `macros` em schema.sql | `schema.sql:430-441` vs `2026_07_31_close_reasons.sql` |
| **DB6** | `webchat_widgets.ask_name`/`require_name` no schema sem uso consistente | `WebChatController.php:43-48` |
| **DB10** | Trigger `trg_messages_after_delete` tem race condition em deletes concorrentes | `db_optimization.sql` |
| **DB12** | `tags.color VARCHAR(7)` — comparação precisa de binary collation | `schema.sql` |
| **DB22** | `conversations.source VARCHAR(50)` sem validação de tamanho | `ConversationService.php:30` |
| **DB26** | `users.role ENUM` mas `viewer` não é usado para limitar UI | `Auth.php:88-93` |
| **DB27** | `user_preferences` UNIQUE + `UserPreference::set` pode falhar silenciosamente | `UserPreference.php:34` |
| **DB28** | `webchat_widgets.welcome_message TEXT NULL` — null vira "null" no JS | `widget/chat.js` |
| **DB29** | `messages.reactions` JSON sem limite de tamanho | `Conversation.php:517-525` |
| **DB30** | `conversations.close_reason` VARCHAR guarda `code` de `CloseReason` sem FK | `schema.sql` |
| **DB31** | `webchat_widgets.widget_key CHAR(36)` mas código gera 32 chars | `schema.sql:165` |
| **DB32** | `flow_options.config JSON` — `FlowController::update` não copia `config` ao regenerar nós | `FlowController.php:117-119` |
| **DB33** | `users.signature` TEXT sem índice | `User` model |
| **DB36** | `contacts.idx_contacts_name` não ajuda `LIKE '%…%'` | `schema.sql:74` |
| **DB37** | `schema.sql:430-441` cria `macros`; `2026_07_17_macros_table.sql` cria outra | duas definições |
| **DB38** | `flow_settings` e `settings` são tabelas de chave-valor redundantes | duas migrations |
| **DB40** | `flow_nodes.config` JSON — `Flow::getNodes` decodifica em PHP | `Flow.php:162-164` |
| **DB41** | `inbox_departments` UNIQUE ausente — pode ter duplicatas | migrations |
| **DB42** | `conversations.priority ENUM` vs `flows.priority INT` | `schema.sql` vs `migrations/2026_07_23_add_flow_priority.sql` |

### Code smells

| # | Problema | Onde |
|---|---|---|
| **A1** | Lógica SQL em controllers em vez de models (channels, show, panel) | `SettingsController.php:18-58`, `InboxController.php:236-265` |
| **A2** | Services misturam orquestração com queries diretas | `ConversationService.php:40-99` |
| **A3** | Models anêmicos (`User::all`, `Macro::all` — só getters) | `app/Models/` |
| **A6** | `ConversationService` 460 linhas, 9 métodos, múltiplas responsabilidades | `app/Services/ConversationService.php` |
| **A7** | `FlowEngineService` 923 linhas, 20+ métodos | `app/Services/FlowEngineService.php` |
| **A8** | `WhatsAppService` 1154 linhas | `app/Services/WhatsAppService.php` |
| **A9** | 9 controllers API com `if (!Auth::id()) View::json(['error' => 'Não autenticado'], 401)` repetido 5x | `app/Controllers/Api/*` |
| **A11** | Inconsistência camelCase vs snake_case (DB snake, PHP arrays snake, vars camel, JS camel) | projeto inteiro |
| **A12** | `app/Core/Helper.php` mistura helpers de formatação com helpers de domínio | `Helper.php` (677 linhas) |
| **A15** | Validação espalhada entre `Request::validate` e checagens inline | vários controllers |
| **A20** | `SettingsController::collectInboxSla` 50+ linhas — devia estar em `SlaService::fromRequest()` | `SettingsController.php:491-532` |
| **M2** | Constantes mágicas: status enum repetido 30+ vezes em vez de `ConversationStatus` enum | projeto inteiro |
| **M3** | Configurações hardcoded que deviam ser `.env`: `PDO::ATTR_TIMEOUT => 5`, `10*1024*1024` upload max, `POLL_INTERVAL=2` | vários |
| **M6** | Backup files em produção: `app/Core/Database.php.backup`, `app/Config/Settings.php.backup` | `app/Core/`, `app/Config/` |
| **M8** | Zero testes (sem `phpunit.xml` ou `tests/`) | projeto inteiro |
| **M9** | PHPDoc inconsistente | vários |
| **M12** | Nomes confusos: `Flow::createNewVersion` (cria rascunho) vs `Flow::publishVersion` | `Flow.php:46,107` |
| **F1** | `onclick=` inline em 30+ lugares — quebra CSP e dificulta manutenção | `inbox/panel.php` e várias views |
| **F2** | Variáveis globais em `window`: `CONV_ID`, `API`, `cannedResponses`, `notifyCount`, `__enhancements` | `inbox/panel.php`, `layouts/main.php` |
| **F3** | `decorateDates()` re-renderiza innerHTML de datas — XSS latente | `inbox/panel.php:1477-1482` |
| **F4** | `composerSuggest` faz `innerHTML` com template sem escape suficiente | `inbox/panel.php:1957-1961` |
| **F5** | Falta debounce em alguns inputs (sortTable, tagCreateInput) | várias views |
| **F6** | Fetch sem tratamento de erro em 6+ lugares (`.catch(function(){})` silencioso) | várias |
| **F7** | Memory leaks: event listeners não removidos em DOM dinâmico | `inbox/panel.php` |
| **F8** | Polling sem `clearTimeout` (dashTimer global, nunca limpo) | `dashboard/index.php:689` |

### Rotas

| # | Problema | Onde |
|---|---|---|
| **R1** | Rotas duplicadas para `/inbox` e `/inbox/{id}` — wildcard casa `/inbox/new` antes de rota específica | `routes/web.php:43-51` |
| **R4** | Inconsistência de nomenclatura de paths: kebab vs camel vs snake (`/api/dashboard-stats` vs `/api/conversations-list`) | `routes/web.php` |
| **R7** | Rotas API não têm CSRF (vide S4) | `routes/web.php:186-228` |
| **R11** | `POST /settings` salva SLA sem `admin` ou `manager` middleware | `routes/web.php:73` |
| **R15** | `/reports/*` sem restrição admin — agents veem relatório completo de outros | `routes/web.php:178-182` |
| **R18-R19** | `POST /settings/subjects/*` e `POST /settings/close-reasons/*` sem checagem de role | `routes/web.php:170-176` |
| **R20** | `User::update` permite ao próprio user mudar `role` — se admin promover outro e desativar a si mesmo, fica sem admin | `UserController.php:103-140` |
| **R27** | `/csat/{token}` aceita UUID/string sem rate limit — brute force possível para adivinhar tokens | `routes/web.php:33` |
| **R28** | `/webhooks/whatsapp` sem rate limit e sem verificar IP do provedor | `routes/web.php:37` |

---

## 🟢 BAIXO

| # | Problema | Onde |
|---|---|---|
| **M7** | `.gitignore` precisa cobrir `env/.env`, `storage/logs/*.log`, `*.backup` | raiz |
| **M11** | `app/Config/Settings.php::$config` é estático sem mecanismo de invalidação externa | `app/Config/Settings.php:12` |
| **M14** | `app/Models/Contact.php:7-323` — 200+ linhas são helpers, devia estar em `app/Helpers/PhoneHelper.php` | `Contact.php` |
| **M16** | `app/Core/Database.php:142-159` — `insert/update/delete` aceitam nome de tabela como string sem whitelist (foot-gun) | `Database.php` |
| **M17** | `app/Config/Environment.php:22-52` — parsing manual de `.env` não suporta escape, multiline, etc. | `Environment.php` |
| **M18** | `app/Config/Settings.php:42-52` — `parseEnvValue` só detecta booleans e numbers | `Settings.php` |
| **M19** | Regex `@([\w\s]+)` ganancioso em `processMentions` — captura até fim da frase + N queries | `ConversationService.php:117-131` |
| **M20** | `Notification` model e `NotificationService` com acoplamento circular | `Notification.php`, `NotificationService.php` |
| **M28** | `getConversationDetail` (private) vs `conversationPanel` (public) — nomenclatura inconsistente | `InboxController.php` |
| **M30** | Match expression aninhado de 9 linhas em `handleConnectionEvent` | `WhatsAppService.php:731-740` |
| **F4** | `getSlashToken` regex `\p{L}\p{N}` é ES2018+ — sem fallback para browsers antigos | `inbox/panel.php:1928-1929` |
| **F18** | `node.id` cru em template literal no form de fluxos (XSS latente) | `flows/form.php:359-381` |
| **F21** | `getSlashToken` regex Unicode sem fallback legacy | `inbox/panel.php:1928-1929` |
| **F29** | `lastMid` no `loadOlder` é variável sem `let`/`var` no contexto do escopo — leak global | `inbox/panel.php:2188` |
| **F30** | `onclick="sortTable(0)"` global em `contacts` quebra se JS externo não carregou | `contacts/index.php:38` |
| **B16** | `Flow::all` faz subquery `COUNT` por flow (20 flows = 20 subqueries) | `Flow.php:25-34` |
| **B19** | `applyMacro` não checa permissão sobre a macro | `InboxController.php:856-887` |
| **B20** | `User::all` faz `GROUP_CONCAT` + subquery count + JOIN | `User.php:25-36` |
| **B22** | `FlowController::saveNodes` valida nós mas ignora `next_node_id` se nó não existe no front | `FlowController.php` |
| **B27** | `BusinessHoursService::ruleFor` faz 2 queries (dept→global) em vez de uma com `ORDER BY department_id IS NULL` | `BusinessHoursService.php:60-72` |
| **B28** | `InboxController::bulk` chama `changeStatus` em loop sem transação | `InboxController.php:822-854` |
| **B30** | `InboxController::chatbot` só redireciona — método morto desde remoção da aba | `InboxController.php:204-207` |
| **B31** | `Flow::parent_flow_id` permite NULL mas nome sugere relação | `Flow.php:50-55` |
| **B36** | `array_slice($allMessages, -30)` em PHP em vez de `LIMIT` no SQL | `InboxController.php:131` |
| **B40** | `flow_nodes.config` JSON — `Flow::getNodes` decodifica em PHP | `Flow.php:162-164` |
| **B44** | `CsrfMiddleware::generateToken` é método público mas `core/Helper.php` já tem `csrf_token()` — duplicado | `CsrfMiddleware.php:26-31` |
| **B47** | `Conversation::getInboxConversations` retorna TUDO sem LIMIT | `Conversation.php:47-122` |
| **B48** | `Contact::all()` retorna todos os contatos sem LIMIT | `Contact.php:100-121` |
| **B49** | `View::back()` redireciona para `referer` externo (open redirect potencial) | `InboxController.php:315` |
| **B50** | Comparação `$target == $today` (DateTime == DateTime) — hábito arriscado | `Helper.php:172,185` |
| **B51** | `Auth::user()` chama `Session::get` e `User::find` toda vez | `Auth.php:35-43` |
| **B52** | `Notification::getForDropdown` faz N+1 (1 query por notificação para puxar conversa) | `Notification.php:104-127` |
| **B56** | `SlaService::set` ignora silenciosamente keys inválidas | `SlaService.php:104-110` |
| **B57** | Comparações soltas (`==`) em várias views | `layouts/main.php:98,110`, `inbox/panel.php`, `inbox/show.php`, `contacts/show.php`, `library/index.php`, `flows/form.php` |
| **B58** | `@file_put_contents` (silencia erro) sem fallback | `WhatsAppService.php:786` |
| **B59** | Regex `'#^#[0-9a-fA-F]{3,7}$/'` aceita `#fff` (3) e `#fffff` (5), mas `#ffff` (4) é RGB expandido | `SettingsController.php:657-660` |
| **B60** | `try { error_log(... var_export(...)); } catch(\Throwable $e) {}` — try dentro de error_log | `UazapiProvider.php:395` |
| **B61** | `inbox/panel.php` ~30 ocorrências de `onclick=` inline | `inbox/panel.php` |
| **B62** | `WhatsAppConnection` mistura instância, conexão e provider num único model | `WhatsAppConnection.php:11-50` |
| **B63** | `NotificationService::resolveRecipients` faz N+1 em admins | `NotificationService.php:160-178` |
| **B64** | `conversations.snoozed_until` sem índice | `db_optimization.sql` |
| **B65** | `Flow::completeFlowState` chama `Conversation::find` só para checar status | `Flow.php:380` |
| **B66** | `getConversationDetail` chama `markMessagesAsRead` que faz UPDATE em todas as mensagens unread | `InboxController.php:541` |
| **B67** | `SettingsController::channels` chama `Flow::all()` e `Department::all()` (sem filtro) para formulário de canais | `SettingsController.php:43-44` |
| **B70** | `sendMessage` após `save_uploaded_file` falhar, retorna "Digite uma mensagem ou anexe um arquivo" — silencioso | `InboxController.php:309-316` |
| **B71** | `Macro::all()` retorna TUDO sem filtro | `Macro.php:9` |
| **B72** | `WhatsAppService::processa reações` faz 4 queries por cada reação | `WhatsAppService.php:329-368` |
| **P21** | `cache_widget_config => true` em `Settings.php:99` — dead code | `app/Config/Settings.php` |
| **P22** | `Setting::get` cacheia em static, limpa só via `Setting::set` | `Setting.php:11-25` |
| **P23** | `TemplateService` sem cache — parsea regex toda chamada outbound | `TemplateService.php` |
| **P24** | `FlowEngineService` retry logic sem pool de workers | `FlowEngineService.php:670+` |
| **P25** | `WhatsAppService::connectionCache` static — não thread-safe em FPM | `WhatsAppService.php:799-846` |
| **P26** | `apiCreateConversation` faz 6 queries sequenciais sem transação | `InboxController.php:593-632` |
| **P27** | `getMessages` faz subquery para reply-to executada antes do LIMIT | `Conversation.php:417-435` |
| **P28** | `audio_recorder.js` inline em `inbox/panel.php` e `inbox/show.php` — mesmo arquivo carregado 2x | `inbox/panel.php`, `inbox/show.php` |
| **P29** | `widget/chat.js` + `audio_recorder.js` concatenados em `WebChatController::widgetJs` — 56KB sem minificação | `WebChatController.php:226-230` |
| **P30** | `imagecreatefromwebp` síncrono no upload — trava request em imagem 5MB | `Helper.php:270` |
| **P31** | `CacheHelper` classe existe mas zero uso externo no projeto | `app/Core/CacheHelper.php:5` |
| **P33** | `Request::capture()` faz json_decode do `php://input` toda vez mesmo se não for JSON | `Request.php:20-30` |
| **P34** | `DashboardController::agentData` executada mesmo para `isAdmin=false` | `DashboardController.php:135-146` |
| **P35** | `getMessages` chama `enrichMessages` que decodifica `reply_to` mas não usa `message_count_cache` | `Conversation.php:417-435` |
| **DB44** | `users.avatar VARCHAR(255)` mas uploads em `public/uploads/avatars/...` — backup/restore pode quebrar | `schema.sql:14` |
| **DB45** | `messages.id BIGINT UNSIGNED` vs `webchat_widgets` usa `CHAR(36)` para public_id | `schema.sql` |
| **DB46** | `conversations.id` em URLs: `inbox/{id}/panel` — expõe cardinalidade | rotas |
| **DB47** | Sem trigger de auditoria em `users` ou `conversations` (apenas `audit_logs` table vazia) | `schema.sql` |
| **DB48** | `conversations.message_count_cache` mantido por triggers; `Conversation::addMessage` PHP não atualiza — depende dos triggers | `Conversation.php:573-587` |
| **DB49** | `inboxes.greeting_message VARCHAR(500)` — mensagens longas truncam | migration |
| **DB50** | `canned_responses` e `macros` quase idênticos — candidato a merge conceitual | `schema.sql:430-441, 443-453` |

---

## Roadmap sugerido

### Onda 1 — Segurança (1 sprint)
- Rotacionar `JWT_SECRET` e o token Uazapi, mover `env/.env` para fora do repo
- HMAC no webhook do WhatsApp
- Middleware de role nas rotas admin
- Cookie `Secure` quando HTTPS
- Remover `APP_DEBUG` em prod
- CSP + headers de segurança básicos

### Onda 2 — Bugs funcionais (1 sprint)
- Adicionar coluna `users.signature` ao `SELECT` do `User::find` (corrige bug DB24)
- Criar migration que **consolida** `schema.sql` para incluir todas as colunas usadas
- Validar `inbox_channels` (criar se faltar)
- Mover upload de arquivo para DEPOIS de validar no `sendMessage`
- Limpar código morto (`if (false)`, `chatbot`)

### Onda 3 — Performance (1-2 sprints)
- Reescrever `getConversationsForInboxes` como uma única query com JOIN
- Cachear dashboard em `CacheHelper::remember` (TTL 30s)
- Paginar `Contact::all`, `Reports::index`, `getMessages` com `LIMIT`
- Minificar e fazer fingerprint dos assets
- Reduzir polling do SSE de 2s para 5-10s

### Onda 4 — Refactor (contínuo)
- Quebrar `InboxController` em `InboxApiController` + `InboxMessageController` + etc.
- Mover `inbox/panel.php` para partials
- Trocar `onclick=` inline por event delegation
- Substituir `confirm()` por modal de confirmação
- Adicionar testes do `SlaService` + 1-2 testes críticos

---

*Auditoria gerada em agosto/2026. Revisar após cada onda para re-priorizar achados remanescentes.*
