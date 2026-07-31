<?php

if (!function_exists('env')) {
function env(string $key, mixed $default = null): mixed
{
    static $env = null;

    if ($env === null) {
        $envFile = __DIR__ . '/../../env/.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim($v);
                $env[$k] = $v;
            }
        }
    }

    return $env[$key] ?? $default;
}
}

function base_url(string $path = '/'): string
{
    static $basePath = null;

    if ($basePath === null) {
        $appUrl = env('APP_URL');
        if (!empty($appUrl)) {
            $basePath = rtrim($appUrl, '/');
        } else {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
            $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
            $basePath = rtrim($protocol . '://' . $host . str_replace('\\', '/', dirname(dirname($scriptName))), '/');
        }
    }

    return $basePath . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    return base_url($path);
}

function url(string $path = '/'): string
{
    return base_url(ltrim($path, '/'));
}

function route(string $name): string
{
    $routes = [
        'login' => '/login',
        'logout' => '/logout',
        'dashboard' => '/',
        'inbox' => '/inbox',
        'inbox.mine' => '/inbox/mine',
        'inbox.unassigned' => '/inbox/unassigned',
        'inbox.new' => '/inbox/new',
        'contacts' => '/contacts',
        'contacts.create' => '/contacts/create',
        'departments' => '/departments',
        'flows' => '/flows',
        'flows.create' => '/flows/create',
        'users' => '/users',
        'users.create' => '/users/create',
        'profile' => '/profile',
    ];
    return base_url($routes[$name] ?? '/');
}

function session(string|array|null $key = null, mixed $default = null): mixed
{
    \App\Core\Session::start();
    if ($key === null) {
        return new class {
            public function has(string $k): bool { return \App\Core\Session::has($k); }
            public function get(string $k, mixed $d = null): mixed { return \App\Core\Session::get($k, $d); }
            public function set(string $k, mixed $v): void { \App\Core\Session::set($k, $v); }
            public function flash(string $k, mixed $v): void { \App\Core\Session::setFlash($k, $v); }
            public function getFlash(string $k, mixed $d = null): mixed { return \App\Core\Session::getFlash($k, $d); }
        };
    }
    if (is_array($key)) {
        foreach ($key as $k => $v) { \App\Core\Session::set($k, $v); }
        return null;
    }
    return \App\Core\Session::get($key, $default);
}

function csrf_token(): string
{
    $token = \App\Core\Session::get('csrf_token');
    if (!$token) {
        $token = bin2hex(random_bytes(32));
        \App\Core\Session::set('csrf_token', $token);
    }
    return $token;
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf_token" value="' . csrf_token() . '">';
}

function old(string $key, mixed $default = ''): mixed
{
    return \App\Core\Session::getFlash('old_' . $key, $default);
}

function error(string $key, mixed $default = null): mixed
{
    $errors = \App\Core\Session::getFlash('errors', []);
    return $errors[$key][0] ?? $default;
}

