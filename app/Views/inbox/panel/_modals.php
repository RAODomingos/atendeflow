<div class="modal-overlay" role="dialog" aria-modal="true" id="transferModal" style="display:none" onclick="if(event.target===this)closeTransferModal()">
    <div class="modal-container" style="max-width:460px">
        <div class="modal-header"><h3>Transferir Atendimento</h3><button class="modal-close" onclick="closeTransferModal()">&times;</button></div>
        <form action="<?= url('inbox/') ?><?= $conv['id'] ?>/transfer" method="POST">
            <?= csrf_field() ?>
            <div class="modal-body">
                <div class="form-group">
                    <label>Departamento</label>
                    <select name="department_id" id="transferDeptSelect" class="form-control" onchange="loadTransferUsers()">
                        <option value="">Manter atual</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['id'] ?>" <?= $dept['id'] == $conv['department_id'] ? 'selected' : '' ?>><?= e($dept['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Atendente</label>
                    <select name="user_id" id="transferUserSelect" class="form-control"><option value="">Fila do departamento</option></select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeTransferModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary">Transferir</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" role="dialog" aria-modal="true" id="priorityModal" style="display:none" onclick="if(event.target===this)closePriorityModal()">
    <div class="modal-container" style="max-width:440px">
        <div class="modal-header">
            <h3><i class="fas fa-flag"></i> Selecionar prioridade</h3>
            <button class="modal-close" onclick="closePriorityModal()">&times;</button>
        </div>
        <div class="modal-body" style="padding:14px">
            <p class="text-muted" style="margin:0 0 12px;font-size:12.5px">Escolha a urgência do atendimento desta conversa.</p>
            <div class="option-list">
                <?php
                $priorities = [
                    'low' => ['label' => 'Baixa', 'desc' => 'Sem urgência, pode aguardar.', 'icon' => 'fa-arrow-down', 'color' => '#1976d2'],
                    'normal' => ['label' => 'Normal', 'desc' => 'Padrão da maioria dos atendimentos.', 'icon' => 'fa-equals', 'color' => 'var(--text-secondary)'],
                    'high' => ['label' => 'Alta', 'desc' => 'Requer atenção em breve.', 'icon' => 'fa-arrow-up', 'color' => '#f57c00'],
                    'urgent' => ['label' => 'Urgente', 'desc' => 'Resolver imediatamente.', 'icon' => 'fa-bolt', 'color' => '#d32f2f'],
                ];
                $currentPriority = $conv['priority'] ?? 'normal';
                foreach ($priorities as $pval => $pinfo): ?>
                <button type="button" class="option-item option-priority-<?= $pval ?> <?= $currentPriority === $pval ? 'is-active' : '' ?>" data-priority="<?= $pval ?>" onclick="pickPriority('<?= $pval ?>')">
                    <span class="option-icon" style="background:<?= $pinfo['color'] ?>15;color:<?= $pinfo['color'] ?>">
                        <i class="fas <?= $pinfo['icon'] ?>"></i>
                    </span>
                    <span class="option-content">
                        <span class="option-title"><?= $pinfo['label'] ?></span>
                        <span class="option-desc"><?= $pinfo['desc'] ?></span>
                    </span>
                    <?php if ($currentPriority === $pval): ?>
                    <i class="fas fa-check option-check"></i>
                    <?php endif; ?>
                </button>
                <?php endforeach; ?>
            </div>
            <?php if (empty($conv['subject']) && false): ?>
            <button type="button" class="btn btn-outline" style="width:100%;margin-top:8px" onclick="clearPriority()">
                <i class="fas fa-times"></i> Remover prioridade
            </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="modal-overlay" role="dialog" aria-modal="true" id="subjectModal" style="display:none" onclick="if(event.target===this)closeSubjectModal()">
    <div class="modal-container" style="max-width:520px">
        <div class="modal-header">
            <h3><i class="fas fa-tag"></i> Selecionar assunto</h3>
            <button class="modal-close" onclick="closeSubjectModal()">&times;</button>
        </div>
        <div class="modal-body" style="padding:14px">
            <input type="text" id="subjectSearchInput" class="form-control" placeholder="Buscar assunto..." oninput="filterSubjects()" style="margin-bottom:10px">
            <div class="option-list" id="subjectList">
                <button type="button" class="option-item <?= empty($conv['subject']) ? 'is-active' : '' ?>" data-subject="" onclick="pickSubject('')">
                    <span class="option-icon" style="background:var(--bg-panel-alt);color:var(--text-muted)"><i class="fas fa-ban"></i></span>
                    <span class="option-content">
                        <span class="option-title">Sem assunto</span>
                        <span class="option-desc">Remover o assunto atual desta conversa.</span>
                    </span>
                    <?php if (empty($conv['subject'])): ?>
                    <i class="fas fa-check option-check"></i>
                    <?php endif; ?>
                </button>
                <?php foreach (($convSubjects ?? []) as $s): ?>
                <button type="button" class="option-item <?= ($conv['subject'] ?? '') === $s['name'] ? 'is-active' : '' ?>" data-subject="<?= e($s['name']) ?>" onclick="pickSubject('<?= e($s['name']) ?>')">
                    <span class="option-icon" style="background:var(--brand-soft);color:var(--brand-2)"><i class="fas fa-tag"></i></span>
                    <span class="option-content">
                        <span class="option-title"><?= e($s['name']) ?></span>
                        <span class="option-desc">Assunto predefinido para classificar a conversa.</span>
                    </span>
                    <?php if (($conv['subject'] ?? '') === $s['name']): ?>
                    <i class="fas fa-check option-check"></i>
                    <?php endif; ?>
                </button>
                <?php endforeach; ?>
                <?php if (empty($convSubjects)): ?>
                <div class="empty-state" style="padding:24px">
                    <i class="fas fa-folder-open" style="font-size:32px;color:var(--text-muted);margin-bottom:8px"></i>
                    <p style="margin:0;font-size:13px">Nenhum assunto cadastrado.</p>
                    <p style="margin:4px 0 0;font-size:12px"><a href="<?= url('settings/subjects') ?>" target="_blank">Cadastrar assuntos</a></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="modal-overlay" role="dialog" aria-modal="true" id="cannedModal" style="display:none" onclick="if(event.target===this)closeCannedModal()">
    <div class="modal-container" style="max-width:480px">
        <div class="modal-header"><h3>Respostas Prontas</h3><button class="modal-close" onclick="closeCannedModal()">&times;</button></div>
        <div class="modal-body">
            <div class="form-group">
                <input type="text" id="cannedSearch" class="form-control" placeholder="Buscar resposta..." onkeyup="filterCanned()">
            </div>
            <div id="cannedList" class="canned-list"></div>
        </div>
    </div>
