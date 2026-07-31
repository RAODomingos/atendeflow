<?php

namespace App\Models;

use App\Core\Database;

class Flow
{
    public static function find(int $id): ?array
    {
        $flow = Database::getInstance()->fetch(
            "SELECT f.*, u.name as created_by_name
             FROM flows f
             JOIN users u ON u.id = f.created_by
             WHERE f.id = ?",
            [$id]
        );
        if ($flow) {
            $flow['nodes'] = self::getNodes($id);
        }
        return $flow;
    }

    public static function all(): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT f.*, u.name as created_by_name,
                    (SELECT COUNT(*) FROM flow_nodes WHERE flow_id = f.id) as node_count
             FROM flows f
             JOIN users u ON u.id = f.created_by
             ORDER BY f.updated_at DESC"
        );
    }

    public static function create(array $data): int
    {
        return Database::getInstance()->insert('flows', $data);
    }

    public static function update(int $id, array $data): int
    {
        return Database::getInstance()->update('flows', $data, 'id = ?', [$id]);
    }

    public static function createNewVersion(int $flowId, int $userId): ?int
    {
        $flow = self::find($flowId);
        if (!$flow) return null;

        $latestVersion = Database::getInstance()->fetch(
            "SELECT MAX(version) as max_version FROM flows WHERE parent_flow_id = ? OR id = ?",
            [$flowId, $flowId]
        );
        $newVersion = ($latestVersion['max_version'] ?? 0) + 1;

        // Marcar versão anterior como não ativa
        Database::getInstance()->update('flows', ['is_active' => 0], 'id = ?', [$flowId]);

        $newFlowId = self::create([
            'name' => $flow['name'],
            'description' => $flow['description'],
            'channel_scope' => $flow['channel_scope'],
            'is_active' => 0, // Começa como draft
            'parent_flow_id' => $flowId,
            'version' => $newVersion,
            'created_by' => $userId,
        ]);

        // Copiar nós
        $nodeMap = [];
        foreach ($flow['nodes'] as $node) {
            $oldId = $node['id'];
            unset($node['id']);
            $node['flow_id'] = $newFlowId;
            $newId = self::addNode($newFlowId, $node);
            $nodeMap[$oldId] = $newId;

            foreach ($node['options'] as $opt) {
                unset($opt['id']);
                $opt['node_id'] = $newId;
                if ($opt['next_node_id'] && isset($nodeMap[$opt['next_node_id']])) {
                    $opt['next_node_id'] = $nodeMap[$opt['next_node_id']];
                }
                self::addOption($newId, $opt);
            }

            if ($node['node_type'] === 'start') {
                Database::getInstance()->update('flows', ['start_node_id' => $newId], 'id = ?', [$newFlowId]);
            }
        }

        return $newFlowId;
    }

    public static function publishVersion(int $flowId): void
    {
        $flow = self::find($flowId);
        if (!$flow) return;

        // Despublicar outras versões do mesmo fluxo
        if ($flow['parent_flow_id']) {
            Database::getInstance()->update(
                'flows',
                ['is_active' => 0],
                '(id = ? OR parent_flow_id = ?) AND id != ?',
                [$flow['parent_flow_id'], $flow['parent_flow_id'], $flowId]
            );
        } else {
            Database::getInstance()->update(
                'flows',
                ['is_active' => 0],
                'parent_flow_id = ? AND id != ?',
                [$flowId, $flowId]
            );
        }

        // Publicar esta versão
        Database::getInstance()->update('flows', ['is_active' => 1, 'is_draft' => 0], 'id = ?', [$flowId]);
    }

    public static function getVersions(int $parentFlowId): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT f.*, u.name as created_by_name
             FROM flows f
             JOIN users u ON u.id = f.created_by
             WHERE f.id = ? OR f.parent_flow_id = ?
             ORDER BY f.version DESC",
            [$parentFlowId, $parentFlowId]
        );
    }

    public static function delete(int $id): int
    {
        return Database::getInstance()->delete('flows', 'id = ?', [$id]);
    }

    public static function getNodes(int $flowId): array
    {
        $nodes = Database::getInstance()->fetchAll(
            "SELECT * FROM flow_nodes WHERE flow_id = ? ORDER BY position_y, position_x",
            [$flowId]
        );

        foreach ($nodes as &$node) {
            $node['options'] = Database::getInstance()->fetchAll(
                "SELECT * FROM flow_options WHERE node_id = ? ORDER BY sort_order",
                [$node['id']]
            );
            if ($node['config']) {
                $node['config'] = json_decode($node['config'], true);
            }
        }

        return $nodes;
    }

    public static function addNode(int $flowId, array $data): int
    {
        $data['flow_id'] = $flowId;
        $data['node_key'] ??= bin2hex(random_bytes(16));
        return Database::getInstance()->insert('flow_nodes', $data);
    }

    public static function updateNode(int $nodeId, array $data): int
    {
        return Database::getInstance()->update('flow_nodes', $data, 'id = ?', [$nodeId]);
    }

    public static function deleteNode(int $nodeId): int
    {
        return Database::getInstance()->delete('flow_nodes', 'id = ?', [$nodeId]);
    }

    public static function addOption(int $nodeId, array $data): int
    {
        $data['node_id'] = $nodeId;
        return Database::getInstance()->insert('flow_options', $data);
    }

    public static function updateOption(int $optionId, array $data): int
    {
        return Database::getInstance()->update('flow_options', $data, 'id = ?', [$optionId]);
    }

    public static function deleteOption(int $optionId): int
    {
        return Database::getInstance()->delete('flow_options', 'id = ?', [$optionId]);
    }

    public static function getActiveForChannel(string $channelType): ?array
    {
        return Database::getInstance()->fetch(
            "SELECT f.* FROM flows f
             WHERE f.is_active = 1
               AND (f.channel_scope = 'all' OR f.channel_scope = ?)
             ORDER BY f.priority DESC, f.updated_at DESC
             LIMIT 1",
            [$channelType]
        );
    }

    public static function getActiveFlowsForChannel(string $channelType): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT f.* FROM flows f
             WHERE f.is_active = 1
               AND (f.channel_scope = 'all' OR f.channel_scope = ?)
             ORDER BY f.priority DESC, f.updated_at DESC",
            [$channelType]
        );
    }

    public static function getBestFlowForChannel(string $channelType, ?array $context = null): ?array
    {
        $flows = self::getActiveFlowsForChannel($channelType);
        if (empty($flows)) {
            return null;
        }

        // Se não há contexto, retorna o fluxo de maior prioridade
        if (!$context) {
            return $flows[0];
        }

        // Avaliar critérios de seleção baseados no contexto
        foreach ($flows as $flow) {
            $config = $flow['config'] ?? [];
            $matches = true;

            // Verificar critérios de departamento
            if (!empty($config['department_id']) && !empty($context['department_id'])) {
                if ($config['department_id'] != $context['department_id']) {
                    $matches = false;
                }
            }

            // Verificar critérios de horário
            if (!empty($config['business_hours_only'])) {
                $hour = (int) date('H');
                $dayOfWeek = (int) date('w'); // 0 = domingo, 6 = sábado
                
                if ($dayOfWeek === 0 || $dayOfWeek === 6) {
                    $matches = false; // Fim de semana
                } elseif ($hour < 9 || $hour >= 18) {
                    $matches = false; // Fora do horário comercial
                }
            }

            // Verificar critérios customizados (tags, etc.)
            if (!empty($config['required_tags']) && !empty($context['tags'])) {
                $requiredTags = explode(',', $config['required_tags']);
                $contextTags = $context['tags'];
                foreach ($requiredTags as $tag) {
                    if (!in_array(trim($tag), $contextTags)) {
                        $matches = false;
                        break;
                    }
                }
            }

            if ($matches) {
                return $flow;
            }
        }

        // Fallback para o fluxo de maior prioridade se nenhum corresponder
        return $flows[0];
    }

    public static function getStartNode(int $flowId): ?array
    {
        return Database::getInstance()->fetch(
            "SELECT * FROM flow_nodes WHERE flow_id = ? AND node_type = 'start' LIMIT 1",
            [$flowId]
        );
    }

    public static function saveFlowState(int $conversationId, int $flowId, int $currentNodeId, ?string $timeoutAt = null): int
    {
        $existing = Database::getInstance()->fetch(
            "SELECT id FROM conversation_flow_states WHERE conversation_id = ? AND is_active = 1",
            [$conversationId]
        );

        $data = [
            'current_node_id' => $currentNodeId,
            'last_activity_at' => date('Y-m-d H:i:s'),
        ];
        
        if ($timeoutAt) {
            $data['timeout_at'] = $timeoutAt;
        }

        if ($existing) {
            Database::getInstance()->update(
                'conversation_flow_states',
                $data,
                'id = ?',
                [$existing['id']]
            );
            return $existing['id'];
        }

        $data['conversation_id'] = $conversationId;
        $data['flow_id'] = $flowId;
        
        return Database::getInstance()->insert('conversation_flow_states', $data);
    }

    public static function getActiveFlowState(int $conversationId): ?array
    {
        return Database::getInstance()->fetch(
            "SELECT fs.* FROM conversation_flow_states fs
             WHERE fs.conversation_id = ? AND fs.is_active = 1
             LIMIT 1",
            [$conversationId]
        );
    }

    public static function completeFlowState(int $conversationId): int
    {
        return Database::getInstance()->update(
            'conversation_flow_states',
            ['is_active' => 0, 'finished_at' => date('Y-m-d H:i:s')],
            'conversation_id = ? AND is_active = 1',
            [$conversationId]
        );
    }

    public static function saveAnswer(int $conversationId, int $nodeId, ?int $optionId = null, ?string $text = null): int
    {
        return Database::getInstance()->insert('flow_answers', [
            'conversation_id' => $conversationId,
            'flow_node_id' => $nodeId,
            'option_id' => $optionId,
            'answer_text' => $text,
        ]);
    }

    public static function getLastAnswer(int $conversationId, int $nodeId): ?array
    {
        return Database::getInstance()->fetch(
            "SELECT * FROM flow_answers WHERE conversation_id = ? AND flow_node_id = ? ORDER BY id DESC LIMIT 1",
            [$conversationId, $nodeId]
        );
    }

    public static function duplicate(int $flowId, int $newUserId): ?int
    {
        $flow = self::find($flowId);
        if (!$flow) return null;

        $newFlowId = self::create([
            'name' => $flow['name'] . ' (cópia)',
            'description' => $flow['description'],
            'channel_scope' => $flow['channel_scope'],
            'created_by' => $newUserId,
        ]);

        $nodeMap = [];
        foreach ($flow['nodes'] as $node) {
            $oldId = $node['id'];
            unset($node['id']);
            $node['flow_id'] = $newFlowId;
            $newId = self::addNode($newFlowId, $node);
            $nodeMap[$oldId] = $newId;

            foreach ($node['options'] as $opt) {
                unset($opt['id']);
                $opt['node_id'] = $newId;
                if ($opt['next_node_id'] && isset($nodeMap[$opt['next_node_id']])) {
                    $opt['next_node_id'] = $nodeMap[$opt['next_node_id']];
                }
                self::addOption($newId, $opt);
            }
        }

        return $newFlowId;
    }
}
