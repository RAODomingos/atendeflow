<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
requireLogin();

$db     = getDB();
$errors  = [];
$success = '';

// Get current user info
$stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$_SESSION['admin_id']]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: /admin/logout.php');
    exit;
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $bio      = trim($_POST['bio'] ?? '');
    
    if (!$name) $errors[] = 'O nome é obrigatório.';
    if (!$username) $errors[] = 'O usuário (login) é obrigatório.';
    
    // Check if username is already taken by another user
    if (empty($errors)) {
        $check = $db->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
        $check->execute([$username, $user['id']]);
        if ($check->fetch()) {
            $errors[] = 'Este nome de usuário já está em uso.';
        }
    }
    
    // Check email format if provided
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'O email informado é inválido.';
    }
    
    if (empty($errors)) {
        $db->prepare('UPDATE users SET name=?, username=?, email=?, bio=? WHERE id=?')
           ->execute([$name, $username, $email, $bio, $user['id']]);
        
        // Update session variables
        $_SESSION['admin_name'] = $name;
        
        $success = 'Perfil atualizado com sucesso!';
        
        // Reload user data
        $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['admin_id']]);
        $user = $stmt->fetch();
    }
}

$pageTitle = 'Meu Perfil';
require __DIR__ . '/_header.php';
?>

<?php if ($success): ?>
  <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php foreach ($errors as $e): ?>
  <div class="alert alert-error"><?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>

<div class="panel" style="max-width:800px">
  <div class="panel-header">
    <h2 class="panel-title">Meu Perfil</h2>
    <span class="text-muted text-sm">Gerencie suas informações de usuário</span>
  </div>

  <form method="POST" class="form-stack">
    <div class="form-row">
      <div class="form-group" style="flex:2">
        <label>Nome completo <span class="required">*</span></label>
        <input type="text" name="name" value="<?= htmlspecialchars($user['name'] ?? '') ?>" required/>
      </div>
      <div class="form-group">
        <label>Usuário (login) <span class="required">*</span></label>
        <input type="text" name="username" value="<?= htmlspecialchars($user['username'] ?? '') ?>" required/>
      </div>
    </div>

    <div class="form-group">
      <label>Email</label>
      <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" placeholder="seu@email.com"/>
      <small class="form-hint">Opcional - usado para notificações</small>
    </div>

    <div class="form-group">
      <label>Biografia</label>
      <textarea name="bio" rows="3" placeholder="Fale um pouco sobre você..."><?= htmlspecialchars($user['bio'] ?? '') ?></textarea>
      <small class="form-hint">Opcional - informações adicionais sobre você</small>
    </div>

    <div class="panel" style="background:#f8faf8;margin:20px 0">
      <div class="panel-header">
        <h3 class="panel-title" style="font-size:1rem">Informações da Conta</h3>
      </div>
      <div style="padding:16px">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px">
          <div>
            <div class="text-muted text-sm">ID do Usuário</div>
            <div><strong>#<?= $user['id'] ?></strong></div>
          </div>
          <div>
            <div class="text-muted text-sm">Data de Criação</div>
            <div><?= date('d/m/Y', strtotime($user['created_at'])) ?></div>
          </div>
          <div>
            <div class="text-muted text-sm">Última Atividade</div>
            <div><?= date('d/m/Y H:i', $_SESSION['last_activity'] ?? time()) ?></div>
          </div>
        </div>
      </div>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Salvar Perfil</button>
      <a href="/admin/change-password.php" class="btn btn-outline">Alterar Senha</a>
      <a href="/admin/" class="btn btn-outline">Voltar</a>
    </div>
  </form>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
