<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Models\Contact;
use App\Models\Conversation;

class ContactController
{
    public function index(Request $request): void
    {
        $search = $request->get('search');
        $page = max(1, (int) $request->get('page', 1));
        $perPage = 24;

        $filters = ['search' => $search];
        $total = Contact::count($filters);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $contacts = Contact::all($filters + [
            'limit' => $perPage,
            'offset' => ($page - 1) * $perPage,
        ]);

        $db = Database::getInstance();
        $totalContacts = (int) ($db->fetch("SELECT COUNT(*) as t FROM contacts")['t'] ?? 0);
        $withConvs = (int) ($db->fetch(
            "SELECT COUNT(DISTINCT contact_id) as t FROM conversations WHERE contact_id IS NOT NULL"
        )['t'] ?? 0);
        $todayStart = date('Y-m-d 00:00:00');
        $newToday = (int) ($db->fetch(
            "SELECT COUNT(*) as t FROM contacts WHERE created_at >= ?", [$todayStart]
        )['t'] ?? 0);
        $withoutConvs = max(0, $totalContacts - $withConvs);

        $tags = $db->fetchAll("SELECT * FROM tags ORDER BY name");

        View::renderWithLayout('contacts/index', 'main', [
            'title' => 'Contatos',
            'activePage' => 'contacts',
            'contacts' => $contacts,
            'tags' => $tags,
            'stats' => [
                'total' => $totalContacts,
                'with_conversations' => $withConvs,
                'without_conversations' => $withoutConvs,
                'new_today' => $newToday,
            ],
            'search' => $search,
            'pagination' => [
                'page' => $page,
                'pages' => $pages,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    public function apiSearch(Request $request): void
    {
        $term = $request->get('q');
        if (!$term || strlen(trim($term)) < 1) {
            View::json([]);
            return;
        }
        View::json(Contact::search($term));
    }

    /**
     * Proxy da API Guild (token fica no servidor): lojas/unidades do cliente.
     * GET /api/guild/stores?customer_id=
     */
    public function apiGuildStores(Request $request): void
    {
        $customerId = trim((string) $request->get('customer_id', ''));
        if ($customerId === '') {
            View::json(['success' => false, 'error' => 'Informe o código da loja.']);
            return;
        }
        try {
            $result = (new \App\Services\GuildService())->getStores($customerId);
            View::json(['success' => true] + $result);
        } catch (\App\Services\GuildException $e) {
            View::json(['success' => false, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            error_log('Erro inesperado no proxy Guild: ' . $e->getMessage());
            View::json(['success' => false, 'error' => 'Falha ao consultar o painel Guild. Tente novamente.']);
        }
    }

    public function show(Request $request, int $id): void
    {
        $contact = Contact::find($id);
        if (!$contact) {
            Session::setFlash('error', 'Contato não encontrado.');
            View::redirect('/contacts');
        }

        $filters = [
            'year'       => (int) $request->get('year', 0),
            'month'      => (int) $request->get('month', 0),
            'department' => (int) $request->get('department', 0),
            'status'     => trim((string) $request->get('status', '')) ?: null,
        ];

        $conversations = Contact::getConversations($id, $filters);
        $statusCounts  = Contact::countConversationsByStatus($id, $filters);
        $availableMonths = Contact::getAvailableMonths($id);
        $availableDepartments = Contact::getAvailableDepartments($id);

        $tags = Database::getInstance()->fetchAll("SELECT * FROM tags ORDER BY name");

        View::renderWithLayout('contacts/show', 'main', [
            'title' => $contact['name'],
            'activePage' => 'contacts',
            'contact' => $contact,
            'conversations' => $conversations,
            'statusCounts' => $statusCounts,
            'availableMonths' => $availableMonths,
            'availableDepartments' => $availableDepartments,
            'filters' => $filters,
            'tags' => $tags,
        ]);
    }

    public function create(Request $request): void
    {
        $tags = Database::getInstance()->fetchAll("SELECT * FROM tags ORDER BY name");

        View::renderWithLayout('contacts/form', 'main', [
            'title' => 'Novo Contato',
            'activePage' => 'contacts',
            'contact' => null,
            'tags' => $tags,
        ]);
    }

    public function store(Request $request): void
    {
        $name = $request->post('name');
        $email = $request->post('email');
        $phone = $request->post('phone');
        $company = $request->post('company');
        $document = $request->post('document');
        $notes = $request->post('notes');

        if (empty(trim($name ?? ''))) {
            Session::setFlash('error', 'O nome do contato é obrigatório.');
            View::back();
        }

        $contactId = Contact::create([
            'name' => $name,
            'email' => $email ?: null,
            'phone' => $phone ?: null,
            'company' => $company ?: null,
            'document' => $document ?: null,
            'notes' => $notes ?: null,
        ]);

        if ($phone) {
            Contact::addPhone($contactId, $phone, 'principal', true);
        }
        if ($email) {
            Contact::addEmail($contactId, $email, 'principal', true);
        }

        $tagIds = $request->post('tag_ids', []);
        if (is_array($tagIds)) {
            foreach ($tagIds as $tagId) {
                Database::getInstance()->insert('contact_tags', [
                    'contact_id' => $contactId,
                    'tag_id' => (int) $tagId,
                ]);
            }
        }

        Session::setFlash('success', 'Contato criado com sucesso.');
        View::redirect("/contacts/{$contactId}");
    }

    public function edit(Request $request, int $id): void
    {
        $contact = Contact::find($id);
        if (!$contact) {
            Session::setFlash('error', 'Contato não encontrado.');
            View::redirect('/contacts');
        }

        $tags = Database::getInstance()->fetchAll("SELECT * FROM tags ORDER BY name");

        View::renderWithLayout('contacts/form', 'main', [
            'title' => 'Editar Contato',
            'activePage' => 'contacts',
            'contact' => $contact,
            'tags' => $tags,
        ]);
    }

    public function update(Request $request, int $id): void
    {
        $contact = Contact::find($id);
        if (!$contact) {
            if ($request->isAjax()) {
                self::json(['success' => false, 'error' => 'Contato não encontrado.']);
            }
            Session::setFlash('error', 'Contato não encontrado.');
            View::redirect('/contacts');
        }

        $name = $request->post('name');
        if (empty(trim($name ?? ''))) {
            if ($request->isAjax()) {
                self::json(['success' => false, 'error' => 'O nome do contato é obrigatório.']);
            }
            Session::setFlash('error', 'O nome do contato é obrigatório.');
            View::back();
        }

        Contact::update($id, [
            'name' => $name,
            'email' => $request->post('email') ?: null,
            'phone' => $request->post('phone') ?: null,
            'company' => $request->post('company') ?: null,
            'document' => $request->post('document') ?: null,
            'notes' => $request->post('notes') ?: null,
        ]);

        // Sync tags dentro de transação para evitar perda em caso de erro
        $db = Database::getInstance();
        try {
            $db->beginTransaction();
            $db->delete('contact_tags', 'contact_id = ?', [$id]);
            $tagIds = $request->post('tag_ids', []);
            if (is_array($tagIds)) {
                foreach ($tagIds as $tagId) {
                    $db->insert('contact_tags', [
                        'contact_id' => $id,
                        'tag_id' => (int) $tagId,
                    ]);
                }
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('Erro ao sincronizar tags do contato: ' . $e->getMessage());
            if ($request->isAjax()) {
                self::json(['success' => false, 'error' => 'Erro ao salvar as etiquetas.']);
            }
            Session::setFlash('error', 'Erro ao salvar as etiquetas. Tente novamente.');
            View::back();
        }

        if ($request->isAjax()) {
            self::json(['success' => true, 'name' => $name]);
        }

        Session::setFlash('success', 'Contato atualizado com sucesso.');
        View::redirect("/contacts/{$id}");
    }

    private static function json(array $data): void
    {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    public function destroy(Request $request, int $id): void
    {
        $contact = Contact::find($id);
        if (!$contact) {
            Session::setFlash('error', 'Contato não encontrado.');
            View::redirect('/contacts');
        }

        $db = Database::getInstance();
        try {
            $db->beginTransaction();

            // Remove conversas (messages, eventos, etc. cascateiam)
            $db->delete('conversations', 'contact_id = ?', [$id]);

            // Remove o contato (contact_phones/emails/tags cascateiam)
            Contact::delete($id);

            $db->commit();
            Session::setFlash('success', 'Contato e todas as conversas associadas foram removidos.');
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('Erro ao excluir contato: ' . $e->getMessage());
            Session::setFlash('error', 'Erro ao excluir contato. Tente novamente.');
        }

        View::redirect('/contacts');
    }

    public function downloadPdf(Request $request, int $id): void
    {
        $contact = Contact::find($id);
        if (!$contact) {
            Session::setFlash('error', 'Contato não encontrado.');
            View::redirect('/contacts');
        }

        $conversations = $this->resolveConversationsForPdf($id, $request);

        if (empty($conversations)) {
            Session::setFlash('error', 'Nenhuma conversa encontrada para os filtros selecionados.');
            View::redirect("/contacts/{$id}");
        }

        $allTags = Database::getInstance()->fetchAll("SELECT * FROM tags ORDER BY name");

        // Carrega conteúdo completo (mensagens, eventos, CSAT, tags) para cada conversa.
        $conversationsData = [];
        foreach ($conversations as $conv) {
            $cid = (int) $conv['id'];
            $conversationsData[] = [
                'conversation' => $this->enrichConversationForPdf($conv),
                'messages'     => \App\Models\Conversation::getMessages($cid),
                'events'       => \App\Models\Conversation::getEvents($cid),
                'csat'         => \App\Models\Conversation::getCsat($cid),
            ];
        }

        $html = View::renderBuffer('contacts/pdf_full', [
            'contact' => $contact,
            'conversationsData' => $conversationsData,
            'allTags' => $allTags,
            'pdfScope' => $this->pdfScopeLabel($request, count($conversations)),
        ]);

        $dompdf = new \Dompdf\Dompdf();
        $dompdf->setPaper('A4');
        $dompdf->loadHtml($html);
        $dompdf->render();

        while (ob_get_level()) {
            ob_end_clean();
        }

        $filename = 'contato-' . slugify($contact['name']) . '-' . $id . '.pdf';
        $dompdf->stream($filename, ['Attachment' => true]);
        exit;
    }

    /**
     * Enriquece a linha crua da conversa (vinda de Contact::getConversations
     * ou da query por IDs) com as tags do contato e demais campos esperados
     * pelo template PDF.
     */
    private function enrichConversationForPdf(array $conv): array
    {
        $db = Database::getInstance();
        // Tags
        $tags = $db->fetchAll(
            "SELECT t.* FROM tags t
             INNER JOIN conversation_tags ct ON ct.tag_id = t.id
             WHERE ct.conversation_id = ?",
            [$conv['id']]
        );
        $conv['tags'] = $tags;
        return $conv;
    }

    /**
     * Resolve a lista de conversas a exportar com base em `?ids=1,2,3` ou nos
     * filtros `?year=&month=&department=&status=`. Sem nenhum filtro, devolve
     * todas as conversas do contato (ordenadas por criação desc).
     *
     * @return array<int, array<string, mixed>>
     */
    private function resolveConversationsForPdf(int $contactId, Request $request): array
    {
        $idsRaw = trim((string) $request->get('ids', ''));
        if ($idsRaw !== '') {
            $ids = array_values(array_filter(array_map('intval', explode(',', $idsRaw)), fn($v) => $v > 0));
            if (empty($ids)) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $params = array_merge([$contactId], $ids);
            return Database::getInstance()->fetchAll(
                "SELECT c.*, ch.type as channel_type, ch.name as channel_name,
                        d.name as department_name, d.color as department_color,
                        u.name as assigned_user_name,
                        (SELECT COUNT(*) FROM messages WHERE conversation_id = c.id) as message_count
                 FROM conversations c
                 LEFT JOIN channels ch ON ch.id = c.channel_id
                 LEFT JOIN departments d ON d.id = c.department_id
                 LEFT JOIN users u ON u.id = c.assigned_user_id
                 WHERE c.contact_id = ? AND c.id IN ($placeholders)
                 ORDER BY c.created_at DESC",
                $params
            );
        }

        $filters = [
            'year'       => (int) $request->get('year', 0),
            'month'      => (int) $request->get('month', 0),
            'department' => (int) $request->get('department', 0),
            'status'     => trim((string) $request->get('status', '')) ?: null,
        ];
        // Se nenhum filtro veio, exporta todas (comportamento legado).
        $hasFilter = $filters['year'] || $filters['month'] || $filters['department'] || $filters['status'];
        if (!$hasFilter) {
            return Database::getInstance()->fetchAll(
                "SELECT c.*, ch.type as channel_type, ch.name as channel_name,
                        d.name as department_name, d.color as department_color,
                        u.name as assigned_user_name,
                        (SELECT COUNT(*) FROM messages WHERE conversation_id = c.id) as message_count
                 FROM conversations c
                 LEFT JOIN channels ch ON ch.id = c.channel_id
                 LEFT JOIN departments d ON d.id = c.department_id
                 LEFT JOIN users u ON u.id = c.assigned_user_id
                 WHERE c.contact_id = ?
                 ORDER BY c.created_at DESC",
                [$contactId]
            );
        }

        return Contact::getConversations($contactId, $filters);
    }

    private function pdfScopeLabel(Request $request, int $count = 0): string
    {
        if (trim((string) $request->get('ids', '')) !== '') {
            $ids = array_filter(explode(',', $request->get('ids', '')));
            $idsCount = count($ids);
            $label = $idsCount > 0 ? $idsCount . ' conversa(s) selecionada(s)' : 'Conversa selecionada';
            if ($count > 0 && $count !== $idsCount) {
                $label .= ' — ' . $count . ' encontrada(s)';
            }
            return $label;
        }
        $filters = [
            'year'       => (int) $request->get('year', 0),
            'month'      => (int) $request->get('month', 0),
            'department' => (int) $request->get('department', 0),
            'status'     => trim((string) $request->get('status', '')),
        ];
        $parts = [];
        $monthNames = [1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
                       'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
        if ($filters['month']) {
            $parts[] = $monthNames[$filters['month']] ?? $filters['month'];
        }
        if ($filters['year']) {
            $parts[] = $filters['year'];
        }
        if ($filters['status']) {
            $statusLabels = ['new' => 'Novos', 'open' => 'Abertos',
                             'waiting_customer' => 'Aguardando cliente', 'waiting_internal' => 'Aguardando interno',
                             'resolved' => 'Resolvidos', 'closed' => 'Fechados', 'spam' => 'Spam'];
            $parts[] = $statusLabels[$filters['status']] ?? $filters['status'];
        }
        if ($filters['department']) {
            $dept = Database::getInstance()->fetch(
                "SELECT name FROM departments WHERE id = ?", [$filters['department']]
            );
            if ($dept) {
                $parts[] = 'Setor: ' . $dept['name'];
            }
        }
        if ($parts) {
            return 'Filtro: ' . implode(' · ', $parts) . ' — ' . $count . ' conversa(s)';
        }
        return 'Todas as conversas — ' . $count . ' encontrada(s)';
    }

    public function merge(Request $request, int $id): void
    {
        $targetId = (int) $request->post('target_id');
        if ($targetId && $targetId !== $id) {
            Contact::merge($id, $targetId);
            Session::setFlash('success', 'Contatos mesclados com sucesso.');
            View::redirect("/contacts/{$targetId}");
        }
        Session::setFlash('error', 'Selecione um contato de destino válido.');
        View::redirect("/contacts/{$id}");
    }

}
