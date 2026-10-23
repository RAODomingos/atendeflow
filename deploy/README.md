# Deploy e operacao

Este diretorio tem o que o servidor precisa para rodar o app em
producao. O app em si nao depende do nginx: o `public/.htaccess` cobre
o Apache e o `nginx/atendeflow.test.conf` cobre o nginx.

## 1) Servidor web

### Apache

O `public/.htaccess` faz o rewrite para o `public/index.php`, bloqueia
`.env`/backups e aplica os headers de seguranca. Garanta que o modulo
`mod_rewrite` esteja ligado e que `AllowOverride All` esteja
habilitado no diretorio do projeto.

### nginx

Copie `nginx/atendeflow.test.conf` para o `sites-enabled` do nginx,
ajuste `root`/`fastcgi_pass`/certificados e recarregue:

```bash
nginx -t && nginx -s reload
```

O conf ja cobre:

- `location /uploads/` com `403` para `.php` (upload nao vira script);
- negacao de dotfiles, `.env`, `.sql`, `.log` e backups;
- headers de seguranca (X-Frame-Options, X-Content-Type-Options,
  Referrer-Policy, Permissions-Policy);
- `client_max_body_size 32M` (o app aceita 10MB por arquivo).

## 2) Workers (obrigatorio)

Sem o worker de SLA, **nada e medido**: as conversas ficam sem linha de
SLA e nao aparecem no relatorio.

```cron
* * * * * cd /var/www/atendeflow && php workers/sla_worker.php >> storage/logs/sla_worker.log 2>&1
* * * * * cd /var/www/atendeflow && php workers/flow_worker.php >> storage/logs/flow_worker.log 2>&1
```

Os dois workers tem **flock**: duas execucoes sobrepostas nao se
processam (o escalonamento do SLA e a mensagem de delay do fluxo
disparariam duas vezes). O `process_jobs.php` e um daemon de fila e
tambem aceita `--once` (uma passagem por job, para cron sem processo
residente).

## 3) Fuso horario

O app grava hora local (`America/Sao_Paulo`) e usa `NOW()` do MySQL
nos filtros. A sessao do banco e alinhada com o fuso da aplicacao
(`Database::applySessionTimezone()`), entao a maquina do MySQL pode
estar em UTC. Se precisar fixar, use `DB_TIMEZONE` no `.env`.

## 4) Access log e o segredo do webhook

O segredo do webhook do WhatsApp pode viajar na query string
(`?secret=...`) — alguns provedores so aceitam assim. A query
completa fica no **access log** do servidor.

Opcoes, em ordem de preferencia:

1. Configure o provedor para enviar o header `X-Webhook-Secret` (o app
   aceita) e ligue `WHATSAPP_WEBHOOK_QUERY_SECRET=0` no `.env`;
2. Use a assinatura HMAC (`X-Webhook-Signature`);
3. Se precisar da query, use o log format `atendeflow_sem_args`
   (definido no conf do nginx deste diretorio), que grava so o
   caminho, sem a query. No Apache, ajuste o `LogFormat` do vhost
   para nao registrar a query ou aponte o access log do webhook para
   um arquivo separado.

O proprio app nunca grava a query do webhook no log
(`storage/logs/webhook.log` registra metodo e tamanho).

## 5) Proxies

Atras de proxy/balance, declare os IPs confiaveis em `TRUSTED_PROXIES`
(`.env`). Sem isso, o rate limit ve o IP do proxy (todo mundo com o
mesmo limite) e o `X-Forwarded-For` e ignorado — que e o correto,
porque esse cabecalho e forjavel pelo cliente.

## 6) Permissoes de disco

O app precisa escrever em:

- `storage/logs/` (logs da aplicacao e dos workers);
- `storage/cache/` (rate limit, cache de arquivos e locks dos workers);
- `public/uploads/` (midia enviada pelos clientes e pelos provedores).

Em producao, sirva `public/uploads/` sem execucao de PHP (o conf do
nginx ja faz isso).
