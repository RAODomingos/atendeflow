<?php

namespace App\Middleware;

use App\Core\Request;

class JwtMiddleware
{
    /**
     * Valida JWT token do header Authorization.
     * Retorna true se autenticado, false caso contrário.
     */
    public function handle(Request $request, callable $next = null): bool
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? $_SERVER['Authorization']
            ?? '';
        if (preg_match('/Bearer\s+(.+)$/i', $header, $matches)) {
            $token = $matches[1];
        } else {
            $this->unauthorized();
            return false;
        }

        $payload = $this->decodeJWT($token);
        if (!$payload) {
            $this->unauthorized();
            return false;
        }

        // Define o usuário na request para uso nos controllers
        $request->user = $payload;
        $_SESSION['api_user_id'] = (int) $payload['sub'];
        $_SESSION['api_user_role'] = $payload['role'] ?? 'agent';

        return true;
    }

    private function unauthorized(): void
    {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Não autorizado']);
        exit;
    }

    private function decodeJWT(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;

        [$header, $payload, $signature] = $parts;
        $secret = env('JWT_SECRET', 'atendeflow-jwt-bridge-secret');

        $expected = $this->base64UrlEncode(
            hash_hmac('sha256', "$header.$payload", $secret, true)
        );

        if (!hash_equals($expected, $signature)) return null;

        $data = json_decode($this->base64UrlDecode($payload), true);
        if (!$data || !isset($data['exp']) || $data['exp'] < time()) return null;

        return $data;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