function has_error(string $key): bool
{
    $errors = \App\Core\Session::getFlash('errors', []);
    return isset($errors[$key]);
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function truncate(string $text, int $limit = 100): string
{
    if (mb_strlen($text) <= $limit) {
        return $text;
    }
    return mb_substr($text, 0, $limit) . '...';
}

function time_elapsed(string $datetime): string
{
    $now = new DateTime();
    $then = new DateTime($datetime);
    $diff = $now->diff($then);

    if ($diff->y > 0) return "{$diff->y}a";
    if ($diff->m > 0) return "{$diff->m}mes";
    if ($diff->d > 0) return "{$diff->d}d";
    if ($diff->h > 0) return "{$diff->h}h";
    if ($diff->i > 0) return "{$diff->i}min";
    return "agora";
}

function format_datetime(string $datetime): string
{
    $dt = new DateTime($datetime);
    return $dt->format('d/m/Y H:i');
}

function format_date_sep(string $datetime): string
{
    $dt = new DateTime($datetime);
    $now = new DateTime();
    $today = (new DateTime())->setTime(0, 0, 0);
    $yesterday = (clone $today)->modify('-1 day');
    $target = (clone $dt)->setTime(0, 0, 0);

    if ($target == $today) return 'Hoje';
    if ($target == $yesterday) return 'Ontem';
    return $dt->format('d/m/Y');
}

function format_time(string $datetime): string
{
    $dt = new DateTime($datetime);
    $now = new DateTime();
    $today = (new DateTime())->setTime(0, 0, 0);
    $yesterday = (clone $today)->modify('-1 day');
    $target = (clone $dt)->setTime(0, 0, 0);

    if ($target == $today) return $dt->format('H:i');
    if ($target == $yesterday) return 'Ontem ' . $dt->format('H:i');
    return $dt->format('d/m H:i');
}

function status_badge(string $status): string
{
    $labels = [
        'new' => 'Novo',
        'open' => 'Aberto',
        'waiting_customer' => 'Em atendimento',
        'waiting_internal' => 'Aguardando Interno',
        'resolved' => 'Resolvido',
        'closed' => 'Fechado',
        'spam' => 'Spam',
    ];

    $label = $labels[$status] ?? $status;

    return "<span class=\"status-badge status-{$status}\">{$label}</span>";
}

function priority_badge(string $priority): string
{
    $labels = [
        'low' => 'Baixa',
        'normal' => 'Normal',
        'high' => 'Alta',
        'urgent' => 'Urgente',
    ];
    $label = $labels[$priority] ?? ucfirst($priority);
    return "<span class=\"priority-badge priority-{$priority}\">{$label}</span>";
}

function channel_icon(string $type): string
{
    $icons = [
        'whatsapp' => 'fab fa-whatsapp',
        'webchat' => 'fas fa-comment-dots',
        'email' => 'fas fa-envelope',
    ];
    return $icons[$type] ?? 'fas fa-comment';
}

function upload_dir(): string
{
    return __DIR__ . '/../../public/uploads';
}

function upload_url(string $path = ''): string
{
    return base_url('uploads/' . ltrim($path, '/'));
}

function save_uploaded_file(string $key, ?array $allowed = null, string $subdir = 'messages'): ?array
{
    $file = $_FILES[$key] ?? null;
    if (!$file || empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $allowed ??= [
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        'audio' => ['mp3', 'wav', 'ogg', 'm4a'],
        'video' => ['mp4', 'webm', 'mov'],
        'file'  => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'zip', 'rar'],
    ];

    $name = $file['name'] ?? 'arquivo';
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === '') {
        return null;
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

    $maxBytes = 10 * 1024 * 1024;
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

function message_file_meta(string $content): ?array
{
    $decoded = json_decode($content, true);
    if (is_array($decoded) && !empty($decoded['url'])) {
        $url = $decoded['url'];
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $url = upload_url($url);
        }
        return [
            'url'  => $url,
            'name' => $decoded['name'] ?? basename($url),
            'size' => $decoded['size'] ?? 0,
        ];
    }
    if (filter_var($content, FILTER_VALIDATE_URL) || str_starts_with($content, 'uploads/')) {
        return ['url' => upload_url(ltrim($content, '/')), 'name' => basename($content), 'size' => 0];
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
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (AtendeFlow)');
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
            'http' => ['timeout' => 15, 'user_agent' => 'Mozilla/5.0 (AtendeFlow)', 'ignore_errors' => true],
            'https' => ['timeout' => 15, 'user_agent' => 'Mozilla/5.0 (AtendeFlow)', 'ignore_errors' => true],
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
            CURLOPT_USERAGENT => 'Mozilla/5.0 (AtendeFlow)',
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
            'user_agent' => 'Mozilla/5.0 (AtendeFlow)',
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
function csat_stars(?array $csat, int $max = 5): string
{
    if (empty($csat) || empty($csat['rating'])) {
        return '';
    }
    $rating = (int) $csat['rating'];
    $html = '<span class="csat-stars" title="Avalia&ccedil;&atilde;o: ' . $rating . '/5">';
    for ($i = 1; $i <= $max; $i++) {
        $on = $i <= $rating ? ' on' : '';
        $html .= '<i class="fas fa-star csat-star' . $on . '"></i>';
    }
    $html .= '</span>';
    return $html;
}

function contrast_color(string $hex): string
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $l = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    return $l > 0.5 ? '#1a1a2e' : '#ffffff';
}

function event_icon(string $type): string
{
    $map = [
        'created' => 'fa-plus-circle',
        'assigned' => 'fa-user-check',
        'transferred' => 'fa-exchange-alt',
        'status_changed' => 'fa-flag',
        'priority_changed' => 'fa-arrow-up',
        'snoozed' => 'fa-clock',
        'csat' => 'fa-star',
        'merged' => 'fa-code-branch',
        'tag_added' => 'fa-tag',
        'tag_removed' => 'fa-tag',
        'note_added' => 'fa-sticky-note',
    ];
    return $map[$type] ?? 'fa-circle';
}

function event_color(string $type): string
{
    $map = [
        'created' => '#22c55e',
        'assigned' => '#3b82f6',
        'transferred' => '#f59e0b',
        'status_changed' => '#8b5cf6',
        'priority_changed' => '#eab308',
        'snoozed' => '#6b7280',
        'csat' => '#f59e0b',
        'merged' => '#ef4444',
        'tag_added' => '#14b8a6',
        'tag_removed' => '#9ca3af',
        'note_added' => '#6b7280',
    ];
    return $map[$type] ?? 'var(--primary)';
}

function slugify(string $text): string
{
    $text = preg_replace('~[^\p{L}\p{N}]+~u', '-', $text);
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    $text = strtolower(trim($text, '-'));
    return $text ?: 'sem-nome';
}
