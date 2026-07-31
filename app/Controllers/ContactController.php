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
        $contacts = Contact::all(['search' => $search]);

        $tags = Database::getInstance()->fetchAll("SELECT * FROM tags ORDER BY name");

        View::renderWithLayout('contacts/index', 'main', [
            'title' => 'Contatos',
            'activePage' => 'contacts',
            'contacts' => $contacts,
            'tags' => $tags,
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

    public function show(Request $request, int $id): void
    {
        $contact = Contact::find($id);
        if (!$contact) {
            Session::setFlash('error', 'Contato não encontrado.');
            View::redirect('/contacts');
        }

        $conversations = Database::getInstance()->fetchAll(
            "SELECT c.*, ch.type as channel_type, ch.name as channel_name,
                    d.name as department_name, d.color as department_color,
                    u.name as assigned_user_name,
                    (SELECT content FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message,
                    (SELECT COUNT(*) FROM messages WHERE conversation_id = c.id) as message_count
             FROM conversations c
             LEFT JOIN channels ch ON ch.id = c.channel_id
             LEFT JOIN departments d ON d.id = c.department_id
             LEFT JOIN users u ON u.id = c.assigned_user_id
             WHERE c.contact_id = ?
             ORDER BY c.created_at DESC",
            [$id]
        );

        $tags = Database::getInstance()->fetchAll("SELECT * FROM tags ORDER BY name");

        View::renderWithLayout('contacts/show', 'main', [
            'title' => $contact['name'],
            'activePage' => 'contacts',
            'contact' => $contact,
            'conversations' => $conversations,
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

        $conversations = Database::getInstance()->fetchAll(
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
            [$id]
        );

        $allTags = Database::getInstance()->fetchAll("SELECT * FROM tags ORDER BY name");

        $html = View::renderBuffer('contacts/pdf', [
            'contact' => $contact,
            'conversations' => $conversations,
            'allTags' => $allTags,
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
