<?php
/**
 * Partial compartilhado: navegação em abas das telas de Configurações.
 * Variáveis esperadas: $subPage (chave da aba ativa) e opcionalmente $settingsBaseUrl.
 *
 * Chaves aceitas: geral | subjects | close-reasons | inboxes | channels | notifications
 */
$subPage = $subPage ?? '';
$tabs = [
    'geral'         => ['label' => 'Geral',          'url' => url('settings')],
    'channels'      => ['label' => 'Canais',         'url' => url('channels')],
    'inboxes'       => ['label' => 'Caixas',         'url' => url('inboxes')],
    'notifications' => ['label' => 'Notificações',   'url' => url('settings/notifications')],
    'subjects'      => ['label' => 'Assuntos',       'url' => url('settings/subjects')],
    'close-reasons' => ['label' => 'Motivos',        'url' => url('settings/close-reasons')],
];
?>
<nav class="settings-tabs" aria-label="Navegação de Configurações">
    <?php foreach ($tabs as $key => $t): ?>
        <a href="<?= $t['url'] ?>"
           class="settings-tab <?= $subPage === $key ? 'active' : '' ?>">
            <?= e($t['label']) ?>
        </a>
    <?php endforeach; ?>
</nav>
<style>
.settings-tabs {
    display: flex;
    gap: 6px;
    margin-bottom: 20px;
    padding: 6px;
    background: var(--bg-card, #f8f9fa);
    border: 1px solid var(--border-soft, #e9ecef);
    border-radius: 12px;
    overflow-x: auto;
    flex-wrap: wrap;
}
.settings-tab {
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-muted, #6c757d);
    text-decoration: none;
    transition: background-color .15s, color .15s;
    white-space: nowrap;
}
.settings-tab:hover {
    background: var(--bg-content, #fff);
    color: var(--text, #212529);
}
.settings-tab.active {
    background: var(--primary, #0078d4);
    color: #fff;
    box-shadow: 0 1px 2px rgba(0,0,0,.08);
}
</style>
