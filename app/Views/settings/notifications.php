<div class="settings-page">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px">
        <div>
            <h1 style="font-size:24px;font-weight:700;margin:0;display:flex;align-items:center;gap:10px">
                <i class="fas fa-bell" style="color:var(--primary);font-size:22px"></i>
                Notificações e Sons
            </h1>
            <p style="margin:4px 0 0;font-size:14px;color:var(--text-muted)">Personalize as notificações sonoras e visuais do sistema</p>
        </div>
    </div>

    <form method="POST" action="<?= url('settings/notifications') ?>">
        <?= csrf_field() ?>

        <div class="card" style="border-radius:12px;overflow:hidden">
            <div class="card-header" style="background:var(--bg-card)">
                <h3><i class="fas fa-music" style="color:var(--primary)"></i> Sons</h3>
            </div>
            <div class="card-body">
                <div class="form-check" style="display:flex;align-items:center;gap:10px;margin-bottom:20px;padding:12px 16px;background:var(--bg-content);border-radius:8px;">
                    <input type="checkbox" name="sound_enabled" value="1" id="soundEnabled" <?= (!isset($prefs['sound_enabled']) || $prefs['sound_enabled'] !== false) ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:var(--primary)">
                    <label for="soundEnabled" style="font-weight:500;cursor:pointer;margin:0">
                        Ativar sons de notificação
                    </label>
                </div>

                <div class="form-row" id="soundOptions">
                    <div class="form-group col-6">
                        <label><i class="fas fa-comment-dots" style="color:var(--primary)"></i> Som de nova mensagem</label>
                        <div style="display:flex;gap:8px">
                            <select name="sound_new_message" class="form-control">
                                <option value="default" <?= (($prefs['sound_new_message'] ?? 'default') === 'default') ? 'selected' : '' ?>>Beep padrão (520Hz)</option>
                                <option value="soft" <?= (($prefs['sound_new_message'] ?? '') === 'soft') ? 'selected' : '' ?>>Beep suave (400Hz)</option>
                                <option value="sharp" <?= (($prefs['sound_new_message'] ?? '') === 'sharp') ? 'selected' : '' ?>>Beep agudo (800Hz)</option>
                                <option value="silent" <?= (($prefs['sound_new_message'] ?? '') === 'silent') ? 'selected' : '' ?>>Silencioso</option>
                            </select>
                            <button type="button" class="btn btn-sm btn-outline" onclick="previewSound('new_message', this)" title="Testar som" style="white-space:nowrap;padding:6px 12px;border-radius:8px">
                                <i class="fas fa-play"></i> Testar
                            </button>
                        </div>
                    </div>

                    <div class="form-group col-6">
                        <label><i class="fas fa-user-plus" style="color:var(--success)"></i> Som de novo cliente</label>
                        <div style="display:flex;gap:8px">
                            <select name="sound_new_conversation" class="form-control">
                                <option value="default" <?= (($prefs['sound_new_conversation'] ?? 'default') === 'default') ? 'selected' : '' ?>>Two-tone padrão (440+660Hz)</option>
                                <option value="soft" <?= (($prefs['sound_new_conversation'] ?? '') === 'soft') ? 'selected' : '' ?>>Two-tone suave (350+500Hz)</option>
                                <option value="sharp" <?= (($prefs['sound_new_conversation'] ?? '') === 'sharp') ? 'selected' : '' ?>>Two-tone agudo (700+900Hz)</option>
                                <option value="silent" <?= (($prefs['sound_new_conversation'] ?? '') === 'silent') ? 'selected' : '' ?>>Silencioso</option>
                            </select>
                            <button type="button" class="btn btn-sm btn-outline" onclick="previewSound('new_conversation', this)" title="Testar som" style="white-space:nowrap;padding:6px 12px;border-radius:8px">
                                <i class="fas fa-play"></i> Testar
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card" style="border-radius:12px;overflow:hidden;margin-top:16px">
            <div class="card-header" style="background:var(--bg-card)">
                <h3><i class="fas fa-globe" style="color:var(--primary)"></i> Notificações do Navegador</h3>
            </div>
            <div class="card-body">
                <div class="form-check" style="display:flex;align-items:center;gap:10px;padding:12px 16px;background:var(--bg-content);border-radius:8px;">
                    <input type="checkbox" name="browser_notif_enabled" value="1" id="browserNotifEnabled" <?= (!isset($prefs['browser_notif_enabled']) || $prefs['browser_notif_enabled'] !== false) ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:var(--primary)">
                    <label for="browserNotifEnabled" style="font-weight:500;cursor:pointer;margin:0">
                        Mostrar notificações do navegador quando o sistema estiver em segundo plano
                    </label>
                </div>
                <p style="margin:8px 0 0;font-size:12px;color:var(--text-muted)">
                    <i class="fas fa-info-circle"></i>
                    As notificações do navegador aparecem mesmo com a aba minimizada ou em outra aba.
                </p>
            </div>
        </div>

        <div class="form-actions" style="margin-top:20px">
            <button type="submit" class="btn btn-primary" style="padding:10px 28px;border-radius:10px">
                <i class="fas fa-save"></i> Salvar Preferências
            </button>
        </div>
    </form>
</div>

<script>
function previewSound(type, btn) {
    if (window.__enhancements && window.__enhancements.SoundManager) {
        var select = btn.closest('.form-group').querySelector('select');
        var profile = select ? select.value : 'default';
        window.__enhancements.SoundManager.play(type === 'new_message' ? 'message' : 'new_conv', profile);
    }
}
</script>
