    <div class="conv-drawer-backdrop" id="clientDrawerBackdrop" onclick="toggleClientDrawer(false)"></div>
    <div class="conv-drawer" id="clientDrawer">
        <div class="conv-drawer-header">
            <h4><i class="fas fa-user"></i> Cliente</h4>
            <div style="display:flex;align-items:center;gap:4px">
                <button type="button" class="drawer-close" onclick="openContactEditModal()" title="Editar contato" style="font-size:14px;width:28px;height:28px">
                    <i class="fas fa-pen"></i>
                </button>
                <button type="button" class="drawer-close" onclick="toggleClientDrawer()" title="Fechar">&times;</button>
            </div>
        </div>
        <div class="conv-drawer-body">
            <div class="client-profile-card">
                <div class="client-cover">
                    <div class="client-cover-photo">
                        <?php if (!empty($contact['avatar'])): ?>
                            <img src="<?= e(str_starts_with($contact['avatar'], 'http') ? $contact['avatar'] : upload_url($contact['avatar'])) ?>" alt="<?= e($contact['name'] ?? '') ?>" onerror="this.style.display='none';this.parentElement.textContent='<?= e($initial) ?>'">
                        <?php else: ?>
                            <?= e($initial) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="client-info-body">
                    <div class="client-name">
                        <?= e($contact['name'] ?? 'Contato') ?>
                        <span class="conv-online-dot <?= $online ? 'online' : '' ?>"></span>
                    </div>
                    <div class="client-status"><?= $online ? 'Online agora' : 'Offline' ?></div>
                    <div class="client-fields-modern">
                        <?php if (!empty($contact['email'])): ?>
                            <div class="client-field-item">
                                <span class="field-icon icon-email"><i class="fas fa-envelope"></i></span>
                                <span><?= e($contact['email']) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($contact['phone'])): ?>
                            <div class="client-field-item">
                                <span class="field-icon icon-phone"><i class="fas fa-phone"></i></span>
                                <span><?= e($contact['phone']) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php
                        $panelNets = [];
                        foreach (($contact['stores'] ?? []) as $ps) { $panelNets[$ps['network_name']] = true; }
                        ?>
                        <?php if ($panelNets): ?>
                            <?php foreach (array_keys($panelNets) as $pNet): ?>
                                <div class="client-field-item">
                                    <span class="field-icon icon-building"><i class="fas fa-store"></i></span>
                                    <span><?= e($pNet) ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php elseif (!empty($contact['company'])): ?>
                            <div class="client-field-item">
                                <span class="field-icon icon-building"><i class="fas fa-building"></i></span>
                                <span><?= e($contact['company']) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($contact['document'])): ?>
                            <div class="client-field-item">
                                <span class="field-icon icon-document"><i class="fas fa-id-card"></i></span>
                                <span><?= e($contact['document']) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php foreach (($contact['phones'] ?? []) as $ph): ?>
                            <?php if (($ph['phone'] ?? null) === ($contact['phone'] ?? null)) continue; ?>
                            <div class="client-field-item">
                                <span class="field-icon icon-phone"><i class="fas fa-phone"></i></span>
                                <span><?= e($ph['phone']) ?><?= !empty($ph['label']) ? ' <small>(' . e($ph['label']) . ')</small>' : '' ?></span>
                            </div>
                        <?php endforeach; ?>
                        <?php foreach (($contact['emails'] ?? []) as $em): ?>
                            <?php if (($em['email'] ?? null) === ($contact['email'] ?? null)) continue; ?>
                            <div class="client-field-item">
                                <span class="field-icon icon-email"><i class="fas fa-envelope"></i></span>
                                <span><?= e($em['email']) ?><?= !empty($em['label']) ? ' <small>(' . e($em['label']) . ')</small>' : '' ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (!empty($contact['tags'])): ?>
                        <div class="conv-tags" style="margin-top: 12px;">
                            <?php foreach ($contact['tags'] as $tag): ?>
                                <span class="conv-tag" style="background:<?= e($tag['color'] ?? '#e9ecef') ?>;color:<?= e(contrast_color($tag['color'] ?? '#e9ecef')) ?>">
                                    <?= e($tag['name']) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h4><i class="fas fa-lock"></i> Observações Internas <span class="badge badge-tag-count"><?= count($internalNotes ?? []) ?></span></h4></div>
                <div class="card-body">
                    <form onsubmit="return submitInternalNote(event)">
                        <?= csrf_field() ?>
                        <textarea id="internalNoteInput" rows="3" class="form-control" placeholder="Adicionar observação interna (visível só para a equipe)..."></textarea>
                        <button type="submit" class="btn btn-sm btn-outline" id="internalNoteBtn" style="margin-top:8px"><i class="fas fa-save"></i> Salvar observação</button>
                    </form>
                    <div class="history-list-modern" id="internalNotesList" style="margin-top:12px">
                        <?php if (empty($internalNotes)): ?>
                            <p class="tag-empty-msg" id="internalNotesEmpty">Nenhuma observação registrada.</p>
                        <?php else: ?>
                            <?php foreach ($internalNotes as $note): ?>
                                <div class="history-item-modern">
                                    <div class="history-icon-modern" style="color:var(--warning)">
                                        <i class="fas fa-lock"></i>
                                    </div>
                                    <div class="history-content">
                                        <p style="font-size:13px;margin:0"><?= nl2br(e($note['content'])) ?></p>
                                        <span class="history-time" style="font-size:11px">
                                            <?= format_datetime($note['created_at']) ?>
                                            <?php if (!empty($note['user_name'])): ?> &middot; <?= e($note['user_name']) ?><?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h4><i class="fas fa-ticket-alt"></i> Atendimentos</h4></div>
                <div class="card-body p-0">
                    <?php if (empty($otherConversations)): ?>
                        <div class="empty-state" style="padding: 20px;"><p>Nenhum outro atendimento deste cliente.</p></div>
                    <?php else: ?>
                        <div class="other-tickets-list">
                            <?php foreach ($otherConversations as $oc): ?>
                                <a href="<?= url('inbox') ?>?conv=<?= $oc['id'] ?>" class="other-ticket-item">
                                    <div class="other-ticket-head">
                                        <span class="other-ticket-title"><?= e($oc['subject'] ?: $oc['contact_name']) ?><?php if (!empty($oc['unit'])): ?> — <?= e($oc['unit']) ?><?php endif; ?></span>
                                    </div>
                                    <div class="other-ticket-meta">
                                        <i class="<?= channel_icon($oc['channel_type'] ?? 'webchat') ?>"></i>
                                        <?= e($oc['channel_name'] ?? '') ?>
                                        <span class="dot-sep">&middot;</span>
                                        <?= status_badge($oc['status']) ?>
                                        <span class="dot-sep">&middot;</span>
                                        <?= format_datetime($oc['last_message_at'] ?? $oc['created_at']) ?>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h4><i class="fas fa-tags"></i> Etiquetas <span class="badge badge-tag-count" id="convTagCount"><?= count($conv['tags'] ?? []) ?></span></h4></div>
                <div class="card-body p-0">
                    <div class="tag-list" id="convTagList">
                        <?php if (empty($conv['tags'])): ?>
                            <p class="text-muted tag-empty-msg">Nenhuma etiqueta.</p>
                        <?php else: ?>
                            <?php foreach ($conv['tags'] as $tag): ?>
                                <span class="conv-tag-modern applied" data-tag-id="<?= $tag['id'] ?>" style="background:<?= e($tag['color'] ?? '#6c757d') ?>;color:<?= e(contrast_color($tag['color'] ?? '#6c757d')) ?>">
                                    <?= e($tag['name']) ?>
                                    <button type="button" class="tag-remove-btn" data-tag-id="<?= $tag['id'] ?>" title="Remover">&times;</button>
                                </span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div class="tag-picker-section">
                        <div class="tag-picker-header">Todas as etiquetas</div>
                        <div class="tag-grid" id="tagGrid">
                            <?php
                            $convTagIds = array_column($conv['tags'] ?? [], 'id');
                            foreach ($allTags as $t):
                                $applied = in_array($t['id'], $convTagIds);
                            ?>
                                <button type="button"
                                    class="tag-grid-item <?= $applied ? 'applied' : '' ?>"
                                    data-tag-id="<?= $t['id'] ?>"
                                    data-color="<?= e($t['color'] ?? '#6c757d') ?>"
                                    style="--tag-color:<?= e($t['color'] ?? '#6c757d') ?>">
                                    <?= e($t['name']) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <div class="tag-create-row">
                            <input type="text" class="tag-create-input" id="tagCreateInput" placeholder="Nova etiqueta..." maxlength="40">
                            <button type="button" class="btn btn-sm btn-primary tag-create-btn" id="tagCreateBtn" disabled>Criar</button>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($csat): ?>
            <div class="card">
                <div class="card-header"><h4><i class="fas fa-smile"></i> Avaliação</h4></div>
                <div class="card-body" style="text-align:center">
                    <div class="csat-stars">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="fas fa-star <?= $i <= $csat['rating'] ? 'on' : '' ?>"></i>
                        <?php endfor; ?>
                    </div>
                    <?php if (!empty($csat['comment'])): ?>
                        <p class="text-muted" style="margin-top:8px;font-size:13px"><?= e($csat['comment']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header"><h4><i class="fas fa-history"></i> Histórico <span class="badge badge-tag-count"><?= count($events) ?></span></h4></div>
                <div class="card-body p-0">
                    <div class="history-list-modern" id="convHistory">
                        <?php if (empty($events)): ?>
                            <p class="tag-empty-msg">Nenhum evento registrado.</p>
                        <?php else: ?>
                            <?php foreach ($events as $event): ?>
                                <div class="history-item-modern">
                                    <div class="history-icon-modern" style="color:<?= event_color($event['event_type']) ?>">
                                        <i class="fas <?= event_icon($event['event_type']) ?>"></i>
                                    </div>
                                    <div class="history-content">
                                        <p style="font-size:13px;margin:0"><?= e($event['description']) ?></p>
                                        <span class="history-time" style="font-size:11px">
                                            <?= format_datetime($event['created_at']) ?>
                                            <?php if ($event['user_name']): ?> &middot; <?= e($event['user_name']) ?><?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
