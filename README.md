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
cp .env.example .env
 ```

### 4. Gerar Chave Secreta

```bash
php artisan key:generate
 ```

### 5. Configurar Banco de Dados

```sql
mysql -u root -p < database/schema.sql
mysql -u root -p < database/migrations/2026_07_14_business_hours.sql
 ```

### 6. Servir a Aplicação

```bash
php -S localhost:8080 -t public
 ```

## Acesso Inicial

- **Backend PHP:** http://localhost:8080/atendeflow
- **Admin:** admin@atendeflow.local / Admin@123

## Executando Testes

```bash
php database/migrate.php
 ```

## Configuração Rápida via Painel

1. Login como admin
2. Acessar `/config`
3. Ajustar tema, widget, proatividade
4. Salvar configurações

## Comando do Docker (opcional)

```bash
docker-compose up -d
 ```

## Suporte

Para suporte, visite: https://github.com/seuusuario/atendeflow/issues
