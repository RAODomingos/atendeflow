<?php
/** @var array $slaForm estado inicial do card de SLA (use_global + values) */
$sf = $slaForm;
$sv = $sf['values'];
$useGlobal = $sf['use_global'];
?>
<div class="channels-page">
    <?php $subPage = 'inboxes'; require __DIR__ . '/_tabs.php'; ?>

    <div class="page-actions">
        <a href="<?= url('inboxes') ?>" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
    </div>

    <div class="card">
        <div class="card-body">
            <form action="<?= url($inbox ? 'inboxes/' . $inbox['id'] : 'inboxes') ?>" method="POST">
                <?= csrf_field() ?>

                <div class="form-group">
                    <label><i class="fas fa-tag"></i> Nome da caixa *</label>
                    <input type="text" name="name" class="form-control" required
                           value="<?= e($inbox['name'] ?? '') ?>" placeholder="Ex.: Suporte Avançado">
                    <p class="form-hint">
                        A caixa de entrada é única: o recorte vem dos filtros da listagem.
                        A caixa é uma visão salva — e o perfil de SLA do time.
                    </p>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-network-wired"></i> Canais vinculados</label>
                    <p class="form-hint">
                        As conversas destes canais aparecem nesta caixa.
                        Quem decide o time que atende é o <strong>setor</strong> do canal, não a caixa.
                    </p>
                    <div class="inbox-check-grid">
                        <?php foreach ($channels as $ch): ?>
                            <label class="inbox-check-item">
                                <input type="checkbox" name="channels[]" value="<?= $ch['id'] ?>"
                                    <?= isset($linkedChannels) && in_array($ch['id'], $linkedChannels) ? 'checked' : '' ?>>
                                <i class="<?= channel_icon($ch['type']) ?>"></i> <?= e($ch['name']) ?>
                                <?php if (($ch['type'] ?? '') === 'whatsapp'): ?>
                                    <?php if (($ch['connection_status'] ?? '') === 'connected'): ?>
                                        <span class="badge badge-success" title="<?= e($ch['phone_number'] ?? '') ?>">conectado</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary" title="Vínculo permitido mesmo desconectado">desconectado</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-layer-group"></i> Natureza das conversas</label>
                    <select name="conversation_scope" class="form-control">
                        <option value="all" <?= (string) ($inbox['conversation_scope'] ?? 'all') === 'all' ? 'selected' : '' ?>>Todas (mensagens gerais e grupos)</option>
                        <option value="groups" <?= (string) ($inbox['conversation_scope'] ?? 'all') === 'groups' ? 'selected' : '' ?>>Somente grupos do WhatsApp</option>
                        <option value="non_groups" <?= (string) ($inbox['conversation_scope'] ?? 'all') === 'non_groups' ? 'selected' : '' ?>>Somente mensagens gerais</option>
                    </select>
                    <p class="form-hint">
                        Define o que a caixa recebe: a caixa de grupos do WhatsApp e a caixa de
                        mensagens gerais podem cobrir os mesmos setores sem mostrar o mesmo
                        atendimento. O padrão (<strong>Todas</strong>) não muda nada.
                    </p>
                </div>

                <div class="form-group" id="inboxDepartmentsGroup">
                    <label><i class="fas fa-layer-group"></i> Setores nesta caixa</label>
                    <p class="form-hint">
                        Conversas destes setores aparecem nesta caixa. Também valem como acesso:
                        quem é do setor enxerga a caixa.
                    </p>
                    <div class="inbox-check-grid">
                        <?php foreach ($departments as $d): ?>
                            <label class="inbox-check-item">
                                <input type="checkbox" name="departments[]" value="<?= $d['id'] ?>"
                                    <?= isset($linkedDepartments) && in_array($d['id'], $linkedDepartments) ? 'checked' : '' ?>>
                                <?= e($d['name']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group" id="inboxUsersGroup">
                    <label><i class="fas fa-users"></i> Usuários com acesso</label>
                    <p class="form-hint">
                        Atendentes que enxergam esta caixa além dos membros dos setores.
                    </p>
                    <div class="inbox-check-grid">
                        <?php foreach ($users as $u): ?>
                            <label class="inbox-check-item">
                                <input type="checkbox" name="users[]" value="<?= $u['id'] ?>"
                                    <?= isset($linkedUsers) && in_array($u['id'], $linkedUsers) ? 'checked' : '' ?>>
                                <?= e($u['name']) ?> <span class="text-muted">(<?= e($u['role']) ?>)</span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="card" style="border-radius:12px;overflow:hidden;margin-top:18px;background:var(--bg-content);border:1px solid var(--border-soft)">
                    <div class="card-header" style="background:transparent;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
                        <h3 style="margin:0;font-size:15px;display:flex;align-items:center;gap:8px">
                            <i class="fas fa-stopwatch" style="color:var(--success)"></i> SLA desta caixa
                        </h3>
                        <div class="form-check" style="display:flex;align-items:center;gap:8px;margin:0;padding:8px 12px;background:var(--bg-card);border-radius:8px">
                            <input type="checkbox" name="sla_use_global" value="1" id="slaUseGlobal" <?= $useGlobal ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--primary)">
                            <label for="slaUseGlobal" style="font-weight:500;cursor:pointer;margin:0;font-size:13px">
                                Usar padrão do sistema
                            </label>
                        </div>
                    </div>
                    <div class="card-body">
                        <p id="slaUseGlobalHint" style="margin:0 0 14px;font-size:13px;color:var(--text-muted)">
                            <i class="fas fa-info-circle"></i>
                            Os valores abaixo são ignorados: a caixa usará os tempos, cores e sons definidos em
                            <a href="<?= url('settings') ?>">Configurações Gerais</a>.
                        </p>

                        <div id="slaCustomFields" style="<?= $useGlobal ? 'display:none' : '' ?>">
                            <div class="form-check" style="display:flex;align-items:center;gap:10px;margin-bottom:14px;padding:10px 14px;background:var(--bg-card);border-radius:8px">
                                <input type="checkbox" name="sla_enabled" value="1" id="slaEnabled" <?= !empty($sv['enabled']) ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--primary)">
                                <label for="slaEnabled" style="font-weight:500;cursor:pointer;margin:0">
                                    Ativar SLA para esta caixa
                                </label>
                            </div>

                            <div class="form-row">
                                <div class="form-group col-6">
                                    <label><i class="fas fa-exclamation-triangle" style="color:#f59e0b"></i> Atenção (amarelo) após</label>
                                    <div style="display:flex;align-items:center;gap:8px">
                                        <input type="number" min="1" max="1440" name="sla_attention_minutes" class="form-control"
                                               value="<?= e($sv['attention_minutes']) ?>" style="width:100px">
                                        <span style="color:var(--text-muted)">minutos</span>
                                    </div>
                                </div>
                                <div class="form-group col-6">
                                    <label><i class="fas fa-bell" style="color:#dc2626"></i> Alerta (vermelho) após</label>
                                    <div style="display:flex;align-items:center;gap:8px">
                                        <input type="number" min="1" max="1440" name="sla_alert_minutes" class="form-control"
                                               value="<?= e($sv['alert_minutes']) ?>" style="width:100px">
                                        <span style="color:var(--text-muted)">minutos</span>
                                    </div>
                                    <small class="text-muted" style="display:block;margin-top:4px;font-size:12px">
                                        Deve ser maior que o tempo de atenção.
                                    </small>
                                </div>
                            </div>

                            <h4 style="margin:14px 0 10px;font-size:13px;font-weight:700;display:flex;align-items:center;gap:6px">
                                <i class="fas fa-palette" style="color:var(--primary)"></i> Cores de fundo
                            </h4>
                            <div class="form-row">
                                <div class="form-group col-4">
                                    <label>Normal</label>
                                    <div style="display:flex;align-items:center;gap:6px">
                                        <input type="color" name="sla_color_normal" value="<?= e($sv['color_normal']) ?>" style="width:42px;height:36px;padding:2px;border-radius:6px;border:1px solid var(--border-soft)">
                                        <input type="text" name="sla_color_normal_text" value="<?= e($sv['color_normal_text']) ?>" class="form-control" style="flex:1;font-family:monospace;font-size:12px">
                                    </div>
                                </div>
                                <div class="form-group col-4">
                                    <label>Atenção</label>
                                    <div style="display:flex;align-items:center;gap:6px">
                                        <input type="color" name="sla_color_attention" value="<?= e($sv['color_attention']) ?>" style="width:42px;height:36px;padding:2px;border-radius:6px;border:1px solid var(--border-soft)">
                                        <input type="text" name="sla_color_attention_text" value="<?= e($sv['color_attention_text']) ?>" class="form-control" style="flex:1;font-family:monospace;font-size:12px">
                                    </div>
                                </div>
                                <div class="form-group col-4">
                                    <label>Alerta</label>
                                    <div style="display:flex;align-items:center;gap:6px">
                                        <input type="color" name="sla_color_alert" value="<?= e($sv['color_alert']) ?>" style="width:42px;height:36px;padding:2px;border-radius:6px;border:1px solid var(--border-soft)">
                                        <input type="text" name="sla_color_alert_text" value="<?= e($sv['color_alert_text']) ?>" class="form-control" style="flex:1;font-family:monospace;font-size:12px">
                                    </div>
                                </div>
                            </div>

                            <h4 style="margin:14px 0 10px;font-size:13px;font-weight:700;display:flex;align-items:center;gap:6px">
                                <i class="fas fa-volume-up" style="color:var(--primary)"></i> Sons de alerta
                            </h4>
                            <div class="form-row">
                                <div class="form-group col-6">
                                    <label>Som ao entrar em "Atenção"</label>
                                    <select name="sla_sound_attention" class="form-control">
                                        <option value="default" <?= $sv['sound_attention'] === 'default' ? 'selected' : '' ?>>Beep padrão (520Hz)</option>
                                        <option value="soft" <?= $sv['sound_attention'] === 'soft' ? 'selected' : '' ?>>Beep suave (420Hz)</option>
                                        <option value="sharp" <?= $sv['sound_attention'] === 'sharp' ? 'selected' : '' ?>>Beep agudo (760Hz)</option>
                                        <option value="silent" <?= $sv['sound_attention'] === 'silent' ? 'selected' : '' ?>>Silencioso</option>
                                    </select>
                                </div>
                                <div class="form-group col-6">
                                    <label>Som ao entrar em "Alerta"</label>
                                    <select name="sla_sound_alert" class="form-control">
                                        <option value="default" <?= $sv['sound_alert'] === 'default' ? 'selected' : '' ?>>Tri-tone padrão (660+440+660Hz)</option>
                                        <option value="soft" <?= $sv['sound_alert'] === 'soft' ? 'selected' : '' ?>>Tri-tone suave (520+360+520Hz)</option>
                                        <option value="sharp" <?= $sv['sound_alert'] === 'sharp' ? 'selected' : '' ?>>Tri-tone agudo (880+660+880Hz)</option>
                                        <option value="silent" <?= $sv['sound_alert'] === 'silent' ? 'selected' : '' ?>>Silencioso</option>
                                    </select>
                                </div>
                            </div>

                            <h4 style="margin:14px 0 10px;font-size:13px;font-weight:700;display:flex;align-items:center;gap:6px">
                                <i class="fas fa-chart-line" style="color:var(--primary)"></i> Medição de compliance
                            </h4>
                            <div class="form-row">
                                <div class="form-group col-6">
                                    <label>SLA de 1ª resposta</label>
                                    <div style="display:flex;align-items:center;gap:8px">
                                        <input type="number" min="1" max="10080" name="sla_response_minutes" class="form-control"
                                               value="<?= e($sv['response_minutes']) ?>" style="width:100px">
                                        <span style="color:var(--text-muted)">minutos</span>
                                    </div>
                                </div>
                                <div class="form-group col-6">
                                    <label>SLA de resolução</label>
                                    <div style="display:flex;align-items:center;gap:8px">
                                        <input type="number" min="1" max="43200" name="sla_resolution_minutes" class="form-control"
                                               value="<?= e($sv['resolution_minutes']) ?>" style="width:100px">
                                        <span style="color:var(--text-muted)">minutos</span>
                                    </div>
                                </div>
                            </div>

                            <h4 style="margin:14px 0 10px;font-size:13px;font-weight:700;display:flex;align-items:center;gap:6px">
                                <i class="fas fa-bell" style="color:var(--primary)"></i> Escalonamento na violação
                            </h4>
                            <div class="form-group" style="margin-bottom:12px">
                                <label>Alerta de cliente sem resposta</label>
                                <div style="display:flex;align-items:center;gap:8px">
                                    <input type="number" min="0" max="10080" name="sla_pending_response_minutes" class="form-control"
                                           value="<?= e($sv['pending_response_minutes']) ?>" style="width:100px">
                                    <span style="color:var(--text-muted)">minutos</span>
                                </div>
                                <small class="text-muted" style="display:block;margin-top:4px;font-size:12px">
                                    0 = usa o limite da 1ª resposta.
                                </small>
                            </div>
                            <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:14px">
                                <div class="form-check" style="display:flex;align-items:center;gap:10px">
                                    <input type="checkbox" name="sla_business_hours" value="1" id="slaBoxBusinessHours" <?= !empty($sv['business_hours']) ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--primary)">
                                    <label for="slaBoxBusinessHours" style="cursor:pointer;margin:0;font-size:13px">
                                        Considerar horário de atendimento
                                    </label>
                                </div>
                                <div class="form-check" style="display:flex;align-items:center;gap:10px">
                                    <input type="checkbox" name="sla_escalate_tag" value="1" id="slaBoxEscalateTag" <?= !empty($sv['escalate_tag']) ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--primary)">
                                    <label for="slaBoxEscalateTag" style="cursor:pointer;margin:0;font-size:13px">
                                        Aplicar a tag "SLA violado"
                                    </label>
                                </div>
                                <div class="form-check" style="display:flex;align-items:center;gap:10px">
                                    <input type="checkbox" name="sla_escalate_managers" value="1" id="slaBoxEscalateManagers" <?= !empty($sv['escalate_managers']) ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--primary)">
                                    <label for="slaBoxEscalateManagers" style="cursor:pointer;margin:0;font-size:13px">
                                        Notificar os gerentes do departamento
                                    </label>
                                </div>
                                <div class="form-check" style="display:flex;align-items:center;gap:10px">
                                    <input type="checkbox" name="sla_escalate_autoassign" value="1" id="slaBoxEscalateAutoassign" <?= !empty($sv['escalate_autoassign']) ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--primary)">
                                    <label for="slaBoxEscalateAutoassign" style="cursor:pointer;margin:0;font-size:13px">
                                        Atribuir ao atendente menos carregado
                                    </label>
                                </div>
                                <div class="form-check" style="display:flex;align-items:center;gap:10px">
                                    <input type="checkbox" name="sla_escalate_whatsapp" value="1" id="slaBoxEscalateWhatsapp" <?= !empty($sv['escalate_whatsapp']) ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--primary)">
                                    <label for="slaBoxEscalateWhatsapp" style="cursor:pointer;margin:0;font-size:13px">
                                        Avisar o plantão por WhatsApp
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> <?= $inbox ? 'Salvar' : 'Criar caixa' ?>
                    </button>
                    <a href="<?= url('inboxes') ?>" class="btn btn-outline">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function() {
    var toggle = document.getElementById('slaUseGlobal');
    var hint = document.getElementById('slaUseGlobalHint');
    var fields = document.getElementById('slaCustomFields');
    if (!toggle || !fields) return;
    function apply() {
        var on = toggle.checked;
        fields.style.display = on ? 'none' : '';
        if (hint) hint.style.display = on ? '' : 'none';
    }
    toggle.addEventListener('change', apply);
    apply();
})();
</script>
