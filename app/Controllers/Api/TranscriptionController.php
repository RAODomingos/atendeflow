<?php

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\View;
use App\Services\TranscriptionService;

class TranscriptionController
{
    /**
     * POST /api/messages/{id}/transcribe
     * Transcreve áudio sob demanda (botão no inbox). Resposta vem do cache
     * em messages.transcription quando já transcrita (sem custo de API).
     */
    public function transcribe(Request $request, int $id): void
    {
        $userId = Auth::id();
        if (!$userId) {
            View::json(['error' => 'Não autenticado'], 401);
            return;
        }

        $row = Database::getInstance()->fetch(
            "SELECT m.id, m.conversation_id, c.assigned_user_id, c.inbox_id FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE m.id = ?",
            [$id]
        );
        if (!$row) {
            View::json(['ok' => false, 'error' => 'Mensagem não encontrada'], 404);
            return;
        }
        // Ownership: mesmo critério de MessagesController::markRead.
        $canSee = Auth::isAdmin()
            || ((int) ($row['assigned_user_id'] ?? 0) === (int) $userId)
            || (!empty($row['inbox_id']) && \App\Models\Inbox::canAccess((int) $row['inbox_id'], (int) $userId));
        if (!$canSee) {
            View::json(['ok' => false, 'error' => 'Sem acesso'], 403);
            return;
        }

        if (RateLimiter::hit('transcribe:' . $userId, 10, 60, 60) > 0) {
            View::json(['ok' => false, 'error' => 'Muitas tentativas. Aguarde.'], 429);
            return;
        }

        try {
            $result = TranscriptionService::transcribeMessage($id, (int) $userId);
            View::json(['ok' => true, 'text' => $result['text'], 'cached' => $result['cached']]);
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            $status = match ($msg) {
                'Transcrição desabilitada.' => 501,
                'Mensagem não encontrada.' => 404,
                'Mensagem não é áudio.',
                'Arquivo de áudio não encontrado.',
                'Arquivo de áudio inválido.' => 422,
                default => str_starts_with($msg, 'Áudio muito longo') ? 422 : 502,
            };
            View::json(['ok' => false, 'error' => $msg], $status);
        }
    }
}
