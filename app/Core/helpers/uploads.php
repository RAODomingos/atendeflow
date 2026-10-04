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
 * Baixa uma imagem remota (ex.: foto de perfil do WhatsApp) e a salva localmente
 * em public/uploads/avatars. Retorna o caminho relativo (ex.: "avatars/abc.png")
 * ou null em caso de falha. As URLs do WhatsApp (pps.whatsapp.net) expiram, por
 * isso o ideal é persistir uma cópia local em vez de armazenar a URL crua.
 */

function download_remote_image(string $url, string $subdir = 'avatars'): ?string
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }

    $maxBytes = 10 * 1024 * 1024;
    $raw = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (OminiDesk)');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
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
 * Usa cURL com redirecionamento e sem verificação de SSL (ambiente localhost),
 * idêntico a download_remote_image, mas genérico (qualquer mídia).
 *
 * @return array{data:string, size:int}|null
 */

function download_remote_file(string $url, int $maxBytes = 0, ?array $extraHeaders = null): ?array
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }
    $maxBytes = $maxBytes > 0 ? $maxBytes : 20 * 1024 * 1024;

    $raw = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (OminiDesk)',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
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
