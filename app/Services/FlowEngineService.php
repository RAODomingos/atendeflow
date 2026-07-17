<?php

namespace App\Services;

use App\Core\Database;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\Contact;
use App\Models\Department;

class FlowEngineService
{
    public function start(int $conversationId, int $flowId): void
    {
        $flow = Flow::find($flowId);
        if (!$flow || !$flow['is_active']) return;

        $startNode = null;
        foreach ($flow['nodes'] as $node) {
            if ($node['node_type'] === 'start') {
                $startNode = $node;
                break;
            }
        }

        if (!$startNode) return;

        Flow::saveFlowState($conversationId, $flowId, $startNode['id']);

        Conversation::addEvent($conversationId, 'flow_started', "Fluxo '{$flow['name']}' iniciado");

        $this->executeNode($conversationId, $startNode);
    }

    public function handleCustomerMessage(int $conversationId, string $messageText): void
    {
        $flowState = Flow::getActiveFlowState($conversationId);
        if (!$flowState) return;

        $flow = Flow::find($flowState['flow_id']);
        if (!$flow) return;

        $currentNode = null;
        foreach ($flow['nodes'] as $node) {
            if ($node['id'] === $flowState['current_node_id']) {
                $currentNode = $node;
                break;
            }
        }

        if (!$currentNode) {
            Flow::completeFlowState($conversationId);
            return;
        }

        if ($currentNode['node_type'] === 'menu') {
            $this->handleMenuResponse($conversationId, $currentNode, $messageText, $flow);
        } elseif ($currentNode['node_type'] === 'question') {
            $this->handleQuestionResponse($conversationId, $currentNode, $messageText, $flow);
        }
    }

    private function handleMenuResponse(int $conversationId, array $node, string $response, array $flow): void
    {
        $conv = Conversation::find($conversationId);
        if (!$conv) return;

        $selectedOption = null;

        foreach ($node['options'] as $option) {
            $label = mb_strtolower(trim($option['label']));
            $resp = mb_strtolower(trim($response));

            if ($label === $resp || $option['value'] === $resp || $option['sort_order'] + 1 === (int) $resp) {
                $selectedOption = $option;
                break;
            }
        }

        if (!$selectedOption) {
            Conversation::addMessage($conversationId, [
                'type' => 'text',
                'content' => $node['config']['invalid_message'] ?? 'Opção inválida. Por favor, escolha uma opção válida:',
                'direction' => 'outbound',
            ]);

            $optionsList = [];
            foreach ($node['options'] as $i => $opt) {
                $optionsList[] = ($i + 1) . ' - ' . $opt['label'];
            }
            Conversation::addMessage($conversationId, [
                'type' => 'text',
                'content' => implode("\n", $optionsList),
                'direction' => 'outbound',
            ]);

            return;
        }

        Flow::saveAnswer($conversationId, $node['id'], $selectedOption['id']);

        $nextNodeId = $selectedOption['next_node_id'];

        if ($node['config']['auto_advance'] ?? true) {
            if ($nextNodeId) {
                $nextNode = null;
                foreach ($flow['nodes'] as $n) {
                    if ($n['id'] === $nextNodeId) {
                        $nextNode = $n;
                        break;
                    }
                }
                if ($nextNode) {
                    Flow::saveFlowState($conversationId, $flow['id'], $nextNode['id']);
                    $this->executeNode($conversationId, $nextNode);
                }
            }
        }
    }

    private function handleQuestionResponse(int $conversationId, array $node, string $response, array $flow): void
    {
        Flow::saveAnswer($conversationId, $node['id'], null, $response);

        $config = $node['config'] ?? [];

        if (!empty($config['save_field'])) {
            $field = $config['save_field'];
            $contactId = Database::getInstance()->fetch(
                "SELECT contact_id FROM conversations WHERE id = ?",
                [$conversationId]
            )['contact_id'] ?? null;

            if ($contactId) {
                if (in_array($field, ['name', 'email', 'phone', 'company', 'document', 'notes'])) {
                    Contact::update($contactId, [$field => $response]);
                }
            }
        }

        $nextNodeId = $config['next_node_id'] ?? ($node['options'][0]['next_node_id'] ?? null);

        if ($nextNodeId) {
            $nextNode = null;
            foreach ($flow['nodes'] as $n) {
                if ($n['id'] === $nextNodeId) {
                    $nextNode = $n;
                    break;
                }
            }
            if ($nextNode) {
                Flow::saveFlowState($conversationId, $flow['id'], $nextNode['id']);
                $this->executeNode($conversationId, $nextNode);
            }
        }
    }

