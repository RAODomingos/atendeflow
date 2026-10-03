<?php
// ============================================================
// api/upload.php - Upload de imagens para o editor Quill
// ============================================================

require_once __DIR__ . '/../config/api_auth.php';

// Authenticate API request (allows same-origin for admin uploads)
APIAuth::authenticate();

// Set CORS headers for authenticated requests
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: X-API-Key, Content-Type');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Inicia sessão para rate limiting
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

// Permite cookies de sessão do admin (mesmo subdomínio)
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Sempre responde JSON
//header('Content-Type: application/json; charset=utf-8'); // Removed, already set above

// Responde preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// Verifica login via sessão
if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autorizado. Faça login no painel admin.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido.']);
    exit;
}

if (empty($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
    http_response_code(400);
    echo json_encode(['error' => 'Nenhum arquivo enviado.']);
    exit;
}

$file = $_FILES['file'];

// Verifica erro de upload do PHP
if ($file['error'] !== UPLOAD_ERR_OK) {
    $erros = [
        UPLOAD_ERR_INI_SIZE   => 'Arquivo excede upload_max_filesize no php.ini.',
        UPLOAD_ERR_FORM_SIZE  => 'Arquivo excede MAX_FILE_SIZE no formulário.',
        UPLOAD_ERR_PARTIAL    => 'Upload incompleto.',
        UPLOAD_ERR_NO_TMP_DIR => 'Pasta temporária não encontrada.',
        UPLOAD_ERR_CANT_WRITE => 'Erro ao gravar no disco.',
        UPLOAD_ERR_EXTENSION  => 'Upload bloqueado por extensão PHP.',
    ];
    http_response_code(400);
    echo json_encode(['error' => $erros[$file['error']] ?? 'Erro desconhecido no upload.']);
    exit;
}

// Limite de tamanho: 5 MB
$maxSize = 5 * 1024 * 1024;
if ($file['size'] > $maxSize) {
    http_response_code(400);
    echo json_encode(['error' => 'Arquivo muito grande. Máximo permitido: 5 MB.']);
    exit;
}

// Check file size
if ($file['size'] > $maxFileSize) {
    echo json_encode(['error' => 'Arquivo muito grande. Máximo permitido: 5MB.']);
    exit;
}

// Check file extension
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExtensions)) {
    echo json_encode(['error' => 'Extensão de arquivo não permitida.']);
    exit;
}

// MIME type validation with additional security
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mime, $allowedMimes)) {
    echo json_encode(['error' => 'Tipo de arquivo não permitido. Use JPG, PNG, GIF, WebP ou SVG.']);
    exit;
}

// Additional image validation for non-SVG files
if ($mime !== 'image/svg+xml') {
    $imageInfo = @getimagesize($file['tmp_name']);
    if (!$imageInfo) {
        echo json_encode(['error' => 'Arquivo de imagem inválido.']);
        exit;
    }
    
    // Check image dimensions
    if ($imageInfo[0] > $maxImageDimensions || $imageInfo[1] > $maxImageDimensions) {
        echo json_encode(['error' => 'Dimensões da imagem muito grandes. Máximo: 4096x4096px.']);
        exit;
    }
    
    // Validate image content
    if (!@imagecreatefromstring(file_get_contents($file['tmp_name']))) {
        echo json_encode(['error' => 'Conteúdo de imagem inválido ou corrompido.']);
        exit;
    }
} else {
    // SVG specific validation
    $svgContent = file_get_contents($file['tmp_name']);
    
    // Check for malicious SVG content
    $maliciousPatterns = [
        '/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi',
        '/<iframe\b[^<]*(?:(?!<\/iframe>)<[^<]*)*<\/iframe>/mi',
        '/javascript:/i',
        '/vbscript:/i',
        '/data:text\/html/i',
        '/on\w+\s*=/i'
    ];
    
    foreach ($maliciousPatterns as $pattern) {
        if (preg_match($pattern, $svgContent)) {
            echo json_encode(['error' => 'Conteúdo SVG malicioso detectado.']);
            exit;
        }
    }
    
    // Validate SVG structure
    if (!strpos($svgContent, '<svg') || !strpos($svgContent, '</svg>')) {
        echo json_encode(['error' => 'Estrutura SVG inválida.']);
        exit;
    }
}

// Garante que a pasta existe e tem permissão
$uploadDir = __DIR__ . '/../uploads/';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0777, true)) {
        http_response_code(500);
        echo json_encode(['error' => 'Não foi possível criar a pasta de uploads.']);
        exit;
    }
}

// Gera nome único e salva
$ext      = $allowed[$mime];
$filename = date('Ymd_His_') . bin2hex(random_bytes(6)) . '.' . $ext;
$destPath = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Falha ao salvar o arquivo no servidor.']);
    exit;
}

// Sucesso
echo json_encode(['url' => '/uploads/' . $filename]);
