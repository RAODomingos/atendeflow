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
             ORDER BY f.updated_at DESC
             LIMIT 1",
            [$channelType]
        );
    }

    public static function getStartNode(int $flowId): ?array
    {
        return Database::getInstance()->fetch(
            "SELECT * FROM flow_nodes WHERE flow_id = ? AND node_type = 'start' LIMIT 1",
            [$flowId]
        );
    }

    public static function saveFlowState(int $conversationId, int $flowId, int $currentNodeId): int
    {
        $existing = Database::getInstance()->fetch(
            "SELECT id FROM conversation_flow_states WHERE conversation_id = ? AND is_active = 1",
            [$conversationId]
        );

        if ($existing) {
            Database::getInstance()->update(
                'conversation_flow_states',
                ['current_node_id' => $currentNodeId],
                'id = ?',
                [$existing['id']]
            );
            return $existing['id'];
        }

        return Database::getInstance()->insert('conversation_flow_states', [
            'conversation_id' => $conversationId,
            'flow_id' => $flowId,
            'current_node_id' => $currentNodeId,
        ]);
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
