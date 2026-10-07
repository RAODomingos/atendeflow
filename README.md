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

## Configuração Rápida via Painel

1. Login como admin
2. Acessar `/settings`
3. Ajustar tema, widget, SLA e canais
4. Salvar configurações

## Suporte

Para suporte, visite: https://github.com/seuusuario/atendeflow/issues
