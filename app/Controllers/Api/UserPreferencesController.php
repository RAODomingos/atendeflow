<?php

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Request;
use App\Core\View;
use App\Models\UserPreference;

class UserPreferencesController
{
    private const ALLOWED_KEYS = [
        'sound_enabled',
        'sound_new_message',
        'sound_new_conversation',
        'browser_notif_enabled',
    ];

    public function index(Request $request): void
    {
        $userId = Auth::id();
        if (!$userId) {
            View::json(['error' => 'Não autenticado'], 401);
            return;
        }

        $prefs = UserPreference::getAll($userId);

        $defaults = [
            'sound_enabled' => true,
            'sound_new_message' => 'default',
            'sound_new_conversation' => 'default',
            'browser_notif_enabled' => true,
        ];

        foreach ($defaults as $key => $val) {
            if (!array_key_exists($key, $prefs)) {
                $prefs[$key] = $val;
            }
        }

        View::json($prefs);
    }

    /**
     * POST /api/user-preferences
     * Atualiza uma ou mais preferências em tempo real.
     * Aceita JSON ou form-data. Body:
     *   { "key": "value", ... }
     */
    public function update(Request $request): void
    {
        $userId = Auth::id();
        if (!$userId) {
            View::json(['error' => 'Não autenticado'], 401);
            return;
        }

        $body = $request->isPost() ? $request->post() : [];
        if (empty($body)) {
            $raw = file_get_contents('php://input');
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        $updated = [];
        foreach (self::ALLOWED_KEYS as $key) {
            if (!array_key_exists($key, $body)) {
                continue;
            }
            $value = $body[$key];
            if (in_array($key, ['sound_enabled', 'browser_notif_enabled'], true)) {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } else {
                $allowed = ['default', 'soft', 'sharp', 'silent'];
                $value = in_array($value, $allowed, true) ? $value : 'default';
            }
            UserPreference::set($userId, $key, $value);
            $updated[$key] = $value;
        }

        View::json([
            'ok' => true,
            'updated' => $updated,
            'prefs' => UserPreference::getAll($userId),
        ]);
    }
}
