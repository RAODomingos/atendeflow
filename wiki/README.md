# Guild Control - Sistema de Administração

Sistema completo de administração com painel administrativo, gerenciamento de artigos, categorias e usuários.

## 📋 Requisitos

- **PHP** 7.4 ou superior
- **MySQL** 5.7 ou superior  
- **Servidor Web** (Apache, Nginx ou XAMPP)
- **Extensões PHP**: PDO, MySQL, Session

## 🚀 Instalação Rápida

### 1. Configurar Banco de Dados

```sql
-- Criar banco de dados
CREATE DATABASE wiki CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Criar tabela de usuários
USE wiki;
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Inserir usuário administrador padrão
INSERT INTO users (name, username, password) VALUES 
('Administrador', 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');
-- Senha: admin123
```

### 2. Configurar Conexão com Banco

Edite o arquivo `config/db.php`:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'wiki');        // Nome do banco criado
define('DB_USER', 'root');        // Seu usuário MySQL
define('DB_PASS', '');            // Sua senha MySQL
```

### 3. Configurar Diretórios

Garanta que os seguintes diretórios tenham permissão de escrita:

```
uploads/        (777)
```

### 4. Acessar Sistema

- **URL Principal**: `http://localhost/`
- **Painel Admin**: `http://localhost/admin/`

## 🔐 Credenciais Padrão

- **Usuário**: `admin`
- **Senha**: `admin123`

> ⚠️ **Importante**: Altere a senha do administrador após o primeiro acesso!

## 📁 Estrutura de Diretórios

```
htdocs/
├── admin/                 # Painel administrativo
│   ├── login.php         # Formulário de login
│   ├── index.php         # Dashboard admin
│   ├── articles.php      # Gerenciar artigos
│   ├── categories.php    # Gerenciar categorias
│   └── users.php         # Gerenciar usuários
├── api/                  # Endpoints da API
│   ├── articles.php      # API de artigos
│   ├── categories.php    # API de categorias
│   └── upload.php        # Upload de arquivos
├── config/               # Configurações
│   ├── db.php           # Conexão com banco
│   ├── auth.php         # Sistema de autenticação
│   └── security.php     # Funções de segurança
├── css/                  # Estilos
├── js/                   # Scripts JavaScript
├── uploads/              # Arquivos enviados
└── index.html           # Página principal
```

## 🔧 Configurações Adicionais

### Segurança

O sistema inclui:
- ✅ Proteção CSRF em todos os formulários
- ✅ Senhas hasheadas com `password_hash()`
- ✅ Sanitização de entrada de dados
- ✅ Sessões seguras com timeout
- ✅ Rate limiting para tentativas de login

### Upload de Arquivos

- **Tamanho máximo**: 5MB
- **Formatos permitidos**: JPG, PNG, PDF, DOC, DOCX
- **Local**: `uploads/`

## 🐛 Solução de Problemas

### "Requisição inválida" no Login

Este erro é uma proteção CSRF. Para resolver:

1. **Limpar cookies do navegador**
2. **Usar apenas uma aba** para fazer login
3. **Verificar se o navegador permite cookies** do localhost
4. **Acessar**: `http://localhost/admin/login_debug.php` para diagnóstico

### Erro de Conexão com Banco

Verifique:
- Se o serviço MySQL está rodando
- Se as credenciais em `config/db.php` estão corretas
- Se o banco `wiki` foi criado

### Permissões de Upload

Execute no terminal:
```bash
chmod 777 uploads/
```

## 📝 Módulos Disponíveis

### Administração
- Dashboard com estatísticas
- Gerenciamento de artigos
- Gerenciamento de categorias
- Gerenciamento de usuários
- Sistema de backup

### API REST
- `GET /api/articles` - Listar artigos
- `POST /api/articles` - Criar artigo
- `PUT /api/articles/{id}` - Atualizar artigo
- `DELETE /api/articles/{id}` - Excluir artigo
- `GET /api/categories` - Listar categorias
- `POST /api/upload` - Upload de arquivos

## 🔄 Backup Automático

O sistema gera backups automáticos na área administrativa. Para configurar backup manual:

1. Acesse `/admin/backup.php`
2. Escolha o tipo de backup
3. Faça download do arquivo gerado

## 📞 Suporte

Para problemas técnicos:
1. Verifique os logs de erro do PHP
2. Teste com o modo debug ativado
3. Confirme as configurações do servidor web

---

**Versão**: 1.0  
**Licença**: MIT  
**Desenvolvido com**: PHP, MySQL, JavaScript, CSS3
