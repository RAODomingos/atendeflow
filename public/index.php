<?php
/*
 * Configurações do PHP para nível de erro mais alto
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

/*
 * Configurações do Composer Autoload
 */
$loader = require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/Core/Helper.php';

/*
 * Setar timezone
 */
date_default_timezone_set('America/Sao_Paulo');

/*
 * Iniciar sistemas de roteamento
 */
$router = new \App\Core\Router();
require __DIR__ . '/../routes/web.php';

// Iniciar sessão
\App\Core\Session::start();

// Compartilhar configurações do sistema com todas as views
\App\Core\View::share('config', \App\Config\AppConfig::getAll());

// Passar requisição autenticada
$request = \App\Core\Request::capture();

// Executar roteamento
$router->dispatch($request);