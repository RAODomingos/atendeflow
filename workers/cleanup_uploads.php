<?php
/**
 * Limpeza de midia antiga (politica de retencao).
 *
 *   php workers/cleanup_uploads.php            # executa
 *   php workers/cleanup_uploads.php --dry-run  # so mostra o que apagaria
 *
 * Apaga arquivos de public/uploads que nao sejam mais referenciados por
 * nenhuma mensagem e que estejam fora da janela de retencao (default
 * 180 dias, MEDIA_RETENTION_DAYS). Sem isso o disco do servidor cresce
 * sem limite: cada foto de perfil e cada anexo do WhatsApp fica para
 * sempre.
 *
 * Cron sugerido (uma vez por dia, de madrugada):
 *   17 3 * * * cd /var/www/atendeflow && php workers/cleanup_uploads.php >> storage/logs/cleanup_uploads.log 2>&1
 */

require_once __DIR__ . '/../app/Core/Autoloader.php';
require_once __DIR__ . '/../app/Core/Helper.php';

use App\Core\Database;

$appConfig = require __DIR__ . '/../config/app.php';
date_default_timezone_set($appConfig['timezone'] ?? 'America/Sao_Paulo');

$root = dirname(__DIR__);
$uploadDir = $root . '/public/uploads';
$dryRun = in_array('--dry-run', $argv ?? [], true);

$retentionDays = (int) (env('MEDIA_RETENTION_DAYS', '180') ?: '180');
if ($retentionDays < 1) {
    $retentionDays = 180;
}

$limit = date('Y-m-d H:i:s', time() - ($retentionDays * 86400));

echo '[' . date('Y-m-d H:i:s') . "] Limpeza de midia (retencao de {$retentionDays} dias"
    . ($dryRun ? ', dry-run' : '') . ")\n";

if (!is_dir($uploadDir)) {
    echo "  uploads nao existe: nada a fazer\n";
    exit(0);
}

Database::connect();
$db = Database::getInstance();

// Arquivos ainda referenciados por mensagens (anexos) nunca saem.
$referenced = [];
try {
    foreach ($db->fetchAll("SELECT file_path FROM message_attachments") as $r) {
        $referenced[(string) $r['file_path']] = true;
    }
} catch (\Throwable $e) {
    echo "  [aviso] nao foi possivel ler message_attachments: " . $e->getMessage() . "\n";
}

$deleted = 0;
$keptByRef = 0;
$keptByAge = 0;
$bytes = 0;

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($uploadDir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);

foreach ($it as $file) {
    /** @var SplFileInfo $file */
    if (!$file->isFile() || $file->isLink()) {
        continue;
    }

    $mtime = (int) $file->getMTime();
    if ($mtime >= strtotime($limit)) {
        $keptByAge++;
        continue;
    }

    $relative = 'uploads/' . str_replace('\\', '/', substr($file->getPathname(), strlen($uploadDir) + 1));
    if (isset($referenced[$relative])) {
        $keptByRef++;
        continue;
    }

    $size = (int) $file->getSize();
    if ($dryRun) {
        echo "  [dry-run] apagaria: {$relative} (" . number_format($size / 1024, 1) . " KB)\n";
    } elseif (@unlink($file->getPathname())) {
        $deleted++;
        $bytes += $size;
    } else {
        echo "  [aviso] nao foi possivel apagar: {$relative}\n";
    }
}

// Remove subpastas vazias.
if (!$dryRun) {
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploadDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    ) as $dir) {
        if ($dir->isDir() && !glob($dir->getPathname() . '/*')) {
            @rmdir($dir->getPathname());
        }
    }
}

echo '[' . date('Y-m-d H:i:s') . "] Concluido: {$deleted} apagado(s), "
    . number_format($bytes / 1024 / 1024, 2) . " MB liberados, "
    . "{$keptByRef} mantidos por referencia, {$keptByAge} dentro da janela\n";
