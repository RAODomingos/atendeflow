<?php
$roleOptions = [
    'agent'   => ['label' => 'Atendente',      'hint' => 'Atende conversas e usa o dia a dia.'],
    'manager' => ['label' => 'Gestor',         'hint' => 'Lidera uma equipe; vê relatórios.'],
    'admin'   => ['label' => 'Administrador',  'hint' => 'Acesso total, incluindo este painel.'],
    'viewer'  => ['label' => 'Visualizador',   'hint' => 'Apenas leitura — sem interagir com conversas.'],
];
?>
<div class="page-form">
    <div class="page-actions">
        <a href="<?= url('users') ?>" class="btn btn-outline btn-sm">
            <i class="fas fa-arrow-left"></i> Voltar
        </a>
    </div>

    <form action="<?= $user ? base_url("users/{$user['id']}/edit") : base_url('users/create') ?>" method="POST" enctype="multipart/form-data" class="user-form-grid">
        <?= csrf_field() ?>

        <div class="card user-form-side">
            <div class="card-body" style="text-align:center;padding:24px 18px">
                <div class="avatar-preview" id="avatarPreview">
                    <?php if (!empty($user['avatar'])): ?>
                        <img src="<?= e(upload_url($user['avatar'])) ?>" alt="<?= e($user['name'] ?? '') ?>">
                    <?php else: ?>
                        <div class="avatar-ph avatar-ph-lg"><?= mb_strtoupper(mb_substr($user['name'] ?? '?', 0, 1)) ?></div>
                    <?php endif; ?>
                </div>
                <div class="form-group" style="margin-top:14px;margin-bottom:0">
                    <label class="btn btn-sm btn-outline" style="display:inline-flex;gap:6px;cursor:pointer">
                        <i class="fas fa-camera"></i>
                        <span>Escolher foto</span>
                        <input type="file" name="avatar" id="avatarInput" accept="image/*" onchange="previewAvatar(event); downscaleAvatar(event)" style="display:none">
                    </label>
                    <?php if (!empty($user['avatar'])): ?>
                        <label class="user-remove-avatar">
                            <input type="checkbox" name="remove_avatar" value="1"> Remover foto atual
                        </label>
                    <?php endif; ?>
                </div>
                <small class="text-muted" style="display:block;margin-top:8px;font-size:11px">JPG, PNG ou WebP. Reduzida para 256px automaticamente.</small>
            </div>
        </div>

        <div class="user-form-main">
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-id-card" style="color:var(--primary)"></i> Identificação</h3>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-6">
                            <label>Nome completo *</label>
                            <input type="text" name="name" class="form-control" required
                                   value="<?= e($user['name'] ?? '') ?>" placeholder="Ex.: Maria Silva">
                        </div>
                        <div class="form-group col-6">
                            <label>E-mail *</label>
                            <input type="email" name="email" class="form-control" required
                                   value="<?= e($user['email'] ?? '') ?>" placeholder="maria@empresa.com">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-shield-halved" style="color:var(--warning)"></i> Acesso &amp; perfil</h3>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-6">
                            <label>Senha <?= $user ? '<span class="text-muted" style="font-weight:400">(deixe em branco para manter)</span>' : '*' ?></label>
                            <input type="password" name="password" class="form-control"
                                   <?= $user ? '' : 'required' ?>
                                   placeholder="<?= $user ? 'Nova senha' : 'Mínimo 6 caracteres' ?>">
                        </div>
                        <div class="form-group col-6">
                            <label>Perfil de acesso</label>
                            <select name="role" class="form-control" id="roleSelect">
                                <?php foreach ($roleOptions as $key => $opt): ?>
                                    <option value="<?= $key ?>"
                                            data-hint="<?= e($opt['hint']) ?>"
                                            <?= ($user['role'] ?? 'agent') === $key ? 'selected' : '' ?>>
                                        <?= e($opt['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small id="roleHint" class="text-muted" style="display:block;margin-top:6px;font-size:12px">
                                <?= e($roleOptions[$user['role'] ?? 'agent']['hint'] ?? '') ?>
                            </small>
                        </div>
                    </div>
                    <?php if ($user): ?>
                        <div class="form-check" style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--bg-panel-alt);border-radius:8px">
                            <input type="checkbox" name="is_active" value="1" id="isActive"
                                   <?= !empty($user['is_active']) ? 'checked' : '' ?>
                                   style="width:16px;height:16px;accent-color:var(--primary)">
                            <label for="isActive" style="font-weight:500;cursor:pointer;margin:0">
                                Usuário ativo <span class="text-muted" style="font-weight:400">— desmarque para bloquear o login</span>
                            </label>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-layer-group" style="color:var(--info)"></i> Departamentos</h3>
                </div>
                <div class="card-body">
                    <?php if (empty($departments)): ?>
                        <p class="text-muted" style="margin:0">Nenhum departamento cadastrado ainda.</p>
                    <?php else: ?>
                        <p class="form-hint" style="margin-top:0">O usuário verá as conversas dos departamentos marcados (Ctrl/⌘ para múltiplos).</p>
                        <div class="user-dept-grid">
                            <?php foreach ($departments as $d):
                                $checked = $user && in_array($d['id'], $userDeptIds ?? []);
                            ?>
                                <label class="user-dept-item <?= $checked ? 'is-checked' : '' ?>">
                                    <input type="checkbox" name="department_ids[]" value="<?= $d['id'] ?>" <?= $checked ? 'checked' : '' ?> onchange="this.parentElement.classList.toggle('is-checked', this.checked)">
                                    <i class="fas fa-layer-group"></i>
                                    <span><?= e($d['name']) ?></span>
                                    <i class="fas fa-check user-dept-check"></i>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3><i class="fab fa-whatsapp" style="color:var(--success)"></i> Assinatura do WhatsApp</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <textarea name="signature" rows="3" class="form-control"
                                  placeholder="*<?= e($user['name'] ?? 'Nome do usuário') ?>*"><?= e($user['signature'] ?? '') ?></textarea>
                        <small class="form-hint">
                            Inserida no topo das mensagens enviadas por WhatsApp. Se vazio, usa
                            "<strong>*<?= e($user['name'] ?? 'nome') ?>*</strong>" por padrão.
                            Pode ser desativada por conversa no botão "Assinatura" do chat.
                        </small>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <a href="<?= url('users') ?>" class="btn btn-outline">Cancelar</a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> <?= $user ? 'Salvar alterações' : 'Criar usuário' ?>
                </button>
            </div>
        </div>
    </form>
</div>

<script>
function previewAvatar(e) {
    var f = e.target.files && e.target.files[0];
    if (!f) return;
    var reader = new FileReader();
    reader.onload = function(ev) {
        var box = document.getElementById('avatarPreview');
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

// Atualiza o hint do perfil dinamicamente
(function() {
    var sel = document.getElementById('roleSelect');
    var hint = document.getElementById('roleHint');
    if (!sel || !hint) return;
    sel.addEventListener('change', function() {
        var opt = sel.options[sel.selectedIndex];
        var h = opt ? opt.getAttribute('data-hint') : '';
        if (hint) hint.textContent = h || '';
    });
})();
</script>
