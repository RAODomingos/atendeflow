<?php

namespace App\Core;

class Request
{
    private array $query;
    private array $body;
    private array $files;
    private array $server;

    public function __construct()
    {
        $this->query = $_GET;
        $this->body = $_POST;
        $this->files = $_FILES;
        $this->server = $_SERVER;
    }

    public static function capture(): self
    {
        $instance = new self();

        if ($instance->isJson()) {
            $json = json_decode(file_get_contents('php://input'), true) ?? [];
            $instance->body = array_merge($instance->body, $json);
        }

        return $instance;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function post(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->body;
        }

        return $this->body[$key] ?? $default;
    }

    public function input(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return array_merge($this->query, $this->body);
        }

        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function method(): string
    {
        return strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    public function uri(): string
    {
        $uri = parse_url($this->server['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        $scriptName = $this->server['SCRIPT_NAME'] ?? '';
        $dir = str_replace('\\', '/', dirname($scriptName));
        $parent = str_replace('\\', '/', dirname($dir));

        // Candidatos a prefixo de instalação, do mais específico ao genérico:
        // - /atendeflow/index.php (nginx subpasta) -> '/atendeflow'
        // - /foo/public/index.php (Apache c/ rewrite) -> '/foo/public', '/foo'
        // - /index.php (raiz) -> nenhum (dir '/')
        $candidates = [];
        if ($dir !== '/' && $dir !== '.' && $dir !== '') {
            $candidates[] = $dir;
        }
        if (str_ends_with($dir, '/public') && $parent !== '/' && $parent !== '.' && $parent !== '') {
            $candidates[] = $parent;
        } elseif ($parent !== '/' && $parent !== '.' && $parent !== '' && $parent !== $dir) {
            $candidates[] = $parent;
        }

        foreach ($candidates as $basePath) {
            if (str_starts_with($uri, $basePath . '/') || $uri === $basePath) {
                $uri = substr($uri, strlen($basePath));
                break;
            }
        }

        return rtrim($uri, '/') ?: '/';
    }

    public function isMethod(string $method): bool
    {
        return $this->method() === strtoupper($method);
    }

    public function isGet(): bool
    {
        return $this->method() === 'GET';
    }

    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    public function isAjax(): bool
    {
        return ($this->server['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    }

    public function isJson(): bool
    {
        $contentType = $this->server['CONTENT_TYPE'] ?? $this->server['HTTP_CONTENT_TYPE'] ?? '';
        return str_contains($contentType, 'application/json');
    }

    public function wantsJson(): bool
    {
        return $this->isAjax() || $this->isJson() || str_contains($this->server['HTTP_ACCEPT'] ?? '', 'application/json');
    }

    public function ip(): string
    {
        // REMOTE_ADDR apenas (X-Forwarded-For é forjável). Usado só p/
        // auditoria/views — nunca como chave de segurança.
        $ip = $this->server['REMOTE_ADDR'] ?? '127.0.0.1';
        return trim(explode(',', (string) $ip)[0]);
    }

    public function userAgent(): string
    {
        return $this->server['HTTP_USER_AGENT'] ?? '';
    }

    public function validate(array $rules): array
    {
        $errors = [];
        $data = $this->all();

        foreach ($rules as $field => $ruleSet) {
            $ruleList = is_array($ruleSet) ? $ruleSet : explode('|', $ruleSet);
            $value = $data[$field] ?? null;

            foreach ($ruleList as $rule) {
                if ($rule === 'required' && ($value === null || $value === '')) {
                    $errors[$field][] = "O campo {$field} é obrigatório.";
                }
                if ($rule === 'email' && $value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$field][] = "O campo {$field} deve ser um e-mail válido.";
                }
                if (str_starts_with($rule, 'min:') && strlen($value) < (int) substr($rule, 4)) {
                    $errors[$field][] = "O campo {$field} deve ter no mínimo " . substr($rule, 4) . " caracteres.";
                }
                if (str_starts_with($rule, 'max:') && strlen($value) > (int) substr($rule, 4)) {
                    $errors[$field][] = "O campo {$field} deve ter no máximo " . substr($rule, 4) . " caracteres.";
                }
            }
        }

        return $errors;
    }
}
