<?php
/*
 * Configurações do PHP — erros vão para o log, NUNCA para o output.
 * Com display_errors=1, warnings/notices do PHP são impressos no corpo da resposta,
 * corrompendo respostas JSON (ex: "Unexpected non-whitespace character after JSON").
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');          // ← NÃO exibir erros no output
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');              // ← Registrar erros no log do PHP/Apache

// Buffer global: captura qualquer output acidental (warnings vazados, BOM, espaços)
// antes de qualquer header ou resposta JSON ser enviada.
ob_start();

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