<?php

namespace App\Core;

class Router
{
    private array $routes = [];
    private array $middleware = [];
    private array $groupMiddleware = [];
    private string $prefix = '';

    public function get(string $path, array $handler, array $middleware = []): void
    {
        $this->addRoute('GET', $path, $handler, $middleware);
    }

    public function post(string $path, array $handler, array $middleware = []): void
    {
        $this->addRoute('POST', $path, $handler, $middleware);
    }

    public function put(string $path, array $handler, array $middleware = []): void
    {
        $this->addRoute('PUT', $path, $handler, $middleware);
    }

    public function delete(string $path, array $handler, array $middleware = []): void
    {
        $this->addRoute('DELETE', $path, $handler, $middleware);
    }

    public function addRoute(string $method, string $path, array $handler, array $middleware = []): void
    {
        $fullPath = $this->prefix . $path;
        $this->routes[] = [
            'method' => $method,
            'path' => $fullPath,
            'handler' => $handler,
            'middleware' => array_merge($this->groupMiddleware, $middleware),
        ];
    }

    public function group(string $prefix, callable $callback, array $middleware = []): void
    {
        $previousPrefix = $this->prefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->prefix .= $prefix;
        $this->groupMiddleware = array_merge($this->groupMiddleware, $middleware);

        $callback($this);

        $this->prefix = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;
    }

    public function dispatch(Request $request): void
    {
        $method = $request->method();
        $uri = $request->uri();

        $route = $this->findRoute($method, $uri);

        if ($route === null) {
            if ($request->wantsJson()) {
                $this->jsonResponse(['error' => 'Rota não encontrada'], 404);
            } else {
                $this->renderError(404);
            }
            return;
        }

        $next = function ($request) { return true; };
        foreach ($route['middleware'] as $mw) {
            $mwClass = $this->resolveMiddleware($mw);
            if ($mwClass && !$mwClass->handle($request, $next)) {
                return;
            }
        }

        [$controllerClass, $action] = $route['handler'];
        $controller = new $controllerClass();

        $params = $route['params'] ?? [];
        array_unshift($params, $request);

        try {
            $controller->$action(...$params);
        } catch (\Exception $e) {
            error_log('Router dispatch error: ' . $e->getMessage());
            if ($request->wantsJson()) {
                $msg = env('APP_DEBUG', false) ? $e->getMessage() : 'Erro interno do servidor.';
                $this->jsonResponse(['error' => $msg], 500);
            } else {
                if (env('APP_DEBUG', false)) {
                    throw $e;
                }
                $this->renderError(500);
            }
        }
    }

    private function findRoute(string $method, string $uri): ?array
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $pattern = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $route['path']);
            $pattern = '#^' . $pattern . '$#';

            if (preg_match($pattern, $uri, $matches)) {
                $route['params'] = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                return $route;
            }
        }

        return null;
    }

    private function resolveMiddleware(string $name): ?object
    {
        $map = [
            'auth' => \App\Middleware\AuthMiddleware::class,
            'admin' => \App\Middleware\AdminMiddleware::class,
            'manager' => \App\Middleware\ManagerMiddleware::class,
            'csrf' => \App\Middleware\CsrfMiddleware::class,
            'jwt' => \App\Middleware\JwtMiddleware::class,
        ];

        $class = $map[$name] ?? null;
        if ($class && class_exists($class)) {
            return new $class();
        }

        return null;
    }

    private function jsonResponse(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function renderError(int $code): void
    {
        http_response_code($code);
        $title = $code === 404 ? 'Página não encontrada' : 'Erro interno do servidor';
        $message = $code === 404 ? 'A página que você procura não existe.' : 'Ocorreu um erro ao processar sua solicitação.';
        require __DIR__ . '/../Views/errors/error.php';
        exit;
    }
}
