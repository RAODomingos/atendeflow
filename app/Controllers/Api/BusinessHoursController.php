<?php

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Request;
use App\Core\View;
use App\Models\BusinessHours;
use App\Models\Inbox;

class BusinessHoursController
{
    /**
     * GET /api/v2/inboxes/{id}/business-hours
     */
    public function index(Request $request, int $id): void
    {
        View::json(BusinessHours::getByInbox($id));
    }

    /**
     * PUT /api/v2/inboxes/{id}/business-hours
     */
    public function save(Request $request, int $id): void
    {
        $inbox = Inbox::find($id);
        if (!$inbox) {
            View::json(['error' => 'Inbox não encontrada'], 404);
            return;
        }

        $hours = $request->post('hours', []);
        if (!is_array($hours)) {
            View::json(['error' => 'Formato inválido'], 400);
            return;
        }

        BusinessHours::saveBatch($id, $hours);

        // Save timezone if provided
        $timezone = $request->post('timezone');
        if ($timezone) {
            Inbox::update($id, ['timezone' => $timezone]);
        }

        View::json(['ok' => true]);
    }

    /**
     * GET /api/v2/inboxes/{id}/is-open
     */
    public function isOpen(Request $request, int $id): void
    {
        View::json([
            'is_open' => BusinessHours::isWithinBusinessHours($id),
            'timezone' => Inbox::find($id)['timezone'] ?? 'America/Sao_Paulo',
        ]);
    }

    /**
     * PUT /api/v2/inboxes/{id}/away-message
     */
    public function saveAwayMessage(Request $request, int $id): void
    {
        $inbox = Inbox::find($id);
        if (!$inbox) {
            View::json(['error' => 'Inbox não encontrada'], 404);
            return;
        }

        $enabled = (bool) $request->post('enabled', false);
        $message = $request->post('message');

        Inbox::update($id, [
            'away_message_enabled' => $enabled ? 1 : 0,
            'away_message' => $message,
        ]);

        View::json(['ok' => true]);
    }
}