</div>

<div class="modal-overlay" role="dialog" aria-modal="true" id="macroModal" style="display:none" onclick="if(event.target===this)closeMacroModal()">
    <div class="modal-container" style="max-width:480px">
        <div class="modal-header"><h3>Macros</h3><button class="modal-close" onclick="closeMacroModal()">&times;</button></div>
    <div class="modal-body">
        <div class="form-group">
            <input type="text" id="macroSearch" class="form-control" placeholder="Buscar macro..." onkeyup="filterMacros()">
        </div>
        <div id="macroList" class="canned-list"></div>
        <p class="text-muted" style="margin-top:12px"><a href="<?= url('macros') ?>" target="_blank">Gerenciar macros</a></p>
    </div>
</div>
</div>

<div class="modal-overlay" role="dialog" aria-modal="true" id="wikiModal" style="display:none" onclick="if(event.target===this)closeWikiModal()">
    <div class="modal-container" style="max-width:480px">
        <div class="modal-header"><h3><i class="fas fa-book-open"></i> Sugerir artigo da Wiki</h3><button class="modal-close" onclick="closeWikiModal()">&times;</button></div>
        <div class="modal-body">
            <div class="form-group">
                <input type="text" id="wikiSearch" class="form-control" placeholder="Buscar artigo..." onkeyup="filterWikiSuggest()">
            </div>
            <div id="wikiList" class="canned-list"></div>
            <p class="text-muted" style="margin-top:12px">Insere no campo de mensagem o <strong>link do portal do cliente</strong>.</p>
        </div>
    </div>
</div>

