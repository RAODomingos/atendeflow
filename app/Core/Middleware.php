<?php

namespace App\Core;

use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\AdminMiddleware;
use App\Middleware\JwtMiddleware;

class Middleware
{
    public static function handleMiddleware(string $middleware, $request, callable $next)
    {
        $middlewareClass = "App\\Middleware\\" . $middleware;
        
        if (class_exists($middlewareClass)) {
            $instance = new $middlewareClass();
            return $instance->handle($request, $next);
        }
        
        return $next($request);
    }
    
    public static function run(string $middleware, $request, callable $next, array $allowed_roles = [])
    {
        // Processar diferentes tipos de middleware
        if ($middleware === 'auth') {
            $auth = new AuthMiddleware();
            return $auth->handle($request, $next);
        }
        
        if ($middleware === 'csrf') {
            $csrf = new CsrfMiddleware();
            return $csrf->handle($request, $next);
        }
        
        if ($middleware === 'admin') {
            $admin = new AdminMiddleware();
            return $admin->handle($request, $next);
        }
        
        if ($middleware === 'jwt') {
            $jwt = new JwtMiddleware();
            return $jwt->handle($request, $next);
        }
        
        return $next($request);
    }
}
