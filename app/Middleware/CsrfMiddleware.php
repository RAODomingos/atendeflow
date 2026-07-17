<?php

namespace App\Middleware;

use App\Core\Request;
use App\Core\Session;

class CsrfMiddleware
{
    public function handle(Request $request, callable $next = null): bool
    {
        if ($request->isPost()) {
            $token = $request->input('_csrf_token');
            $sessionToken = Session::get('csrf_token');

            if (empty($token) || $token !== $sessionToken) {
                http_response_code(422);
                header('Content-Type: application/json');
                echo json_encode(['message' => 'Token CSRF inválido'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        return true;
    }

    public function generateToken(): string
    {
        $token = bin2hex(random_bytes(32));
        Session::set('csrf_token', $token);
        return $token;
    }
}
