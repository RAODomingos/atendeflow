<?php

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Request;
use App\Core\View;
use App\Services\SlaService;

class SlaController
{
    /**
     * GET /api/sla/config[?inbox_id=X]
     * Retorna a configuração de SLA + thresholds computados. Usado pelo
     * frontend para pintar os conv-itens e tocar os sons apropriados.
     *
     * Quando `inbox_id` é informado, devolve a config efetiva da caixa
     * (override ou global). Caso contrário, devolve apenas a global.
     */
    public function config(Request $request): void
    {
        if (!Auth::id()) {
            View::json(['error' => 'Não autenticado'], 401);
            return;
        }

        $inboxIdRaw = $request->input('inbox_id');
        $inboxId = ($inboxIdRaw === null || $inboxIdRaw === '') ? null : (int) $inboxIdRaw;
        $payload = SlaService::configFor($inboxId);
        $cfg = $payload['config'];
        $thresholds = SlaService::thresholds($inboxId);

        View::json([
            'inbox_id' => $inboxId,
            'is_custom' => $payload['is_custom'],
            'enabled' => $cfg['enabled'] === '1',
            'attention_minutes' => (int) $cfg['attention_minutes'],
            'alert_minutes' => (int) $cfg['alert_minutes'],
            'attention_seconds' => $thresholds['attention_seconds'],
            'alert_seconds' => $thresholds['alert_seconds'],
            'colors' => [
                'normal' => $cfg['color_normal'],
                'attention' => $cfg['color_attention'],
                'alert' => $cfg['color_alert'],
                'normal_text' => $cfg['color_normal_text'],
                'attention_text' => $cfg['color_attention_text'],
                'alert_text' => $cfg['color_alert_text'],
            ],
            'sounds' => [
                'attention' => $cfg['sound_attention'],
                'alert' => $cfg['sound_alert'],
            ],
        ]);
    }
}
