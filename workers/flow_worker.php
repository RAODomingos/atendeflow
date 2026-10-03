<?php
/**
 * Worker para processar tarefas assíncronas do sistema de fluxos
 * - Processa delays agendados
 * - Processa retries de mensagens outbound
 * - Limpa estados antigos
 * 
 * Executar via cron: php workers/flow_worker.php
 */

require_once __DIR__ . '/../app/Core/Autoloader.php';
require_once __DIR__ . '/../app/Core/Helper.php';

use App\Core\Database;
use App\Services\FlowEngineService;

$appConfig = require __DIR__ . '/../config/app.php';
date_default_timezone_set($appConfig['timezone'] ?? 'America/Sao_Paulo');

Database::connect();

echo "[" . date('Y-m-d H:i:s') . "] Iniciando worker de fluxos...\n";

try {
    $flowEngine = new FlowEngineService();
    
    // Processar delays pendentes
    echo "[" . date('Y-m-d H:i:s') . "] Processando delays pendentes...\n";
    $flowEngine->processPendingDelays();
    echo "[" . date('Y-m-d H:i:s') . "] Delays processados.\n";
    
    // Processar retries de mensagens
    echo "[" . date('Y-m-d H:i:s') . "] Processando retries de mensagens...\n";
    $flowEngine->processPendingRetries();
    echo "[" . date('Y-m-d H:i:s') . "] Retries processados.\n";
    
    // Limpar estados antigos (executar diariamente)
    $dayOfWeek = (int) date('w');
    if ($dayOfWeek === 1) { // Segunda-feira
        echo "[" . date('Y-m-d H:i:s') . "] Executando limpeza de estados antigos...\n";
        $flowEngine->cleanupOldStates();
        echo "[" . date('Y-m-d H:i:s') . "] Limpeza concluída.\n";
    }
    
    echo "[" . date('Y-m-d H:i:s') . "] Worker concluído com sucesso.\n";
    
} catch (\Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERRO: " . $e->getMessage() . "\n";
    echo "[" . date('Y-m-d H:i:s') . "] Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}