    public function executeNode(int $conversationId, array $node): void
    {
        $conv = Conversation::find($conversationId);
        if (!$conv) return;

        switch ($node['node_type']) {
            case 'start':
                $this->goToNextNode($conversationId, $node);
                break;

            case 'message':
                $content = $this->processTemplate($node['content'] ?? '', $conv);
                Conversation::addMessage($conversationId, [
                    'type' => 'text',
                    'content' => $content,
                    'direction' => 'outbound',
                ]);
                $this->goToNextNode($conversationId, $node);
                break;

            case 'menu':
                $content = $this->processTemplate($node['content'] ?? '', $conv);
                Conversation::addMessage($conversationId, [
                    'type' => 'text',
                    'content' => $content,
                    'direction' => 'outbound',
                ]);

                $optionsList = [];
                foreach ($node['options'] as $i => $opt) {
                    $optionsList[] = ($i + 1) . ' - ' . $opt['label'];
                }
                Conversation::addMessage($conversationId, [
                    'type' => 'text',
                    'content' => implode("\n", $optionsList),
                    'direction' => 'outbound',
                ]);

                Flow::saveFlowState($conversationId, $conv['flow_id'] ?? $node['flow_id'], $node['id']);
                break;

            case 'question':
                $content = $this->processTemplate($node['content'] ?? '', $conv);
                Conversation::addMessage($conversationId, [
                    'type' => 'text',
                    'content' => $content,
                    'direction' => 'outbound',
                ]);
                Flow::saveFlowState($conversationId, $conv['flow_id'] ?? $node['flow_id'], $node['id']);
                break;

            case 'collect_field':
                $content = $this->processTemplate($node['content'] ?? '', $conv);
                Conversation::addMessage($conversationId, [
                    'type' => 'text',
                    'content' => $content,
                    'direction' => 'outbound',
                ]);
                Flow::saveFlowState($conversationId, $conv['flow_id'] ?? $node['flow_id'], $node['id']);
                break;

            case 'assign_department':
                $config = $node['config'] ?? [];
                $departmentId = $config['department_id'] ?? null;
                if ($departmentId) {
                    Conversation::update($conversationId, ['department_id' => $departmentId]);
                    $dept = Department::find($departmentId);
                    Conversation::addEvent($conversationId, 'department_changed',
                        "Departamento definido como: {$dept['name']}");
                }
                $this->goToNextNode($conversationId, $node);
                break;

            case 'assign_user':
                $config = $node['config'] ?? [];
                $userId = $config['user_id'] ?? null;
                if ($userId) {
                    Conversation::update($conversationId, ['assigned_user_id' => $userId]);
                    Conversation::addEvent($conversationId, 'assigned', "Atendimento atribuído automaticamente pelo fluxo", $userId);
                }
                $this->goToNextNode($conversationId, $node);
                break;

            case 'add_tag':
                $config = $node['config'] ?? [];
                if (!empty($config['tag_id'])) {
                    Database::getInstance()->insert('conversation_tags', [
                        'conversation_id' => $conversationId,
                        'tag_id' => $config['tag_id'],
                    ]);
                    Conversation::addEvent($conversationId, 'tag_added', "Etiqueta adicionada pelo fluxo");
                }
                $this->goToNextNode($conversationId, $node);
                break;

            case 'handoff':
                Conversation::addMessage($conversationId, [
                    'type' => 'text',
                    'content' => $node['content'] ?? 'Um de nossos atendentes vai atender você em breve.',
                    'direction' => 'outbound',
                ]);
                Conversation::update($conversationId, ['status' => 'new']);
                Flow::completeFlowState($conversationId);
                Conversation::addEvent($conversationId, 'flow_completed', 'Fluxo finalizado, encaminhado para atendimento humano');
                break;

            case 'end':
                Flow::completeFlowState($conversationId);
                Conversation::addEvent($conversationId, 'flow_completed', 'Fluxo finalizado');
                break;
        }
    }

    private function goToNextNode(int $conversationId, array $node): void
    {
        if (!empty($node['options'])) {
            $nextNodeId = $node['options'][0]['next_node_id'];
            if ($nextNodeId) {
                $flow = Flow::find($node['flow_id']);
                if ($flow) {
                    foreach ($flow['nodes'] as $n) {
                        if ($n['id'] === $nextNodeId) {
                            Flow::saveFlowState($conversationId, $flow['id'], $nextNodeId);
                            $this->executeNode($conversationId, $n);
                            return;
                        }
                    }
                }
            }
        }

        Flow::completeFlowState($conversationId);
    }

    private function processTemplate(string $content, array $conversation): string
    {
        $replacements = [
            '{nome}' => $conversation['contact_name'] ?? 'Cliente',
            '{email}' => $conversation['contact_email'] ?? '',
            '{telefone}' => $conversation['contact_phone'] ?? '',
            '{departamento}' => $conversation['department_name'] ?? '',
            '{atendente}' => $conversation['assigned_user_name'] ?? 'Atendente',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }
}
