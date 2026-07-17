<?php

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\View;
use App\Models\User;

class AuthController
{
    /**
     * POST /api/v2/auth/login
     * Retorna JWT token para uso no Next.js
     */
    public function login(Request $request): void
    {
        $email = $request->post('email');
        $password = $request->post('password');

        if (empty($email) || empty($password)) {
            View::json(['error' => 'Informe e-mail e senha'], 400);
            return;
        }

        $user = User::findByEmail($email);
        if (!$user || !password_verify($password, $user['password'])) {
            View::json(['error' => 'Credenciais inválidas'], 401);
            return;
        }

        if (!$user['is_active']) {
            View::json(['error' => 'Usuário inativo'], 401);
            return;
        }

        User::update($user['id'], ['last_login_at' => date('Y-m-d H:i:s')]);

        $payload = [
            'sub' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'name' => $user['name'],
            'iat' => time(),
            'exp' => time() + 86400, // 24h
        ];

        $token = $this->encodeJWT($payload);
        $refreshToken = $this->encodeJWT(array_merge($payload, ['exp' => time() + 604800])); // 7d

        View::json([
            'accessToken' => $token,
            'refreshToken' => $refreshToken,
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
            ],
        ]);
    }

    /**
     * GET /api/v2/auth/me
     * Retorna dados do usuário autenticado via JWT
     */
    public function me(Request $request): void
    {
        $userData = $this->authenticate($request);
        if (!$userData) {
            View::json(['error' => 'Não autorizado'], 401);
            return;
        }

        $user = User::find((int) $userData['sub']);
        if (!$user) {
            View::json(['error' => 'Usuário não encontrado'], 404);
            return;
        }

        unset($user['password']);
        View::json($user);
    }

    /**
     * Extrai e valida o token JWT do header Authorization
     */
    private function authenticate(Request $request): ?array
    {
        $header = $request->input('token') ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.+)$/i', $header, $matches)) {
            $token = $matches[1];
        } else {
            return null;
        }

        return $this->decodeJWT($token);
    }

    /**
     * JWT encode simples (HMAC-SHA256)
     */
    private function encodeJWT(array $payload): string
    {
        $secret = $this->getSecret();

        $header = self::base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payloadEncoded = self::base64UrlEncode(json_encode($payload));
        $signature = self::base64UrlEncode(
            hash_hmac('sha256', "$header.$payloadEncoded", $secret, true)
        );

        return "$header.$payloadEncoded.$signature";
    }

    /**
     * JWT decode simples
     */
    private function decodeJWT(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;

        [$header, $payload, $signature] = $parts;
        $secret = $this->getSecret();

        $expected = self::base64UrlEncode(
            hash_hmac('sha256', "$header.$payload", $secret, true)
        );

        if (!hash_equals($expected, $signature)) return null;

        $data = json_decode(self::base64UrlDecode($payload), true);
        if (!$data || !isset($data['exp']) || $data['exp'] < time()) return null;

        return $data;
    }

    private function getSecret(): string
    {
        return env('JWT_SECRET', 'atendeflow-jwt-bridge-secret');
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
