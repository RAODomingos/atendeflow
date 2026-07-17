<div class="page-form">
    <div class="page-toolbar">
        <div>
            <h2 class="page-title" style="margin:0"><i class="fas fa-user-circle"></i> Meu Perfil</h2>
            <p class="page-subtitle">Gerencie suas informações pessoais e preferências</p>
        </div>
        <a href="<?= url('dashboard') ?>" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Dashboard</a>
    </div>

    <div class="profile-layout">
        <div class="profile-sidebar">
            <div class="profile-card">
                <div class="profile-cover"></div>
                <div class="profile-avatar-section">
                    <div class="profile-avatar">
                        <?php if (!empty($user['avatar'])): ?>
                            <img src="<?= e(upload_url($user['avatar'])) ?>" alt="<?= e($user['name']) ?>">
                        <?php else: ?>
                            <div class="avatar-ph-lg"><?= mb_strtoupper(mb_substr($user['name'], 0, 1)) ?></div>
                        <?php endif; ?>
                    </div>
                    <h3 class="profile-name"><?= e($user['name']) ?></h3>
                    <span class="profile-role badge badge-<?= $user['role'] === 'admin' ? 'danger' : ($user['role'] === 'manager' ? 'warning' : ($user['role'] === 'viewer' ? 'secondary' : 'primary')) ?>">
                        <?= e($user['role']) ?>
                    </span>
                </div>
                <div class="profile-stats">
                    <div class="profile-stat">
                        <span class="stat-value"><?= $convCount ?? 0 ?></span>
                        <span class="stat-label">Atendimentos</span>
                    </div>
                    <div class="profile-stat">
                        <span class="stat-value"><?= count($departments) ?></span>
                        <span class="stat-label">Departamentos</span>
                    </div>
                    <div class="profile-stat">
                        <span class="stat-value"><?= $user['is_active'] ? 'Ativo' : 'Inativo' ?></span>
                        <span class="stat-label">Status</span>
                    </div>
                </div>
                <div class="profile-info">
                    <div class="profile-info-item">
                        <i class="fas fa-envelope"></i>
                        <span><?= e($user['email']) ?></span>
                    </div>
                    <div class="profile-info-item">
                        <i class="fas fa-calendar"></i>
                        <span>Membro desde <?= format_datetime($user['created_at']) ?></span>
                    </div>
                    <div class="profile-info-item">
                        <i class="fas fa-clock"></i>
                        <span><?= $user['last_login_at'] ? 'Último acesso: ' . time_elapsed($user['last_login_at']) : 'Nunca acessou' ?></span>
                    </div>
                </div>
                <?php if (!empty($departments)): ?>
                    <div class="profile-depts">
                        <span class="profile-depts-title"><i class="fas fa-layer-group"></i> Departamentos</span>
                        <div class="profile-depts-list">
                            <?php foreach ($departments as $dept): ?>
                                <span class="badge badge-info"><?= e($dept['name']) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="profile-main">
            <div class="card">
                <div class="card-header">
                    <h4><i class="fas fa-edit"></i> Editar Perfil</h4>
                </div>
                <div class="card-body">
                    <form action="<?= url('profile') ?>" method="POST" enctype="multipart/form-data">
                        <?= csrf_field() ?>

                        <div class="form-group">
                            <label><i class="fas fa-camera"></i> Foto</label>
                            <div class="avatar-upload-row">
                                <input type="file" name="avatar" id="avatarInput" accept="image/*" onchange="previewAvatar(event); downscaleAvatar(event)">
                                <?php if (!empty($user['avatar'])): ?>
                                    <label class="remove-avatar-label"><input type="checkbox" name="remove_avatar" value="1"> Remover foto</label>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-6">
                                <label><i class="fas fa-user"></i> Nome</label>
                                <input type="text" name="name" class="form-control" required value="<?= e($user['name']) ?>">
                            </div>
                            <div class="form-group col-6">
                                <label><i class="fas fa-envelope"></i> E-mail</label>
                                <input type="email" name="email" class="form-control" value="<?= e($user['email']) ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-6">
                                <label><i class="fas fa-lock"></i> Nova senha</label>
                                <input type="password" name="password" class="form-control" placeholder="Deixe em branco para manter">
                            </div>
                            <div class="form-group col-6">
                                <label><i class="fas fa-user-tag"></i> Perfil</label>
                                <input type="text" class="form-control" value="<?= e($user['role']) ?>" disabled>
                            </div>
                        </div>

                        <div class="form-group">
                            <label><i class="fab fa-whatsapp"></i> Assinatura do WhatsApp</label>
                            <textarea name="signature" rows="3" class="form-control" placeholder="*<?= e($user['name'] ?? 'Seu nome') ?>*"><?= e($user['signature'] ?? '') ?></textarea>
                            <small class="form-hint">Inserida no topo das mensagens enviadas por WhatsApp. Se vazio, usa "*<?= e($user['name'] ?? 'seu nome') ?>*" por padrão. Desative por conversa pelo botão "Assinatura" no chat.</small>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Atualizar Perfil
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function previewAvatar(e) {
    var f = e.target.files && e.target.files[0];
    if (!f) return;
    var reader = new FileReader();
    reader.onload = function(ev) {
        var box = document.querySelector('.profile-avatar');
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
