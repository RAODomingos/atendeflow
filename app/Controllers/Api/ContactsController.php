<?php

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Request;
use App\Core\View;
use App\Models\Contact;
use App\Models\Tag;

class ContactsController
{
    /**
     * GET /api/v2/contacts
     */
    public function index(Request $request): void
    {
        $filters = [];
        $search = trim((string) $request->input('search'));
        if ($search) {
            $filters['search'] = $search;
        }

        $contacts = Contact::all($filters);
        View::json($contacts);
    }

    /**
     * GET /api/v2/contacts/{id}
     */
    public function show(Request $request, int $id): void
    {
        $contact = Contact::find($id);
        if (!$contact) {
            View::json(['error' => 'Contato não encontrado'], 404);
            return;
        }
        View::json($contact);
    }

    /**
     * POST /api/v2/contacts
     */
    public function store(Request $request): void
    {
        $name = trim((string) $request->post('name'));
        if (empty($name)) {
            View::json(['error' => 'Nome é obrigatório'], 400);
            return;
        }

        $id = Contact::create([
            'name' => $name,
            'email' => $request->post('email'),
            'phone' => $request->post('phone'),
            'company' => $request->post('company'),
            'document' => $request->post('document'),
            'notes' => $request->post('notes'),
        ]);

        $contact = Contact::find($id);
        View::json($contact, 201);
    }

    /**
     * PUT /api/v2/contacts/{id}
     */
    public function update(Request $request, int $id): void
    {
        $contact = Contact::find($id);
        if (!$contact) {
            View::json(['error' => 'Contato não encontrado'], 404);
            return;
        }

        Contact::update($id, array_filter([
            'name' => $request->post('name'),
            'email' => $request->post('email'),
            'phone' => $request->post('phone'),
            'company' => $request->post('company'),
            'document' => $request->post('document'),
            'notes' => $request->post('notes'),
        ]));

        View::json(Contact::find($id));
    }

    /**
     * DELETE /api/v2/contacts/{id}
     */
    public function destroy(Request $request, int $id): void
    {
        $contact = Contact::find($id);
        if (!$contact) {
            View::json(['error' => 'Contato não encontrado'], 404);
            return;
        }
        Contact::delete($id);
        View::json(['ok' => true]);
    }

    /**
     * POST /api/v2/contacts/{id}/merge
     */
    public function merge(Request $request, int $id): void
    {
        $targetId = (int) $request->post('target_id');
        if (!$targetId || $targetId === $id) {
            View::json(['error' => 'ID de destino inválido'], 400);
            return;
        }
        Contact::merge($id, $targetId);
        View::json(['ok' => true, 'target_id' => $targetId]);
    }
}
