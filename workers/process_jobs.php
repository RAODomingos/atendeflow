<?php
/**
 * Job processor worker
 * Run: php workers/process_jobs.php
 *
 * Fila com reserva: o worker marca o job como reservado antes de
 * processar. A reserva tem prazo — se o worker morre no meio do
 * processamento, o job volta para a fila depois de
 * $staleReservationSeconds (sem isso ele ficava reservado para sempre
 * e nunca mais era processado).
 *
 * Job que esgota as tentativas NAO é apagado: fica com failed_at e
 * last_error para inspeção (dead-letter), em vez de sumir da fila sem
 * deixar rastro.
 */

require_once __DIR__ . '/../app/Core/Autoloader.php';
require_once __DIR__ . '/../app/Core/Helper.php';

use App\Core\Database;

$appConfig = require __DIR__ . '/../config/app.php';
date_default_timezone_set($appConfig['timezone'] ?? 'America/Sao_Paulo');

Database::connect();

/** Prazo da reserva: depois disso o job volta para a fila. */
$staleReservationSeconds = 300;

/**
 * Modo daemon (padrão) ou passagem unica.
 *
 * `--once` processa no maximo um job e sai: util para cron (uma
 * passagem por minuto, sem processo residente) e para depuracao.
 */
$once = in_array('--once', $argv ?? [], true);

echo "[Worker] Processando jobs...\n";

while (true) {
    // Reservas vencidas (worker morto) voltam para a fila.
    $staleBefore = date('Y-m-d H:i:s', time() - $staleReservationSeconds);

    $job = Database::getInstance()->fetch(
        "SELECT * FROM jobs
         WHERE failed_at IS NULL
           AND attempts < max_attempts
           AND available_at <= NOW()
           AND (reserved_at IS NULL OR reserved_at < ?)
         ORDER BY priority DESC, created_at ASC
         LIMIT 1",
        [$staleBefore]
    );

    if ($job) {
        // Reserva condicional: se outro worker pegou o job no intervalo
        // entre o SELECT e o UPDATE, a reserva não vale e o job é
        // processado por quem chegou primeiro.
        $reserved = Database::getInstance()->update(
            'jobs',
            [
                'reserved_at' => date('Y-m-d H:i:s'),
                'attempts' => (int) $job['attempts'] + 1,
            ],
            'id = ? AND (reserved_at IS NULL OR reserved_at < ?)',
            [$job['id'], $staleBefore]
        );

        if ($reserved === 0) {
            continue; // outro worker reservou antes
        }

        try {
            $payload = json_decode((string) $job['payload'], true);
            $handler = $payload['handler'] ?? null;
            $params = $payload['params'] ?? [];

            // Handler inexistente é FALHA, não sucesso: antes o job era
            // apagado e logado como "processado", perdendo o trabalho.
            if (!$handler || !class_exists($handler)) {
                throw new \RuntimeException('Handler inexistente: ' . (string) $handler);
            }

            $instance = new $handler();
            $method = $payload['method'] ?? 'handle';
            if (!method_exists($instance, $method)) {
                throw new \RuntimeException("Metodo inexistente: {$handler}::{$method}");
            }

            $instance->$method(...(is_array($params) ? $params : []));

            Database::getInstance()->delete('jobs', 'id = ?', [$job['id']]);
            echo "  [OK] Job #{$job['id']} processado\n";
        } catch (\Throwable $e) {
            // \Throwable e nao \Exception: um TypeError no handler
            // derrubava o worker e deixava o job reservado para sempre.
            $attempts = (int) $job['attempts'] + 1;
            $message = mb_substr($e->getMessage(), 0, 1000);
            echo "  [ERRO] Job #{$job['id']} (tentativa {$attempts}): {$message}\n";

            if ($attempts >= (int) $job['max_attempts']) {
                // Dead-letter: o job fica registrado para inspeção.
                Database::getInstance()->update(
                    'jobs',
                    [
                        'reserved_at' => null,
                        'failed_at' => date('Y-m-d H:i:s'),
                        'last_error' => $message,
                    ],
                    'id = ?',
                    [$job['id']]
                );
                echo "  [FALHOU] Job #{$job['id']} esgotou as tentativas; marcado como failed\n";
            } else {
                Database::getInstance()->update(
                    'jobs',
                    [
                        'reserved_at' => null,
                        'last_error' => $message,
                    ],
                    'id = ?',
                    [$job['id']]
                );
            }
        }
    }

    if ($once && !$job) {
        echo "  [FIM] Nenhum job pendente\n";
        break;
    }

    if ($once) {
        // Uma passagem por job: a fila é drenada em passes sucessivos.
        break;
    }

    sleep(2);
}
