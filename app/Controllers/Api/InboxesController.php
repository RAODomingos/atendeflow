<?php

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Request;
use App\Core\View;
use App\Models\Inbox;
use App\Models\Department;
use App\Models\Channel;

class InboxesController
{
    /**
     * GET /api/v2/inboxes
     */
    public function index(Request $request): void
    {
        $userId = $request->input('userId');
        if ($userId) {
            $inboxes = Inbox::getUserInboxes((int) $userId);
        } else {
            $inboxes = Inbox::getUserInboxes(Auth::id());
        }

        // Enrich with channels and departments
        foreach ($inboxes as &$inbox) {
            $inbox['channels'] = Inbox::getChannels($inbox['id']);
            $inbox['departments'] = Inbox::getDepartments($inbox['id']);
            $inbox['users'] = Inbox::getUsers($inbox['id']);
        }

        View::json($inboxes);
    }

    /**
     * GET /api/v2/inboxes/{id}
     */
    public function show(Request $request, int $id): void
    {
        $inbox = Inbox::find($id);
        if (!$inbox) {
            View::json(['error' => 'Inbox não encontrada'], 404);
            return;
        }

        $inbox['channels'] = Inbox::getChannels($id);
        $inbox['departments'] = Inbox::getDepartments($id);
        $inbox['users'] = Inbox::getUsers($id);

        View::json($inbox);
    }

    /**
     * POST /api/v2/inboxes
     */
    public function store(Request $request): void
    {
        $name = trim((string) $request->post('name'));
        if (empty($name)) {
            View::json(['error' => 'Nome é obrigatório'], 400);
            return;
        }

        $id = Inbox::create([
            'name' => $name,
            'type' => $request->post('type', 'department'),
        ]);

        $channelIds = $request->post('channel_ids');
        if (is_array($channelIds)) {
            Inbox::setChannels($id, $channelIds);
        }
        $departmentIds = $request->post('department_ids');
        if (is_array($departmentIds)) {
            Inbox::setDepartments($id, $departmentIds);
        }
        $userIds = $request->post('user_ids');
        if (is_array($userIds)) {
            Inbox::setUsers($id, $userIds);
        }

        View::json(Inbox::find($id), 201);
    }

    /**
     * PUT /api/v2/inboxes/{id}
     */
    public function update(Request $request, int $id): void
    {
        $inbox = Inbox::find($id);
        if (!$inbox) {
            View::json(['error' => 'Inbox não encontrada'], 404);
            return;
        }

        Inbox::update($id, array_filter([
            'name' => $request->post('name'),
            'type' => $request->post('type'),
            'is_active' => $request->post('is_active'),
        ]));

        if ($request->post('channel_ids') !== null) {
            Inbox::setChannels($id, $request->post('channel_ids'));
        }
        if ($request->post('department_ids') !== null) {
            Inbox::setDepartments($id, $request->post('department_ids'));
        }
        if ($request->post('user_ids') !== null) {
            Inbox::setUsers($id, $request->post('user_ids'));
        }

        View::json(Inbox::find($id));
    }

    /**
     * DELETE /api/v2/inboxes/{id}
     */
    public function destroy(Request $request, int $id): void
    {
        $inbox = Inbox::find($id);
        if (!$inbox) {
            View::json(['error' => 'Inbox não encontrada'], 404);
            return;
        }
        Inbox::delete($id);
        View::json(['ok' => true]);
    }

    /**
     * POST /api/v2/inboxes/{id}/channels
     */
    public function setChannels(Request $request, int $id): void
    {
        $channelIds = $request->post('channelIds', []);
        Inbox::setChannels($id, $channelIds);
        View::json(['ok' => true]);
    }

    /**
     * POST /api/v2/inboxes/{id}/departments
     */
    public function setDepartments(Request $request, int $id): void
    {
        $departmentIds = $request->post('departmentIds', []);
        Inbox::setDepartments($id, $departmentIds);
        View::json(['ok' => true]);
    }

    /**
     * POST /api/v2/inboxes/{id}/users
     */
    public function setUsers(Request $request, int $id): void
    {
        $userIds = $request->post('userIds', []);
        Inbox::setUsers($id, $userIds);
        View::json(['ok' => true]);
    }
}
