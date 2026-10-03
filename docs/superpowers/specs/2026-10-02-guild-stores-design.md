# Spec: Lojas e Unidades Guild no contato (Atendeflow)

- Data: 2026-10-02
- Status: aprovada pelo solicitante (design em seções, abordagem A)
- Escopo: vincular lojas/unidades da Guild ao contato + select de unidade no inbox

## 1. Contexto e objetivo

Atendentes precisam associar cada contato às suas lojas na Guild e, por
conversa, escolher a unidade atendida. Hoje `contacts.company` é texto
livre ("Empresa") e `conversations.unit` é texto livre digitado no painel
do inbox. A Guild expõe `GET /api/mac/customer/{id}/stores` →
`{ success, data: { network_name, stores: [{id, name}] } }`, com
autenticação por token fixo no servidor.

Mapeamento fechado com o solicitante: **loja = `network_name`**,
**unidade = item de `stores[]`**. O `{id}` é o código Guild do cliente,
digitado pelo atendente. Um contato pode ter 1 ou mais lojas, salvas no
contato. "Empresa" passa a se chamar "Loja" na interface.

## 2. Decisões (aprovadas)

1. Abordagem A: proxy server-side + tabela `contact_stores` (rejeitadas:
   JSON em `contacts.company` e chamada direta do browser com token
   exposto).
2. `contacts.company` permanece no banco como legado silencioso
   (FlowEngine, `{{contact.company}}`/`{empresa}` e merges antigos não
   quebram); a UI exibe as networks vinculadas.
3. Vincular **soma**: marcar checkboxes adiciona; desmarcar remove só as
   unidades da network exibida (nunca apaga outras lojas às cegas).
4. `conversations.unit` continua VARCHAR e recebe **só o `store_name`**
   (network visível no select via optgroup; PDFs/filtros inalterados).
5. Sem cache da API na v1 (lojas salvas no contato já são a persistência);
   sem suite automatizada nova (repo não tem; verificação manual +
   detector impeccable).

## 3. Dados

Migration `database/migrations/2026_10_02_contact_stores.sql`:

```sql
CREATE TABLE contact_stores (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contact_id INT NOT NULL,
  customer_id VARCHAR(64) NOT NULL COMMENT 'codigo Guild digitado',
  network_name VARCHAR(255) NOT NULL COMMENT 'loja',
  store_id INT NOT NULL COMMENT 'unidade',
  store_name VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_contact_store (contact_id, store_id),
  CONSTRAINT fk_contact_stores_contact FOREIGN KEY (contact_id)
    REFERENCES contacts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

`App\Models\Contact`:
- `find()` inclui `stores` (ordenadas por `network_name`, `store_name`).
- `syncStores(int $contactId, array $stores)` — transacional (espelho do
  sync de tags): insere pares novos, remove os da(s) network(s)
  exibida(s) desmarcados.
- `removeStore(int $contactId, int $storeId)`.
- `merge()` une lojas origem→destino sem duplicar (mesmo padrão de
  phones/emails); `company` segue na lista de campos herdados.

## 4. Serviço, proxy e token

`App\Services\GuildService::getStores(string $customerId): array`
(esqueleto de `WhatsAppHttpClient`: curl + fallback `stream_context`,
timeout 8s). Config via `Setting`: `guild_api_base`
(default `https://painel.guild.com.br`), `guild_api_token` (default `''`).
Header `Authorization: Bearer <token>`. Retorno normalizado
`['network_name' => string, 'stores' => [['id' => int, 'name' => string]]]`.
Exceções com mensagem PT-BR: timeout, sem token configurado, 401/403,
404 (customer inexistente), JSON inválido, `success: false`.

Rota `GET /api/guild/stores?customer_id=` (proxy; exige sessão, sem gate
de manager — agente usa; token jamais chega ao browser). Resposta JSON
`{ success, network_name, stores }` ou `{ success: false, error }`.

Token/base editáveis na tela de Configurações existente
(`SettingsController` + settings view geral).

## 5. UI do contato

- `contacts/form.php`, drawer de `contacts/show.php`, modal de
  `contacts/index.php`: bloco "Loja (Guild)": input código + botão Buscar
  → proxy → exibe `network_name` + checkboxes de unidades → salvar chama
  `syncStores()`. Estilo Fluent existente (`form-control`, foco azul,
  4–8px; sem componente novo fora do vocabulário).
- Rename de label: "Empresa" → "Loja" nos 4 pontos acima + card `cd-info`
  do show + PDFs (`contacts/pdf.php`, `contacts/pdf_full.php`,
  `inbox/pdf.php` passam a listar loja/unidades).
- Card `cd-info` (show): exibe networks distintas + unidades; mantém
  avatar/nome/tags/empresa-legado fora do card (identidade no header).
- `contacts/index.php` `data-company` passa a carregar networks (busca
  textual continua funcionando); `_conv_list.php`, `inbox/panel.php` e
  `inbox/show.php` trocam `contact_company` pela primeira network.
- `Conversation` selects que projetam `ct.company as contact_company`
  passam a projetar a primeira network em ordem alfabética
  (`ORDER BY network_name LIMIT 1` sobre `contact_stores`).

## 6. Unidade no inbox

`inbox/panel.php`: `convUnitInput` (texto) vira `Loja` (networks distintas
do contato) → `Unidade` (stores da network, `<optgroup>` por network).
Salvar reaproveita `POST /inbox/{id}/unit` com o `store_name`. Contato sem
lojas vinculadas → mantém o input texto legado (fallback, zero quebra).
Erros do proxy: toast PT-BR via mecanismo existente, sem travar a tela.

## 7. Compatibilidade

- `contacts.company`, `{{contact.company}}`, `{empresa}`, macros e fluxos:
  inalterados no backend.
- `conversations.unit`: tipo e tamanho inalterados; valores antigos
  (texto livre) continuam exibidos; ao reeditar, o select tenta
  pré-selecionar pelo `store_name`, senão cai no fallback texto.
- Merge de contatos: une lojas; nunca perde unidades.

## 8. Erros e limites

- Sem token: proxy responde `success:false` ("Token da Guild não
  configurado em Configurações"); UI orienta o admin.
- Timeout/5xx/JSON inválido: mensagem PT-BR, retry manual pelo atendente.
- `customer_id` vazio ou com caracteres inválidos: 422 local, sem chamar
  a Guild. `store_id` sempre int; nomes com `e()` em todas as views.

## 9. Verificação

- Manual: buscar código válido e inválido; vincular 2 lojas ao mesmo
  contato; unidade por loja no inbox; fallback sem loja; merge com lojas;
  PDF com loja/unidade; rename Empresa→Loja visível.
- `impeccable detect --json` nos arquivos tocados: 0 `warning`
  (advisories de rampa operacional são aceitáveis e documentados).
- `git diff --stat` revisado antes de qualquer commit (sem commitar
  `storage/cache/*` ou `wiki-frontend` sujo pré-existente).

## 10. Fora de escopo (não fazer)

- Cache com TTL da API Guild; importação em massa; múltiplos tokens;
- edição de networks/unidades (espelham a Guild; origem é a busca);
- alterar `{{contact.company}}`/macros existentes; testes automatizados.
