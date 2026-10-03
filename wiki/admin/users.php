<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
requireLogin();

$db     = getDB();
$errors = array();
$success = '';

// ── EXCLUIR ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'delete') {
    $id = (int)$_POST['id'];
    if ($id === (int)$_SESSION['admin_id']) {
        $errors[] = 'Voce nao pode excluir o proprio usuario.';
    } else {
        $db->prepare('DELETE FROM users WHERE id = ?')->execute(array($id));
        $success = 'Usuario excluido com sucesso.';
    }
}

// ── EDITAR USUÁRIO ────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["_action"]) && $_POST["_action"] === "update") {
    $id       = (int)$_POST["id"];
    $name     = trim(isset($_POST["name"])     ? $_POST["name"]     : "");
    $username = trim(isset($_POST["username"]) ? $_POST["username"] : "");
    $password = trim(isset($_POST["password"]) ? $_POST["password"] : "");
    $confirm  = trim(isset($_POST["confirm"])  ? $_POST["confirm"]  : "");
    if (!$name)     $errors[] = "O nome e obrigatorio.";
    if (!$username) $errors[] = "O usuario (login) e obrigatorio.";
    if ($password !== "" && strlen($password) < 6) $errors[] = "A senha deve ter pelo menos 6 caracteres.";
    if ($password !== $confirm) $errors[] = "As senhas nao conferem.";
    if (empty($errors)) {
        $check = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $check->execute(array($username, $id));
        if ($check->fetch()) {
            $errors[] = "Este nome de usuario ja esta em uso.";
        } else {
            if ($password !== "") {
                $hash = password_hash($password, PASSWORD_BCRYPT, array("cost" => 12));
                $db->prepare("UPDATE users SET name=?,username=?,password=? WHERE id=?")->execute(array($name,$username,$hash,$id));
            } else {
                $db->prepare("UPDATE users SET name=?,username=? WHERE id=?")->execute(array($name,$username,$id));
            }
            $success = "Usuario atualizado com sucesso.";
        }
    }
}

// ── CRIAR USUÁRIO ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'create') {
    $name     = trim(isset($_POST['name'])     ? $_POST['name']     : '');
    $username = trim(isset($_POST['username']) ? $_POST['username'] : '');
    $password = trim(isset($_POST['password']) ? $_POST['password'] : '');
    $confirm  = trim(isset($_POST['confirm'])  ? $_POST['confirm']  : '');

    if (!$name)     $errors[] = 'O nome e obrigatorio.';
    if (!$username) $errors[] = 'O usuario (login) e obrigatorio.';
    if (strlen($password) < 6) $errors[] = 'A senha deve ter pelo menos 6 caracteres.';
    if ($password !== $confirm) $errors[] = 'As senhas nao conferem.';

    if (empty($errors)) {
        // Verifica se username já existe
        $check = $db->prepare('SELECT id FROM users WHERE username = ?');
        $check->execute(array($username));
        if ($check->fetch()) {
            $errors[] = 'Este nome de usuario ja esta em uso.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT, array('cost' => 12));
            $db->prepare('INSERT INTO users (name, username, password) VALUES (?,?,?)')
               ->execute(array($name, $username, $hash));
            $success = 'Usuario criado com sucesso.';
        }
    }
}

// ── LISTAR USUÁRIOS ──────────────────────────────────────────
$users = $db->query('SELECT id, name, username, created_at FROM users ORDER BY created_at ASC')->fetchAll();

$pageTitle     = 'Usuários';
$topbarActions = '<button class="btn btn-primary" onclick="openModal()">
  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
       stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
    <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
  </svg>
  Novo usuário
</button>';
require __DIR__ . '/_header.php';
?>

<?php if ($success): ?>
  <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php foreach ($errors as $e): ?>
  <div class="alert alert-error"><?php echo htmlspecialchars($e); ?></div>
<?php endforeach; ?>

