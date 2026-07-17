<?php

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;

class AdminMiddleware
{
    public function handle(Request $request, callable $next = null): bool
    {
        if (!Auth::isAdmin()) {
            if ($request->wantsJson()) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Acesso restrito a administradores']);
                exit;
            }
            \App\Core\Session::setFlash('error', 'Acesso restrito a administradores.');
            header('Location: ' . base_url('/'));
            exit;
        }
        return true;
    }
}
