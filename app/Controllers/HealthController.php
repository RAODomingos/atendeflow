<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\ErrorMetrics;
use App\Core\Request;
use App\Core\View;

/**
 * Health check do sistema.
 *
 *  - GET /health       (publico): so "ok" ou "erro" — nao expoe nada.
 *  - GET /api/health   (admin): detalhe (banco, disco, workers, erros).
 */
class HealthController
{
    /** Caminho do log do worker de SLA (para saber se ele roda). */
    private const SLA_WORKER_LOG = '/storage/logs/sla_worker.log';

    /**
     * Verificacao publica (monitor externo).
     */
    public function check(Request $request): void
    {
        $ok = $this->databaseOk();

        View::json([
            'status' => $ok ? 'ok' : 'erro',
            'time' => date('c'),
        ], $ok ? 200 : 503);
    }

    /**
     * Detalhe (admin).
     */
    public function details(Request $request): void
    {
        $root = dirname(__DIR__, 2);

        $checks = [
            'database' => $this->databaseOk(),
            'uploads_writable' => $this->dirWritable($root . '/public/uploads'),
            'cache_writable' => $this->dirWritable($root . '/storage/cache'),
            'logs_writable' => $this->dirWritable($root . '/storage/logs'),
        ];

        $workers = [
            'sla' => $this->workerStatus('sla_worker', self::SLA_WORKER_LOG),
            'flow' => $this->workerStatus('flow_worker', '/storage/logs/flow_worker.log'),
        ];

        $diskFree = @disk_free_space($root);
        $diskTotal = @disk_total_space($root);

        $payload = [
            'status' => !in_array(false, $checks, true) ? 'ok' : 'erro',
            'time' => date('c'),
            'checks' => $checks,
            'workers' => $workers,
            'errors' => ErrorMetrics::summary(),
            'disk' => [
                'free_bytes' => $diskFree === false ? null : (int) $diskFree,
                'total_bytes' => $diskTotal === false ? null : (int) $diskTotal,
                'free_percent' => ($diskFree !== false && $diskTotal) ? round($diskFree / $diskTotal * 100, 1) : null,
            ],
            'php' => PHP_VERSION,
            'app_timezone' => date_default_timezone_get(),
        ];

        View::json($payload, $payload['status'] === 'ok' ? 200 : 503);
    }

    private function databaseOk(): bool
    {
        try {
            $row = Database::getInstance()->fetch("SELECT 1 AS ok");
            return (int) ($row['ok'] ?? 0) === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function dirWritable(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }
        return is_writable($dir);
    }

    /**
     * Estado do worker: rodando agora (lock) e ultima execucao (log).
     */
    private function workerStatus(string $name, string $logRelative): array
    {
        $root = dirname(__DIR__, 2);
        $lockFile = $root . '/storage/cache/' . $name . '.lock';
        $running = false;
        if (is_file($lockFile)) {
            $fp = @fopen($lockFile, 'r');
            if ($fp) {
                // Se o lock pode ser adquirido, ninguem esta com ele.
                $acquired = flock($fp, LOCK_EX | LOCK_NB);
                $running = !$acquired;
                if ($acquired) {
                    // Liberamos o nosso (que acabamos de tomar) para nao
                    // prender o worker que vier rodar em seguida.
                    flock($fp, LOCK_UN);
                }
                fclose($fp);
            }
        }

        $lastRun = null;
        $logFile = $root . $logRelative;
        if (is_file($logFile)) {
            $mtime = @filemtime($logFile);
            if ($mtime) {
                $lastRun = date('Y-m-d H:i:s', $mtime);
            }
        }

        return [
            'running' => $running,
            'last_log_at' => $lastRun,
            'log_exists' => is_file($logFile),
        ];
    }
}