<div class="panel">
  <div class="panel-header">
    <h2 class="panel-title">
      Todos os usuários
      <span class="badge" style="margin-left:8px;font-size:.8rem"><?php echo count($users); ?></span>
    </h2>
    <span class="text-muted text-sm">Administradores com acesso ao painel</span>
  </div>

  <?php if (empty($users)): ?>
    <div class="empty-state">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
           stroke-linecap="round" stroke-linejoin="round">
        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
        <circle cx="9" cy="7" r="4"/>
        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
      </svg>
      <p>Nenhum usuário cadastrado.</p>
    </div>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th width="40">#</th>
          <th>Nome</th>
          <th>Usuário (login)</th>
          <th>Criado em</th>
          <th width="120">Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
          <td class="text-muted"><?php echo $u['id']; ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:10px;">
              <div class="user-avatar">
                <?php echo strtoupper(substr($u['name'], 0, 1)); ?>
              </div>
              <div>
                <div style="font-weight:600"><?php echo htmlspecialchars($u['name']); ?></div>
                <?php if ($u['id'] == $_SESSION['admin_id']): ?>
                  <div class="text-muted text-sm">Você</div>
                <?php endif; ?>
              </div>
            </div>
          </td>
          <td><code><?php echo htmlspecialchars($u['username']); ?></code></td>
          <td class="text-muted text-sm"><?php echo date('d/m/Y \à\s H:i', strtotime($u['created_at'])); ?></td>
          <td>
            <div class="action-btns">
              <button class="btn btn-xs btn-outline"
                      onclick="openEditModal(<?php echo $u['id']; ?>, '<?php echo addslashes($u['name']); ?>', '<?php echo addslashes($u['username']); ?>')">
                Editar
              </button>
              <?php if ($u['id'] != $_SESSION['admin_id']): ?>
                <form method="POST" style="display:inline"
                      onsubmit="return confirm('Excluir o usuario \'<?php echo addslashes($u['username']); ?>\'?')">
                  <input type="hidden" name="_action" value="delete"/>
                  <input type="hidden" name="id" value="<?php echo $u['id']; ?>"/>
                  <button type="submit" class="btn btn-xs btn-danger">Excluir</button>
                </form>
              <?php else: ?>
                <span class="text-muted text-sm" title="Nao pode excluir a si mesmo">—</span>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<!-- ── MODAL: Novo usuário ── -->
<div class="modal-overlay" id="modalOverlay" onclick="closeModal()"></div>

<div class="modal" id="userModal">
  <div class="modal-header">
    <h3 class="modal-title" id="modalTitle">Novo usuário</h3>
    <button class="modal-close" onclick="closeModal()">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M18 6 6 18M6 6l12 12"/>
      </svg>
    </button>
  </div>

  <form method="POST" id="userForm" class="form-stack" style="padding:0">
    <input type="hidden" name="_action" id="formAction" value="create"/>
    <input type="hidden" name="id"      id="formUserId" value=""/>

    <div class="form-group">
      <label>Nome completo <span class="required">*</span></label>
      <input type="text" name="name" id="fieldName" placeholder="Ex: Maria Silva" required/>
    </div>

    <div class="form-group">
      <label>Usuário (login) <span class="required">*</span></label>
      <input type="text" name="username" id="fieldUsername"
             placeholder="Ex: maria.silva" autocomplete="off" required/>
      <small class="form-hint">Usado para fazer login no painel.</small>
    </div>

    <div class="form-divider" id="passwordSection">
      <div class="form-group">
        <label>Senha <span class="required" id="passRequired">*</span></label>
        <div class="input-password">
          <input type="password" name="password" id="fieldPassword"
                 placeholder="Mínimo 6 caracteres" autocomplete="new-password"/>
          <button type="button" class="toggle-pass" onclick="togglePass('fieldPassword', this)">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
              <circle cx="12" cy="12" r="3"/>
            </svg>
          </button>
        </div>
      </div>

      <div class="form-group">
        <label>Confirmar senha <span class="required" id="confirmRequired">*</span></label>
        <div class="input-password">
          <input type="password" name="confirm" id="fieldConfirm"
                 placeholder="Repita a senha" autocomplete="new-password"/>
          <button type="button" class="toggle-pass" onclick="togglePass('fieldConfirm', this)">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
              <circle cx="12" cy="12" r="3"/>
            </svg>
          </button>
        </div>
        <small class="form-hint" id="editPassHint" style="display:none">
          Deixe em branco para manter a senha atual.
        </small>
      </div>
    </div>

    <div class="form-actions" style="padding-top:8px">
      <button type="submit" class="btn btn-primary" id="submitBtn">Criar usuário</button>
      <button type="button" class="btn btn-outline" onclick="closeModal()">Cancelar</button>
    </div>
  </form>
</div>

<style>
/* ── Estilos exclusivos desta página ── */
.user-avatar {
  width: 34px; height: 34px; border-radius: 50%;
  background: var(--green); color: #fff;
  display: grid; place-items: center;
  font-size: .85rem; font-weight: 700; flex-shrink: 0;
}

.modal-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(0,0,0,.45); z-index: 400;
  animation: fadeIn .18s ease;
}
.modal-overlay.open { display: block; }

