<?php

namespace App\Core;

/**
 * Rate limiting persistente em arquivos (storage/cache/rate_limit).
 *
 * O contador e atualizado sob um lock exclusivo: leitura, alteracao e
 * gravacao acontecem na mesma secao critica. Antes a leitura era feita
 * fora do lock, entao N requisicoes paralelas liam o mesmo contador e
 * todas escreviam count=1 — o limite de tentativas nunca disparava.
 */
class RateLimiter
{
    /** Arquivos de contadores mais velhos que isso sao removidos. */
    private const CLEANUP_AFTER = 86400; // 24h

    private static function dir(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/cache/rate_limit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private static function file(string $key): string
    {
        return self::dir() . '/' . sha1($key) . '.json';
    }

    /**
     * Executa o callback com o estado do contador sob lock exclusivo.
     *
     * O callback recebe o estado e devolve ['data' => estado a gravar,
     * 'result' => valor de retorno]. O estado e gravado no mesmo lock.
     */
    private static function withLock(string $key, callable $fn)
    {
        $empty = ['count' => 0, 'first' => time(), 'blocked_until' => 0];

        $fp = @fopen(self::file($key), 'c+');
        if (!$fp) {
            // Disco indisponivel: segue sem persistir (nao trava o login).
            $out = $fn($empty);
            return $out['result'];
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                $out = $fn($empty);
                return $out['result'];
            }

            $raw = stream_get_contents($fp);
            $data = json_decode((string) $raw, true);
            if (!is_array($data)) {
                $data = $empty;
            }
            $data += $empty;

            $out = $fn($data);

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string) json_encode($out['data']));
            fflush($fp);

            flock($fp, LOCK_UN);
            return $out['result'];
        } finally {
            fclose($fp);
        }
    }

    /**
     * Registra uma falha. Retorna segundos restantes de bloqueio (>0) se
     * o limite foi atingido, ou 0 caso contrário.
     */
    public static function hit(string $key, int $maxAttempts = 5, int $windowSeconds = 600, int $blockSeconds = 900): int
    {
        $result = self::withLock($key, function (array $data) use ($maxAttempts, $windowSeconds, $blockSeconds) {
            $now = time();
            if ($now - (int) $data['first'] > $windowSeconds) {
                $data = ['count' => 0, 'first' => $now, 'blocked_until' => 0];
            }
            $data['count'] = (int) $data['count'] + 1;
            if ($data['count'] >= $maxAttempts) {
                $data['blocked_until'] = $now + $blockSeconds;
                return ['data' => $data, 'result' => $blockSeconds];
            }
            return ['data' => $data, 'result' => 0];
        });

        // Limpeza eventual dos contadores antigos: o diretorio nao pode
        // crescer para sempre (um arquivo por IP/identidade vista).
        if (mt_rand(1, 50) === 1) {
            self::cleanup();
        }

        return $result;
    }

    /** Segundos restantes de bloqueio (0 = liberado). */
    public static function blocked(string $key): int
    {
        $f = self::file($key);
        if (!is_file($f)) {
            return 0;
        }
        $fp = @fopen($f, 'r');
        if (!$fp) {
            return 0;
        }
        try {
            flock($fp, LOCK_SH);
            $data = json_decode((string) stream_get_contents($fp), true);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }
        if (!is_array($data)) {
            return 0;
        }
        $left = (int) ($data['blocked_until'] ?? 0) - time();
        return $left > 0 ? $left : 0;
    }

    public static function clear(string $key): void
    {
        @unlink(self::file($key));
    }

    /**
     * Chave por IP de origem.
     */
    public static function clientKey(string $prefix): string
    {
        return $prefix . ':ip:' . self::clientIp();
    }

    /**
     * Chave por identidade (e-mail, telefone, etc.).
     *
     * Complementa a chave por IP: com NAT, um IP compartilhado nao pode
     * travar o login de todo mundo, e um e-mail nao pode ser martelado
     * de varios IPs. O bloqueio vale quando QUALQUER das dimensoes
     * estoura.
     */
    public static function identifierKey(string $prefix, string $identifier): string
    {
        return $prefix . ':id:' . strtolower(trim($identifier));
    }

    /**
     * IP real do cliente.
     *
     * X-Forwarded-For so e considerado quando quem conecta (REMOTE_ADDR)
     * e um proxy confiavel (TRUSTED_PROXIES). Sem essa checagem, o
     * cliente forjava o cabecalho e burlava o limite do login.
     */
    public static function clientIp(): string
    {
        $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        // REMOTE_ADDR com lista (proxy que concatena): o primeiro e o peer.
        if ($remote !== '' && str_contains($remote, ',')) {
            $remote = trim(explode(',', $remote)[0]);
        }

        if ($remote !== '' && self::isTrustedProxy($remote)) {
            $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            if ($forwarded !== '') {
                $first = trim(explode(',', $forwarded)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) {
                    return $first;
                }
            }
        }

        return $remote !== '' ? $remote : 'unknown';
    }

    /**
     * Lista de proxies confiaveis (env TRUSTED_PROXIES, IPs separados por
     * virgula). Vazio = ninguem e confiavel.
     */
    private static function isTrustedProxy(string $ip): bool
    {
        // O helper env() so existe quando o Helper.php esta carregado
        // (o app carrega; scripts isolados nao).
        $raw = function_exists('env')
            ? (string) env('TRUSTED_PROXIES', '')
            : (string) (getenv('TRUSTED_PROXIES') ?: '');
        if (trim($raw) === '') {
            return false;
        }
        foreach (explode(',', $raw) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && $candidate === $ip) {
                return true;
            }
        }
        return false;
    }

    /**
     * Remove contadores antigos (o diretorio crescia para sempre).
     */
    public static function cleanup(): void
    {
        $dir = self::dir();
        $files = @glob($dir . '/*.json') ?: [];
        $limit = time() - self::CLEANUP_AFTER;
        foreach ($files as $f) {
            $mtime = @filemtime($f);
            if ($mtime !== false && $mtime < $limit) {
                @unlink($f);
            }
        }
    }
}
