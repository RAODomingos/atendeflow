<?php

// Parte de app/Core/Helper.php — funções de uploads.
// Carregado automaticamente pelo Helper.php (não incluir diretamente).

function upload_dir(): string
{
    return dirname(__DIR__, 3) . '/public/uploads';
}

function upload_url(string $path = ''): string
{
    return base_url('uploads/' . ltrim($path, '/'));
}

/**
 * Confere se o MIME real (sniffed) é compatível com a extensão declarada.
 * Listas propositalmente amplas: o objetivo é barrar disfarces
 * (ex.: script PHP renomeado para .jpg), não validar formatos exóticos.
 */

function self_mime_allowed(string $type, string $ext, string $mime): bool
{
    $mime = strtolower(trim(explode(';', $mime)[0]));

    // Texto puro nunca pode se passar por mídia/executável e vice-versa.
    if (in_array($mime, ['application/x-php', 'application/x-httpd-php', 'application/x-sh', 'text/x-php', 'text/x-shellscript'], true)) {
        return false;
    }

    $map = [
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
        'png' => ['image/png'], 'gif' => ['image/gif'], 'webp' => ['image/webp'],
        'mp3' => ['audio/mpeg', 'audio/mp3'], 'wav' => ['audio/wav', 'audio/x-wav'],
        'ogg' => ['audio/ogg', 'video/ogg'], 'm4a' => ['audio/mp4', 'audio/x-m4a'],
        'aac' => ['audio/aac'], 'opus' => ['audio/opus', 'audio/ogg'],
        'webm' => ['video/webm', 'audio/webm'], 'mpga' => ['audio/mpeg'],
        'mp4' => ['video/mp4'], 'mov' => ['video/quicktime'], '3gp' => ['video/3gpp'],
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword'], 'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls' => ['application/vnd.ms-excel'], 'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'ppt' => ['application/vnd.ms-powerpoint'], 'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'txt' => ['text/plain'], 'csv' => ['text/plain', 'text/csv'],
        'zip' => ['application/zip'], 'rar' => ['application/x-rar', 'application/vnd.rar'],
    ];

    // Extensão desconhecida no mapa: aceita (allowlist de extensão já filtrou).
    if (!isset($map[$ext])) {
        return true;
    }
    if (in_array($mime, $map[$ext], true)) {
        return true;
    }
    // Alguns containers (zip/rar/office) chegam como octet-stream: aceita
    // SÓ para o tipo 'file'. Mídia (image/audio/video) exige MIME exato —
    // polyglot com MIME genérico não passa.
    if ($mime === 'application/octet-stream' && $type === 'file') {
        return true;
    }
    return false;
}

function save_uploaded_file(string $key, ?array $allowed = null, string $subdir = 'messages'): ?array
{
    $file = $_FILES[$key] ?? null;
    if (!$file || empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $allowed ??= [
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        'audio' => ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'opus', 'webm', 'mpga'],
        'video' => ['mp4', 'webm', 'mov', '3gp'],
        'file'  => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'zip', 'rar', 'csv'],
    ];

    $name = $file['name'] ?? 'arquivo';
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === '') {
        return null;
    }

    // Bloqueia executáveis e dupla extensão (ex.: foto.php, doc.php.jpg).
    $blocked = ['php', 'phtml', 'phar', 'php5', 'php7', 'exe', 'sh', 'bat', 'cmd', 'com', 'msi', 'jar', 'py', 'pl', 'cgi', 'htaccess'];
    $parts = explode('.', strtolower($name));
    foreach ($parts as $p) {
        if (in_array($p, $blocked, true)) {
            return null;
        }
    }

    $type = null;
    foreach ($allowed as $t => $exts) {
        if (in_array($ext, $exts, true)) {
            $type = $t;
            break;
        }
    }
    if ($type === null) {
        return null;
    }

    // Valida o conteúdo real (MIME sniffing), não só a extensão.
    $realMime = null;
    if (class_exists('finfo')) {
        try {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $realMime = $finfo->file($file['tmp_name']) ?: null;
        } catch (\Throwable $e) {
            $realMime = null;
        }
    }
    if ($realMime !== null && !self_mime_allowed($type, $ext, $realMime)) {
        return null;
    }

    $maxBytes = (int) (require dirname(__DIR__, 3) . '/config/app.php')['upload_max_size'] ?? (10 * 1024 * 1024);
    if ($maxBytes <= 0) {
        $maxBytes = 10 * 1024 * 1024;
    }
    if ($file['size'] > $maxBytes) {
        return null;
    }

    $dir = upload_dir() . '/' . $subdir;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $newName = bin2hex(random_bytes(12)) . '.' . $ext;
    $dest = $dir . '/' . $newName;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return null;
    }

    return [
        'path' => $subdir . '/' . $newName,
        'url'  => upload_url($subdir . '/' . $newName),
        'name' => $name,
        'size' => $file['size'],
        'type' => $type,
        'mime' => mime_content_type($dest) ?: 'application/octet-stream',
    ];
}

