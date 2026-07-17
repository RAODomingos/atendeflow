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

        if (Auth::attempt($email, $password)) {
            Session::setFlash('success', 'Login realizado com sucesso.');
            View::redirect('/');
        } else {
            Session::setFlash('error', 'E-mail ou senha inválidos.');
            Session::setFlash('old_email', $email);
            View::redirect('/login');
        }
    }

    public function logout(Request $request): void
    {
        Auth::logout();
        Session::setFlash('success', 'Sessão encerrada.');
        View::redirect('/login');
    }
}
