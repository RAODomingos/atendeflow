<?php
$color = $department['color'] ?? '#4A90D9';
$hasFilters = ($filters['year'] ?? 0) || ($filters['month'] ?? 0) || ($filters['status'] ?? null);
$statusLabels = [
    'new' => 'Novos', 'open' => 'Abertos',
    'waiting_customer' => 'Aguardando cliente', 'waiting_internal' => 'Aguardando interno',
    'resolved' => 'Resolvidos', 'closed' => 'Fechados', 'spam' => 'Spam',
];
$statusIcons = [
    'new' => 'fa-bell', 'open' => 'fa-comments',
    'waiting_customer' => 'fa-headset', 'waiting_internal' => 'fa-pause',
    'resolved' => 'fa-check-circle', 'closed' => 'fa-archive', 'spam' => 'fa-ban',
];
$statusKeys = ['new', 'open', 'waiting_customer', 'waiting_internal', 'resolved', 'closed', 'spam'];
$totalCount = (int) ($statusCounts['_total'] ?? count($conversations));
$totalConvsAll = array_sum(array_filter($statusCounts, fn($_, $k) => $k !== '_total', ARRAY_FILTER_USE_BOTH));
$members = is_array($users) ? count($users) : 0;
$managers = is_array($users) ? count(array_filter($users, fn($u) => !empty($u['is_manager']))) : 0;
$filterBase = url('departments/' . (int) $department['id']);
?>
<div class="department-detail">
    <div class="page-toolbar">
        <a href="<?= url('departments') ?>" class="btn btn-sm btn-outline">
            <i class="fas fa-arrow-left"></i> Voltar
        </a>
        <div>
            <button class="btn btn-sm btn-primary" data-action="open-edit-dept">
                <i class="fas fa-edit"></i> Editar
            </button>
            <button class="btn btn-sm btn-danger" data-action="delete-dept" title="Excluir">
                <i class="fas fa-trash"></i>
            </button>
        </div>
    </div>

    <div class="dept-header" style="--dept-color: <?= e($color) ?>;">
        <div class="dept-header-banner"></div>
        <div class="dept-header-body">
            <div class="dept-header-avatar" style="background:<?= e($color) ?>">
                <i class="fas fa-layer-group"></i>
            </div>
            <div class="dept-header-info">
                <h1 class="dept-header-name"><?= e($department['name']) ?></h1>
                <?php if (!empty($department['description'])): ?>
                    <p class="dept-header-desc"><?= e($department['description']) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="dept-stat-row">
        <div class="dept-stat-card">
            <div class="dept-stat-icon" style="background:var(--brand-soft);color:var(--brand)">
                <i class="fas fa-users"></i>
            </div>
            <div class="dept-stat-body">
                <div class="dept-stat-value"><?= $members ?></div>
                <div class="dept-stat-label">Membros <span style="color:var(--text-muted);font-weight:500">(<?= $managers ?> gestor<?= $managers !== 1 ? 'es' : '' ?>)</span></div>
            </div>
        </div>
        <div class="dept-stat-card">
            <div class="dept-stat-icon" style="background:var(--info-soft);color:var(--info)">
                <i class="fas fa-comments"></i>
            </div>
            <div class="dept-stat-body">
                <div class="dept-stat-value"><?= $totalConvsAll ?></div>
                <div class="dept-stat-label">Total de conversas</div>
            </div>
        </div>
        <div class="dept-stat-card">
            <div class="dept-stat-icon" style="background:var(--warning-soft);color:var(--warning)">
                <i class="fas fa-spinner"></i>
            </div>
            <div class="dept-stat-body">
                <div class="dept-stat-value"><?= (int) ($statusCounts['new'] ?? 0) + (int) ($statusCounts['open'] ?? 0) + (int) ($statusCounts['waiting_customer'] ?? 0) + (int) ($statusCounts['waiting_internal'] ?? 0) ?></div>
                <div class="dept-stat-label">Em aberto</div>
            </div>
        </div>
        <div class="dept-stat-card">
            <div class="dept-stat-icon" style="background:var(--success-soft);color:var(--success)">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="dept-stat-body">
                <div class="dept-stat-value"><?= (int) ($statusCounts['resolved'] ?? 0) + (int) ($statusCounts['closed'] ?? 0) ?></div>
                <div class="dept-stat-label">Concluídas</div>
            </div>
        </div>
    </div>

    <div class="dept-info-card" style="margin-bottom:20px">
        <div class="dept-info-header" style="flex-wrap:wrap;gap:12px">
            <span>
                <i class="fas fa-clock" style="color:var(--brand)"></i>
                Horário de Funcionamento
            </span>
            <?php if ($bhIsOpen): ?>
                <span class="badge" style="background:var(--success-soft);color:var(--success);font-size:11px;padding:4px 10px;border-radius:20px">
                    <i class="fas fa-circle" style="font-size:8px"></i> Aberto agora
                </span>
            <?php else: ?>
                <span class="badge" style="background:var(--danger-soft);color:var(--danger);font-size:11px;padding:4px 10px;border-radius:20px">
                    <i class="fas fa-circle" style="font-size:8px"></i> Fechado agora
                </span>
            <?php endif; ?>
        </div>
        <form action="<?= url('departments/') ?><?= (int) $department['id'] ?>/business-hours" method="POST">
            <?= csrf_field() ?>
            <div class="form-check" style="display:flex;align-items:center;gap:10px;margin-bottom:16px;padding:12px 16px;background:var(--bg-panel-alt);border-radius:8px">
                <input type="checkbox" name="business_hours_enabled" value="1" id="bhEnabled" <?= $bhEnabled ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:var(--primary)">
                <label for="bhEnabled" style="font-weight:500;cursor:pointer;margin:0">
                    Ativar horário de funcionamento (mensagem de ausência fora do expediente)
                </label>
            </div>
            <div class="form-row">
                <div class="form-group col-6">
                    <label>Fuso horário</label>
                    <input type="text" name="business_hours_timezone" class="form-control"
                           value="<?= e($bhTimezone) ?>" placeholder="America/Sao_Paulo">
                </div>
            </div>
            <div class="form-group">
                <label>Mensagem de ausência</label>
                <textarea name="absence_message" class="form-control" rows="2"
                          placeholder="Mensagem enviada quando o cliente escreve fora do horário"><?= e($bhAbsence) ?></textarea>
                <small class="text-muted" style="display:block;margin-top:4px;font-size:12px">Enviada automaticamente uma única vez por conversa, exceto se houver um fluxo (bot) ativo.</small>
            </div>
            <div style="margin-top:12px;overflow-x:auto">
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
                            $r = $bhSchedule[$d] ?? null;
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
            <div style="margin-top:12px;display:flex;justify-content:flex-end">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar Horário</button>
            </div>
        </form>
    </div>

    <div class="dept-info-grid">
        <div class="dept-info-card">
            <div class="dept-info-header">
                <span>
                    <i class="fas fa-users" style="color:var(--brand)"></i>
                    Membros do Departamento
                </span>
                <button class="btn btn-sm btn-primary" data-action="open-add-user">
                    <i class="fas fa-plus"></i> Adicionar
                </button>
            </div>
            <div>
                <?php if (empty($users)): ?>
                    <div class="empty-state-enhanced" style="padding:40px 18px">
                        <div class="empty-icon"><i class="fas fa-user-friends"></i></div>
                        <h3>Nenhum membro</h3>
                        <p>Adicione usuários a este departamento para que possam atender conversas dele.</p>
                    </div>
                <?php else: ?>
                    <ul class="dept-member-list">
                        <?php foreach ($users as $user):
                            $initial = mb_strtoupper(mb_substr($user['name'], 0, 1));
                            $roleLabel = ['admin'=>'Administrador','manager'=>'Gestor','agent'=>'Atendente','viewer'=>'Visualizador'][$user['role']] ?? ucfirst($user['role']);
                            $isManager = !empty($user['is_manager']);
                        ?>
                            <li class="dept-member-item">
                                <div class="dept-member-avatar">
                                    <?php if (!empty($user['avatar'])): ?>
                                        <img src="<?= e(upload_url($user['avatar'])) ?>" alt="<?= e($user['name']) ?>">
                                    <?php else: ?>
                                        <span><?= $initial ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="dept-member-info">
                                    <div class="dept-member-name">
                                        <?= e($user['name']) ?>
                                        <?php if ($isManager): ?>
                                            <span class="dept-member-manager" title="Gestor do departamento">
                                                <i class="fas fa-star"></i> Gestor
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="dept-member-meta">
                                        <span><?= e($roleLabel) ?></span>
                                        <span>·</span>
                                        <span><i class="fas fa-comments" style="font-size:10px"></i> <?= (int)($user['conversation_count'] ?? 0) ?> conversas</span>
                                    </div>
                                </div>
                                <form action="<?= url('departments/') ?><?= (int) $department['id'] ?>/users/<?= (int) $user['id'] ?>/remove" method="POST"
                                      data-confirm="Remover <?= e(addslashes($user['name'])) ?> deste departamento?"
                                      ">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm btn-outline btn-icon btn-icon-danger" title="Remover do departamento">
                                        <i class="fas fa-xmark"></i>
                                    </button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <div class="dept-info-card">
            <div class="dept-info-header" style="flex-wrap:wrap;gap:12px">
                <span>
                    <i class="fas fa-comments" style="color:var(--brand)"></i>
                    Conversas do Departamento
                    <span class="badge badge-tag-count"><?= $totalCount ?></span>
                </span>
                <form method="GET" action="<?= $filterBase ?>" class="conv-filter-form" id="deptConvFilterForm">
                    <div class="conv-filter-group" title="Mês">
                        <i class="fas fa-calendar-day"></i>
                        <select name="month" onchange="document.getElementById('deptConvFilterForm').submit()">
                            <option value="0">Todos os meses</option>
                            <?php
                            $monthNames = [1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
                                           'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
                            foreach ($monthNames as $m => $label): ?>
                                <option value="<?= $m ?>" <?= (int)($filters['month'] ?? 0) === $m ? 'selected' : '' ?>>
                                    <?= $label ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="conv-filter-group" title="Ano">
                        <i class="fas fa-calendar"></i>
                        <select name="year" onchange="document.getElementById('deptConvFilterForm').submit()">
                            <option value="0">Todos os anos</option>
                            <?php
                            $years = array_unique(array_merge(
                                array_column($availableMonths, 'year'),
                                [(int) date('Y')]
                            ));
                            rsort($years);
                            foreach ($years as $y): ?>
                                <option value="<?= $y ?>" <?= (int)($filters['year'] ?? 0) === (int) $y ? 'selected' : '' ?>>
                                    <?= $y ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="conv-filter-group" title="Status">
                        <i class="fas fa-circle-info"></i>
                        <select name="status" onchange="document.getElementById('deptConvFilterForm').submit()">
                            <option value="">Todos os status</option>
                            <?php foreach ($statusKeys as $sk): ?>
                                <option value="<?= e($sk) ?>" <?= ($filters['status'] ?? '') === $sk ? 'selected' : '' ?>>
                                    <?= e($statusLabels[$sk] ?? $sk) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($hasFilters): ?>
                        <a href="<?= $filterBase ?>" class="btn btn-sm btn-outline" title="Limpar filtros">
                            <i class="fas fa-xmark"></i>
                        </a>
                    <?php endif; ?>
                </form>
            </div>
            <div>
                <?php if (empty($conversations)): ?>
                    <div class="empty-state-enhanced" style="padding:40px 18px">
                        <div class="empty-icon"><i class="fas fa-inbox"></i></div>
                        <h3><?= $hasFilters ? 'Nenhuma conversa neste filtro' : 'Nenhuma conversa' ?></h3>
                        <p><?= $hasFilters ? 'Tente ajustar o período ou status.' : 'Conversas marcadas neste departamento aparecerão aqui.' ?></p>
                    </div>
                <?php else: ?>
                    <div class="conversation-timeline">
                        <?php foreach ($conversations as $conv): ?>
                            <a href="<?= url('inbox') ?>?conv=<?= $conv['id'] ?>" class="timeline-item">
                                <div class="timeline-dot"></div>
                                <div class="timeline-content">
                                    <div class="timeline-top">
                                        <div class="timeline-channel">
                                            <i class="<?= channel_icon($conv['channel_type'] ?? 'webchat') ?>" style="color:<?= ['whatsapp'=>'#25D366','webchat'=>'var(--brand)','email'=>'#f59e0b','telegram'=>'#0088cc','facebook'=>'#1877f2','instagram'=>'#e1306c','phone'=>'#6c757d'][$conv['channel_type'] ?? ''] ?? 'var(--text-muted)' ?>"></i>
                                            <?= e($conv['channel_name'] ?? '') ?>
                                            <span class="timeline-contact">
                                                <i class="fas fa-user"></i> <?= e($conv['contact_name'] ?? '-') ?>
                                            </span>
                                        </div>
                                        <div class="timeline-time"><?= time_elapsed($conv['created_at']) ?></div>
                                    </div>
                                    <div class="timeline-subject">
                                        <?= e($conv['subject'] ?: truncate($conv['last_message'] ?? 'Sem mensagens', 100)) ?>
                                    </div>
                                    <div class="timeline-meta">
                                        <?= status_badge($conv['status']) ?>
                                        <?= priority_badge($conv['priority'] ?? 'normal') ?>
                                        <span style="font-size:13px;color:var(--text-muted);display:flex;align-items:center;gap:5px"><i class="fas fa-comment-dots" style="font-size:11px"></i> <?= (int)($conv['message_count'] ?? 0) ?> mensagens</span>
                                    </div>
                                    <div class="timeline-footer">
                                        <div class="timeline-footer-left">
                                            <span class="timeline-agent">
                                                <i class="fas fa-user-circle" style="color:var(--brand);font-size:14px"></i>
                                                <?= e($conv['assigned_user_name'] ?? 'Não atribuído') ?>
                                            </span>
                                        </div>
                                        <div class="timeline-footer-right"><i class="far fa-calendar-alt"></i> <?= format_datetime($conv['created_at']) ?></div>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="modal" id="editDeptModal" style="display:none" onclick="if(event.target===this)closeEditDeptModal()">
    <div class="modal-content" style="max-width:480px">
        <div class="modal-header">
            <h3><i class="fas fa-edit" style="color:var(--primary)"></i> Editar Departamento</h3>
            <button class="modal-close" type="button" onclick="closeEditDeptModal()">&times;</button>
        </div>
        <form action="<?= url('departments/') ?><?= (int) $department['id'] ?>/update" method="POST" id="editDeptForm">
            <?= csrf_field() ?>
            <div class="modal-body">
                <div class="form-group">
                    <label>Nome *</label>
                    <input type="text" name="name" class="form-control" required value="<?= e($department['name']) ?>">
                </div>
                <div class="form-group">
                    <label>Descrição</label>
                    <textarea name="description" rows="3" class="form-control"><?= e($department['description'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label>Cor de identificação</label>
                    <div style="display:flex;align-items:center;gap:8px">
                        <input type="color" name="color" value="<?= e($color) ?>" id="editDeptColorInput" style="width:48px;height:36px;padding:2px;border-radius:8px;border:1px solid var(--border-soft);cursor:pointer">
                        <input type="text" name="color_hex" id="editDeptColorHex" value="<?= e($color) ?>" class="form-control" style="flex:1;font-family:monospace;font-size:12px" maxlength="7">
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="justify-content:flex-end;display:flex;gap:8px;padding:14px 18px;border-top:1px solid var(--border-soft)">
                <button type="button" class="btn btn-outline" onclick="closeEditDeptModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar</button>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="addUserModal" style="display:none" onclick="if(event.target===this)closeAddUserModal()">
    <div class="modal-content" style="max-width:440px">
        <div class="modal-header">
            <h3><i class="fas fa-user-plus" style="color:var(--primary)"></i> Adicionar Membro</h3>
            <button class="modal-close" type="button" onclick="closeAddUserModal()">&times;</button>
        </div>
        <form action="<?= url('departments/') ?><?= (int) $department['id'] ?>/users" method="POST" id="addUserForm">
            <?= csrf_field() ?>
            <div class="modal-body">
                <div class="form-group">
                    <label>Usuário *</label>
                    <select name="user_id" class="form-control" required>
                        <option value="">Selecione um usuário…</option>
                        <?php if (empty($allUsers)): ?>
                            <option value="" disabled>Todos os usuários já estão no departamento.</option>
                        <?php else: ?>
                            <?php foreach ($allUsers as $u): ?>
                                <option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?> (<?= e($u['email']) ?>)</option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="form-check" style="display:flex;align-items:center;gap:8px;padding:10px 14px;background:var(--bg-panel-alt);border-radius:8px">
                    <input type="checkbox" name="is_manager" value="1" id="isManager" style="width:16px;height:16px;accent-color:var(--primary)">
                    <label for="isManager" style="font-weight:500;cursor:pointer;margin:0">
                        <i class="fas fa-star" style="color:var(--warning)"></i> Definir como gestor do departamento
                    </label>
                </div>
            </div>
            <div class="modal-footer" style="justify-content:flex-end;display:flex;gap:8px;padding:14px 18px;border-top:1px solid var(--border-soft)">
                <button type="button" class="btn btn-outline" onclick="closeAddUserModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary" <?= empty($allUsers) ? 'disabled' : '' ?>>
                    <i class="fas fa-check"></i> Adicionar
                </button>
            </div>
        </form>
    </div>
</div>

<form id="deleteDeptForm" action="<?= url('departments/') ?><?= (int) $department['id'] ?>/delete" method="POST" style="display:none">
    <?= csrf_field() ?>
</form>

<script>
(function() {
    function openEditDeptModal() {
        document.getElementById('editDeptModal').style.display = 'flex';
    }
    function closeEditDeptModal() {
        document.getElementById('editDeptModal').style.display = 'none';
    }
    function openAddUserModal() {
        document.getElementById('addUserModal').style.display = 'flex';
        setTimeout(function() {
            var sel = document.querySelector('#addUserModal select[name="user_id"]');
            if (sel) sel.focus();
        }, 50);
    }
    function closeAddUserModal() {
        document.getElementById('addUserModal').style.display = 'none';
    }

    document.addEventListener('click', function(e) {
        var t = e.target.closest('[data-action]');
        if (!t) return;
        if (t.dataset.action === 'open-edit-dept') {
            e.preventDefault();
            openEditDeptModal();
        } else if (t.dataset.action === 'open-add-user') {
            e.preventDefault();
            openAddUserModal();
        } else if (t.dataset.action === 'delete-dept') {
            e.preventDefault();
            OminiConfirm('Excluir o departamento "<?= e(addslashes($department['name'])) ?>"? Esta ação não pode ser desfeita.').then(function(ok) {
                if (!ok) return;
                document.getElementById('deleteDeptForm').submit();
            });
        }
    });

    // Sincroniza color picker com input hex (criar + editar)
    function bindColorPair(pickerId, hexId) {
        var picker = document.getElementById(pickerId);
        var hex = document.getElementById(hexId);
        if (!picker || !hex) return;
        picker.addEventListener('input', function() { hex.value = picker.value; });
        hex.addEventListener('input', function() {
            var v = hex.value.trim();
            if (/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(v)) picker.value = v;
        });
    }
    bindColorPair('editDeptColorInput', 'editDeptColorHex');
})();
</script>
