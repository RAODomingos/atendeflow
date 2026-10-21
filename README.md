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

## SLA mensurável (compliance)

Além das cores e dos sons, o sistema **mede** os dois SLAs de cada conversa:

| SLA | O que mede | Limite padrão |
|---|---|---|
| **1ª resposta** | da 1ª mensagem do cliente até a 1ª resposta **humana** (resposta de robô não conta) | 15 min |
| **Resolução** | tempo útil com a bola com a gente, até o encerramento | 24 h |

O que isso gera:

- **Chip de SLA** no header da conversa (1ª resposta e resolução no tooltip).
- **Card no dashboard**: "SLA crítico agora" + compliance de 30 dias.
- **Relatório `/reports/sla`** (gerente+): compliance por atendente, departamento
  e caixa, lista das conversas que furaram o SLA e export CSV.
- **Escalonamento automático** na violação da 1ª resposta: tag "SLA violado",
  notificação aos gerentes do departamento, atribuição ao atendente menos
  carregado e aviso por WhatsApp ao plantão. Cada ação é ligável/desligável
  em `/settings/notifications` (e por caixa em `/inboxes/{id}/edit`).

A medição é feita por um worker por cron. **Sem ele, nada é medido** (as
conversas ativas ficam sem linha de SLA e não aparecem no relatório):

```cron
* * * * * cd /var/www/atendeflow && php workers/sla_worker.php >> storage/logs/sla_worker.log 2>&1
```

A primeira execução faz o *backfill* das conversas já encerradas (em lotes),
o que dá o compliance histórico.

## Configuração Rápida via Painel

1. Login como admin
2. Acessar `/settings`
3. Ajustar tema, widget, SLA e canais
4. Salvar configurações

## Suporte

Para suporte, visite: https://github.com/seuusuario/atendeflow/issues