/**
 * Converte imagens em formatos não suportados pela Uazapi (PNG, WebP, GIF, BMP)
 * para JPEG antes do envio. Necessário porque a Uazapi (plano free, em
 * particular) rejeita esses formatos com "unsupported image format".
 *
 * Detecta o tipo pelo CONTEÚDO (mime_content_type), não pela extensão —
 * arquivos renomeados (.webp → .png) também são corrigidos.
 *
 * - JPEG: mantém como está
 * - WebP animado: mantém (GD não decodifica)
 * - PNG/GIF/BMP/WebP estático: converte para JPEG
 *
 * Retorna o array $uploaded (possivelmente com path/url/name/mime atualizados).
 */

function normalize_image_for_whatsapp(array $uploaded): array
{
    if (($uploaded['type'] ?? '') !== 'image') {
        return $uploaded;
    }
    if (!isset($uploaded['path']) || $uploaded['path'] === '') {
        return $uploaded;
    }
    $path = upload_dir() . '/' . $uploaded['path'];
    if (!is_file($path)) {
        return $uploaded;
    }
    if (!function_exists('imagecreatefromwebp')) {
        return $uploaded;
    }

    $mime = (string) mime_content_type($path);
    if (in_array($mime, ['image/jpeg', 'image/jpg'], true)) {
        return $uploaded;
    }

    // WebP animado: o GD não decodifica — a conversão client-side (canvas) já cuida.
    if ($mime === 'image/webp' && is_animated_webp($path)) {
        return $uploaded;
    }

    $im = match ($mime) {
        'image/webp' => @imagecreatefromwebp($path),
        'image/png'  => @imagecreatefrompng($path),
        'image/gif'  => @imagecreatefromgif($path),
        'image/bmp'  => @imagecreatefrombmp($path),
        default      => false,
    };
    if (!$im) {
        return $uploaded;
    }

    $newName = bin2hex(random_bytes(12)) . '.jpg';
    $newPath = upload_dir() . '/' . dirname($uploaded['path']) . '/' . $newName;
    if (!imagejpeg($im, $newPath, 92)) {
        imagedestroy($im);
        return $uploaded;
    }
    imagedestroy($im);

    // Remove o arquivo original (não-JPEG) — economiza espaço.
    @unlink($path);

    return [
        'path' => dirname($uploaded['path']) . '/' . $newName,
        'url'  => upload_url(dirname($uploaded['path']) . '/' . $newName),
        'name' => pathinfo($uploaded['name'], PATHINFO_FILENAME) . '.jpg',
        'size' => filesize($newPath),
        'type' => 'image',
        'mime' => 'image/jpeg',
    ];
}

/**
 * Detecta WebP animado (chunk ANIM presente no header RIFF).
 */

function is_animated_webp(string $path): bool
{
    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return false;
    }
    $header = fread($fh, 34);
    fclose($fh);
    if (strlen($header) < 34 || substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WEBP') {
        return false;
    }
    $chunk = substr($header, 12, 4);
    return $chunk === 'VP8X';
}

