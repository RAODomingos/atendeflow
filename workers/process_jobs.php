<?php
/**
 * Job processor worker
 * Run: php workers/process_jobs.php
 */

require_once __DIR__ . '/../app/Core/Autoloader.php';
require_once __DIR__ . '/../app/Core/Helper.php';

use App\Core\Database;

Database::connect();

echo "[Worker] Processando jobs...\n";

while (true) {
    $job = Database::getInstance()->fetch(
        "SELECT * FROM jobs
         WHERE reserved_at IS NULL
           AND available_at <= NOW()
           AND attempts < max_attempts
         ORDER BY priority DESC, created_at ASC
         LIMIT 1"
    );

    if ($job) {
        Database::getInstance()->update('jobs', [
            'reserved_at' => date('Y-m-d H:i:s'),
            'attempts' => $job['attempts'] + 1,
        ], 'id = ?', [$job['id']]);

        try {
            $payload = json_decode($job['payload'], true);
            $handler = $payload['handler'] ?? null;
            $params = $payload['params'] ?? [];

            if ($handler && class_exists($handler)) {
                $instance = new $handler();
                $method = $payload['method'] ?? 'handle';
                if (method_exists($instance, $method)) {
                    $instance->$method(...$params);
                }
            }

            Database::getInstance()->delete('jobs', 'id = ?', [$job['id']]);
            echo "  [OK] Job #{$job['id']} processado\n";
        } catch (\Exception $e) {
            echo "  [ERRO] Job #{$job['id']}: {$e->getMessage()}\n";

            if ($job['attempts'] >= $job['max_attempts']) {
                Database::getInstance()->delete('jobs', 'id = ?', [$job['id']]);
                echo "  [REMOVED] Job #{$job['id']} excedeu tentativas\n";
            } else {
                Database::getInstance()->update('jobs', [
                    'reserved_at' => null,
                ], 'id = ?', [$job['id']]);
            }
        }
    }

    sleep(2);
}
