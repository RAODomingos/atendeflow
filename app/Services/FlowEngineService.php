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

        if (in_array($currentNode['node_type'], ['menu', 'button_list', 'list_menu'], true)) {
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
            $this->dispatchOutboundMessage(
                $conversationId,
                'text',
                $node['config']['invalid_message'] ?? 'Opção inválida. Por favor, escolha uma opção válida:'
            );

            $optionsList = [];
            foreach ($node['options'] as $i => $opt) {
                $optionsList[] = ($i + 1) . ' - ' . $opt['label'];
            }
            $this->dispatchOutboundMessage($conversationId, 'text', implode("\n", $optionsList));

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
                $this->dispatchOutboundMessage($conversationId, 'text', $content);
                $this->goToNextNode($conversationId, $node);
                break;

            case 'menu':
                $content = $this->processTemplate($node['content'] ?? '', $conv);
                $this->dispatchOutboundMessage($conversationId, 'text', $content);

                $optionsList = [];
                foreach ($node['options'] as $i => $opt) {
                    $optionsList[] = ($i + 1) . ' - ' . $opt['label'];
                }
                $this->dispatchOutboundMessage($conversationId, 'text', implode("\n", $optionsList));

                Flow::saveFlowState($conversationId, $conv['flow_id'] ?? $node['flow_id'], $node['id']);
                break;

            case 'question':
                $content = $this->processTemplate($node['content'] ?? '', $conv);
                $this->dispatchOutboundMessage($conversationId, 'text', $content);
                Flow::saveFlowState($conversationId, $conv['flow_id'] ?? $node['flow_id'], $node['id']);
                break;

            case 'collect_field':
                $content = $this->processTemplate($node['content'] ?? '', $conv);
                $this->dispatchOutboundMessage($conversationId, 'text', $content);
                Flow::saveFlowState($conversationId, $conv['flow_id'] ?? $node['flow_id'], $node['id']);
                break;

            case 'button_list':
                $content = $this->processTemplate($node['content'] ?? '', $conv);
                $buttons = [];
                foreach ($node['options'] ?? [] as $opt) {
                    $buttons[] = ['id' => $opt['value'] ?? $opt['label'], 'label' => $opt['label']];
                }
                $this->dispatchOutboundMessage($conversationId, 'button_list', json_encode(['text' => $content, 'buttons' => $buttons], JSON_UNESCAPED_UNICODE));
                Flow::saveFlowState($conversationId, $conv['flow_id'] ?? $node['flow_id'], $node['id']);
                break;

            case 'list_menu':
                $content = $this->processTemplate($node['content'] ?? '', $conv);
                $items = [];
                foreach ($node['options'] ?? [] as $opt) {
                    $items[] = ['id' => $opt['value'] ?? $opt['label'], 'label' => $opt['label']];
                }
                $listTitle = $node['config']['list_title'] ?? 'Opções';
                $this->dispatchOutboundMessage($conversationId, 'list_menu', json_encode(['text' => $content, 'title' => $listTitle, 'items' => $items], JSON_UNESCAPED_UNICODE));
                Flow::saveFlowState($conversationId, $conv['flow_id'] ?? $node['flow_id'], $node['id']);
                break;

            case 'image':
            case 'audio':
            case 'video':
                $config = $node['config'] ?? [];
                $fileUrl = $config['file_url'] ?? '';
                if ($fileUrl) {
                    $this->dispatchOutboundMessage($conversationId, $node['node_type'], json_encode(['url' => $fileUrl], JSON_UNESCAPED_SLASHES));
                }
                $this->goToNextNode($conversationId, $node);
                break;

            case 'send_file':
                $config = $node['config'] ?? [];
                $fileUrl = $config['file_url'] ?? '';
                $fileName = $config['file_name'] ?? 'arquivo';
                if ($fileUrl) {
                    $this->dispatchOutboundMessage($conversationId, 'file', json_encode(['url' => $fileUrl, 'name' => $fileName], JSON_UNESCAPED_SLASHES));
                }
                $this->goToNextNode($conversationId, $node);
                break;

            case 'delay':
                $config = $node['config'] ?? [];
                $seconds = (int) ($config['seconds'] ?? 2);
                sleep($seconds);
                $this->goToNextNode($conversationId, $node);
                break;

            case 'condition':
                $config = $node['config'] ?? [];
                $variable = $config['variable'] ?? '';
                $expected = $config['expected'] ?? '';
                $matched = false;

                if ($variable && $expected) {
                    $answer = Flow::getLastAnswer($conversationId, $node['id']);
                    $fieldValue = $answer['answer_text'] ?? '';
                    if (mb_strtolower(trim($fieldValue)) === mb_strtolower(trim($expected))) {
                        $matched = true;
                    }
                }

                if ($matched && !empty($node['options'])) {
                    $nextNodeId = $node['options'][0]['next_node_id'];
                } else {
                    $nextNodeId = $node['options'][1]['next_node_id'] ?? ($node['options'][0]['next_node_id'] ?? null);
                }

                if ($nextNodeId) {
                    $condFlow = Flow::find($node['flow_id']);
                    if ($condFlow) {
                        foreach ($condFlow['nodes'] as $n) {
                            if ($n['id'] === $nextNodeId) {
                                Flow::saveFlowState($conversationId, $condFlow['id'], $nextNodeId);
                                $this->executeNode($conversationId, $n);
                                return;
                            }
                        }
                    }
                }
                Flow::completeFlowState($conversationId);
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
                $this->dispatchOutboundMessage($conversationId, 'text', $node['content'] ?? 'Um de nossos atendentes vai atender você em breve.');
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

    private function dispatchOutboundMessage(int $conversationId, string $type, string $content): int
    {
        $messageId = Conversation::addMessage($conversationId, [
            'type' => $type,
            'content' => $content,
            'direction' => 'outbound',
        ]);

        $conv = Conversation::find($conversationId);
        if ($conv && ($conv['channel_type'] ?? '') === 'whatsapp') {
            try {
                $service = new WhatsAppService();
                $service->sendOutbound($conversationId, $messageId, $type, $content);
            } catch (\Throwable $e) {
                error_log("FlowEngine WhatsApp outbound error: " . $e->getMessage());
            }
        }

        return $messageId;
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
