<?php

namespace App\Models;

use App\Core\Database;

class Macro
{
    public static function all(): array
    {
        $macros = Database::getInstance()->fetchAll(
            "SELECT * FROM macros ORDER BY title ASC LIMIT 200"
        );
        foreach ($macros as &$m) {
            $m['items'] = self::items((int) $m['id']);
        }
        return $macros;
    }

    public static function getForContext(?int $departmentId, ?int $userId): array
    {
        $sql = "SELECT * FROM macros WHERE 1=1";
        $params = [];
        if ($departmentId) {
            $sql .= " AND (department_id IS NULL OR department_id = ?)";
            $params[] = $departmentId;
        }
        if ($userId) {
            $sql .= " AND (user_id IS NULL OR user_id = ?)";
            $params[] = $userId;
        }
        $sql .= " ORDER BY title ASC LIMIT 200";
        $macros = Database::getInstance()->fetchAll($sql, $params);
        foreach ($macros as &$m) {
            $m['items'] = self::items((int) $m['id']);
            // Resumo p/ listagem rápida no painel (ex.: "2 msgs + 1 foto")
            $m['items_summary'] = self::summarize($m['items'], $m['content'] ?? null);
        }
        return $macros;
    }

    public static function find(int $id): ?array
    {
        $macro = Database::getInstance()->fetch("SELECT * FROM macros WHERE id = ?", [$id]);
        if ($macro) {
            $macro['items'] = self::items($id);
        }
        return $macro;
    }

    public static function create(array $data): int
    {
        return Database::getInstance()->insert('macros', $data);
    }

    public static function update(int $id, array $data): int
    {
        return Database::getInstance()->update('macros', $data, 'id = ?', [$id]);
    }

    public static function delete(int $id): int
    {
        return Database::getInstance()->delete('macros', 'id = ?', [$id]);
    }

    /**
     * Itens ordenados da macro (multi-mensagens + mídia).
     * Retorna [] quando a tabela ainda não existe (instalação antiga sem migration).
     */
    public static function items(int $macroId): array
    {
        try {
            return Database::getInstance()->fetchAll(
                "SELECT * FROM macro_items WHERE macro_id = ? ORDER BY position ASC, id ASC",
                [$macroId]
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function replaceItems(int $macroId, array $items): void
    {
        $db = Database::getInstance();
        try {
            $db->query("DELETE FROM macro_items WHERE macro_id = ?", [$macroId]);
        } catch (\Throwable $e) {
            return;
        }
        $pos = 0;
        foreach ($items as $it) {
            $type = strtolower(trim((string) ($it['type'] ?? 'text')));
            if (!in_array($type, ['text', 'image', 'video', 'audio', 'file'], true)) {
                $type = 'text';
            }
            $content = trim((string) ($it['content'] ?? ''));
            $mediaUrl = trim((string) ($it['media_url'] ?? ''));
            // Pula itens vazios (sem texto e sem mídia)
            if ($content === '' && $mediaUrl === '') {
                continue;
            }
            // Texto sem mídia vira text; mídia sem type explícito infere pela extensão/mime
            if ($mediaUrl !== '' && $type === 'text' && $content === '') {
                $type = 'file';
            }
            $db->insert('macro_items', [
                'macro_id' => $macroId,
                'position' => $pos++,
                'type' => $type,
                'content' => $content !== '' ? $content : null,
                'media_url' => $mediaUrl !== '' ? $mediaUrl : null,
                'media_name' => !empty($it['media_name']) ? $it['media_name'] : null,
                'media_mime' => !empty($it['media_mime']) ? $it['media_mime'] : null,
                'media_size' => isset($it['media_size']) ? (int) $it['media_size'] : 0,
                'media_path' => !empty($it['media_path']) ? $it['media_path'] : null,
            ]);
        }
    }

    /**
     * Resumo legível dos itens p/ UI (ex.: "3 mensagens • 1 foto + 1 vídeo").
     */
    public static function summarize(array $items, ?string $legacyContent = null): string
    {
        if (empty($items)) {
            if (!empty(trim((string) $legacyContent))) {
                return '1 mensagem de texto';
            }
            return 'Só ações (sem mensagem)';
        }
        $texts = 0;
        $media = ['image' => 0, 'video' => 0, 'audio' => 0, 'file' => 0];
        foreach ($items as $it) {
            $t = $it['type'] ?? 'text';
            if ($t === 'text') {
                $texts++;
            } elseif (isset($media[$t])) {
                $media[$t]++;
            }
        }
        $labels = ['image' => 'foto', 'video' => 'vídeo', 'audio' => 'áudio', 'file' => 'arquivo'];
        $parts = [];
        if ($texts > 0) {
            $parts[] = $texts . ($texts === 1 ? ' mensagem' : ' mensagens');
        }
        foreach ($labels as $k => $label) {
            if ($media[$k] > 0) {
                $parts[] = $media[$k] . ' ' . $label . ($media[$k] > 1 ? 's' : ($k === 'image' ? '' : ''));
            }
        }
        return implode(' + ', $parts);
    }
}
