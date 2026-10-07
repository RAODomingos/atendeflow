<?php

// Helpers de segurança (regras de senha, etc.). Carregado pelo Helper.php.

if (!function_exists('password_strength_error')) {
    /**
     * Retorna mensagem de erro PT-BR se a senha for fraca, ou null se ok.
     * Regra: mín. 10 chars com letras e números.
     */
    function password_strength_error(?string $password): ?string
    {
        $password = (string) ($password ?? '');
        if (strlen($password) < 10) {
            return 'A senha deve ter no mínimo 10 caracteres.';
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            return 'A senha deve conter letras e números.';
        }
        return null;
    }
}
