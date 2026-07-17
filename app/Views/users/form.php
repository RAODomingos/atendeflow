<div class="page-form">
    <div class="page-toolbar">
        <h2 class="page-title"><i class="fas fa-users"></i> <?= $user ? 'Editar' : 'Novo' ?> Usuário</h2>
        <a href="<?= url('users') ?>" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
    </div>
    <div class="card">
        <div class="card-body">
            <form action="<?= $user ? "/users/{$user['id']}/edit" : '/users/create' ?>" method="POST" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <div class="form-group user-avatar-edit">
                    <label><i class="fas fa-camera"></i> Foto</label>
                    <div class="avatar-edit-row">
                        <div class="avatar-preview">
                            <?php if (!empty($user['avatar'])): ?>
                                <img src="<?= e(upload_url($user['avatar'])) ?>" alt="<?= e($user['name'] ?? '') ?>">
                            <?php else: ?>
                                <div class="avatar-ph"><?= mb_strtoupper(mb_substr($user['name'] ?? '?', 0, 1)) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="avatar-edit-actions">
                            <input type="file" name="avatar" id="avatarInput" accept="image/*" onchange="previewAvatar(event); downscaleAvatar(event)">
                            <?php if (!empty($user['avatar'])): ?>
                                <label><input type="checkbox" name="remove_avatar" value="1"> Remover foto</label>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-6">
                        <label><i class="fas fa-user"></i> Nome *</label>
                        <input type="text" name="name" class="form-control" required
                               value="<?= e($user['name'] ?? '') ?>">
                    </div>
                    <div class="form-group col-6">
                        <label><i class="fas fa-envelope"></i> E-mail *</label>
                        <input type="email" name="email" class="form-control" required
                               value="<?= e($user['email'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-6">
                        <label><i class="fas fa-lock"></i> Senha <?= $user ? '(deixe em branco para manter)' : '*' ?></label>
                        <input type="password" name="password" class="form-control"
                               <?= $user ? '' : 'required' ?>
                               placeholder="<?= $user ? 'Nova senha' : 'Mínimo 6 caracteres' ?>">
                    </div>
                    <div class="form-group col-6">
                        <label><i class="fas fa-user-tag"></i> Perfil</label>
                        <select name="role" class="form-control">
                            <option value="agent" <?= ($user['role'] ?? '') === 'agent' ? 'selected' : '' ?>>Atendente</option>
                            <option value="manager" <?= ($user['role'] ?? '') === 'manager' ? 'selected' : '' ?>>Gestor</option>
                            <option value="admin" <?= ($user['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Administrador</option>
                            <option value="viewer" <?= ($user['role'] ?? '') === 'viewer' ? 'selected' : '' ?>>Visualizador</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-layer-group"></i> Departamentos</label>
                    <select name="department_ids[]" class="form-control" multiple>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['id'] ?>"
                                <?= $user && in_array($dept['id'], $userDeptIds ?? []) ? 'selected' : '' ?>>
                                <?= e($dept['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($user): ?>
                    <div class="form-group">
                        <label class="checkbox-inline">
                            <input type="checkbox" name="is_active" value="1" <?= $user['is_active'] ? 'checked' : '' ?>>
                            Usuário ativo
                        </label>
                    </div>
                <?php endif; ?>
                <div class="form-group">
                    <label><i class="fab fa-whatsapp"></i> Assinatura do WhatsApp</label>
                    <textarea name="signature" rows="3" class="form-control"
                              placeholder="*<?= e($user['name'] ?? 'Nome do usuário') ?>*"><?= e($user['signature'] ?? '') ?></textarea>
                    <small class="form-hint">Inserida no topo das mensagens enviadas por WhatsApp. Se vazio, usa "*<?= e($user['name'] ?? 'nome') ?>*" por padrão. Desative por conversa pelo botão "Assinatura" no chat.</small>
                </div>
                <div class="form-actions">
                    <a href="<?= url('users') ?>" class="btn btn-outline">Cancelar</a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Salvar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function previewAvatar(e) {
    var f = e.target.files && e.target.files[0];
    if (!f) return;
    var reader = new FileReader();
    reader.onload = function(ev) {
        var box = document.querySelector('.avatar-preview');
        if (box) box.innerHTML = '<img src="' + ev.target.result + '" alt="">';
    };
    reader.readAsDataURL(f);
}
function resizeImageFile(file, max, cb) {
    var reader = new FileReader();
    reader.onload = function() {
        var img = new Image();
        img.onload = function() {
            var scale = Math.min(1, max / Math.max(img.width, img.height));
            var w = Math.max(1, Math.round(img.width * scale));
            var h = Math.max(1, Math.round(img.height * scale));
            var canvas = document.createElement('canvas');
            canvas.width = w; canvas.height = h;
            canvas.getContext('2d').drawImage(img, 0, 0, w, h);
            canvas.toBlob(function(b) { cb(b); }, 'image/png');
        };
        img.onerror = function() { cb(null); };
        img.src = reader.result;
    };
    reader.onerror = function() { cb(null); };
    reader.readAsDataURL(file);
}
function downscaleAvatar(e) {
    var input = e.target;
    var file = input.files && input.files[0];
    if (!file || !/^image\//.test(file.type)) return;
    resizeImageFile(file, 256, function(blob) {
        if (!blob) return;
        try {
            var dt = new DataTransfer();
            dt.items.add(new File([blob], 'avatar.png', { type: 'image/png' }));
            input.files = dt.files;
        } catch (err) { }
    });
}
</script>
