<div class="contacts-page">
    <div class="page-toolbar">
        <form action="<?= url('contacts') ?>" method="GET" class="search-form">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" name="search" class="search-input"
                       placeholder="Buscar por nome, e-mail ou telefone..."
                       value="<?= e($_GET['search'] ?? '') ?>"
                       oninput="debounceContactSearch(this)">
            </div>
        </form>
        <div style="display:flex;gap:8px;align-items:center">
            <span class="badge badge-info" style="font-size:13px;padding:6px 12px">
                <i class="fas fa-users"></i> <?= count($contacts) ?> contatos
            </span>
            <a href="<?= url('contacts/create') ?>" class="btn btn-primary btn-sm">
                <i class="fas fa-plus"></i> Novo Contato
            </a>
        </div>
    </div>

    <div class="card" style="border-radius:12px;overflow:hidden">
        <div class="card-body p-0">
            <?php if (empty($contacts)): ?>
                <div class="empty-state-enhanced">
                    <div class="empty-icon"><i class="fas fa-address-book"></i></div>
                    <h3>Nenhum contato encontrado</h3>
                    <p>Crie um novo contato para começar.</p>
                    <a href="<?= url('contacts/create') ?>" class="btn btn-primary mt-2">
                        <i class="fas fa-plus"></i> Criar Contato
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table" id="contactsTable">
                        <thead>
                            <tr>
                                <th onclick="sortTable(0)" style="cursor:pointer">
                                    Nome <i class="fas fa-sort" style="font-size:10px;opacity:0.5"></i>
                                </th>
                                <th onclick="sortTable(1)" style="cursor:pointer">
                                    E-mail <i class="fas fa-sort" style="font-size:10px;opacity:0.5"></i>
                                </th>
                                <th>Telefone</th>
                                <th>Empresa</th>
                                <th style="text-align:center">Conversas</th>
                                <th>Último contato</th>
                                <th style="text-align:center">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($contacts as $contact): ?>
                            <?php
                                $cId = (int) $contact['id'];
                                $cName = e($contact['name']);
                                $cEmail = e($contact['email'] ?? '');
                                $cPhone = e($contact['phone'] ?? '');
                                $cCompany = e($contact['company'] ?? '');
                                $cDoc = e($contact['document'] ?? '');
                                $cNotes = e($contact['notes'] ?? '');
                                $cAvatar = $contact['avatar'] ?? '';
                                $cAvatarUrl = $cAvatar ? (str_starts_with($cAvatar, 'http') ? $cAvatar : upload_url($cAvatar)) : '';
                                $cInitial = mb_strtoupper(mb_substr($contact['name'], 0, 1));
                                $cConvCount = (int)($contact['conversation_count'] ?? 0);
                                $cLastMsg = $contact['last_message_at'] ?? '';
                            ?>
                                <tr data-id="<?= $cId ?>"
                                    data-name="<?= $cName ?>"
                                    data-email="<?= $cEmail ?>"
                                    data-phone="<?= $cPhone ?>"
                                    data-company="<?= $cCompany ?>"
                                    data-document="<?= $cDoc ?>"
                                    data-notes="<?= $cNotes ?>"
                                    data-avatar="<?= e($cAvatar) ?>">
                                    <td>
                                        <div class="user-cell">
                                            <div class="avatar-sm contact-list-avatar" style="background:linear-gradient(135deg,var(--primary),#667eea);overflow:hidden">
                                                <?php if ($cAvatarUrl): ?>
                                                    <img src="<?= $cAvatarUrl ?>" alt="<?= $cName ?>" style="width:100%;height:100%;object-fit:cover">
                                                <?php else: ?>
                                                    <?= $cInitial ?>
                                                <?php endif; ?>
                                            </div>
                                            <a href="<?= url('contacts/') ?><?= $cId ?>" style="font-weight:500">
                                                <?= $cName ?>
                                            </a>
                                        </div>
                                    </td>
                                    <td><?= $cEmail ?: '<span class="text-muted">-</span>' ?></td>
                                    <td><?= $cPhone ?: '<span class="text-muted">-</span>' ?></td>
                                    <td><?= $cCompany ?: '<span class="text-muted">-</span>' ?></td>
                                    <td style="text-align:center">
                                        <span class="badge badge-info" style="font-size:12px;min-width:28px">
                                            <?= $cConvCount ?>
                                        </span>
                                    </td>
                                    <td style="font-size:13px;color:var(--text-muted)">
                                        <?= $cLastMsg ? time_elapsed($cLastMsg) : '-' ?>
                                    </td>
                                    <td style="text-align:center">
                                        <div class="action-cell" style="display:flex;gap:4px;justify-content:center">
                                            <a href="<?= url('contacts/') ?><?= $cId ?>" class="btn btn-sm btn-outline" title="Visualizar">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-outline" title="Editar" onclick="openListDrawer(this)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline btn-icon-danger" title="Excluir" onclick="confirmDeleteContact(<?= $cId ?>, <?= htmlspecialchars(json_encode($contact['name'], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="contact-drawer-overlay" id="listDrawerOverlay" onclick="closeListDrawer()"></div>
<div class="contact-drawer" id="listDrawer">
    <div class="contact-drawer-header">
        <h3><i class="fas fa-user-edit" style="color:var(--brand)"></i> Editar Contato</h3>
        <button class="contact-drawer-close" onclick="closeListDrawer()">&times;</button>
    </div>
    <div class="contact-drawer-body">
        <form id="listEditForm" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="contact_id" id="listEditId">
            <div class="form-group">
                <label><i class="fas fa-user"></i> Nome *</label>
                <input type="text" name="name" id="listEditName" class="form-control" required placeholder="Nome completo">
            </div>
            <div class="form-group">
                <label><i class="fas fa-building"></i> Empresa</label>
                <input type="text" name="company" id="listEditCompany" class="form-control" placeholder="Empresa">
            </div>
            <div class="form-group">
                <label><i class="fas fa-envelope"></i> E-mail</label>
                <input type="email" name="email" id="listEditEmail" class="form-control" placeholder="email@exemplo.com">
            </div>
            <div class="form-group">
                <label><i class="fas fa-phone"></i> Telefone</label>
                <input type="text" name="phone" id="listEditPhone" class="form-control" placeholder="(11) 99999-9999">
            </div>
            <div class="form-group">
                <label><i class="fas fa-id-card"></i> CPF/CNPJ</label>
                <input type="text" name="document" id="listEditDocument" class="form-control">
            </div>
            <div class="form-group">
                <label><i class="fas fa-tags"></i> Etiquetas</label>
                <select name="tag_ids[]" class="form-control" multiple>
                    <?php foreach ($tags as $tag): ?>
                        <option value="<?= $tag['id'] ?>"><?= e($tag['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label><i class="fas fa-sticky-note"></i> Observações</label>
                <textarea name="notes" id="listEditNotes" rows="5" class="form-control" placeholder="Informações adicionais sobre o contato..."></textarea>
            </div>
        </form>
    </div>
    <div class="contact-drawer-footer">
        <button type="button" class="btn btn-outline" onclick="closeListDrawer()">Cancelar</button>
        <button type="submit" class="btn btn-primary" form="listEditForm">
            <i class="fas fa-save"></i> Salvar
        </button>
    </div>
</div>

<form id="deleteContactForm" method="POST" style="display:none">
    <?= csrf_field() ?>
</form>

<script>
var contactSearchTimer;
var contactsBaseUrl = <?= json_encode(url('contacts/'), JSON_UNESCAPED_SLASHES) ?>;
function debounceContactSearch(input) {
    clearTimeout(contactSearchTimer);
    contactSearchTimer = setTimeout(function() { input.closest('form').submit(); }, 400);
}

function sortTable(col) {
    var table = document.getElementById('contactsTable');
    if (!table) return;
    var tbody = table.querySelector('tbody');
    var rows = Array.from(tbody.querySelectorAll('tr'));
    var dir = table.getAttribute('data-sort-dir') === 'asc' ? 'desc' : 'asc';
    table.setAttribute('data-sort-dir', dir);
    rows.sort(function(a, b) {
        var aVal = (a.cells[col]?.textContent || '').trim().toLowerCase();
        var bVal = (b.cells[col]?.textContent || '').trim().toLowerCase();
        if (col === 4) { aVal = parseInt(aVal) || 0; bVal = parseInt(bVal) || 0; return dir === 'asc' ? aVal - bVal : bVal - aVal; }
        return dir === 'asc' ? aVal.localeCompare(bVal) : bVal.localeCompare(aVal);
    });
    rows.forEach(function(r) { tbody.appendChild(r); });
    table.querySelectorAll('thead th i.fa-sort, thead th i.fa-sort-up, thead th i.fa-sort-down').forEach(function(i) {
        i.className = 'fas fa-sort';
        i.style.opacity = '0.3';
    });
    var icon = table.querySelector('thead th:nth-child(' + (col + 1) + ') i');
    if (icon) { icon.className = 'fas fa-sort-' + (dir === 'asc' ? 'up' : 'down'); icon.style.opacity = '0.8'; }
}

function confirmDeleteContact(id, name) {
    if (confirm('Excluir contato "' + name + '"? Esta ação não pode ser desfeita.')) {
        var form = document.getElementById('deleteContactForm');
        form.action = contactsBaseUrl + id + '/delete';
        form.submit();
    }
}

function openListDrawer(btn) {
    var tr = btn.closest('tr');
    document.getElementById('listEditId').value = tr.dataset.id;
    document.getElementById('listEditName').value = tr.dataset.name;
    document.getElementById('listEditCompany').value = tr.dataset.company;
    document.getElementById('listEditEmail').value = tr.dataset.email;
    document.getElementById('listEditPhone').value = tr.dataset.phone;
    document.getElementById('listEditDocument').value = tr.dataset.document;
    document.getElementById('listEditNotes').value = tr.dataset.notes;
    document.getElementById('listEditForm').action = contactsBaseUrl + tr.dataset.id + '/update';
    document.getElementById('listDrawer').classList.add('open');
    document.getElementById('listDrawerOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeListDrawer() {
    document.getElementById('listDrawer').classList.remove('open');
    document.getElementById('listDrawerOverlay').classList.remove('open');
    document.body.style.overflow = '';
}

document.getElementById('listEditForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var btn = this.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
    var data = new FormData(this);
    fetch(this.action, {
        method: 'POST',
        body: new URLSearchParams(data),
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    })
    .then(function(r) { return r.json(); })
    .then(function(resp) {
        if (resp.success) {
            var id = document.getElementById('listEditId').value;
            var tr = document.querySelector('tr[data-id="' + id + '"]');
            if (tr) {
                tr.dataset.name = document.getElementById('listEditName').value;
                tr.dataset.company = document.getElementById('listEditCompany').value;
                tr.dataset.email = document.getElementById('listEditEmail').value;
                tr.dataset.phone = document.getElementById('listEditPhone').value;
                tr.dataset.document = document.getElementById('listEditDocument').value;
                tr.dataset.notes = document.getElementById('listEditNotes').value;
                tr.querySelector('td:first-child a:last-child').textContent = document.getElementById('listEditName').value;
                tr.cells[1].textContent = document.getElementById('listEditEmail').value || '-';
                tr.cells[2].textContent = document.getElementById('listEditPhone').value || '-';
                tr.cells[3].textContent = document.getElementById('listEditCompany').value || '-';
            }
            closeListDrawer();
        } else {
            alert(resp.error || 'Erro ao salvar contato.');
        }
    })
    .catch(function() {
        alert('Erro de rede. Tente novamente.');
    })
    .finally(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Salvar';
    });
});
</script>
