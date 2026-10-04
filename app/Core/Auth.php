<?php

namespace App\Core;

use App\Models\User;

class Auth
{
    public static function attempt(string $email, string $password): bool
    {
        $user = User::findByEmail($email);
        if (!$user || !password_verify($password, $user['password'])) {
            return false;
        }

        if (!$user['is_active']) {
            return false;
        }

        Session::set('user_id', $user['id']);
        Session::set('user_name', $user['name']);
        Session::set('user_email', $user['email']);
        Session::set('user_role', $user['role']);
        Session::regenerate();

        User::update($user['id'], ['last_login_at' => date('Y-m-d H:i:s')]);

        return true;
    }

    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }
        $userId = Session::get('user_id');
        $user = User::find($userId);
        if (!$user || !$user['is_active']) {
            self::logout();
            return null;
        }
        return $user;
    }

    public static function id(): ?int
    {
        $sessionId = Session::get('user_id');
        if ($sessionId) return (int) $sessionId;

        // JWT bridge
        if (isset($_SESSION['api_user_id'])) {
            return (int) $_SESSION['api_user_id'];
        }

        return null;
    }

    public static function check(): bool
    {
        return Session::has('user_id') || isset($_SESSION['api_user_id']);
    }

    public static function role(): ?string
    {
        $role = Session::get('user_role');
        if ($role) return $role;

        if (isset($_SESSION['api_user_role'])) {
            return $_SESSION['api_user_role'];
        }

        return null;
    }

    public static function isAdmin(): bool
    {
        // Revalida no banco (não confia no role em cache na sessão:
        // demote/desativação revogam na próxima requisição).
        if (Session::has('user_id')) {
            $u = self::user();
            return $u !== null && ($u['role'] ?? null) === 'admin';
        }
        return self::role() === 'admin';
    }

    public static function isManager(): bool
    {
        if (Session::has('user_id')) {
            $u = self::user();
            return $u !== null && in_array($u['role'] ?? null, ['admin', 'manager'], true);
        }
        return in_array(self::role(), ['admin', 'manager']);
    }

    public static function can(string $permission): bool
    {
        $role = self::role();
        $permissions = [
            'admin' => ['*'],
            'manager' => ['view_all', 'assign', 'transfer', 'reports', 'manage_department'],
            'agent' => ['view_assigned', 'reply', 'close'],
            'viewer' => ['view_assigned'],
        ];

        $rolePerms = $permissions[$role] ?? [];
        return in_array('*', $rolePerms) || in_array($permission, $rolePerms);
    }

    public static function logout(): void
    {
        Session::destroy();
    }
}
