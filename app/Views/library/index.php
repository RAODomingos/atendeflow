<?php
/** @var array $tags */
/** @var array $canned */
/** @var array $departments */
/** @var array|null $editTag */
/** @var array|null $editCanned */
?>
<div class="library-page">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px">
        <div>
            <h1 style="font-size:24px;font-weight:700;margin:0;display:flex;align-items:center;gap:10px">
                <i class="fas fa-book" style="color:var(--primary);font-size:22px"></i>
                Biblioteca
            </h1>
            <p style="margin:4px 0 0;font-size:14px;color:var(--text-muted)">Gerencie etiquetas e respostas prontas</p>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <!-- Tags -->
        <div class="card" style="border-radius:12px;overflow:hidden">
            <div class="card-header" style="background:var(--bg-card)">
                <h3><i class="fas fa-tags" style="color:var(--primary)"></i> Etiquetas (Tags)</h3>
            </div>
            <div class="card-body">
                <form method="POST" action="<?= url('library/tags') ?>" class="mb-3" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
                    <?= csrf_field() ?>
                    <div style="flex:1;min-width:140px">
                        <label style="display:block;font-size:12px;font-weight:500;color:var(--secondary);margin-bottom:4px">Nome</label>
                        <input type="text" name="name" class="form-control" required
                               value="<?= $editTag ? e($editTag['name']) : '' ?>"
                               placeholder="Ex: VIP, Urgente">
                    </div>
                    <div>
                        <label style="display:block;font-size:12px;font-weight:500;color:var(--secondary);margin-bottom:4px">Cor</label>
                        <input type="color" name="color" class="form-control form-control-color"
                               value="<?= e($editTag['color'] ?? '#6c757d') ?>" style="width:46px;height:38px;padding:2px;cursor:pointer">
                    </div>
                    <div>
                        <?php if ($editTag): ?>
                            <button type="submit" formaction="/library/tags/<?= $editTag['id'] ?>" class="btn btn-primary">
                                <i class="fas fa-check"></i> Atualizar
                            </button>
                            <a href="<?= url('library') ?>" class="btn btn-outline">
                                <i class="fas fa-times"></i> Cancelar
                            </a>
                        <?php else: ?>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Adicionar
                            </button>
                        <?php endif; ?>
                    </div>
                </form>

                <?php if (empty($tags)): ?>
                    <div style="text-align:center;padding:24px;color:var(--text-muted)">
                        <i class="fas fa-tags fa-2x" style="opacity:0.3;margin-bottom:8px;display:block"></i>
                        <p>Nenhuma etiqueta criada.</p>
                    </div>
                <?php else: ?>
                    <div style="display:flex;flex-direction:column;gap:6px">
                        <?php foreach ($tags as $t): ?>
                            <div class="tag-row" style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 12px;border:1px solid var(--border-color);border-radius:8px;transition:all 0.2s">
                                <span class="tag-pill" style="display:inline-flex;align-items:center;padding:4px 14px;border-radius:12px;font-size:12px;font-weight:600;background:<?= e($t['color']) ?>;color:#fff;">
                                    <?= e($t['name']) ?>
                                </span>
                                <div style="display:flex;gap:4px">
                                    <a href="<?= url('library/tags/') ?><?= $t['id'] ?>/edit" class="btn btn-sm btn-outline" title="Editar">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form method="POST" action="<?= url('library/tags/') ?><?= $t['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Remover a etiqueta &quot;<?= e(addslashes($t['name'])) ?>&quot;?');">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-outline" title="Remover" style="color:var(--danger)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Canned responses -->
        <div class="card" style="border-radius:12px;overflow:hidden">
            <div class="card-header" style="background:var(--bg-card)">
                <h3><i class="fas fa-comment-dots" style="color:var(--warning)"></i> Mensagens Prontas</h3>
            </div>
            <div class="card-body">
                <form method="POST" action="<?= url('library/canned') ?>" class="mb-3">
                    <?= csrf_field() ?>
                    <div style="margin-bottom:8px">
                        <label style="display:block;font-size:12px;font-weight:500;color:var(--secondary);margin-bottom:4px">Título</label>
                        <input type="text" name="title" class="form-control" required
                               value="<?= $editCanned ? e($editCanned['title']) : '' ?>"
                               placeholder="Ex: Saudação inicial">
                    </div>
                    <div style="margin-bottom:8px">
                        <label style="display:block;font-size:12px;font-weight:500;color:var(--secondary);margin-bottom:4px">Conteúdo</label>
                        <textarea name="content" class="form-control" rows="3" required
                                  placeholder="Texto da mensagem pré-pronta"><?= $editCanned ? e($editCanned['content']) : '' ?></textarea>
                    </div>
                    <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
                        <div style="flex:1;min-width:140px">
                            <label style="display:block;font-size:12px;font-weight:500;color:var(--secondary);margin-bottom:4px">Departamento</label>
                            <select name="department_id" class="form-control">
                                <option value="">Todos</option>
                                <?php foreach ($departments as $d): ?>
                                    <option value="<?= $d['id'] ?>" <?= $editCanned && ($editCanned['department_id'] ?? null) == $d['id'] ? 'selected' : '' ?>>
                                        <?= e($d['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <?php if ($editCanned): ?>
                                <button type="submit" formaction="/library/canned/<?= $editCanned['id'] ?>" class="btn btn-primary">
                                    <i class="fas fa-check"></i> Atualizar
                                </button>
                                <a href="<?= url('library') ?>" class="btn btn-outline">
                                    <i class="fas fa-times"></i> Cancelar
                                </a>
                            <?php else: ?>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-plus"></i> Adicionar
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>

                <?php if (empty($canned)): ?>
                    <div style="text-align:center;padding:24px;color:var(--text-muted)">
                        <i class="fas fa-comment-dots fa-2x" style="opacity:0.3;margin-bottom:8px;display:block"></i>
                        <p>Nenhuma mensagem pronta criada.</p>
                    </div>
                <?php else: ?>
                    <div style="display:flex;flex-direction:column;gap:6px;max-height:400px;overflow-y:auto">
                        <?php foreach ($canned as $c): ?>
                            <div class="canned-row" style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;padding:10px 12px;border:1px solid var(--border-color);border-radius:8px;transition:all 0.2s">
                                <div style="min-width:0;flex:1">
                                    <div style="font-weight:600;font-size:13px;display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                                        <?= e($c['title']) ?>
                                        <?php if (!empty($c['department_name'])): ?>
                                            <span style="background:rgba(99,102,241,.12);color:var(--primary);font-size:10px;padding:1px 8px;border-radius:10px;font-weight:600"><?= e($c['department_name']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size:12px;color:var(--text-muted);margin-top:2px;word-break:break-word"><?= e(truncate($c['content'], 90)) ?></div>
                                </div>
                                <div style="display:flex;gap:4px;flex-shrink:0">
                                    <a href="<?= url('library/canned/') ?><?= $c['id'] ?>/edit" class="btn btn-sm btn-outline" title="Editar">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form method="POST" action="<?= url('library/canned/') ?><?= $c['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Remover esta mensagem pronta?');">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-outline" title="Remover" style="color:var(--danger)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