.modal {
  display: none; position: fixed;
  top: 50%; left: 50%;
  transform: translate(-50%, -48%);
  width: min(480px, calc(100vw - 32px));
  background: #fff; border-radius: 14px;
  box-shadow: 0 20px 60px rgba(0,0,0,.18);
  z-index: 500; padding: 28px;
  animation: slideUp .2s ease;
}
.modal.open { display: block; }

@keyframes fadeIn { from { opacity:0 } to { opacity:1 } }
@keyframes slideUp { from { opacity:0; transform:translate(-50%,-45%) } to { opacity:1; transform:translate(-50%,-50%) } }

.modal-header {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 22px;
}
.modal-title { font-size: 1.05rem; font-weight: 700; color: var(--ink); }
.modal-close {
  background: none; border: none; cursor: pointer; color: var(--ink-3);
  display: grid; place-items: center; width: 30px; height: 30px;
  border-radius: 6px; transition: var(--transition);
}
.modal-close:hover { background: var(--green-light); color: var(--green); }

.form-divider { display: flex; flex-direction: column; gap: 16px; }

.input-password { position: relative; }
.input-password input { padding-right: 42px; }
.toggle-pass {
  position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
  background: none; border: none; cursor: pointer; color: var(--ink-3);
  display: grid; place-items: center;
}
.toggle-pass:hover { color: var(--green); }
</style>

<script>
function openModal() {
  document.getElementById('modalTitle').textContent   = 'Novo usuário';
  document.getElementById('formAction').value         = 'create';
  document.getElementById('formUserId').value         = '';
  document.getElementById('fieldName').value          = '';
  document.getElementById('fieldUsername').value      = '';
  document.getElementById('fieldPassword').value      = '';
  document.getElementById('fieldConfirm').value       = '';
  document.getElementById('fieldPassword').required   = true;
  document.getElementById('fieldConfirm').required    = true;
  document.getElementById('submitBtn').textContent    = 'Criar usuário';
  document.getElementById('editPassHint').style.display = 'none';
  document.getElementById('passRequired').style.display  = 'inline';
  document.getElementById('confirmRequired').style.display = 'inline';
  document.getElementById('modalOverlay').classList.add('open');
  document.getElementById('userModal').classList.add('open');
  setTimeout(function() { document.getElementById('fieldName').focus(); }, 100);
}

function openEditModal(id, name, username) {
  document.getElementById('modalTitle').textContent   = 'Editar usuário';
  document.getElementById('formAction').value         = 'update';
  document.getElementById('formUserId').value         = id;
  document.getElementById('fieldName').value          = name;
  document.getElementById('fieldUsername').value      = username;
  document.getElementById('fieldPassword').value      = '';
  document.getElementById('fieldConfirm').value       = '';
  document.getElementById('fieldPassword').required   = false;
  document.getElementById('fieldConfirm').required    = false;
  document.getElementById('submitBtn').textContent    = 'Salvar alterações';
  document.getElementById('editPassHint').style.display  = 'block';
  document.getElementById('passRequired').style.display  = 'none';
  document.getElementById('confirmRequired').style.display = 'none';
  document.getElementById('modalOverlay').classList.add('open');
  document.getElementById('userModal').classList.add('open');
  setTimeout(function() { document.getElementById('fieldName').focus(); }, 100);
}

function closeModal() {
  document.getElementById('modalOverlay').classList.remove('open');
  document.getElementById('userModal').classList.remove('open');
}

function togglePass(fieldId, btn) {
  var input = document.getElementById(fieldId);
  input.type = input.type === 'password' ? 'text' : 'password';
}

// Fecha com ESC
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeModal();
});

// Abre modal automaticamente se houve erro de validação no POST
<?php if (!empty($errors) && !empty($_POST['_action']) && ($_POST['_action'] === 'create' || $_POST['_action'] === 'update')): ?>
<?php if ($_POST['_action'] === 'update'): ?>
openEditModal(
  <?php echo (int)$_POST['id']; ?>,
  '<?php echo addslashes(isset($_POST['name']) ? $_POST['name'] : ''); ?>',
  '<?php echo addslashes(isset($_POST['username']) ? $_POST['username'] : ''); ?>'
);
<?php else: ?>
openModal();
document.getElementById('fieldName').value     = '<?php echo addslashes(isset($_POST['name']) ? $_POST['name'] : ''); ?>';
document.getElementById('fieldUsername').value = '<?php echo addslashes(isset($_POST['username']) ? $_POST['username'] : ''); ?>';
<?php endif; ?>
<?php endif; ?>
</script>

<?php require __DIR__ . '/_footer.php'; ?>
