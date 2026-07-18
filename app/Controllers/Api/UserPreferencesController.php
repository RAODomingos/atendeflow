<?php

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Request;
use App\Core\View;
use App\Models\UserPreference;

class UserPreferencesController
{
    public function index(Request $request): void
    {
        $userId = Auth::id();
        if (!$userId) {
            View::json(['error' => 'Não autenticado'], 401);
            return;
        }

        $prefs = UserPreference::getAll($userId);

        // Ensure defaults for sound preferences
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
}