<div class="modal-overlay" role="dialog" aria-modal="true" id="statusModal" style="display:none" onclick="if(event.target===this)closeStatusModal()">
    <div class="modal-container" style="max-width:520px">
        <div class="modal-header">
            <h3><i class="fas fa-comments"></i> Selecionar status</h3>
            <button class="modal-close" onclick="closeStatusModal()">&times;</button>
        </div>
        <form action="<?= url('inbox/') ?><?= $conv['id'] ?>/status" method="POST" id="statusForm">
            <?= csrf_field() ?>
            <input type="hidden" name="status" id="statusValue" value="<?= e($conv['status']) ?>">
            <div class="modal-body" style="padding:14px">
                <?php $isGroupConv = !empty($conv['group_id']); ?>
                <?php if ($isGroupConv): ?>
                <p class="text-muted" style="margin:0 0 12px;font-size:12.5px"><i class="fas fa-users"></i> Conversa de grupo: fica sempre aberta, não pode ser encerrada.</p>
                <?php else: ?>
                <p class="text-muted" style="margin:0 0 12px;font-size:12.5px">Defina o estado atual desta conversa na fila.</p>
                <?php endif; ?>
                <div class="option-list">
                    <?php
                    $statuses = [
                        'open' => ['label' => 'Aberto', 'desc' => 'Conversa em atendimento ativo.', 'icon' => 'fa-comments', 'cls' => 'option-status-open'],
                        'waiting_customer' => ['label' => 'Em atendimento', 'desc' => 'Aguardando resposta do cliente.', 'icon' => 'fa-headset', 'cls' => 'option-status-waiting_customer'],
                        'waiting_internal' => ['label' => 'Aguardando interno', 'desc' => 'Aguardando outro atendente ou setor.', 'icon' => 'fa-hourglass-half', 'cls' => 'option-status-waiting_internal'],
                        'resolved' => ['label' => 'Resolvido', 'desc' => 'Solicitação concluída com sucesso.', 'icon' => 'fa-check-circle', 'cls' => 'option-status-resolved'],
                        'closed' => ['label' => 'Fechado', 'desc' => 'Conversa encerrada sem solução.', 'icon' => 'fa-archive', 'cls' => 'option-status-closed'],
                        'spam' => ['label' => 'Spam', 'desc' => 'Marcada como lixo eletrônico.', 'icon' => 'fa-ban', 'cls' => 'option-status-spam'],
                    ];
                    if ($isGroupConv) {
                        unset($statuses['resolved'], $statuses['closed'], $statuses['spam']);
                    }
                    foreach ($statuses as $sval => $sinfo): ?>
                    <button type="button" class="option-item <?= $sinfo['cls'] ?> <?= $conv['status'] === $sval ? 'is-active' : '' ?>" data-status="<?= $sval ?>" onclick="pickStatus('<?= $sval ?>')">
                        <span class="option-icon"><i class="fas <?= $sinfo['icon'] ?>"></i></span>
                        <span class="option-content">
                            <span class="option-title"><?= $sinfo['label'] ?></span>
                            <span class="option-desc"><?= $sinfo['desc'] ?></span>
                        </span>
                        <?php if ($conv['status'] === $sval): ?>
                        <i class="fas fa-check option-check"></i>
                        <?php endif; ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <div id="closeFields" class="close-fields" style="display:none">
                    <div class="close-fields-header">
                        <i class="fas fa-clipboard-check"></i>
                        <div class="close-fields-title">
                            <strong>Detalhes do encerramento</strong>
                            <span>Registre o motivo e contexto para histórico e relatórios.</span>
                        </div>
                    </div>

                    <div class="close-fields-section">
                        <label class="close-fields-label">
                            <span>Motivo</span>
                            <span class="close-fields-required">obrigatório</span>
                        </label>
                        <input type="hidden" name="reason" id="closeReasonValue" value="<?= e($conversation['close_reason'] ?? '') ?>">
                        <div class="reason-grid" id="reasonGrid">
                            <?php foreach ($closeReasons as $r): ?>
                            <button type="button" class="reason-chip <?= ($conversation['close_reason'] ?? '') === $r['code'] ? 'is-active' : '' ?>" data-reason="<?= e($r['code']) ?>" data-label="<?= e($r['label']) ?>" onclick="pickReason('<?= e($r['code']) ?>', this)" style="--reason-color:<?= e($r['color']) ?>">
                                <span class="reason-icon"><i class="fas <?= e($r['icon'] ?: 'fa-tag') ?>"></i></span>
                                <span class="reason-label"><?= e($r['label']) ?></span>
                                <?php if (!empty($r['description'])): ?>
                                <span class="reason-desc"><?= e($r['description']) ?></span>
                                <?php endif; ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                        <p class="close-fields-hint" id="closeReasonHint" style="display:none">
                            <i class="fas fa-info-circle"></i>
                            <span>Nenhum motivo selecionado. Selecione um acima para continuar.</span>
                        </p>
                    </div>

                    <div class="close-fields-section">
                        <label class="close-fields-label" for="closeDescription">
                            <span>Descrição / observações</span>
                            <span class="close-fields-optional">opcional</span>
                        </label>
                        <textarea name="description" id="closeDescription" class="close-fields-textarea" rows="3" maxlength="600"
                                  placeholder="Descreva brevemente o que foi feito, a solução aplicada ou o contexto do encerramento..."
                                  oninput="updateCharCount('closeDescription','closeCharCount',600)"><?= e($conversation['close_description'] ?? '') ?></textarea>
                        <div class="close-fields-meta">
                            <span class="close-fields-hint-inline"><i class="fas fa-lock"></i> Visível apenas para a equipe</span>
                            <span class="char-count" id="closeCharCount"><?= mb_strlen($conversation['close_description'] ?? '') ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeStatusModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="statusSubmitBtn">Alterar status</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" role="dialog" aria-modal="true" id="snoozeModal" style="display:none" onclick="if(event.target===this)closeSnoozeModal()">
    <div class="modal-container" style="max-width:460px">
        <div class="modal-header"><h3>Agendar Atendimento</h3><button class="modal-close" onclick="closeSnoozeModal()">&times;</button></div>
        <form action="<?= url('inbox/') ?><?= $conv['id'] ?>/snooze" method="POST">
            <?= csrf_field() ?>
            <div class="modal-body">
                <div class="form-group">
                    <label>Retomar em</label>
                    <select name="until" class="form-control">
                        <option value="">Cancelar agendamento</option>
                        <option value="<?= date('Y-m-d\TH:i', strtotime('+1 hour')) ?>">Em 1 hora</option>
                        <option value="<?= date('Y-m-d\TH:i', strtotime('+4 hour')) ?>">Em 4 horas</option>
                        <option value="<?= date('Y-m-d\TH:i', strtotime('+1 day')) ?>">Amanhã</option>
                        <option value="<?= date('Y-m-d\TH:i', strtotime('+3 day')) ?>">Em 3 dias</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeSnoozeModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary">Agendar</button>
            </div>
        </form>
    </div>
