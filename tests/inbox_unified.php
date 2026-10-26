<?php
/**
 * Testes da caixa de entrada unificada (refactor de 2026-10-24).
 *
 * A caixa deixou de ser container: a conversa pertence a uma caixa pelo
 * setor, pelo canal ou pelo responsável. A listagem virou uma query só e o
 * recorte vem dos filtros da tela.
 *
 * Roda junto do runner principal (tests/run.php).
 */

$convSrc   = file_get_contents(__DIR__ . '/../app/Models/Conversation.php');
$inboxSrc  = file_get_contents(__DIR__ . '/../app/Models/Inbox.php');
$ctlSrc    = file_get_contents(__DIR__ . '/../app/Controllers/InboxController.php');
$countsSrc = file_get_contents(__DIR__ . '/../app/Controllers/Api/InboxCountsController.php');
$searchSrc = file_get_contents(__DIR__ . '/../app/Controllers/Api/GlobalSearchController.php');
$rtSrc     = file_get_contents(__DIR__ . '/../app/Controllers/Api/RealtimeController.php');
$viewSrc   = file_get_contents(__DIR__ . '/../app/Views/inbox/index.php');
$mainSrc   = file_get_contents(__DIR__ . '/../app/Views/layouts/main.php');
$formSrc   = file_get_contents(__DIR__ . '/../app/Views/settings/inbox_form.php');
$jsSrc     = file_get_contents(__DIR__ . '/../public/assets/js/app-enhancements.js');
$migSrc    = file_get_contents(__DIR__ . '/../database/migrations/2026_10_24_caixa_unificada.sql');
$migGrupos = file_get_contents(__DIR__ . '/../database/migrations/2026_10_24_caixa_grupos.sql');
$settingsSrc = file_get_contents(__DIR__ . '/../app/Controllers/SettingsController.php');

// ── A pertinência NÃO é mais pelo ponteiro da conversa ───────────────────
// c.inbox_id = ? era o roteamento antigo; com ele, a mesma conversa
// contava em duas caixas e o menu somava duas vezes.
check(
    'caixa unificada: a pertinencia nao usa mais c.inbox_id',
    !str_contains($convSrc, 'c.inbox_id = ?')
);
check(
    'caixa unificada: sumiu o fallback de inbox_id nulo',
    !str_contains($convSrc, 'c.inbox_id IS NULL')
);
check(
    'caixa unificada: existe um unico predicado de pertinencia',
    str_contains($convSrc, 'function membershipPredicate')
    && str_contains($inboxSrc, 'function loadMembershipMap')
    && str_contains($inboxSrc, 'function setsFromMap')
);
check(
    'caixa unificada: a caixa pessoal usa os donos declarados',
    str_contains($inboxSrc, 'function getExplicitUsers')
);

// ── A listagem é uma query só ────────────────────────────────────────────
// A UNION ALL por caixa repetia a base pesada (subqueries de mensagens)
// N vezes e ainda deduplicava em PHP. (O texto "UNION ALL" pode sobrar em
// comentário explicando o que era; o que não pode é a montagem das branches.)
check(
    'caixa unificada: a listagem nao faz UNION por caixa',
    !str_contains($convSrc, "implode(' UNION ALL '")
    && !str_contains($convSrc, 'UNION ALL SELECT')
    && str_contains($convSrc, 'function getConversationsForSets')
);
check(
    'caixa unificada: o total do escopo conta sem somar badges',
    str_contains($convSrc, 'function openCountForSets')
    && str_contains($countsSrc, 'openCountForSets')
);
check(
    'caixa unificada: os badges por caixa contam sem duplicar',
    str_contains($convSrc, 'COUNT(DISTINCT CASE WHEN')
);

// ── "Minha Caixa" é filtro por responsável ───────────────────────────────
// Antes ela dependia de existir uma caixa pessoal; um atendente sem
// caixa pessoal viajava as conversas que tinha atribuídas.
check(
    'caixa unificada: minha caixa usa o filtro por responsavel',
    str_contains($ctlSrc, "\$filters['mine'] = true")
);
check(
    'caixa unificada: minha caixa nao depende de caixa pessoal',
    !str_contains($ctlSrc, 'getOwnedPersonalInboxes')
);
check(
    'caixa unificada: minha caixa filtra so o que esta alocado a mim',
    str_contains($convSrc, "\$filters['mine']")
    && str_contains($convSrc, "assigned_user_id = ?")
);
check(
    'caixa unificada: o escopo mine ganha do filtro de responsavel',
    str_contains($ctlSrc, "if (\$request->input('mine'))")
);

