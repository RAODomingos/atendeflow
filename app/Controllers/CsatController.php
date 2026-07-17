<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\View;
use App\Models\Conversation;
use App\Models\Contact;

/**
 * Página pública de avaliação CSAT (usada pelo link enviado em canais como
 * WhatsApp/e-mail, e como fallback do widget do ChatWeb).
 */
class CsatController
{
    public function show(Request $request, string $token): void
    {
        $conversation = Conversation::findByPublicId($token);
        if (!$conversation) {
            http_response_code(404);
            echo '<h1>Avaliação não encontrada</h1>';
            return;
        }

        $contact = Contact::find((int) $conversation['contact_id']);
        $existing = Conversation::getCsat($conversation['id']);

        View::render('csat/public', [
            'conversation' => $conversation,
            'contact' => $contact,
            'token' => $token,
            'existing' => $existing,
        ]);
    }

    public function submit(Request $request, string $token): void
    {
        $conversation = Conversation::findByPublicId($token);
        if (!$conversation) {
            http_response_code(404);
            echo '<h1>Avaliação não encontrada</h1>';
            return;
        }

        $rating = (int) $request->post('rating');
        $comment = trim((string) $request->post('comment'));

        if ($rating >= 1 && $rating <= 5) {
            Conversation::addCsat($conversation['id'], $rating, $comment);
        }

        $contact = Contact::find((int) $conversation['contact_id']);

        View::render('csat/public', [
            'conversation' => $conversation,
            'contact' => $contact,
            'token' => $token,
            'existing' => Conversation::getCsat($conversation['id']),
            'thank' => true,
        ]);
    }
}
