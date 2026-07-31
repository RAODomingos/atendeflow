<?php
/**
 * Worker para processar tarefas assíncronas do sistema de fluxos
 * - Processa delays agendados
 * - Processa retries de mensagens outbound
 * - Limpa estados antigos
 * 
 * Executar via cron: php workers/flow_worker.php
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Core/Auth.php';
require_once __DIR__ . '/../app/Models/Flow.php';
require_once __DIR__ . '/../app/Models/Conversation.php';
require_once __DIR__ . '/../app/Models/Contact.php';
require_once __DIR__ . '/../app/Services/FlowEngineService.php';

use App\Services\FlowEngineService;

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
