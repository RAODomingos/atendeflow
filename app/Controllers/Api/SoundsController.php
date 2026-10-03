<?php

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Request;
use App\Core\View;
use App\Models\NotificationSound;

class SoundsController
{
    /**
     * GET /api/sounds — áudios personalizados do usuário logado.
     */
    public function index(Request $request): void
    {
        $userId = Auth::id();
        if (!$userId) {
            View::json(['error' => 'Não autenticado'], 401);
            return;
        }
        $sounds = array_map(
            [NotificationSound::class, 'toApi'],
            NotificationSound::forUser($userId)
        );
        View::json(['sounds' => $sounds]);
    }

    /**
     * POST /api/sounds/upload (multipart: audio + name?)
     * mp3/wav/ogg/m4a, até 1 MB, máx. 10 por usuário.
     */
    public function upload(Request $request): void
    {
        $userId = Auth::id();
        if (!$userId) {
            View::json(['error' => 'Não autenticado'], 401);
            return;
        }
        if (NotificationSound::countForUser($userId) >= NotificationSound::MAX_PER_USER) {
            View::json(['error' => 'Limite de ' . NotificationSound::MAX_PER_USER . ' áudios atingido. Exclua um para enviar outro.'], 422);
            return;
        }
        $file = $_FILES['audio'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            View::json(['error' => 'Selecione um arquivo de áudio.'], 422);
            return;
        }
        if (($file['size'] ?? 0) > NotificationSound::MAX_BYTES) {
            View::json(['error' => 'Áudio muito grande (máx. 1 MB). Use um toque curto.'], 422);
            return;
        }
        $saved = save_uploaded_file('audio', ['audio' => ['mp3', 'wav', 'ogg', 'm4a']], 'sounds/' . $userId);
        if (!$saved) {
            View::json(['error' => 'Formato inválido. Envie mp3, wav, ogg ou m4a.'], 422);
            return;
        }
        $rawName = trim((string) ($request->post('name') ?? $saved['name'] ?? 'Meu toque'));
        $name = preg_replace('/\.[a-z0-9]+$/i', '', $rawName) ?: 'Meu toque';
        $id = NotificationSound::create($userId, $name, $saved['path'], $saved['mime'] ?? null, (int) ($saved['size'] ?? 0));
        $row = NotificationSound::find($id);
        View::json(['ok' => true, 'sound' => NotificationSound::toApi($row)]);
    }

    /**
     * POST /api/sounds/{id}/delete — só o dono exclui.
     */
    public function destroy(Request $request, int $id): void
    {
        $userId = Auth::id();
        if (!$userId) {
            View::json(['error' => 'Não autenticado'], 401);
            return;
        }
        if (!NotificationSound::deleteOwned($id, $userId)) {
            View::json(['error' => 'Áudio não encontrado.'], 404);
            return;
        }
        View::json(['ok' => true]);
    }
}