// ── Os filtros existem no modelo e na tela ───────────────────────────────
// O filtro de SETOR foi removido: a caixa de entrada é única e o recorte
// vem de canal/prioridade/responsável. Sobrou filtro de setor na tela, o
// servidor ou o live-feed, a barra "não funciona" — o poll reescreve a
// lista com um recorte que a tela não conhece.
foreach (['channel_id', 'channel', 'priority', 'assignee', 'unread'] as $filter) {
    check(
        "caixa unificada: o filtro {$filter} existe no modelo",
        str_contains($convSrc, "\$filters['{$filter}']")
    );
}
check(
    'caixa unificada: o filtro de setor saiu do modelo',
    !str_contains($convSrc, "\$filters['department']")
);
check(
    'caixa unificada: o filtro de setor saiu do servidor',
    !str_contains($ctlSrc, "input('department'")
);
check(
    'caixa unificada: o filtro de setor saiu da tela',
    !str_contains($viewSrc, 'name="department"')
);
check(
    'caixa unificada: o filtro de setor saiu do live-feed',
    !str_contains($jsSrc, "'department'")
);
check(
    'caixa unificada: a tela tem a barra de filtros',
    str_contains($viewSrc, 'filter-bar')
    && str_contains($viewSrc, 'name="channel_id"')
    && str_contains($viewSrc, 'name="priority"')
    && str_contains($viewSrc, 'name="assignee"')
    && str_contains($viewSrc, 'name="unread"')
);

// ── Uma regra de visibilidade só ─────────────────────────────────────────
check(
    'caixa unificada: existe uma regra de visibilidade unica',
    str_contains($convSrc, 'function visibilitySql')
    && str_contains($searchSrc, 'visibilitySql')
    && str_contains($rtSrc, 'visibilitySql')
);

// ── Sobrou chamada de metodo removido? ───────────────────────────────────
// Só a CHAMADA importa: o nome pode sobrar em comentário explicando o
// histórico (foi o que aconteceu no WhatsAppService).
$appFiles = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../app'));
foreach ($it as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $appFiles[] = $file->getPathname();
    }
}
$stale = [];
foreach ($appFiles as $file) {
    $src = file_get_contents($file);
    // Comentário não é chamada: o nome pode sobreviver na historia do
    // código (o WhatsAppService explica ali a troca do metodo).
    $code = '';
    foreach (explode("\n", $src) as $line) {
        $line = preg_replace('#//.*$#', '', $line);
        $line = preg_replace('#^\s*\*.*$#', '', $line);
        $code .= $line . "\n";
    }
    if (str_contains($code, 'Inbox::resolveInboxForChannel(')
        || str_contains($code, 'Inbox::resolveInboxForDepartment(')) {
        $stale[] = basename($file);
    }
}
check(
    'caixa unificada: nenhuma chamada do metodo removido sobrou',
    empty($stale),
    implode(',', $stale)
);

// ── A aba inicial filtra o status ────────────────────────────────────────
// Sem o status padrao, /inbox vinha sem filtro: a aba "Em atendimento"
// ficava destacada e a lista mostrava conversa finalizada.
check(
    'caixa unificada: o filtro de status tem padrao',
    str_contains($ctlSrc, 'filtersFromRequest(Request $request, string $defaultStatus')
    && str_contains($ctlSrc, "filtersFromRequest(\$request, 'active')")
);

// ── O live-feed carrega o mesmo recorte da tela ──────────────────────────
// Sem isso o poll reescrevia a lista com conversas de fora do filtro e o
// filtro "nao funcionava" alguns segundos depois de aplicar.
check(
    'caixa unificada: o poll usa o recorte da pagina',
    str_contains($jsSrc, '_filterQuery()')
    && str_contains($jsSrc, "baseUrl + '/api/conversations' + this._filterQuery()")
);
check(
    'caixa unificada: os contadores das abas usam o mesmo recorte',
    str_contains($jsSrc, "baseUrl + '/api/conversations-counts' + this._filterQuery()")
);
check(
    'caixa unificada: checkbox desligado nao vira filtro',
    str_contains($jsSrc, "el.type === 'checkbox'")
);
check(
    'caixa unificada: o SSE respeita o recorte da tela',
    str_contains($jsSrc, '_matchesCurrentFilter(c) {')
    && str_contains($jsSrc, '_matchesCurrentFilter(initial)')
);

