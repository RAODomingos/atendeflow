<?php

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Request;
use App\Core\View;
use App\Models\WhatsAppConnection;

/**
 * GET /api/whatsapp-status — resumo das conexões WhatsApp fora do ar
 * para a barra de alerta vermelha do topo (polling leve, 60s).
 */
class WhatsappStatusController
{
    public function index(Request $request): void
    {
        if (!Auth::id()) {
            View::json(['error' => 'Não autenticado'], 401);
            return;
        }
        $down = WhatsAppConnection::disconnected();
        View::json([
            'disconnected' => array_map(fn($c) => [
                'id' => (int) $c['id'],
                'channel' => $c['channel_name'] ?? ('#' . $c['id']),
                'status' => $c['status'] ?? 'disconnected',
            ], $down),
            'count' => count($down),
            'can_manage' => Auth::isAdmin(),
        ]);
    }
}
