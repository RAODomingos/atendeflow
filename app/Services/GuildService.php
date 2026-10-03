<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Cliente da API do painel Guild (lojas/unidades do cliente).
 * Loja = `network_name`; unidade = item de `stores[]`.
 */
class GuildException extends \RuntimeException
{
    public function __construct(public readonly string $kind, string $message)
    {
        parent::__construct($message);
    }

    public function getKind(): string
    {
        return $this->kind;
    }
}

class GuildService
{
    public function __construct(
        private ?string $baseUrl = null,
        private ?string $token = null,
        private int $timeout = 8
    ) {
        $this->baseUrl = rtrim($baseUrl ?? (string) Setting::get('guild_api_base', 'https://painel.guild.com.br'), '/');
        $this->token = $token ?? (string) Setting::get('guild_api_token', '');
    }

    /**
     * @return array{network_name:string, stores:array<int, array{id:int, name:string}>}
     */
    public function getStores(string $customerId): array
    {
        $customerId = trim($customerId);
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $customerId)) {
            throw new GuildException('invalid_customer_id', 'Código da loja inválido. Use apenas letras, números, hífen e sublinhado.');
        }
        if ($this->token === '') {
            throw new GuildException('missing_token', 'Token da Guild não configurado em Configurações.');
        }

        $url = $this->baseUrl . '/api/mac/customer/' . rawurlencode($customerId) . '/stores';
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $this->token];

        if (function_exists('curl_init')) {
            [$status, $raw] = $this->viaCurl($url, $headers);
        } else {
            [$status, $raw] = $this->viaStream($url, $headers);
        }

        if ($raw === '') {
            throw new GuildException('unreachable', 'Não foi possível alcançar o painel Guild. Tente novamente.');
        }
        if ($status === 401 || $status === 403) {
            throw new GuildException('unauthorized', 'Token da Guild rejeitado. Confira em Configurações.');
        }
        if ($status === 404) {
            throw new GuildException('not_found', 'Código não encontrado no painel Guild.');
        }
        if ($status < 200 || $status >= 300) {
            throw new GuildException('http_error', 'Painel Guild respondeu com erro (HTTP ' . $status . '). Tente novamente.');
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || ($data['success'] ?? false) !== true || !is_array($data['data'] ?? null)) {
            throw new GuildException('invalid_json', 'Resposta inesperada do painel Guild. Tente novamente.');
        }
        $payload = $data['data'];
        $network = trim((string) ($payload['network_name'] ?? ''));
        $stores = [];
        if (is_array($payload['stores'] ?? null)) {
            foreach ($payload['stores'] as $s) {
                if (!is_array($s) || !isset($s['id']) || !isset($s['name'])) continue;
                $stores[] = ['id' => (int) $s['id'], 'name' => trim((string) $s['name'])];
            }
        }
        if ($network === '' || $stores === []) {
            throw new GuildException('invalid_json', 'Resposta inesperada do painel Guild. Tente novamente.');
        }
        return ['network_name' => $network, 'stores' => $stores];
    }

    /** @return array{0:int, 1:string} */
    private function viaCurl(string $url, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $raw = (string) curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === '' && in_array($errno, [CURLE_OPERATION_TIMEDOUT, 28], true)) {
            throw new GuildException('timeout', 'Tempo esgotado ao consultar o painel Guild. Tente novamente.');
        }
        return [$status, $raw];
    }

    /** @return array{0:int, 1:string} */
    private function viaStream(string $url, array $headers): array
    {
        $ctx = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'timeout' => $this->timeout,
        ]]);
        $raw = (string) @file_get_contents($url, false, $ctx);
        $status = 0;
        if (isset($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $h, $m)) { $status = (int) $m[1]; break; }
            }
        }
        return [$status, $raw];
    }
}
