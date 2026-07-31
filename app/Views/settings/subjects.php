<div class="settings-page">
    <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
        <a href="<?= url('settings') ?>" class="btn btn-sm <?= strpos($_SERVER['REQUEST_URI'] ?? '', '/settings/subjects') === false && strpos($_SERVER['REQUEST_URI'] ?? '', '/settings/substatuses') === false && strpos($_SERVER['REQUEST_URI'] ?? '', '/settings/close-reasons') === false ? 'btn-primary' : 'btn-outline' ?>">Geral</a>
        <a href="<?= url('settings/substatuses') ?>" class="btn btn-sm <?= strpos($_SERVER['REQUEST_URI'] ?? '', '/settings/substatuses') !== false ? 'btn-primary' : 'btn-outline' ?>">Sub-status</a>
        <a href="<?= url('settings/subjects') ?>" class="btn btn-sm <?= strpos($_SERVER['REQUEST_URI'] ?? '', '/settings/subjects') !== false ? 'btn-primary' : 'btn-outline' ?>">Assuntos</a>
        <a href="<?= url('settings/close-reasons') ?>" class="btn btn-sm <?= strpos($_SERVER['REQUEST_URI'] ?? '', '/settings/close-reasons') !== false ? 'btn-primary' : 'btn-outline' ?>">Motivos</a>
    </div>
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px">
        <div>
            <h1 style="font-size:24px;font-weight:700;margin:0;display:flex;align-items:center;gap:10px">
                <i class="fas fa-bookmark" style="color:var(--primary);font-size:22px"></i>
                Assuntos Predefinidos
            </h1>
            <p style="margin:4px 0 0;font-size:14px;color:var(--text-muted)">Gerencie os assuntos disponíveis nas conversas</p>
        </div>
        <button type="button" class="btn btn-primary" onclick="document.getElementById('newSubjectModal').style.display='flex'">
            <i class="fas fa-plus"></i> Novo Assunto
        </button>
    </div>

    <div class="card" style="border-radius:12px;overflow:hidden">
        <div class="card-body" style="padding:0">
            <?php if (empty($subjects)): ?>
                <div style="text-align:center;padding:40px 20px;color:var(--text-muted)">
                    <i class="fas fa-bookmark" style="font-size:40px;opacity:.3;margin-bottom:12px;display:block"></i>
                    <p>Nenhum assunto cadastrado. Clique em "Novo Assunto" para criar.</p>
                </div>
            <?php else: ?>
                <table class="table" style="width:100%;border-collapse:collapse">
                    <thead>
                        <tr style="background:var(--bg-content);text-align:left">
                            <th style="padding:12px 16px;font-size:13px;font-weight:600;color:var(--text-muted)">Ordem</th>
                            <th style="padding:12px 16px;font-size:13px;font-weight:600;color:var(--text-muted)">Nome</th>
                            <th style="padding:12px 16px;font-size:13px;font-weight:600;color:var(--text-muted)">Ativo</th>
                            <th style="padding:12px 16px;font-size:13px;font-weight:600;color:var(--text-muted)">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subjects as $s): ?>
                            <tr style="border-top:1px solid var(--border-soft)">
                                <td style="padding:12px 16px"><?= (int)($s['sort_order'] ?? 0) ?></td>
                                <td style="padding:12px 16px;font-weight:600"><?= e($s['name']) ?></td>
                                <td style="padding:12px 16px">
                                    <?php if (!empty($s['is_active'])): ?>
                                        <span class="chip chip-success">Sim</span>
                                    <?php else: ?>
                                        <span class="chip chip-neutral">Não</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:12px 16px">
                                    <button type="button" class="btn btn-sm btn-outline" onclick="editSubject(<?= $s['id'] ?>, '<?= e($s['name']) ?>', <?= (int)($s['sort_order'] ?? 0) ?>, <?= !empty($s['is_active']) ? 1 : 0 ?>)">
                                        <i class="fas fa-pen"></i>
                                    </button>
                                    <form method="POST" action="<?= url('settings/subjects/') ?><?= $s['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Remover assunto?')">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-outline" style="color:var(--danger)"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="newSubjectModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.4);z-index:9999;align-items:center;justify-content:center" onclick="if(event.target===this)this.style.display='none'">
    <div style="background:#fff;border-radius:16px;padding:28px;width:420px;max-width:90vw;box-shadow:0 20px 60px rgba(0,0,0,.2)">
        <h3 style="margin:0 0 20px;font-size:18px;font-weight:700">Novo Assunto</h3>
        <form method="POST" action="<?= url('settings/subjects/create') ?>">
            <?= csrf_field() ?>
            <div class="form-group">
                <label>Nome</label>
                <input type="text" name="name" class="form-control" required maxlength="255" placeholder="Ex: Suporte Técnico, Dúvidas...">
            </div>
            <div class="form-group">
                <label>Ordem de exibição</label>
                <input type="number" name="sort_order" class="form-control" value="0" min="0" style="width:100px">
            </div>
            <div style="display:flex;gap:10px;margin-top:20px;justify-content:flex-end">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('newSubjectModal').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar</button>
            </div>
        </form>
    </div>
</div>

<div id="editSubjectModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.4);z-index:9999;align-items:center;justify-content:center" onclick="if(event.target===this)this.style.display='none'">
    <div style="background:#fff;border-radius:16px;padding:28px;width:420px;max-width:90vw;box-shadow:0 20px 60px rgba(0,0,0,.2)">
        <h3 style="margin:0 0 20px;font-size:18px;font-weight:700">Editar Assunto</h3>
        <form method="POST" action="" id="editSubjectForm">
            <?= csrf_field() ?>
            <div class="form-group">
                <label>Nome</label>
                <input type="text" name="name" id="editSubjName" class="form-control" required maxlength="255">
            </div>
            <div class="form-group">
                <label>Ordem de exibição</label>
                <input type="number" name="sort_order" id="editSubjOrder" class="form-control" min="0" style="width:100px">
            </div>
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                    <input type="checkbox" name="is_active" id="editSubjActive" value="1" style="width:18px;height:18px;accent-color:var(--primary)">
                    Assunto ativo
                </label>
            </div>
            <div style="display:flex;gap:10px;margin-top:20px;justify-content:flex-end">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('editSubjectModal').style.display='none'">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar</button>
            </div>
        </form>
    </div>
</div>

<script>
function editSubject(id, name, order, active) {
    document.getElementById('editSubjectForm').action = '<?= url('settings/subjects/') ?>' + id + '/update';
    document.getElementById('editSubjName').value = name;
    document.getElementById('editSubjOrder').value = order;
    document.getElementById('editSubjActive').checked = !!active;
    document.getElementById('editSubjectModal').style.display = 'flex';
}
</script>
