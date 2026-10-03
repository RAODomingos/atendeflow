# Spec: Fluxo do bot Loja/Unidade Guild (teste)

- Data: 2026-10-03
- Status: design em seções aprovado pelo solicitante (abordagem A: nó dinâmico)
- Escopo: novo `node_type` + builder + seed do fluxo de teste

## 1. Contexto e objetivo

Fluxo customer-facing no motor de fluxos (`FlowEngineService`) que
identifica loja/unidade antes do atendimento. Contato **sem loja** → bot
pede o CNPJ (`collect_field`, `save_field=document`), segue no mesmo
fluxo e transfere para humano. Contato com **1 loja** → lista as unidades.
Com **N lojas** → pergunta a loja (`ID - Nome`), depois as unidades da
escolhida. Ao final grava `unit` na conversa e **segue o fluxo** para os
próximos nós. Tudo editável no construtor de fluxos.

## 2. Decisões (aprovadas)

1. Novo `node_type: guild_select` dinâmico (rejeitados: menus estáticos —
   impossível com opções por contato; seed hardcodada sem UI — viola o
   "editável").
2. Ramo sem-loja executa o nó de `no_store_node_id` sem perguntar nada.
3. CNPJ usa `collect_field` padrão e continua no mesmo fluxo (sem fluxo
   novo, sem endpoint novo).
4. Unidades sempre ao vivo via `GuildService` (nunca persistidas no nó).
5. `conversations.unit` continua texto com o `store_name`; PDFs e
   relatórios inalterados.

## 3. Runtime (`FlowEngineService`)

- `executeNode`: novo `case 'guild_select'`. Lê `Contact::getStores`.
  - 0 lojas → localiza o nó de `config.no_store_node_id` e o executa
    (segue o padrão de busca de nó por id já usado no engine).
  - 1 loja → busca unidades via `GuildService::getStores(customer_id)` e
    apresenta via `dispatchMenuPresentation` com options montadas em
    runtime (`value` = nome da unidade; loja implícita).
  - N lojas → apresenta lojas (`value` = `customer_id`, label
    `ID - Nome`); a escolha fica em resposta parcial no `flow_state` e o
    nó apresenta as unidades daquela loja.
  - Salva o estado no próprio nó (`saveFlowState`), como `menu` faz.
- `handleCustomerMessage`: novo ramo para `guild_select` que casa a
  resposta como `handleMenuResponse` (label, value ou número). Etapa
  loja→unidade: registra a loja e reapresenta. Escolha final:
  `Flow::saveAnswer` + `Conversation::update(unit)` (respeita
  `config.save_unit`, default ligado) + avança a `config.next_node_id`.
- Resposta inválida: repete com `config.invalid_message` (padrão dos
  menus). Falha da Guild (timeout, token, JSON): `config.error_message`
  + repete; o contador vive na resposta parcial do `flow_state` e na 3ª
  falha executa `handoff`.
- Timeouts: inclui `guild_select` nos tipos de interação de
  `refreshInteractionTimeoutIfNeeded` e no roteamento de resposta
  (ao lado de `question/menu/button_list/list_menu/collect_field`).

## 4. Builder (`flows/form.php`)

- Novo tipo `guild_select` na paleta e no form, com campos editáveis:
  `content` (pergunta, com variáveis `{{...}}`), labels
  (`store_prompt`, `unit_prompt`, `invalid_message`, `error_message`,
  `max_attempts` default 3), `presentation` (buttons/list/text),
  `no_store_node_id`, `next_node_id`, `save_unit` (default ligado).
- Validação: `no_store_node_id` e `next_node_id` devem apontar para nós
  existentes do mesmo fluxo; preview das 3 ramificações.
- Sem mudança no schema de nós: tudo em `content/config/options`.

## 5. Seed do fluxo de teste

Migration/seed versionada criando **"Atendimento Loja/Unidade (teste)"**
inativo: `start` → `guild_select` (`no_store_node_id` → CNPJ,
`next_node_id` → mensagem de confirmação) → ramo CNPJ: `collect_field`
(`save_field=document`) → `message` → `handoff`; ramo com loja:
`message` ("Unidade registrada: {{conversation.unit}}!") → `handoff`
(trocável por `end` no construtor).

## 6. Compatibilidade

- Canais: reaproveita `dispatchOutboundMessage` (botões/lista no
  WhatsApp quando suportado, texto numerado como fallback; WebChat igual).
- `conversation.unit` texto; `contact.document` normal; timeouts e worker
  existentes sem mudança; token ausente cai no ramo de erro configurável.

## 7. Verificação

- Scripts descartáveis RED→GREEN: ramos 0/1/N lojas, CNPJ→document,
  unidade→`conversation.unit`, erro Guild 3x→handoff, builder rejeita
  next inválido.
- `php -l`, `impeccable detect --json` 0 `warning`, manual ponta a ponta
  (WebChat/WhatsApp) nos 3 cenários + editar um texto no construtor.

## 8. Fora de escopo

- Busca Guild por CNPJ; cache de unidades; transferência com contexto
  extra; correção do cliente no meio do fluxo; testes automatizados.