function message_file_meta(string $content): ?array
{
    $decoded = json_decode($content, true);
    if (is_array($decoded) && !empty($decoded['url'])) {
        $url = $decoded['url'];
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $url = upload_url($url);
        }
        return [
            'url'     => $url,
            'name'    => $decoded['name'] ?? basename($url),
            'size'    => $decoded['size'] ?? 0,
            'mime'    => $decoded['mime'] ?? '',
            'caption' => isset($decoded['caption']) && is_string($decoded['caption']) ? $decoded['caption'] : '',
        ];
    }
    if (filter_var($content, FILTER_VALIDATE_URL) || str_starts_with($content, 'uploads/')) {
        return ['url' => upload_url(ltrim($content, '/')), 'name' => basename($content), 'size' => 0, 'caption' => ''];
    }
    return null;
}

/**
 * Infere o tipo de mídia (image/audio/video/file/sticker) a partir do conteúdo
 * serializado como JSON {"url":..., "mime":...}. Retorna null se não for mídia.
 * Usado como fallback quando a coluna `type` da mensagem veio vazia/incorreta.
 */

function media_type_from_content(string $content): ?string
{
    $decoded = json_decode($content, true);
    if (!is_array($decoded) || empty($decoded['url']) || !is_string($decoded['url'])) {
        return null;
    }
    // CSAT payloads have title/prompt, not file metadata — ignore
    if (isset($decoded['title']) || isset($decoded['prompt'])) {
        return null;
    }
    $mime = $decoded['mime'] ?? '';
    if (str_starts_with($mime, 'image/')) {
        return ($mime === 'image/webp') ? 'sticker' : 'image';
    }
    if (str_starts_with($mime, 'audio/')) {
        return 'audio';
    }
    if (str_starts_with($mime, 'video/')) {
        return 'video';
    }
    if (str_starts_with($mime, 'application/') || str_starts_with($mime, 'text/')) {
        return 'file';
    }
    $ext = strtolower(pathinfo(parse_url($decoded['url'], PHP_URL_PATH) ?: $decoded['url'], PATHINFO_EXTENSION));
    return match ($ext) {
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif' => 'image',
        'mp3', 'ogg', 'm4a', 'aac', 'wav', 'amr' => 'audio',
        'mp4', 'mov', 'avi', 'mkv', 'webm', '3gp' => 'video',
        default => 'file',
    };
}

function format_bytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 1) . ' ' . $units[$i];
}

/**
 * Hosts liberados para download remoto.
 *
 * Vazio = nenhum host especifico (o bloqueio de rede privada continua
 * valendo). Preencha com padroes separatedos por virgula, ex.:
 * REMOTE_FETCH_ALLOWED_HOSTS=pps.whatsapp.net,*.waha.*
 */
function remote_fetch_allowed_hosts(): array
{
    $raw = function_exists('env') ? (string) env('REMOTE_FETCH_ALLOWED_HOSTS', '') : '';
    $list = array_values(array_filter(array_map('trim', explode(',', $raw))));
    return $list;
}

/**
 * Verificacao TLS desligada (ambiente com certificado proprio).
 *
 * O padrao e VERIFICAR: sem isso, quem esta no meio da conexao
 * consegue trocar o arquivo baixado. so desligue em ambiente de
 * teste, com REMOTE_FETCH_INSECURE=1.
 */
function remote_fetch_insecure(): bool
{
    $raw = function_exists('env') ? (string) env('REMOTE_FETCH_INSECURE', '') : '';
    return trim($raw) === '1' || strtolower(trim($raw)) === 'true';
}

/**
 * Host do provedor de WhatsApp configurado (WAHA_BASE_URL).
 *
 * O provedor pode devolver midia hospedada na propria maquina
 * (comum em ambiente local) — esse host e liberado mesmo em rede
 * privada.
 */
function remote_fetch_provider_host(): string
{
    $base = function_exists('env') ? (string) env('WAHA_BASE_URL', '') : '';
    if (trim($base) === '') {
        return '';
    }
    $host = parse_url($base, PHP_URL_HOST);
    return is_string($host) ? $host : '';
}

/**
 * Hosts dos provedores de WhatsApp configurados (tabela
 * whatsapp_connections). O provedor pode devolver midia hospedada na
 * propria maquina — o host dele e liberado mesmo em rede privada.
 *
 * @return array<int, string>
 */
