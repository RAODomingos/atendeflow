<div class="page-form">
    <div class="page-actions">
        <a href="<?= url('contacts') ?>" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
    </div>
    <div class="card">
        <div class="card-body">
            <form action="<?= $contact ? url("contacts/{$contact['id']}/update") : url('contacts/create') ?>" method="POST">
                <?= csrf_field() ?>
                <div class="form-row">
                    <div class="form-group col-8">
                        <label><i class="fas fa-user"></i> Nome *</label>
                        <input type="text" name="name" class="form-control" required
                               value="<?= e($contact['name'] ?? '') ?>"
                               placeholder="Nome completo">
                    </div>
                    <div class="form-group col-4">
                        <label><i class="fas fa-building"></i> Empresa</label>
                        <input type="text" name="company" class="form-control"
                               value="<?= e($contact['company'] ?? '') ?>"
                               placeholder="Empresa">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-6">
                        <label><i class="fas fa-envelope"></i> E-mail</label>
                        <input type="email" name="email" class="form-control"
                               value="<?= e($contact['email'] ?? '') ?>"
                               placeholder="email@exemplo.com">
                    </div>
                    <div class="form-group col-6">
                        <label><i class="fas fa-phone"></i> Telefone</label>
                        <input type="text" name="phone" class="form-control"
                               value="<?= e($contact['phone'] ?? '') ?>"
                               placeholder="(11) 99999-9999">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-6">
                        <label><i class="fas fa-id-card"></i> CPF/CNPJ</label>
                        <input type="text" name="document" class="form-control"
                               value="<?= e($contact['document'] ?? '') ?>">
                    </div>
                    <div class="form-group col-6">
                        <label><i class="fas fa-tags"></i> Etiquetas</label>
                        <select name="tag_ids[]" class="form-control" multiple>
                            <?php foreach ($tags as $tag): ?>
                                <option value="<?= $tag['id'] ?>"
                                    <?= $contact && in_array($tag['id'], array_column($contact['tags'] ?? [], 'id')) ? 'selected' : '' ?>>
                                    <?= e($tag['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-sticky-note"></i> Observações</label>
                    <textarea name="notes" rows="4" class="form-control"
                              placeholder="Informações adicionais sobre o contato..."><?= e($contact['notes'] ?? '') ?></textarea>
                </div>
                <div class="form-actions">
                    <a href="<?= url('contacts') ?>" class="btn btn-outline">Cancelar</a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Salvar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
