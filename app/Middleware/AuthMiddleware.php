<?php

namespace App\Middleware;

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
        return true;
    }
}

