<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;

class AuthController
{
    public function showLogin(Request $request): void
    {
        if (Auth::check()) {
            View::redirect('/');
        }
        View::render('auth/login');
    }

    public function login(Request $request): void
    {
        $email = $request->post('email');
        $password = $request->post('password');

        if (empty($email) || empty($password)) {
            Session::setFlash('error', 'Informe e-mail e senha.');
            Session::setFlash('old_email', $email);
            View::redirect('/login');
            return;
        }

        // Rate limit: 5 tentativas / 10 min por IP, bloqueio de 15 min.
        $rlKey = \App\Core\RateLimiter::clientKey('login');
        if (($blocked = \App\Core\RateLimiter::blocked($rlKey)) > 0) {
            Session::setFlash('error', 'Muitas tentativas. Tente novamente em ' . ceil($blocked / 60) . ' min.');
            Session::setFlash('old_email', $email);
            View::redirect('/login');
            return;
        }

        if (Auth::attempt($email, $password)) {
            \App\Core\RateLimiter::clear($rlKey);
            self::audit('login_success', $email);
            Session::setFlash('success', 'Login realizado com sucesso.');
            View::redirect('/');
        } else {
            $wait = \App\Core\RateLimiter::hit($rlKey);
            self::audit('login_failed', $email);
            Session::setFlash(
                'error',
                $wait > 0
                    ? 'Muitas tentativas. Tente novamente em ' . ceil($wait / 60) . ' min.'
                    : 'E-mail ou senha inválidos.'
            );
            Session::setFlash('old_email', $email);
            View::redirect('/login');
        }
    }

    public function logout(Request $request): void
    {
        Auth::logout();
        // destroy() invalida a sessão anterior: reabre uma limpa para o flash sobreviver ao redirect.
        \App\Core\Session::start();
        Session::setFlash('success', 'Sessão encerrada.');
        View::redirect('/login');
    }

    private static function audit(string $action, string $email): void
    {
        try {
            \App\Core\Database::getInstance()->insert('audit_logs', [
                'user_id' => null,
                'action' => $action,
                'entity_type' => 'user',
                'entity_id' => null,
                'old_values' => null,
                'new_values' => json_encode(['email' => $email], JSON_UNESCAPED_UNICODE),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            ]);
        } catch (\Throwable $e) {
            // Tabela ausente em installs antigos: não quebra o login.
        }
    }
}