// ── O formulario de caixa reflecte a semantica nova ─────────────────────
check(
    'caixa unificada: o formulario separa setor de dono',
    str_contains($formSrc, 'Donos desta caixa')
    && str_contains($formSrc, 'inboxDepartmentsGroup')
);

// ── Os escopos "minhas" e "sem responsável" viajam na URL ───────────────
// Sao recortes do mesmo escopo. Se ficarem so no servidor, o poll do
// live-feed reescreve a lista sem eles e a pagina volta a mostrar tudo.
check(
    'caixa unificada: o escopo mine e lido da url',
    str_contains($ctlSrc, "input('mine')")
    && str_contains($ctlSrc, "input('unassigned')")
);
check(
    'caixa unificada: o link da minha caixa leva o escopo',
    str_contains($mainSrc, "route('inbox.mine') ?>?mine=1")
);
check(
    'caixa unificada: o poll leva os escopos',
    str_contains($jsSrc, "'unread', 'mine', 'unassigned'")
);
check(
    'caixa unificada: a tela preserva o escopo nos formularios',
    str_contains($viewSrc, 'name="mine"')
    && str_contains($viewSrc, 'name="unassigned"')
);

// ── A migracao cria os indices dos eixos de pertinencia ─────────────────
check(
    'caixa unificada: a migracao dos eixos existe',
    $migSrc !== false
    && str_contains($migSrc, 'idx_conv_department')
    && str_contains($migSrc, 'idx_conv_channel')
    && str_contains($migSrc, 'idx_conv_assigned')
    && str_contains($migSrc, 'idx_inbox_dept_department')
    && str_contains($migSrc, 'idx_inbox_ch_channel')
    && str_contains($migSrc, 'idx_inbox_user_user')
);

// ── Grupo do WhatsApp x mensagem geral ──────────────────────────────────
// "Grupos Suporte" e "Suporte" cobrem os mesmos setores: o que os separa
// e a NATUREZA da conversa (group_id preenchido ou nao). Sem isso as duas
// caixas mostram exatamente o mesmo atendimento.
check(
    'caixa unificada: a migracao cria o escopo de conversa da caixa',
    $migGrupos !== false
    && str_contains($migGrupos, 'conversation_scope')
    && str_contains($migGrupos, 'groups')
    && str_contains($migGrupos, 'non_groups')
);
check(
    'caixa unificada: a caixa le o escopo de conversa',
    str_contains($inboxSrc, 'conversation_scope')
    && str_contains($inboxSrc, "'non_groups' => 'nonGroups'")
);
check(
    'caixa unificada: o predicado separa grupo de mensagem geral',
    str_contains($convSrc, 'group_id IS NOT NULL')
    && str_contains($convSrc, 'group_id IS NULL')
    && str_contains($convSrc, 'function axesClause')
);
check(
    'caixa unificada: o grupo ligado a caixa entra na pertinencia',
    str_contains($inboxSrc, 'whatsapp_groups')
    && str_contains($convSrc, 'group_id IN')
);
check(
    'caixa unificada: o formulario define a natureza das conversas',
    str_contains($formSrc, 'name="conversation_scope"')
    && str_contains($formSrc, 'Natureza das conversas')
);
check(
    'caixa unificada: o controller grava o escopo da caixa',
    str_contains($settingsSrc, "conversation_scope")
    && str_contains($settingsSrc, 'inboxScope')
);

// ── A bolinha das caixas de grupo só conta menção ────────────────────────
// Mensagem comum de grupo é histórico do grupo, não é atendimento: a
// bolinha acende quando o número é mencionado e a menção ainda não foi
// lida. Sem isso, a caixa de grupos fica acesa com o grupo inteiro.
check(
    'caixa unificada: o badge da caixa de grupo exige mencao nao lida',
    str_contains($convSrc, 'is_mention = 1')
    && str_contains($convSrc, 'is_read = 0')
    && str_contains($convSrc, 'openCountsByInbox')
);
check(
    'caixa unificada: o badge de grupo nao vale para as outras caixas',
    str_contains($convSrc, "=== 'groups'")
);
