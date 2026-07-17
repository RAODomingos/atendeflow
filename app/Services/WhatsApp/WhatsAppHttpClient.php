<?php

namespace App\Services\WhatsApp;

/**
 * Cliente HTTP mínimo e dependência-zero para integração com provedores de WhatsApp.
 * Usa cURL quando disponível e recorre a file_get_contents como fallback.
 */
class WhatsAppHttpClient
{
    public function __construct(
        private string $baseUrl,
        private array $defaultHeaders = []
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function get(string $path, array $headers = []): array
    {
        return $this->request('GET', $path, null, $headers);
    }

    public function post(string $path, array|object|null $body = null, array $headers = []): array
    {
        return $this->request('POST', $path, $body, $headers);
    }

    public function put(string $path, array|object|null $body = null, array $headers = []): array
    {
        return $this->request('PUT', $path, $body, $headers);
    }

    public function delete(string $path, array|object|null $body = null, array $headers = []): array
    {
        return $this->request('DELETE', $path, $body, $headers);
    }

    /**
     * @return array{status:int, body:mixed, raw:string}
     */
    public function request(string $method, string $path, array|object|null $body = null, array $headers = []): array
    {
        $url = $this->baseUrl . $path;
        $allHeaders = array_merge($this->defaultHeaders, $headers);

        $json = $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (function_exists('curl_init')) {
            return $this->requestCurl($method, $url, $json, $allHeaders);
        }

        return $this->requestStream($method, $url, $json, $allHeaders);
    }

    private function requestCurl(string $method, string $url, ?string $json, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->formatHeaders($headers));

        if ($json !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }

        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === '' && $error !== '') {
            throw new \RuntimeException("Erro de requisição HTTP ({$method} {$url}): {$error}");
        }

        return [
            'status' => $status ?: 200,
            'raw' => $raw,
            'body' => $this->decode($raw),
        ];
    }

    private function requestStream(string $method, string $url, ?string $json, array $headers): array
    {
        $opts = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $this->formatHeaders($headers)),
                'ignore_errors' => true,
                'timeout' => 30,
            ],
        ];
        if ($json !== null) {
            $opts['http']['content'] = $json;
        }

        $ctx = stream_context_create($opts);
        $raw = (string) @file_get_contents($url, false, $ctx);
        $status = 200;
        if (isset($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $h, $m)) {
                    $status = (int) $m[1];
                    break;
                }
            }
        }

        return [
            'status' => $status,
            'raw' => $raw,
            'body' => $this->decode($raw),
        ];
    }

    private function formatHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $out[] = is_int($k) ? $v : "{$k}: {$v}";
        }
        return $out;
    }

    private function decode(string $raw): mixed
    {
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return $decoded === null && json_last_error() !== JSON_ERROR_NONE ? $raw : $decoded;
    }
}
