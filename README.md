# Guia de Instalação do OminiDesk

## Requisitos

- PHP 8.1+
- MySQL 8+
- Extensões PHP: PDO, mbstring, json, openssl
- Composér

## Passos de Instalação

### 1. Clonar o Repositório

```bash
 git clone https://github.com/seuusuario/atendeflow.git
 cd atendeflow
 ```

### 2. Instalar Dependências

```bash
 composer install
 ```

### 3. Copiar Arquivo .env

```bash
cp env/.env.example .env
# Gere um JWT_SECRET novo: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

### 4. Gerar Chave Secreta

```bash
# Não há artisan neste projeto (PHP puro). Defina manualmente no .env:
# JWT_SECRET=<valor aleatório de 64 hex> e APP_DEBUG=false em produção
```

### 5. Configurar Banco de Dados

```bash
mysql -u root -p < database/schema.sql
php database/migrate.php
# O migrate.php aplica todas as migrations de database/migrations/ em ordem
# (protocolo, grupos, receipts, inbox_channels, etc.) de forma idempotente.
```

### 6. Servir a Aplicação

```bash
php -S localhost:8080 -t public
 ```

## Acesso Inicial

- **Backend PHP:** http://localhost:8080/
- **Admin:** admin@atendeflow.local / Admin@123 (troque a senha no primeiro login: mín. 10 chars com letras e números)

## Executando Migrations

```bash
php database/migrate.php
```

## Testes

```bash
php tests/run.php               # suíte principal (sem banco de dados)
php tests/e2e_sla.php           # e2e do relógio do SLA (MySQL, autolimpante)
php tests/e2e_sla_measurable.php # e2e da medição de compliance (MySQL, autolimpante)
php tests/e2e_notifications.php # e2e do dedup de notificações (MySQL, autolimpante)
php tests/smoke_sla.php         # smoke HTTP (com o servidor rodando em :8099)
```

O relógio do SLA conta **segundos úteis**: só o tempo dentro do horário de
atendimento configurado no departamento da conversa consome SLA. Fora do
expediente, fins de semana e a espera da resposta do cliente não queimam o
relógio. O interruptor fica em `/settings/notifications`
("Considerar horário de atendimento") e vale para todas as caixas de entrada.

## Caixa de entrada

A lista tem três abas:

- **Em atendimento** — a bola está com a equipe: conversas abertas,
  aguardando o cliente ou aguardando outro setor (`open`,
  `waiting_customer`, `waiting_internal`).
- **Aguardando** — a fila de chegada: conversas novas, as que **saíram do
  bot** e as **transferidas de setor** (`new`).
- **Concluído** — atendimentos finalizados (`resolved`, `closed`).

"Em atendimento" e "Aguardando" mostram a quantidade ao lado do nome (o
contador some quando 0); "Concluído" não tem contador.

Transferir de setor (manual ou por macro) devolve a conversa para a fila do
destino como **nova**: ela aparece em **Aguardando**.

Cada linha da lista mostra **foto, nome, protocolo** e a **loja selecionada
na conversa**. A atualização acontece por SSE (na hora), por um poll de **10s**
(re-renderiza só as linhas que mudaram) e por um re-render completo a cada
**60s** — o item do LiveFeed é gerado em JS e precisa sair idêntico ao do PHP
(mesma marcação em `_conv_list.php` e `LiveFeed._createItem()`).

## Busca global (barra do topo)

A barra **Pesquisar** do topo (**Ctrl K**) procura enquanto você digita e abre
um painel com quatro grupos:

- **Grupos** — por nome ou JID; o clique abre a ficha do grupo.
- **Clientes** — por nome, e-mail, telefone, documento, empresa ou loja
  vinculada. Cada linha mostra a foto, os dados de contato e quantos
  atendimentos o cliente tem; o clique abre a página do cliente.
- **Atendimentos** — por protocolo (com ou sem máscara: `2610-21342` acha
  `261021342`), assunto, nome do cliente ou **texto das mensagens**. O clique
  abre a conversa direto na caixa.
- **Páginas** — atalhos para as telas do menu.

O escopo respeita a permissão do usuário: admin vê tudo; os demais veem só o
que são responsáveis, o que está numa caixa que acessam ou o que é do seu
departamento (mesma regra da caixa de entrada). API: `GET /api/global-search?q=termo`.

## Grupos de WhatsApp (histórico sim, alerta só na menção)

Em **Grupos WhatsApp** (`/whatsapp/groups`) cada grupo tem a sua própria ficha:

- **Caixa onde as conversas do grupo aparecem** — você escolhe por grupo; é
  nessa caixa que as conversas do grupo aparecem (uma conversa do grupo = uma
  linha na caixa, como um atendimento).
- **Monitorado / Arquivado** — arquivado, o grupo para de ser espelhado na
  caixa e de avisar; o histórico já espelhado continua lá.
- **Novo tópico após X h** — se o grupo ficar em silêncio por X horas, a
  mensagem seguinte abre **outra conversa** (assunto novo) em vez de entulhar
  a conversa antiga.
- **Participantes** — a lista vem do provedor e fica em cache (o botão
  *Atualizar lista* renova); é ela que alimenta o `@menção` no envio.
- **Menções** — o histórico de menções com link *Ver* que abre a mensagem na
  conversa. Na lista, a coluna mostra quantas são **novas** (em vermelho) e o
  total.

O comportamento do alerta é o ponto principal:

- **Todo o tráfego do grupo aparece na caixa** (histórico completo, como um
  atendimento normal).
- **O sino só toca quando alguém marca o número da conexão ou `@todos`** —
  e só essa mensagem fica não lida; o resto entra como histórico já lido.
- O grupo **não vira cliente**: o contato técnico do grupo fica fora da
  lista de clientes (e da busca por cliente); na busca do topo, grupos têm
  grupo próprio.
- Transferir a conversa do grupo **move a caixa do grupo**: o sino passa a
  avisar a caixa nova.

E há **SLA da menção**: em grupo não faz sentido medir "1ª resposta" (o
tráfego é contínuo), então o relógio começa **na menção** e para na 1ª
resposta humana. Limite em *Configurações › Notificações › SLA de menção em
grupo* (0 = herda o limite da 1ª resposta). Cada menção gera o próprio alerta
(tag "Menção em grupo", gerentes do departamento e WhatsApp para os telefones
de alerta).

## SLA mensurável (compliance)

Além das cores e dos sons, o sistema **mede** os dois SLAs de cada conversa:

| SLA | O que mede | Limite padrão |
|---|---|---|
| **1ª resposta** | da 1ª mensagem do cliente até a 1ª resposta **humana** (resposta de robô não conta) | 15 min |
| **Resolução** | tempo útil com a bola com a gente, até o encerramento | 24 h |

O que isso gera:

- **Chip de SLA** no header da conversa (1ª resposta e resolução no tooltip).
  Em grupo, o chip mostra o SLA **da menção** (da marcação até a 1ª resposta).
- **Card no dashboard**: "SLA crítico agora" + compliance de 30 dias.
- **Relatório `/reports/sla`** (gerente+): compliance por atendente, departamento
  e caixa, lista das conversas que furaram o SLA e export CSV.
- **Escalonamento automático** em dois casos:
  - **atendimento novo sem resposta** (violação da 1ª resposta): tag "SLA violado";
  - **cliente sem resposta** em conversa já atendida: tag "Sem resposta do cliente"
    (dispara uma vez por mensagem do cliente, nunca duplica).

  Em ambos: notificação in-app aos gerentes do departamento, atribuição ao
  atendente menos carregado (só se ninguém atende ainda) e aviso por WhatsApp
  aos telefones de alerta. Cada ação é ligável/desligável em
  `/settings/notifications` (e por caixa em `/inboxes/{id}/edit`).

### O que conta como "resposta" no relógio do SLA

Só a resposta **humana** (mensagem enviada por um atendente) encerra a
espera. Mensagem de **bot/fluxo**, de automação ou de sistema **não** conta —
é o cliente continuando esperando por alguém. É a mesma regra da medição
("1ª resposta humana").

Sem isso, a conversa que chega na **fila de chegada** (aba *Aguardando*) vinda
de um fluxo de bot ficava com o relógio congelado desde a última fala do bot e
**nunca** entrava no estado "SLA estourado" — o cliente ficava esperando
escondido. Hoje a fila pinta o item em vermelho assim que o cliente passa do
limite, mesmo com o bot falando por último.

### Onde configurar os telefones de alerta

No detalhe do departamento (`/departments/{id}`), no card **Telefones de Alerta
do SLA** — um número por linha (a vírgula também separa). Todos os números
recebem o aviso de WhatsApp. O envio precisa do toggle "Avisar o plantão por
WhatsApp" em `/settings/notifications`.

O limite do alerta de "cliente sem resposta" é o campo **Alerta de cliente sem
resposta** (0 = usa o mesmo limite da 1ª resposta).

> O SLA **nunca** manda mensagem para o cliente. Quando um fluxo (bot)
> expira, a conversa volta para a fila em silêncio — o cliente não recebe
> "Tempo esgotado…"; a equipe só vê o evento na linha do tempo.

A medição é feita por um worker por cron. **Sem ele, nada é medido** (as
conversas ativas ficam sem linha de SLA e não aparecem no relatório):

```cron
* * * * * cd /var/www/atendeflow && php workers/sla_worker.php >> storage/logs/sla_worker.log 2>&1
```

No Windows (desenvolvimento local), rode o worker na mão — ou pelo Agendador
de Tarefas do Windows:

```powershell
C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe workers\sla_worker.php
```

> As **cores e o "estourado" da lista** são calculados ao vivo (não dependem
> do worker): a fila de chegada fica vermelha sozinha quando o cliente passa
> do limite. O worker é o que grava a medição, aplica a tag "SLA violado",
> escala e alimenta o relatório de compliance.

A primeira execução faz o *backfill* das conversas já encerradas (em lotes),
o que dá o compliance histórico.

## Configuração Rápida via Painel

1. Login como admin
2. Acessar `/settings`
3. Ajustar tema, widget, SLA e canais
4. Salvar configurações

## Suporte

Para suporte, visite: https://github.com/seuusuario/atendeflow/issues
