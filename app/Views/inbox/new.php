<div class="page-form">
    <div class="page-toolbar">
        <h2 class="page-title"><i class="fas fa-plus-circle"></i> Novo Atendimento</h2>
        <a href="<?= url('inbox') ?>" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
    </div>
    <div class="card">
        <div class="card-body">
            <form action="<?= url('inbox/new') ?>" method="POST">
                <?= csrf_field() ?>
                <div class="form-row">
                    <div class="form-group col-6">
                        <label><i class="fas fa-user"></i> Contato</label>
                        <select name="contact_id" class="form-control" required>
                            <option value="">Selecione um contato</option>
                            <?php foreach ($contacts as $contact): ?>
                                <option value="<?= $contact['id'] ?>">
                                    <?= e($contact['name']) ?> - <?= e($contact['email'] ?: $contact['phone'] ?: '') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-6">
                        <label><i class="fas fa-layer-group"></i> Departamento</label>
                        <select name="department_id" class="form-control">
                            <option value="">Nenhum</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?= $dept['id'] ?>"><?= e($dept['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-6">
                        <label><i class="fas fa-comment-dots"></i> Canal</label>
                        <select name="channel_id" class="form-control" required>
                            <option value="">Selecione um canal</option>
                            <?php foreach ($channels as $ch): ?>
                                <option value="<?= $ch['id'] ?>">
                                    <?= e($ch['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-6">
                        <label><i class="fas fa-heading"></i> Assunto</label>
                        <input type="text" name="subject" class="form-control" placeholder="Assunto do atendimento">
                    </div>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-comment"></i> Mensagem inicial</label>
                    <textarea name="message" rows="4" class="form-control" placeholder="Digite a primeira mensagem..."></textarea>
                </div>
                <div class="form-actions">
                    <a href="<?= url('inbox') ?>" class="btn btn-outline">Cancelar</a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Criar Atendimento
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