</div>


<div class="modal-overlay" role="dialog" aria-modal="true" id="mergeModal" style="display:none" onclick="if(event.target===this)closeMergeModal()">
    <div class="modal-container" style="max-width:460px">
        <div class="modal-header"><h3>Mesclar Conversa</h3><button class="modal-close" onclick="closeMergeModal()">&times;</button></div>
        <form action="<?= url('inbox/') ?><?= $conv['id'] ?>/merge" method="POST">
            <?= csrf_field() ?>
            <div class="modal-body">
                <p class="text-muted" style="margin-bottom:14px">Mover mensagens e etiquetas desta conversa para outra do mesmo cliente.</p>
                <div class="form-group">
                    <label>Conversa de destino</label>
                    <select name="target_id" class="form-control">
                        <option value="">Selecione...</option>
                        <?php foreach ($otherConversations as $oc): ?>
                            <option value="<?= $oc['id'] ?>">#<?= $oc['id'] ?> - <?= e($oc['subject'] ?: $oc['contact_name']) ?> (<?= e($oc['status']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeMergeModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary">Mesclar</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" role="dialog" aria-modal="true" id="editModal" style="display:none" onclick="if(event.target===this)closeEditModal()">
    <div class="modal-container" style="max-width:460px">
        <div class="modal-header"><h3>Editar Mensagem</h3><button class="modal-close" onclick="closeEditModal()">&times;</button></div>
        <div class="modal-body">
            <div class="form-group">
                <textarea id="editContent" class="form-control" rows="4"></textarea>
            </div>
            <div class="form-group" style="margin-bottom:0">
                <button class="btn btn-primary" onclick="saveEdit()">Salvar</button>
            </div>
        </div>
    </div>
</div>

<div class="modal-overlay" role="dialog" aria-modal="true" id="contactEditModal" style="display:none" onclick="if(event.target===this)closeContactEditModal()">
    <div class="modal-container" style="max-width:480px">
        <div class="modal-header">
            <h3><i class="fas fa-user-edit"></i> Editar Contato</h3>
            <button class="modal-close" onclick="closeContactEditModal()">&times;</button>
        </div>
        <form action="<?= url('contacts/' . ((int)$contact['id'] ?? 0) . '/update') ?>" method="POST" id="contactEditForm" onsubmit="return submitContactEdit(event)">
            <?= csrf_field() ?>
            <div class="modal-body">
                <div class="form-group">
                    <label><i class="fas fa-user"></i> Nome</label>
                    <input type="text" name="name" class="form-control" value="<?= e($contact['name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-envelope"></i> E-mail</label>
                    <input type="email" name="email" class="form-control" value="<?= e($contact['email'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-phone"></i> Telefone</label>
                    <input type="text" name="phone" class="form-control" value="<?= e($contact['phone'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-id-card"></i> Documento</label>
                    <input type="text" name="document" class="form-control" value="<?= e($contact['document'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-building"></i> Loja</label>
                    <input type="text" name="company" class="form-control" value="<?= e($contact['company'] ?? '') ?>">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeContactEditModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="contactEditBtn"><i class="fas fa-save"></i> Salvar</button>
            </div>
        </form>
    </div>
</div>
