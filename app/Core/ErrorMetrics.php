<?php

namespace App\Core;

/**
 * Contagem agregada de erros da aplicacao.
 *
 * O log de erro sozinho nao responde "quantas vezes isso aconteceu
 * hoje?". Aqui fica um contador por dia (storage/cache/metrics) e o
 * ultimo erro, para o health check e para o painel.
 */
class ErrorMetrics
{
    /** Quantos dias manter no historico. */
    private const KEEP_DAYS = 14;

    private static function file(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/cache/metrics';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . '/errors.json';
    }

    /**
     * Registra um erro.
     */
    public static function record(\Throwable $e): void
    {
        $file = self::file();
        $fp = @fopen($file, 'c+');
        if (!$fp) {
            return;
        }
        try {
            flock($fp, LOCK_EX);
            $raw = stream_get_contents($fp);
            $data = json_decode((string) $raw, true);
            if (!is_array($data)) {
                $data = [];
            }
            $data += ['days' => [], 'last' => null];

            $day = date('Y-m-d');
            $data['days'][$day] = (int) ($data['days'][$day] ?? 0) + 1;

            $data['last'] = [
                'at' => date('Y-m-d H:i:s'),
                'message' => mb_substr($e->getMessage(), 0, 500),
                'type' => get_class($e),
                'file' => basename($e->getFile()) . ':' . $e->getLine(),
            ];

            // Poda o historico.
            $cutoff = date('Y-m-d', time() - (self::KEEP_DAYS * 86400));
            foreach (array_keys($data['days']) as $d) {
                if ((string) $d < $cutoff) {
                    unset($data['days'][$d]);
                }
            }

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string) json_encode($data));
            fflush($fp);
            flock($fp, LOCK_UN);
        } catch (\Throwable $ignored) {
            // Metrica nunca pode derrubar a request.
        } finally {
            fclose($fp);
        }
    }

    /**
     * Resumo dos erros.
     *
     * @return array{today:int, total:int, last:?array, days:array<string,int>}
     */
    public static function summary(): array
    {
        $file = self::file();
        $data = [];
        if (is_file($file)) {
            $decoded = json_decode((string) @file_get_contents($file), true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
        $days = is_array($data['days'] ?? null) ? $data['days'] : [];
        $today = date('Y-m-d');

        return [
            'today' => (int) ($days[$today] ?? 0),
            'total' => array_sum(array_map('intval', $days)),
            'last' => is_array($data['last'] ?? null) ? $data['last'] : null,
            'days' => $days,
        ];
    }
}