function remote_fetch_provider_hosts(): array
{
    static $hosts = null;
    if ($hosts !== null) {
        return $hosts;
    }

    $hosts = [];
    $envHost = remote_fetch_provider_host();
    if ($envHost !== '') {
        $hosts[] = strtolower($envHost);
    }

    try {
        if (class_exists(\App\Core\Database::class)) {
            $rows = \App\Core\Database::getInstance()->fetchAll(
                "SELECT DISTINCT base_url FROM whatsapp_connections WHERE base_url IS NOT NULL AND base_url <> ''"
            );
            foreach ($rows as $r) {
                $host = parse_url((string) $r['base_url'], PHP_URL_HOST);
                if (is_string($host) && $host !== '') {
                    $hosts[] = strtolower($host);
                }
            }
        }
    } catch (\Throwable $e) {
        // Sem banco (script isolado): segue so com o host do env.
    }

    return array_values(array_unique($hosts));
}

/**
 * O IP e de rede privada/reservada (loopback, RFC1918, link-local,
 * multicast, IPv6 local)?
 */
function is_private_ip(string $ip): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $cidrs = [
            '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8',
            '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.168.0.0/16',
            '198.18.0.0/15', '224.0.0.0/4', '240.0.0.0/4',
        ];
        foreach ($cidrs as $cidr) {
            if (ip_in_range($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        if ($ip === '::1' || $ip === '::') {
            return true;
        }
        $bin = inet_pton($ip);
        if ($bin === false) {
            return true;
        }
        $hex = bin2hex($bin);
        // fc00::/7 (unicast local), fe80::/10 (link-local)
        if (str_starts_with($hex, 'fc') || str_starts_with($hex, 'fd')) {
            return true;
        }
        if (preg_match('/^fe[89ab]/', $hex)) {
            return true;
        }
        // ::ffff:x.x.x.x (IPv4 mapeado)
        if (str_starts_with($hex, '00000000000000000000ffff')) {
            $mapped = inet_ntop(substr($bin, 12));
            return $mapped === false ? true : is_private_ip($mapped);
        }
        return false;
    }

    return true;
}

/**
 * O IP esta dentro da faixa CIDR?
 */
function ip_in_range(string $ip, string $cidr): bool
{
    [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, '32');
    $ipBin = @inet_pton($ip);
    $subnetBin = @inet_pton($subnet);
    if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
        return false;
    }
    $bits = (int) $bits;
    $bytes = intdiv($bits, 8);
    if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
        return false;
    }
    $rest = $bits % 8;
    if ($rest === 0) {
        return true;
    }
    $mask = ~((1 << (8 - $rest)) - 1) & 0xFF;
    return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
}

/**
 * A URL pode ser buscada?
 *
 * - somente http/https
 * - o host nao pode apontar para rede privada/reservada (SSRF: a URL
 *   vem do payload do webhook, que eControlled por terceiros)
 * - se houver allowlist configurada, o host precisa estar nela
 */
function remote_fetch_allowed(string $url): bool
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    $parts = parse_url($url);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return false;
    }
    $host = (string) ($parts['host'] ?? '');
    if ($host === '') {
        return false;
    }

    $allowed = remote_fetch_allowed_hosts();
    if ($allowed) {
        $ok = false;
        foreach ($allowed as $pattern) {
            if (fnmatch(strtolower($pattern), strtolower($host))) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            return false;
        }
    }

    // O proprio provedor (Uazapi/WAHA/Evolution) pode devolver midia
    // hospedada na maquina dele (comum em ambiente local). Os hosts
    // configurados dos provedores sao liberados mesmo em rede privada.
    if (in_array(strtolower($host), remote_fetch_provider_hosts(), true)) {
        return true;
    }

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return !is_private_ip($host);
    }

    // Host nome: resolve e valida cada IP (pega nome que aponta para
    // rede interna).
    $ips = @gethostbynamel($host);
    if (!$ips) {
        $v6 = @dns_get_record($host, DNS_AAAA);
        $ips = array_values(array_filter(array_map(
            static fn($r) => $r['ipv6'] ?? null,
            is_array($v6) ? $v6 : []
        )));
    }
    if (!$ips) {
        return false; // nao deu para validar: bloqueia
    }
    foreach ($ips as $ip) {
        if (is_private_ip((string) $ip)) {
            return false;
        }
    }
    return true;
}

