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
                                <tr style="transition:all 0.2s">
                                    <td>
                                        <div class="user-cell">
                                            <div class="avatar-sm" style="background:linear-gradient(135deg,var(--primary),#667eea)">
                                                <?= mb_strtoupper(mb_substr($contact['name'], 0, 1)) ?>
                                            </div>
                                            <a href="<?= url('contacts/') ?><?= $contact['id'] ?>" style="font-weight:500">
                                                <?= e($contact['name']) ?>
                                            </a>
                                        </div>
                                    </td>
                                    <td><?= e($contact['email'] ?? '<span class="text-muted">-</span>') ?></td>
                                    <td><?= e($contact['phone'] ?? '<span class="text-muted">-</span>') ?></td>
                                    <td><?= e($contact['company'] ?? '<span class="text-muted">-</span>') ?></td>
                                    <td style="text-align:center">
                                        <span class="badge badge-info" style="font-size:12px;min-width:28px">
                                            <?= $contact['conversation_count'] ?? 0 ?>
                                        </span>
                                    </td>
                                    <td style="font-size:13px;color:var(--text-muted)">
                                        <?= $contact['last_message_at'] ? time_elapsed($contact['last_message_at']) : '-' ?>
                                    </td>
                                    <td style="text-align:center">
                                        <div class="action-cell" style="display:flex;gap:4px;justify-content:center">
                                            <a href="<?= url('contacts/') ?><?= $contact['id'] ?>" class="btn btn-sm btn-outline" title="Visualizar">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="<?= url('contacts/') ?><?= $contact['id'] ?>/edit" class="btn btn-sm btn-outline" title="Editar">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-outline btn-icon-danger" title="Excluir" onclick="confirmDeleteContact(<?= $contact['id'] ?>, '<?= htmlspecialchars(addslashes($contact['name']), ENT_QUOTES) ?>')">
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

<form id="deleteContactForm" method="POST" style="display:none">
    <?= csrf_field() ?>
</form>

<script>
var contactSearchTimer;
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
        form.action = '/contacts/' + id + '/delete';
        form.submit();
    }
}
</script>
