<?php
// ============================================================
//  admin/change-password.php — Alterar senha do administrador
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
requireLogin();

$db      = getDB();
$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current  = $_POST['current_password']  ?? '';
    $new      = $_POST['new_password']       ?? '';
    $confirm  = $_POST['confirm_password']   ?? '';

    // Busca usuário atual
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['admin_id']]);
    $user = $stmt->fetch();

    if (!password_verify($current, $user['password'])) {
        $errors[] = 'Senha atual incorreta.';
    }
    if (strlen($new) < 6) {
        $errors[] = 'A nova senha deve ter pelo menos 6 caracteres.';
    }
    if ($new !== $confirm) {
        $errors[] = 'A confirmação de senha não confere.';
    }

    if (empty($errors)) {
        $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
        $db->prepare('UPDATE users SET password = ? WHERE id = ?')
           ->execute([$hash, $_SESSION['admin_id']]);
        $success = true;
    }
}

$pageTitle = 'Alterar senha';
require '_header.php';
?>

<div class="panel" style="max-width: 480px">
  <div class="panel-header">
    <h2 class="panel-title">Alterar senha</h2>
  </div>

  <?php if ($success): ?>
    <div class="alert alert-success" style="margin:16px 20px 0">
      ✅ Senha alterada com sucesso!
    </div>
  <?php endif; ?>

  <?php foreach ($errors as $e): ?>
    <div class="alert alert-error" style="margin:16px 20px 0">
      <?= htmlspecialchars($e) ?>
    </div>
  <?php endforeach; ?>

  <form method="POST" class="form-stack">
    <div class="form-group">
      <label>Senha atual <span class="required">*</span></label>
      <input type="password" name="current_password" placeholder="••••••••" required autocomplete="current-password"/>
    </div>
    <div class="form-group">
      <label>Nova senha <span class="required">*</span></label>
      <input type="password" name="new_password" id="newPass" placeholder="Mínimo 6 caracteres" required autocomplete="new-password"/>
    </div>
    <div class="form-group">
      <label>Confirmar nova senha <span class="required">*</span></label>
      <input type="password" name="confirm_password" placeholder="Repita a nova senha" required autocomplete="new-password"/>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Salvar nova senha</button>
      <a href="/admin/" class="btn btn-outline">Cancelar</a>
    </div>
  </form>
</div>

<?php require '_footer.php'; ?>
