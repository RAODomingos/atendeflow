<div class="settings-page">
    <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
        <a href="<?= url('settings') ?>" class="btn btn-sm <?= strpos($_SERVER['REQUEST_URI'] ?? '', '/settings/substatuses') === false && strpos($_SERVER['REQUEST_URI'] ?? '', '/settings/subjects') === false ? 'btn-primary' : 'btn-outline' ?>">Geral</a>
        <a href="<?= url('settings/substatuses') ?>" class="btn btn-sm <?= strpos($_SERVER['REQUEST_URI'] ?? '', '/settings/substatuses') !== false ? 'btn-primary' : 'btn-outline' ?>">Sub-status</a>
        <a href="<?= url('settings/subjects') ?>" class="btn btn-sm <?= strpos($_SERVER['REQUEST_URI'] ?? '', '/settings/subjects') !== false ? 'btn-primary' : 'btn-outline' ?>">Assuntos</a>
    </div>
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px">
        <div>
            <h1 style="font-size:24px;font-weight:700;margin:0;display:flex;align-items:center;gap:10px">
                <i class="fas fa-cog" style="color:var(--primary);font-size:22px"></i>
                Configurações
            </h1>
            <p style="margin:4px 0 0;font-size:14px;color:var(--text-muted)">Gerencie as configurações do sistema</p>
        </div>
    </div>

    <form method="POST" action="<?= url('settings') ?>">
        <?= csrf_field() ?>

        <div class="card" style="border-radius:12px;overflow:hidden">
            <div class="card-header" style="background:var(--bg-card)">
                <h3><i class="fas fa-clock" style="color:var(--primary)"></i> Horário de Funcionamento</h3>
            </div>
            <div class="card-body">
                <div class="form-check" style="display:flex;align-items:center;gap:10px;margin-bottom:16px;padding:12px 16px;background:var(--bg-content);border-radius:8px;">
                    <input type="checkbox" name="business_hours_enabled" value="1" id="bhEnabled" <?= (!empty($settings['business_hours_enabled']) && $settings['business_hours_enabled'] === '1') ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:var(--primary)">
                    <label for="bhEnabled" style="font-weight:500;cursor:pointer;margin:0">
                        Ativar horário de funcionamento (mensagem de ausência fora do expediente)
                    </label>
                </div>

                <div class="form-row">
                    <div class="form-group col-6">
                        <label>Fuso horário</label>
                        <input type="text" name="business_hours_timezone" class="form-control"
                               value="<?= e($settings['business_hours_timezone'] ?? 'America/Sao_Paulo') ?>"
                               placeholder="America/Sao_Paulo">
                    </div>
                    <div class="form-group col-6">
                        <label>Escopo</label>
                        <select name="bh_department_id" class="form-control" onchange="window.location='/settings?dept=' + this.value">
                            <option value="">Geral (todos os departamentos)</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?= $dept['id'] ?>" <?= ($currentDept && $currentDept == $dept['id']) ? 'selected' : '' ?>><?= e($dept['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Mensagem de ausência</label>
                    <textarea name="absence_message" class="form-control" rows="3"
                              placeholder="Mensagem enviada quando o cliente escreve fora do horário"><?= e($settings['absence_message'] ?? '') ?></textarea>
                    <small class="text-muted" style="display:block;margin-top:4px;font-size:12px">Enviada automaticamente uma única vez por conversa, exceto se houver um fluxo (bot) ativo.</small>
                </div>

                <div style="margin-top:20px;overflow-x:auto">
                    <table class="table bh-table" style="min-width:auto">
                        <thead>
                            <tr>
                                <th style="padding-left:0">Dia</th>
                                <th style="text-align:center">Aberto?</th>
                                <th>Abertura</th>
                                <th>Fechamento</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $dayNames = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
                            $today = (int) date('w');
                            foreach ($dayNames as $d => $name):
                                $r = $schedule[$d] ?? null;
                                $open = $r ? (int) $r['is_open'] : 1;
                                $openTime = $r['open_time'] ?? '08:00:00';
                                $closeTime = $r['close_time'] ?? '18:00:00';
                                $isToday = $d === $today;
                            ?>
                                <tr style="<?= $isToday ? 'background:var(--primary-light);font-weight:600' : '' ?>">
                                    <td style="padding-left:0"><?= $name ?> <?= $isToday ? '<span style="font-size:10px;color:var(--primary)">(Hoje)</span>' : '' ?></td>
                                    <td style="text-align:center">
                                        <input type="checkbox" name="bh[<?= $d ?>][is_open]" value="1" <?= $open ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--primary)">
                                    </td>
                                    <td><input type="time" name="bh[<?= $d ?>][open_time]" class="form-control" value="<?= e(substr($openTime, 0, 5)) ?>" style="width:140px"></td>
                                    <td><input type="time" name="bh[<?= $d ?>][close_time]" class="form-control" value="<?= e(substr($closeTime, 0, 5)) ?>" style="width:140px"></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card" style="border-radius:12px;overflow:hidden;margin-top:16px">
            <div class="card-header" style="background:var(--bg-card)">
                <h3><i class="fas fa-smile" style="color:var(--warning)"></i> CSAT Pós-Resolução</h3>
            </div>
            <div class="card-body">
                <div class="form-check" style="display:flex;align-items:center;gap:10px;margin-bottom:16px;padding:12px 16px;background:var(--bg-content);border-radius:8px;">
                    <input type="checkbox" name="csat_enabled" value="1" id="csatEnabled" <?= (!empty($settings['csat_enabled']) && $settings['csat_enabled'] === '1') ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:var(--primary)">
                    <label for="csatEnabled" style="font-weight:500;cursor:pointer;margin:0">
                        Enviar solicitação de avaliação ao marcar a conversa como "Resolvida"
                    </label>
                </div>
                <div class="form-group">
                    <label>Mensagem de solicitação de CSAT</label>
                    <textarea name="csat_message" class="form-control" rows="3" placeholder="Ex: Olá {{contact.name}}, como foi seu atendimento? Avalie de 1 a 5 estrelas: {{csat_link}}"><?= e($settings['csat_message'] ?? '') ?></textarea>
                    <small class="text-muted" style="display:block;margin-top:4px;font-size:12px">
                        Variáveis: <code>{{contact.name}}</code>, <code>{{contact.email}}</code>,
                        <code>{{department.name}}</code>, <code>{{agent.name}}</code>, <code>{{csat_link}}</code>
                    </small>
                </div>
            </div>
        </div>

        <div class="form-actions" style="margin-top:20px">
            <button type="submit" class="btn btn-primary" style="padding:10px 28px;border-radius:10px">
                <i class="fas fa-save"></i> Salvar Configurações
            </button>
        </div>
    </form>
</div>