/**
 * Baixa uma imagem remota (ex.: foto de perfil do WhatsApp) e a salva localmente
 * em public/uploads/avatars. Retorna o caminho relativo (ex.: "avatars/abc.png")
 * ou null em caso de falha. As URLs do WhatsApp (pps.whatsapp.net) expiram, por
 * isso o ideal é persistir uma cópia local em vez de armazenar a URL crua.
 */

function download_remote_image(string $url, string $subdir = 'avatars'): ?string
{
    if (!remote_fetch_allowed($url)) {
        return null;
    }

    $maxBytes = 10 * 1024 * 1024;
    $raw = null;
    $insecure = remote_fetch_insecure();

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (OminiDesk)');
        // TLS verificado por padrao (o redirect tambem so pode ser http/https).
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$insecure);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $insecure ? 0 : 2);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === '' || $status < 200 || $status >= 300) {
            return null;
        }
    } else {
        $ctx = stream_context_create([
            'http' => ['timeout' => 15, 'user_agent' => 'Mozilla/5.0 (OminiDesk)', 'ignore_errors' => true],
            'https' => ['timeout' => 15, 'user_agent' => 'Mozilla/5.0 (OminiDesk)', 'ignore_errors' => true],
        ]);
        $raw = (string) @file_get_contents($url, false, $ctx);
        if ($raw === '') {
            return null;
        }
    }

    $size = strlen($raw);
    if ($size === 0 || $size > $maxBytes) {
        return null;
    }

    // Detecta o tipo pela assinatura (ignora a extensão da URL, que pode ser dinâmica).
    $mime = null;
    if (str_starts_with($raw, "\x89PNG")) {
        $mime = 'image/png';
    } elseif (str_starts_with($raw, "\xff\xd8\xff")) {
        $mime = 'image/jpeg';
    } elseif (str_starts_with($raw, 'GIF8')) {
        $mime = 'image/gif';
    } elseif (str_starts_with($raw, 'RIFF') && substr($raw, 8, 4) === 'WEBP') {
        $mime = 'image/webp';
    }
    if (!$mime) {
        return null;
    }
    $ext = match ($mime) {
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        default => 'bin',
    };

    $dir = upload_dir() . '/' . $subdir;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $newName = bin2hex(random_bytes(12)) . '.' . $ext;
    $dest = $dir . '/' . $newName;

    if (file_put_contents($dest, $raw) === false) {
        return null;
    }

    return $subdir . '/' . $newName;
}

/**
 * Baixa um arquivo remoto (URL) e retorna os bytes crus e o tamanho.
 * Mesma politica de seguranca de download_remote_image: TLS verificado
 * por padrao e bloqueio de rede privada (SSRF).
 *
 * @return array{data:string, size:int}|null
 */

function download_remote_file(string $url, int $maxBytes = 0, ?array $extraHeaders = null): ?array
{
    if (!remote_fetch_allowed($url)) {
        return null;
    }
    $maxBytes = $maxBytes > 0 ? $maxBytes : 20 * 1024 * 1024;
    $insecure = remote_fetch_insecure();

    $raw = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (OminiDesk)',
            CURLOPT_SSL_VERIFYPEER => !$insecure,
            CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        if ($extraHeaders) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $extraHeaders);
        }
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === '' || $status < 200 || $status >= 300) {
            return null;
        }
    } else {
        $httpOpts = [
            'timeout' => 60,
            'user_agent' => 'Mozilla/5.0 (OminiDesk)',
            'ignore_errors' => true,
        ];
        if ($extraHeaders) {
            $httpOpts['header'] = implode("\r\n", $extraHeaders);
        }
        $ctx = stream_context_create([
            'http' => $httpOpts,
            'https' => $httpOpts,
        ]);
        $raw = (string) @file_get_contents($url, false, $ctx);
        if ($raw === '') {
            return null;
        }
    }

    $size = strlen($raw);
    if ($size === 0 || $size > $maxBytes) {
        return null;
    }

    return ['data' => $raw, 'size' => $size];
}

/**
 * Renderiza as estrelas de uma avaliação CSAT (1 a 5).
 * Retorna string vazia se a pesquisa não foi respondida.
 */

function avatar_url(?string $path): ?string
{
    if ($path === null || $path === '') {
        return null;
    }
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        return $path;
    }
    return upload_url(ltrim($path, '/'));
}
