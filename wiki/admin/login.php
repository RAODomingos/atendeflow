<?php
// ============================================================
//  admin/login.php
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

// Initialize secure session
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
    session_start();
}

$error = '';
$success = '';

// Handle timeout message
if (isset($_GET['timeout']) && $_GET['timeout'] == 1) {
    $error = 'Sua sessão expirou. Faça login novamente.';
}

// Redirect if already logged in
if (isLoggedIn()) { 
    header('Location: /admin/'); 
    exit; 
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitizeInput($_POST['username'] ?? '', 'string');
    $password = $_POST['password'] ?? '';
    
    // Validate CSRF token
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Requisição inválida. Tente novamente.';
    } else {
        $result = attemptLogin($username, $password);
        
        if ($result['success']) {
            $_SESSION['admin_id']   = $result['user']['id'];
            $_SESSION['admin_name'] = $result['user']['name'];
            $_SESSION['admin_username'] = $result['user']['username'];
            $_SESSION['last_activity'] = time();
            header('Location: /admin/');
            exit;
        } else {
            $error = $result['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Login — Guild Control Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="css/admin.css"/>
</head>
<body class="login-page">
  <div class="login-box">
    <div class="login-logo">
      <div class="logo-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 2L2 7l10 5 10-5-10-5z"/>
          <path d="M2 17l10 5 10-5"/>
          <path d="M2 12l10 5 10-5"/>
        </svg>
      </div>
      <div>
        <div class="login-title">Guild Control</div>
        <div class="login-sub">Painel Administrativo</div>
      </div>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" class="login-form">
    <?= getCSRFInput() ?>
    <div class="form-group">
      <label for="username">Usuário</label>
      <input type="text" id="username" name="username" required
             value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
             autocomplete="username">
    </div>
    <div class="form-group">
      <label for="password">Senha</label>
      <input type="password" id="password" name="password" required
             autocomplete="current-password">
    </div>
    <button type="submit" class="btn btn-primary btn-full">Entrar</button>
  </form>
  </div>
</body>
</html>
