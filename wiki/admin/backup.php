<?php
// ============================================================
// admin/backup.php - Automatic Backup System
// ============================================================
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
requireLogin();

$db = getDB();

// Create backup function
function createBackup($type = 'manual') {
    global $db;
    
    try {
        $backupDir = __DIR__ . '/../backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        $timestamp = date('Y-m-d_H-i-s');
        $filename = "backup_{$timestamp}.json";
        $filepath = $backupDir . '/' . $filename;
        
        // Get all data
        $backup = [];
        
        // Backup tables
        $tables = ['articles', 'categories', 'users', 'notifications', 'article_views'];
        foreach ($tables as $table) {
            $stmt = $db->query("SELECT * FROM {$table}");
            $backup[$table] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Add metadata
        $backup['metadata'] = [
            'created_at' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'type' => $type,
            'tables' => $tables
        ];
        
        // Save backup
        $jsonBackup = json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($filepath, $jsonBackup);
        
        // Log backup
        $fileSize = filesize($filepath);
        $stmt = $db->prepare('INSERT INTO backup_logs (filename, file_size, backup_type, status) VALUES (?, ?, ?, ?)');
        $stmt->execute([$filename, $fileSize, $type, 'success']);
        
        return [
            'success' => true,
            'filename' => $filename,
            'size' => $fileSize,
            'path' => $filepath
        ];
        
    } catch (Exception $e) {
        // Log error
        $stmt = $db->prepare('INSERT INTO backup_logs (filename, backup_type, status) VALUES (?, ?, ?)');
        $stmt->execute(['error', $type, 'failed']);
        
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// Restore backup function
function restoreBackup($filename) {
    global $db;
    
    try {
        $backupDir = __DIR__ . '/../backups';
        $filepath = $backupDir . '/' . $filename;
        
        if (!file_exists($filepath)) {
            throw new Exception('Arquivo de backup não encontrado');
        }
        
        $backup = json_decode(file_get_contents($filepath), true);
        
        if (!$backup || !isset($backup['metadata'])) {
            throw new Exception('Formato de backup inválido');
        }
        
        // Start transaction
        $db->beginTransaction();
        
        try {
            // Clear existing data (except users for safety)
            $db->exec('DELETE FROM article_views');
            $db->exec('DELETE FROM notifications');
            $db->exec('DELETE FROM articles');
            $db->exec('DELETE FROM categories');
            
            // Restore data
            foreach ($backup as $table => $data) {
                if ($table === 'metadata') continue;
                
                if (!empty($data)) {
                    $columns = array_keys($data[0]);
                    $placeholders = str_repeat('?,', count($columns) - 1) . '?';
                    
                    $sql = "INSERT INTO {$table} (" . implode(',', $columns) . ") VALUES ({$placeholders})";
                    $stmt = $db->prepare($sql);
                    
                    foreach ($data as $row) {
                        $stmt->execute(array_values($row));
                    }
                }
            }
            
            $db->commit();
            
            return [
                'success' => true,
                'message' => 'Backup restaurado com sucesso'
            ];
            
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// Handle actions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'backup':
            $result = createBackup('manual');
            if ($result['success']) {
                $message = "Backup criado com sucesso: {$result['filename']} (" . formatBytes($result['size']) . ")";
            } else {
                $error = "Erro ao criar backup: " . $result['error'];
            }
            break;
            
        case 'restore':
            $filename = $_POST['filename'] ?? '';
            if ($filename) {
                $result = restoreBackup($filename);
                if ($result['success']) {
                    $message = $result['message'];
                } else {
                    $error = "Erro ao restaurar backup: " . $result['error'];
                }
            }
            break;
            
        case 'delete':
            $filename = $_POST['filename'] ?? '';
            if ($filename) {
                $backupDir = __DIR__ . '/../backups';
                $filepath = $backupDir . '/' . $filename;
                
                if (file_exists($filepath)) {
                    unlink($filepath);
                    $db->prepare('DELETE FROM backup_logs WHERE filename = ?')->execute([$filename]);
                    $message = "Backup excluído com sucesso";
                } else {
                    $error = "Arquivo de backup não encontrado";
                }
            }
            break;
    }
}

// Get backup history
$backups = $db->query('SELECT * FROM backup_logs ORDER BY created_at DESC LIMIT 20')->fetchAll();

$pageTitle = 'Backup do Sistema';
require '_header.php';
?>

<div class="backup-container">
  <div class="backup-header">
    <h1 class="backup-title">Backup do Sistema</h1>
    <div class="backup-actions">
      <button class="btn btn-primary" id="createBackup">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
          <polyline points="17,21 17,13 7,13 7,21"/>
          <polyline points="7,3 7,8 15,8"/>
        </svg>
        Criar Backup
      </button>
    </div>
  </div>

  <?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>
  
  <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="backup-grid">
    <div class="backup-section">
      <div class="panel">
        <div class="panel-header">
          <h2 class="panel-title">Informações do Sistema</h2>
        </div>
        <div class="system-info">
          <div class="info-item">
            <div class="info-label">Total de Artigos</div>
            <div class="info-value"><?= $db->query('SELECT COUNT(*) FROM articles')->fetchColumn() ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Total de Categorias</div>
            <div class="info-value"><?= $db->query('SELECT COUNT(*) FROM categories')->fetchColumn() ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Total de Usuários</div>
            <div class="info-value"><?= $db->query('SELECT COUNT(*) FROM users')->fetchColumn() ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Último Backup</div>
            <div class="info-value">
              <?php
              $lastBackup = $db->query('SELECT created_at FROM backup_logs WHERE status = "success" ORDER BY created_at DESC LIMIT 1')->fetchColumn();
              echo $lastBackup ? date('d/m/Y H:i', strtotime($lastBackup)) : 'Nunca';
              ?>
            </div>
          </div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-header">
          <h2 class="panel-title">Configurações Automáticas</h2>
        </div>
        <div class="backup-settings">
          <div class="setting-item">
            <label class="setting-label">
              <input type="checkbox" id="autoBackup" />
              Backup automático diário
            </label>
            <div class="setting-description">
              Cria automaticamente um backup todos os dias às 02:00
            </div>
          </div>
          <div class="setting-item">
            <label class="setting-label">
              <input type="number" id="retentionDays" value="30" min="1" max="365" />
              Dias de retenção
            </label>
            <div class="setting-description">
              Remove backups antigos após este período
            </div>
          </div>
          <button class="btn btn-outline btn-sm">Salvar Configurações</button>
        </div>
      </div>
    </div>

    <div class="backup-section">
      <div class="panel">
        <div class="panel-header">
          <h2 class="panel-title">Histórico de Backups</h2>
        </div>
        <div class="backup-list">
          <?php if (empty($backups)): ?>
          <div class="empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/>
              <polyline points="13,2 13,9 20,9"/>
            </svg>
            <h3>Nenhum backup encontrado</h3>
            <p>Crie seu primeiro backup usando o botão acima.</p>
          </div>
          <?php else: ?>
            <?php foreach ($backups as $backup): ?>
            <div class="backup-item <?= $backup['status'] === 'success' ? 'success' : 'error' ?>">
              <div class="backup-info">
                <div class="backup-name"><?= htmlspecialchars($backup['filename']) ?></div>
                <div class="backup-meta">
                  <span class="backup-date"><?= date('d/m/Y H:i', strtotime($backup['created_at'])) ?></span>
                  <?php if ($backup['file_size']): ?>
                    <span class="backup-size"><?= formatBytes($backup['file_size']) ?></span>
                  <?php endif; ?>
                  <span class="backup-type"><?= $backup['backup_type'] === 'manual' ? 'Manual' : 'Automático' ?></span>
                </div>
              </div>
              <div class="backup-actions">
                <?php if ($backup['status'] === 'success'): ?>
                <button class="btn btn-xs btn-outline restore-btn" data-filename="<?= htmlspecialchars($backup['filename']) ?>">
                  Restaurar
                </button>
                <button class="btn btn-xs btn-outline download-btn" data-filename="<?= htmlspecialchars($backup['filename']) ?>">
                  Download
                </button>
                <?php endif; ?>
                <button class="btn btn-xs btn-danger delete-btn" data-filename="<?= htmlspecialchars($backup['filename']) ?>">
                  Excluir
                </button>
              </div>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Restore confirmation modal -->
<div id="restoreModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3>Confirmar Restauração</h3>
      <button class="modal-close">&times;</button>
    </div>
    <div class="modal-body">
      <p>Tem certeza que deseja restaurar este backup?</p>
      <p><strong>Atenção:</strong> Esta ação irá substituir todos os dados atuais.</p>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline modal-close">Cancelar</button>
      <button class="btn btn-danger" id="confirmRestore">Restaurar</button>
    </div>
  </div>
</div>

<style>
.backup-container {
  max-width: 1200px;
  margin: 0 auto;
}

.backup-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 24px;
  padding-bottom: 16px;
  border-bottom: 1px solid var(--border);
}

.backup-title {
  font-size: 24px;
  font-weight: 600;
  color: var(--ink);
}

.backup-grid {
  display: grid;
  grid-template-columns: 1fr 2fr;
  gap: 24px;
}

.backup-section {
  display: flex;
  flex-direction: column;
  gap: 24px;
}

.system-info {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.info-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px;
  background: var(--bg);
  border-radius: 8px;
  border: 1px solid var(--border);
}

.info-label {
  color: var(--ink-3);
  font-size: 14px;
}

.info-value {
  font-weight: 600;
  color: var(--ink);
}

.backup-settings {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.setting-item {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.setting-label {
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 500;
  color: var(--ink);
}

.setting-description {
  font-size: 12px;
  color: var(--ink-3);
  margin-left: 24px;
}

.backup-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.backup-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 16px;
  background: var(--white);
  border: 1px solid var(--border);
  border-radius: 12px;
}

.backup-item.success {
  border-left: 4px solid var(--green);
}

.backup-item.error {
  border-left: 4px solid var(--red);
}

.backup-name {
  font-weight: 600;
  color: var(--ink);
  margin-bottom: 4px;
}

.backup-meta {
  display: flex;
  gap: 12px;
  font-size: 12px;
  color: var(--ink-3);
}

.backup-actions {
  display: flex;
  gap: 8px;
}

.empty-state {
  text-align: center;
  padding: 60px 20px;
  color: var(--ink-3);
}

.empty-state svg {
  width: 64px;
  height: 64px;
  margin-bottom: 16px;
  opacity: 0.5;
}

@media (max-width: 768px) {
  .backup-grid {
    grid-template-columns: 1fr;
  }
  
  .backup-item {
    flex-direction: column;
    gap: 12px;
    align-items: flex-start;
  }
  
  .backup-actions {
    width: 100%;
    justify-content: flex-end;
  }
}
</style>

<script>
// Backup system JavaScript
class BackupManager {
  constructor() {
    this.init();
  }

  init() {
    this.setupEventListeners();
    this.setupModal();
  }

  setupEventListeners() {
    // Create backup
    document.getElementById('createBackup')?.addEventListener('click', () => {
      this.createBackup();
    });

    // Restore backup
    document.addEventListener('click', (e) => {
      if (e.target.classList.contains('restore-btn')) {
        this.showRestoreModal(e.target.dataset.filename);
      }
      
      if (e.target.classList.contains('download-btn')) {
        this.downloadBackup(e.target.dataset.filename);
      }
      
      if (e.target.classList.contains('delete-btn')) {
        this.deleteBackup(e.target.dataset.filename);
      }
    });
  }

  setupModal() {
    const modal = document.getElementById('restoreModal');
    const closeBtns = modal.querySelectorAll('.modal-close');
    const confirmBtn = document.getElementById('confirmRestore');

    closeBtns.forEach(btn => {
      btn.addEventListener('click', () => this.hideModal());
    });

    confirmBtn.addEventListener('click', () => {
      const filename = confirmBtn.dataset.filename;
      if (filename) {
        this.restoreBackup(filename);
      }
    });

    modal.addEventListener('click', (e) => {
      if (e.target === modal) {
        this.hideModal();
      }
    });
  }

  async createBackup() {
    const btn = document.getElementById('createBackup');
    const originalText = btn.innerHTML;
    
    btn.innerHTML = '<span>Criando...</span>';
    btn.disabled = true;

    try {
      const response = await fetch('backup.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=backup'
      });

      if (response.ok) {
        location.reload();
      } else {
        throw new Error('Erro ao criar backup');
      }
    } catch (error) {
      console.error('Error creating backup:', error);
      btn.innerHTML = originalText;
      btn.disabled = false;
    }
  }

  showRestoreModal(filename) {
    const modal = document.getElementById('restoreModal');
    const confirmBtn = document.getElementById('confirmRestore');
    
    confirmBtn.dataset.filename = filename;
    modal.style.display = 'flex';
  }

  hideModal() {
    const modal = document.getElementById('restoreModal');
    modal.style.display = 'none';
  }

  async restoreBackup(filename) {
    try {
      const response = await fetch('backup.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=restore&filename=${filename}`
      });

      if (response.ok) {
        location.reload();
      } else {
        throw new Error('Erro ao restaurar backup');
      }
    } catch (error) {
      console.error('Error restoring backup:', error);
    }
  }

  downloadBackup(filename) {
    window.open(`/backups/${filename}`, '_blank');
  }

  async deleteBackup(filename) {
    if (!confirm('Tem certeza que deseja excluir este backup?')) {
      return;
    }

    try {
      const response = await fetch('backup.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=delete&filename=${filename}`
      });

      if (response.ok) {
        location.reload();
      } else {
        throw new Error('Erro ao excluir backup');
      }
    } catch (error) {
      console.error('Error deleting backup:', error);
    }
  }
}

// Helper function to format bytes
function formatBytes(bytes) {
  if (bytes === 0) return '0 Bytes';
  const k = 1024;
  const sizes = ['Bytes', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// Initialize backup manager
document.addEventListener('DOMContentLoaded', () => {
  new BackupManager();
});
</script>

<?php require '_footer.php'; ?>
