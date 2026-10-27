<div class="settings-page">
    <?php $subPage = 'notifications'; require __DIR__ . '/_tabs.php'; ?>

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

                <?php
                $soundOpts = function (string $current, array $builtins) use ($customSounds) {
                    $html = '';
                    foreach ($builtins as $val => $label) {
                        $html .= '<option value="' . e($val) . '"' . ($current === $val ? ' selected' : '') . '>' . e($label) . '</option>';
                    }
                    foreach (($customSounds ?? []) as $s) {
                        $v = 'custom:' . (int) $s['id'];
                        $html .= '<option value="' . e($v) . '"' . ($current === $v ? ' selected' : '') . '>🎵 ' . e($s['name']) . ' (meu áudio)</option>';
                    }
                    return $html;
                };
                ?>
                <div class="form-row" id="soundOptions">
                    <div class="form-group col-6">
                        <label><i class="fas fa-comment-dots" style="color:var(--primary)"></i> Som de nova mensagem</label>
                        <div style="display:flex;gap:8px">
                            <select name="sound_new_message" class="form-control sound-select" data-sound-type="message">
                                <?= $soundOpts($prefs['sound_new_message'] ?? 'default', ['default' => 'Beep padrão (520Hz)', 'soft' => 'Beep suave (400Hz)', 'sharp' => 'Beep agudo (800Hz)', 'silent' => 'Silencioso']) ?>
                            </select>
                            <button type="button" class="btn btn-sm btn-outline" onclick="previewSound('new_message', this)" title="Testar som" style="white-space:nowrap;padding:6px 12px;border-radius:8px">
                                <i class="fas fa-play"></i> Testar
                            </button>
                        </div>
                    </div>

                    <div class="form-group col-6">
                        <label><i class="fas fa-user-plus" style="color:var(--success)"></i> Som de novo cliente</label>
                        <div style="display:flex;gap:8px">
                            <select name="sound_new_conversation" class="form-control sound-select" data-sound-type="new_conv">
                                <?= $soundOpts($prefs['sound_new_conversation'] ?? 'default', ['default' => 'Two-tone padrão (440+660Hz)', 'soft' => 'Two-tone suave (350+500Hz)', 'sharp' => 'Two-tone agudo (700+900Hz)', 'silent' => 'Silencioso']) ?>
                            </select>
                            <button type="button" class="btn btn-sm btn-outline" onclick="previewSound('new_conversation', this)" title="Testar som" style="white-space:nowrap;padding:6px 12px;border-radius:8px">
                                <i class="fas fa-play"></i> Testar
                            </button>
                        </div>
                    </div>

                    <div class="form-group col-6">
                        <label><i class="fas fa-at" style="color:#f59e0b"></i> Som de menção (@número / @todos)</label>
                        <div style="display:flex;gap:8px">
                            <select name="sound_mention" class="form-control sound-select" data-sound-type="mention">
                                <?= $soundOpts($prefs['sound_mention'] ?? 'default', ['default' => 'Tri-tone menção (880+660+1174Hz)', 'soft' => 'Menção suave', 'sharp' => 'Menção aguda', 'silent' => 'Silencioso']) ?>
                            </select>
                            <button type="button" class="btn btn-sm btn-outline" onclick="previewSound('mention', this)" title="Testar som" style="white-space:nowrap;padding:6px 12px;border-radius:8px">
                                <i class="fas fa-play"></i> Testar
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card" style="border-radius:12px;overflow:hidden;margin-top:16px">
            <div class="card-header" style="background:var(--bg-card)">
                <h3><i class="fas fa-file-audio" style="color:var(--primary)"></i> Meus áudios personalizados</h3>
            </div>
            <div class="card-body">
                <p style="margin:0 0 12px;font-size:13px;color:var(--text-muted)">
                    Suba toques curtos (mp3, wav, ogg ou m4a — máx. 1 MB cada, até <?= \App\Models\NotificationSound::MAX_PER_USER ?> áudios)
                    e escolha-os nos seletores de som acima.
                </p>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
                    <input type="file" id="customSoundFile" accept=".mp3,.wav,.ogg,.m4a,audio/*" class="form-control" style="max-width:320px">
                    <button type="button" class="btn btn-sm btn-primary" onclick="uploadCustomSound(this)" style="padding:8px 16px;border-radius:8px">
                        <i class="fas fa-upload"></i> Enviar áudio
                    </button>
                    <span id="customSoundMsg" style="font-size:12px"></span>
                </div>
                <div id="customSoundsList" style="display:flex;flex-direction:column;gap:8px">
                    <?php if (empty($customSounds)): ?>
                        <p class="form-hint" id="customSoundsEmpty" style="margin:0">Nenhum áudio enviado ainda.</p>
                    <?php endif; ?>
                    <?php foreach (($customSounds ?? []) as $s): ?>
                        <div class="custom-sound-row" data-sound-id="<?= (int) $s['id'] ?>" style="display:flex;align-items:center;gap:10px;padding:8px 12px;background:var(--bg-content);border-radius:8px">
                            <i class="fas fa-music" style="color:var(--primary)"></i>
                            <strong style="flex:1;font-size:13px"><?= e($s['name']) ?></strong>
                            <button type="button" class="btn btn-sm btn-outline" onclick="playCustomSound(<?= (int) $s['id'] ?>)" title="Ouvir"><i class="fas fa-play"></i></button>
                            <button type="button" class="btn btn-sm btn-outline" onclick="deleteCustomSound(<?= (int) $s['id'] ?>, this)" title="Excluir" style="color:#dc2626"><i class="fas fa-trash"></i></button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="card" style="border-radius:12px;overflow:hidden;margin-top:16px">
            <div class="card-header" style="background:var(--bg-card)">
                <h3><i class="fas fa-globe" style="color:var(--primary)"></i> Notificações do Navegador (PC)</h3>
            </div>
            <div class="card-body">
                <div class="form-check" style="display:flex;align-items:center;gap:10px;padding:12px 16px;background:var(--bg-content);border-radius:8px;">
                    <input type="checkbox" name="browser_notif_enabled" value="1" id="browserNotifEnabled" <?= (!isset($prefs['browser_notif_enabled']) || $prefs['browser_notif_enabled'] !== false) ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:var(--primary)">
                    <label for="browserNotifEnabled" style="font-weight:500;cursor:pointer;margin:0">
                        Mostrar notificações do navegador quando o sistema estiver em segundo plano
                    </label>
                </div>
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:12px">
                    <span id="browserNotifStatus" class="badge" style="font-size:12px">verificando…</span>
                    <button type="button" class="btn btn-sm btn-primary" onclick="enableBrowserNotif(this)" style="padding:8px 16px;border-radius:8px">
                        <i class="fas fa-bell"></i> Ativar notificações
                    </button>
                    <button type="button" class="btn btn-sm btn-outline" onclick="sendBrowserTestNotif()" style="padding:8px 16px;border-radius:8px">
                        <i class="fas fa-paper-plane"></i> Enviar teste
                    </button>
                </div>
                <p style="margin:8px 0 0;font-size:12px;color:var(--text-muted)">
                    <i class="fas fa-info-circle"></i>
                    As notificações do navegador aparecem mesmo com a aba minimizada ou em outra aba.
                    Clicar nela abre a conversa. Se o status mostrar <strong>bloqueadas</strong>, libere no
                    cadeado da barra de endereço do navegador.
                </p>
            </div>
        </div>

        <div class="card" style="border-radius:12px;overflow:hidden;margin-top:16px">
            <div class="card-header" style="background:var(--bg-card)">
                <h3><i class="fas fa-stopwatch" style="color:var(--success)"></i> SLA — Padrão do Sistema</h3>
            </div>
            <div class="card-body">
                <p style="margin:0 0 16px;font-size:13px;color:var(--text-muted)">
                    Estes valores são usados por <strong>todas as caixas de entrada</strong> que estiverem
                    com "Usar padrão do sistema" habilitado. Para personalizar tempos, cores ou sons
                    de uma caixa específica, abra
                    <a href="<?= url('inboxes') ?>">Caixas de Entrada</a> e edite a caixa desejada.
                </p>

                <div class="form-check" style="display:flex;align-items:center;gap:10px;margin-bottom:16px;padding:12px 16px;background:var(--bg-content);border-radius:8px;">
                    <input type="checkbox" name="sla_enabled" value="1" id="slaEnabled" <?= (!empty($sla['enabled']) && $sla['enabled'] === '1') ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:var(--primary)">
                    <label for="slaEnabled" style="font-weight:500;cursor:pointer;margin:0">
                        Ativar SLA (cores de espera e sons de alerta na lista de conversas)
                    </label>
                </div>

                <div class="form-check" style="display:flex;align-items:center;gap:10px;margin-bottom:8px;padding:12px 16px;background:var(--bg-content);border-radius:8px;">
                    <input type="checkbox" name="sla_business_hours" value="1" id="slaBusinessHours" <?= (!isset($sla['business_hours']) || $sla['business_hours'] === '1') ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:var(--primary)">
                    <label for="slaBusinessHours" style="font-weight:500;cursor:pointer;margin:0">
                        Considerar horário de atendimento
                    </label>
                </div>
                <p style="margin:0 0 16px;padding:0 16px;font-size:12px;color:var(--text-muted)">
                    <i class="fas fa-info-circle"></i>
                    Com esta opção, o relógio do SLA só conta o tempo dentro do horário de atendimento
                    configurado no <strong>departamento</strong> da conversa (veja
                    <a href="<?= url('settings/departments') ?>">Departamentos</a>): o tempo fora do expediente, fins
                    de semana e feriados não consomem SLA. O relógio também congela quando o atendente já
                    respondeu (a bola está com o cliente) ou quando a conversa está
                    <em>aguardando outro setor</em>.
                </p>

                <div class="form-row">
                    <div class="form-group col-6">
                        <label><i class="fas fa-exclamation-triangle" style="color:#f59e0b"></i> Atenção (amarelo) após</label>
                        <div style="display:flex;align-items:center;gap:8px">
                            <input type="number" min="1" max="1440" name="sla_attention_minutes" class="form-control"
                                   value="<?= e($sla['attention_minutes'] ?? '5') ?>" style="width:100px">
                            <span style="color:var(--text-muted)">minutos</span>
                        </div>
                    </div>
                    <div class="form-group col-6">
                        <label><i class="fas fa-bell" style="color:#dc2626"></i> Alerta (vermelho) após</label>
                        <div style="display:flex;align-items:center;gap:8px">
                            <input type="number" min="1" max="1440" name="sla_alert_minutes" class="form-control"
                                   value="<?= e($sla['alert_minutes'] ?? '10') ?>" style="width:100px">
                            <span style="color:var(--text-muted)">minutos</span>
                        </div>
                        <small class="text-muted" style="display:block;margin-top:4px;font-size:12px">
                            Deve ser maior que o tempo de atenção.
                        </small>
                    </div>
                </div>

                <h4 style="margin:18px 0 10px;font-size:14px;font-weight:700;display:flex;align-items:center;gap:6px">
                    <i class="fas fa-palette" style="color:var(--primary)"></i> Cores de fundo (padrão)
                </h4>
                <div class="form-row">
                    <div class="form-group col-4">
                        <label>Normal</label>
                        <div style="display:flex;align-items:center;gap:6px">
                            <input type="color" name="sla_color_normal" value="<?= e($sla['color_normal'] ?? '#dcfce7') ?>" style="width:42px;height:36px;padding:2px;border-radius:6px;border:1px solid var(--border-soft)">
                            <input type="text" name="sla_color_normal_text" value="<?= e($sla['color_normal_text'] ?? '#166534') ?>" class="form-control" placeholder="#166534" style="flex:1;font-family:monospace;font-size:12px">
                        </div>
                        <small class="text-muted" style="font-size:11px">Fundo + cor do texto</small>
                    </div>
                    <div class="form-group col-4">
                        <label>Atenção</label>
                        <div style="display:flex;align-items:center;gap:6px">
                            <input type="color" name="sla_color_attention" value="<?= e($sla['color_attention'] ?? '#fef3c7') ?>" style="width:42px;height:36px;padding:2px;border-radius:6px;border:1px solid var(--border-soft)">
                            <input type="text" name="sla_color_attention_text" value="<?= e($sla['color_attention_text'] ?? '#92400e') ?>" class="form-control" placeholder="#92400e" style="flex:1;font-family:monospace;font-size:12px">
                        </div>
                    </div>
                    <div class="form-group col-4">
                        <label>Alerta</label>
                        <div style="display:flex;align-items:center;gap:6px">
                            <input type="color" name="sla_color_alert" value="<?= e($sla['color_alert'] ?? '#fee2e2') ?>" style="width:42px;height:36px;padding:2px;border-radius:6px;border:1px solid var(--border-soft)">
                            <input type="text" name="sla_color_alert_text" value="<?= e($sla['color_alert_text'] ?? '#991b1b') ?>" class="form-control" placeholder="#991b1b" style="flex:1;font-family:monospace;font-size:12px">
                        </div>
                    </div>
                </div>

                <div style="display:flex;gap:8px;margin-top:6px;flex-wrap:wrap">
                    <div class="conv-item conv-sla-normal" style="flex:1;min-width:140px;padding:8px 10px;border-radius:10px;font-size:12px;display:flex;align-items:center;gap:6px">
                        <i class="fas fa-circle" style="font-size:8px"></i> Exemplo normal
                    </div>
                    <div class="conv-item conv-sla-attention" style="flex:1;min-width:140px;padding:8px 10px;border-radius:10px;font-size:12px;display:flex;align-items:center;gap:6px">
                        <i class="fas fa-exclamation-triangle"></i> Exemplo atenção
                    </div>
                    <div class="conv-item conv-sla-alert" style="flex:1;min-width:140px;padding:8px 10px;border-radius:10px;font-size:12px;display:flex;align-items:center;gap:6px">
                        <i class="fas fa-bell"></i> Exemplo alerta
                    </div>
                </div>

                <h4 style="margin:20px 0 10px;font-size:14px;font-weight:700;display:flex;align-items:center;gap:6px">
                    <i class="fas fa-volume-up" style="color:var(--primary)"></i> Sons de alerta (padrão)
                </h4>
                <div class="form-row">
                    <div class="form-group col-6">
                        <label>Som ao entrar em "Atenção"</label>
                        <div style="display:flex;gap:8px">
                            <select name="sla_sound_attention" class="form-control">
                                <option value="default" <?= ($sla['sound_attention'] ?? 'default') === 'default' ? 'selected' : '' ?>>Beep padrão (520Hz)</option>
                                <option value="soft" <?= ($sla['sound_attention'] ?? '') === 'soft' ? 'selected' : '' ?>>Beep suave (420Hz)</option>
                                <option value="sharp" <?= ($sla['sound_attention'] ?? '') === 'sharp' ? 'selected' : '' ?>>Beep agudo (760Hz)</option>
                                <option value="silent" <?= ($sla['sound_attention'] ?? '') === 'silent' ? 'selected' : '' ?>>Silencioso</option>
                            </select>
                            <button type="button" class="btn btn-sm btn-outline" onclick="previewSlaSound('attention', this)" style="white-space:nowrap;padding:6px 12px;border-radius:8px">
                                <i class="fas fa-play"></i> Testar
                            </button>
                        </div>
                    </div>
                    <div class="form-group col-6">
                        <label>Som ao entrar em "Alerta"</label>
                        <div style="display:flex;gap:8px">
                            <select name="sla_sound_alert" class="form-control">
                                <option value="default" <?= ($sla['sound_alert'] ?? 'default') === 'default' ? 'selected' : '' ?>>Tri-tone padrão (660+440+660Hz)</option>
                                <option value="soft" <?= ($sla['sound_alert'] ?? '') === 'soft' ? 'selected' : '' ?>>Tri-tone suave (520+360+520Hz)</option>
                                <option value="sharp" <?= ($sla['sound_alert'] ?? '') === 'sharp' ? 'selected' : '' ?>>Tri-tone agudo (880+660+880Hz)</option>
                                <option value="silent" <?= ($sla['sound_alert'] ?? '') === 'silent' ? 'selected' : '' ?>>Silencioso</option>
                            </select>
                            <button type="button" class="btn btn-sm btn-outline" onclick="previewSlaSound('alert', this)" style="white-space:nowrap;padding:6px 12px;border-radius:8px">
                                <i class="fas fa-play"></i> Testar
                            </button>
                        </div>
                    </div>
                </div>
                <small class="text-muted" style="display:block;margin-top:4px;font-size:12px">
                    <i class="fas fa-info-circle"></i>
                    Os sons só tocam uma vez por conversa, na transição para um nível mais crítico.
                    Use o "som mestre" acima para ativar/silenciar todos os sons.
                </small>

                <h4 style="margin:22px 0 10px;font-size:14px;font-weight:700;display:flex;align-items:center;gap:6px">
                    <i class="fas fa-chart-line" style="color:var(--primary)"></i> Medição de compliance
                </h4>
                <div class="form-row">
                    <div class="form-group col-6">
                        <label><i class="fas fa-bolt" style="color:#f59e0b"></i> SLA de 1ª resposta</label>
                        <div style="display:flex;align-items:center;gap:8px">
                            <input type="number" min="1" max="10080" name="sla_response_minutes" class="form-control"
                                   value="<?= e($sla['response_minutes'] ?? '15') ?>" style="width:100px">
                            <span style="color:var(--text-muted)">minutos</span>
                        </div>
                        <small class="text-muted" style="display:block;margin-top:4px;font-size:12px">
                            Tempo máximo até a primeira resposta do <strong>atendente</strong>. Resposta de
                            robô não conta. Passou disso, a conversa é contada como violação.
                        </small>
                    </div>
                    <div class="form-group col-6">
                        <label><i class="fas fa-flag-checkered" style="color:var(--primary)"></i> SLA de resolução</label>
                        <div style="display:flex;align-items:center;gap:8px">
                            <input type="number" min="1" max="43200" name="sla_resolution_minutes" class="form-control"
                                   value="<?= e($sla['resolution_minutes'] ?? '1440') ?>" style="width:100px">
                            <span style="color:var(--text-muted)">minutos</span>
                        </div>
                        <small class="text-muted" style="display:block;margin-top:4px;font-size:12px">
                            Tempo máximo até o encerramento, contando só o período em que a bola
                            esteve com a gente (fora do expediente e com o cliente não contam).
                        </small>
                    </div>
                </div>

                <h4 style="margin:18px 0 10px;font-size:14px;font-weight:700;display:flex;align-items:center;gap:6px">
                    <i class="fas fa-bell" style="color:var(--primary)"></i> Escalonamento na violação
                </h4>
                <div class="form-group" style="margin-bottom:14px">
                    <label><i class="fas fa-user-clock" style="color:#f59e0b"></i> Alerta de cliente sem resposta</label>
                    <div style="display:flex;align-items:center;gap:8px">
                        <input type="number" min="0" max="10080" name="sla_pending_response_minutes" class="form-control"
                               value="<?= e($sla['pending_response_minutes'] ?? '0') ?>" style="width:100px">
                        <span style="color:var(--text-muted)">minutos</span>
                    </div>
                    <small class="text-muted" style="display:block;margin-top:4px;font-size:12px">
                        Tempo que o cliente pode ficar esperando <strong>depois de a conversa já ter sido
                        atendida</strong> antes de o sistema alertar os gerentes. <strong>0</strong> usa o
                        mesmo limite da 1ª resposta.
                    </small>
                </div>
                <div class="form-group" style="margin-bottom:14px">
                    <label><i class="fas fa-users" style="color:#7c3aed"></i> SLA de menção em grupo</label>
                    <div style="display:flex;align-items:center;gap:8px">
                        <input type="number" min="0" max="10080" name="sla_group_mention_minutes" class="form-control"
                               value="<?= e($sla['group_mention_minutes'] ?? '0') ?>" style="width:100px">
                        <span style="color:var(--text-muted)">minutos</span>
                    </div>
                    <small class="text-muted" style="display:block;margin-top:4px;font-size:12px">
                        Tempo que uma <strong>menção em grupo de WhatsApp</strong> pode ficar sem resposta antes
                        de o sistema alertar os gerentes. Cada menção tem o seu próprio alerta.
                        <strong>0</strong> usa o mesmo limite da 1ª resposta.
                    </small>
                </div>
                <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:16px">
                    <div class="form-check" style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--bg-content);border-radius:8px">
                        <input type="checkbox" name="sla_escalate_tag" value="1" id="slaEscalateTag" <?= (($sla['escalate_tag'] ?? '1') === '1') ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--primary)">
                        <label for="slaEscalateTag" style="font-weight:500;cursor:pointer;margin:0;font-size:13px">
                            Aplicar a tag <strong>"SLA violado"</strong> na conversa
                        </label>
                    </div>
                    <div class="form-check" style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--bg-content);border-radius:8px">
                        <input type="checkbox" name="sla_escalate_managers" value="1" id="slaEscalateManagers" <?= (($sla['escalate_managers'] ?? '1') === '1') ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--primary)">
                        <label for="slaEscalateManagers" style="font-weight:500;cursor:pointer;margin:0;font-size:13px">
                            Notificar os gerentes do departamento
                        </label>
                    </div>
                    <div class="form-check" style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--bg-content);border-radius:8px">
                        <input type="checkbox" name="sla_escalate_autoassign" value="1" id="slaEscalateAutoassign" <?= (($sla['escalate_autoassign'] ?? '0') === '1') ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--primary)">
                        <label for="slaEscalateAutoassign" style="font-weight:500;cursor:pointer;margin:0;font-size:13px">
                            Atribuir ao atendente menos carregado
                            <span class="text-muted" style="font-weight:400">(quando a conversa ainda não tem responsável)</span>
                        </label>
                    </div>
                    <div class="form-check" style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--bg-content);border-radius:8px">
                        <input type="checkbox" name="sla_escalate_whatsapp" value="1" id="slaEscalateWhatsapp" <?= (($sla['escalate_whatsapp'] ?? '0') === '1') ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--primary)">
                        <label for="slaEscalateWhatsapp" style="font-weight:500;cursor:pointer;margin:0;font-size:13px">
                            Avisar o plantão por WhatsApp
                            <span class="text-muted" style="font-weight:400">(telefone configurado no departamento)</span>
                        </label>
                    </div>
                </div>
                <div class="form-group">
                    <label>Mensagem do aviso</label>
                    <textarea name="sla_escalation_message" rows="2" class="form-control"><?= e($sla['escalation_message'] ?? '') ?></textarea>
                    <small class="text-muted" style="display:block;margin-top:4px;font-size:12px">
                        Variáveis: <code>{{contact.name}}</code>, <code>{{conversation.protocol}}</code>,
                        <code>{{sla.wait}}</code>, <code>{{sla.limit}}</code>, <code>{{sla.type}}</code>
                    </small>
                </div>
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
window.__customSounds = <?= json_encode(array_column($customSounds ?? [], null, 'id'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
(function syncCustomSounds() {
    if (window.__enhancements && window.__enhancements.SoundManager) {
        var map = {};
        Object.keys(window.__customSounds || {}).forEach(function (id) {
            map[id] = window.__customSounds[id].url;
        });
        window.__enhancements.SoundManager.setCustomSounds(map);
    }
})();
function soundTypeFor(key) {
    return key === 'new_message' ? 'message' : (key === 'new_conversation' ? 'new_conv' : key);
}
function previewSound(type, btn) {
    if (window.__enhancements && window.__enhancements.SoundManager) {
        var select = btn.closest('.form-group').querySelector('select');
        var profile = select ? select.value : 'default';
        window.__enhancements.SoundManager.play(soundTypeFor(type), profile);
    }
}
function previewSlaSound(type, btn) {
    if (window.__enhancements && window.__enhancements.SoundManager) {
        var select = btn.closest('.form-group').querySelector('select');
        var profile = select ? select.value : 'default';
        window.__enhancements.SoundManager.play(type, profile);
    }
}
function playCustomSound(id) {
    if (window.__enhancements && window.__enhancements.SoundManager) {
        window.__enhancements.SoundManager.play('message', 'custom:' + id);
    } else {
        var s = (window.__customSounds || {})[id];
        if (s) new Audio(s.url).play();
    }
}
function baseUrl() {
    return document.querySelector('meta[name="base-url"]')?.content || '';
}
function uploadCustomSound(btn) {
    var input = document.getElementById('customSoundFile');
    var msg = document.getElementById('customSoundsMsg') || document.getElementById('customSoundMsg');
    var file = input && input.files && input.files[0];
    if (!file) { msg.textContent = 'Selecione um arquivo primeiro.'; msg.style.color = '#dc2626'; return; }
    btn.disabled = true;
    msg.textContent = 'Enviando…'; msg.style.color = '';
    var fd = new FormData();
    fd.append('audio', file);
    fetch(baseUrl() + '/api/sounds/upload', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
        .then(function (res) {
            btn.disabled = false;
            if (!res.body || !res.body.ok) throw new Error((res.body && res.body.error) || 'Falha no envio');
            var s = res.body.sound;
            window.__customSounds[s.id] = s;
            if (window.__enhancements && window.__enhancements.SoundManager) {
                var map = {};
                Object.keys(window.__customSounds).forEach(function (id) { map[id] = window.__customSounds[id].url; });
                window.__enhancements.SoundManager.setCustomSounds(map);
            }
            appendCustomSoundRow(s);
            addCustomOptionToSelects(s);
            input.value = '';
            msg.textContent = 'Áudio "' + s.name + '" pronto para usar!'; msg.style.color = '#166534';
        })
        .catch(function (e) {
            btn.disabled = false;
            msg.textContent = e.message || 'Falha no envio'; msg.style.color = '#dc2626';
        });
}
function appendCustomSoundRow(s) {
    var list = document.getElementById('customSoundsList');
    var empty = document.getElementById('customSoundsEmpty');
    if (empty) empty.remove();
    var div = document.createElement('div');
    div.className = 'custom-sound-row';
    div.dataset.soundId = s.id;
    div.style.cssText = 'display:flex;align-items:center;gap:10px;padding:8px 12px;background:var(--bg-content);border-radius:8px';
    var name = document.createElement('strong');
    name.style.cssText = 'flex:1;font-size:13px';
    name.textContent = s.name;
    var play = document.createElement('button');
    play.type = 'button'; play.className = 'btn btn-sm btn-outline'; play.title = 'Ouvir';
    play.innerHTML = '<i class="fas fa-play"></i>';
    play.onclick = function () { playCustomSound(s.id); };
    var del = document.createElement('button');
    del.type = 'button'; del.className = 'btn btn-sm btn-outline'; del.title = 'Excluir';
    del.style.color = '#dc2626'; del.innerHTML = '<i class="fas fa-trash"></i>';
    del.onclick = function () { deleteCustomSound(s.id, del); };
    var icon = document.createElement('i');
    icon.className = 'fas fa-music'; icon.style.color = 'var(--primary)';
    div.appendChild(icon); div.appendChild(name); div.appendChild(play); div.appendChild(del);
    list.appendChild(div);
}
function addCustomOptionToSelects(s) {
    document.querySelectorAll('select.sound-select').forEach(function (sel) {
        var opt = document.createElement('option');
        opt.value = 'custom:' + s.id;
        opt.textContent = '🎵 ' + s.name + ' (meu áudio)';
        sel.appendChild(opt);
    });
}
function deleteCustomSound(id, btn) {
    var doDelete = function() {
    var fd = new FormData();
    fetch(baseUrl() + '/api/sounds/' + id + '/delete', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (!j || !j.ok) throw new Error((j && j.error) || 'Falha ao excluir');
            delete window.__customSounds[id];
            var row = document.querySelector('.custom-sound-row[data-sound-id="' + id + '"]');
            if (row) row.remove();
            document.querySelectorAll('select.sound-select option[value="custom:' + id + '"]').forEach(function (o) { o.remove(); });
        })
        .catch(function (e) { window.toast(e.message || 'Falha ao excluir'); });
    };
    (window.confirmModal ? window.confirmModal('Excluir este áudio? Conversas que o usam voltam ao toque padrão.', 'Excluir áudio') : Promise.resolve(confirm('Excluir este áudio? Conversas que o usam voltam ao toque padrão.'))).then(function(ok) { if (ok) doDelete(); });
}
// — Notificações do navegador (PC) —
function refreshBrowserNotifStatus() {
    var el = document.getElementById('browserNotifStatus');
    if (!el) return;
    var state = 'unsupported';
    try { state = ('Notification' in window) ? Notification.permission : 'unsupported'; } catch (_) {}
    var map = {
        granted: ['Ativadas ✓', '#166534', '#dcfce7'],
        denied: ['Bloqueadas no navegador', '#991b1b', '#fee2e2'],
        default: ['Ainda não solicitadas', '#92400e', '#fef3c7'],
        unsupported: ['Navegador sem suporte', '#991b1b', '#fee2e2']
    };
    var m = map[state] || map.default;
    el.textContent = m[0];
    el.style.color = m[1];
    el.style.background = m[2];
    el.style.padding = '4px 10px';
    el.style.borderRadius = '20px';
}
function enableBrowserNotif(btn) {
    var gn = window.__enhancements && window.__enhancements.GlobalNotifier;
    if (!('Notification' in window)) { window.toast('Este navegador não suporta notificações.'); return; }
    if (Notification.permission === 'granted') {
        refreshBrowserNotifStatus();
        if (gn) gn.testBrowserNotif();
        return;
    }
    if (btn) btn.disabled = true;
    Notification.requestPermission().then(function (perm) {
        if (btn) btn.disabled = false;
        refreshBrowserNotifStatus();
        if (perm === 'granted' && gn) gn.testBrowserNotif();
        if (perm === 'denied') window.toast('Permissão bloqueada. Libere no cadeado da barra de endereço e tente de novo.');
    }).catch(function () { if (btn) btn.disabled = false; refreshBrowserNotifStatus(); });
}
function sendBrowserTestNotif() {
    var gn = window.__enhancements && window.__enhancements.GlobalNotifier;
    if (!('Notification' in window)) { window.toast('Este navegador não suporta notificações.'); return; }
    if (Notification.permission !== 'granted') { window.toast('Clique em "Ativar notificações" primeiro.'); return; }
    if (gn) gn.testBrowserNotif();
}
document.addEventListener('DOMContentLoaded', refreshBrowserNotifStatus);
refreshBrowserNotifStatus();
</script>
