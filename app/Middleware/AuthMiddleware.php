<?php

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;

class AuthMiddleware
{
    public function handle(Request $request, callable $next = null): bool
    {
        if (!isset($_SESSION['user_id'])) {
            if ($request->wantsJson()) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Usuário não autenticado']);
                exit;
            }
            \App\Core\Session::setFlash('error', 'Você precisa estar logado para acessar esta página.');
            header('Location: ' . base_url('/login'));
            exit;
        }
        // Revalida is_active a cada request: desativado perde acesso imediato.
        // Auth::user() retorna null + destroy se inativo/inexistente.
        if (Auth::user() === null) {
            if ($request->wantsJson()) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Usuário desativado']);
                exit;
            }
            \App\Core\Session::setFlash('error', 'Sua conta foi desativada.');
            header('Location: ' . base_url('/login'));
            exit;
        }
        return true;
    }
}

