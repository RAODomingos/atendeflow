<?php

namespace App\Services;

use App\Core\Database;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\Contact;
use App\Models\Department;

class FlowEngineService
{
    /**
     * Seam de teste: subclasses podem devolver um GuildService stubado.
     */
    protected function guildService(): GuildService
    {
        return new GuildService();
    }

    /**
     * Normaliza referência de nó vinda do builder ('node_5' ou 5) para int.
     */
    private function guildNodeRef(mixed $v): int
    {
        return (int) preg_replace('/\D+/', '', (string) $v);
    }

    private function logExecution(int $conversationId, int $flowId, int $nodeId, string $eventType, ?array $data = null): void
    {
        Database::getInstance()->insert('flow_execution_logs', [
            'conversation_id' => $conversationId,
            'flow_id' => $flowId,
            'node_id' => $nodeId,
            'event_type' => $eventType,
            'event_data' => $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    private function getSetting(string $key, string $default = ''): string
    {
        $setting = Database::getInstance()->fetch("SELECT value FROM flow_settings WHERE key_name = ?", [$key]);
        return $setting['value'] ?? $default;
    }

    /**
     * Monta o array meta (url, name, mime, path) de um nó de mídia a partir da
     * config do nó. Suporta:
     *  - URL externa (https://...) → só url, Uazapi busca ela
     *  - Upload local (uploads/... ou /atendeflow/uploads/...) → path relativo
     *    para o disco + url pública (a Uazapi usa o path para base64)
     */
    private function buildMediaMetaFromFlowConfig(string $fileUrl, array $config): array
    {
        $meta = ['url' => $fileUrl];

        if (!empty($config['file_name'])) {
            $meta['name'] = $config['file_name'];
        }
        if (!empty($config['mime']) || !empty($config['mimetype'])) {
            $meta['mime'] = $config['mime'] ?? $config['mimetype'];
        }

        // O frontend salva file_path (caminho local relativo, ex.: 'flows/abc.webm')
        // sempre que o arquivo foi enviado do servidor local. Ele TEM prioridade:
        // mesmo que file_url seja a URL pública completa (https://...), a Uazapi
        // (servidor remoto) não consegue acessar a URL local — então enviamos o
        // arquivo do disco em base64 via path.
        if (!empty($config['file_path']) && is_string($config['file_path'])) {
            $meta['path'] = $config['file_path'];
        } elseif (!$this->isExternalUrl($fileUrl)) {
            // Sem file_path salvo e URL não-externa (ex.: 'uploads/...'): tenta
            // derivar o caminho local a partir do próprio file_url.
            $path = $this->flowFileUrlToLocalPath($fileUrl, $config);
            if ($path !== null) {
                $meta['path'] = $path;
            }
        }

        return $meta;
    }

    private function isExternalUrl(string $url): bool
    {
        return (bool) preg_match('#^https?://#i', $url);
    }

    /**
     * Converte o file_url (que pode estar como "uploads/flows/abc.webm" ou
     * "/atendeflow/uploads/flows/abc.webm" ou até a URL pública completa) em
     * um caminho relativo que o UazapiProvider::resolveLocalFile entende.
     *
     * Também usa o config.file_path se o frontend salvou explicitamente
     * (caminho puro retornado pelo backend de upload).
     */
    private function flowFileUrlToLocalPath(string $fileUrl, array $config): ?string
    {
        if (!empty($config['file_path']) && is_string($config['file_path'])) {
            return $config['file_path'];
        }

        // Remove scheme/host se a URL vier completa
        $path = parse_url($fileUrl, PHP_URL_PATH) ?: $fileUrl;

        // Remove prefixos comuns (/atendeflow/uploads/, /uploads/, /)
        $path = ltrim($path, '/');
        if (stripos($path, 'atendeflow/') === 0) {
            $path = substr($path, strlen('atendeflow/'));
        }
        if (stripos($path, 'uploads/') === 0) {
            $path = substr($path, strlen('uploads/'));
        }

        // Verifica se o arquivo existe em public/uploads/<path>
        $candidate = dirname(__DIR__, 2) . '/public/uploads/' . $path;
        if (is_file($candidate)) {
            return $path;
        }

        // Verifica em <docroot>/<path> (caso o upload tenha ido para outro lugar)
        $candidate = dirname(__DIR__, 2) . '/' . $path;
        if (is_file($candidate)) {
            return $path;
        }

        return null;
    }

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

        $timeoutMinutes = (int) $this->getSetting('flow_timeout_minutes', '30');
        $timeoutAt = date('Y-m-d H:i:s', strtotime("+{$timeoutMinutes} minutes"));
        
        Flow::saveFlowState($conversationId, $flowId, $startNode['id'], $timeoutAt);

        Conversation::addEvent($conversationId, 'flow_started', "Fluxo '{$flow['name']}' iniciado");
        $this->logExecution($conversationId, $flowId, $startNode['id'], 'flow_started', ['flow_name' => $flow['name']]);

        $this->executeNode($conversationId, $startNode);
    }

    public function handleCustomerMessage(int $conversationId, string $messageText): void
    {
        $flowState = Flow::getActiveFlowState($conversationId);
        if (!$flowState) return;

        // Verificar timeout
        if ($flowState['timeout_at'] && new \DateTime($flowState['timeout_at']) < new \DateTime()) {
            $this->handleTimeout($conversationId, $flowState);
            return;
        }

        // Atualizar atividade
        Database::getInstance()->update(
            'conversation_flow_states',
            ['last_activity_at' => date('Y-m-d H:i:s')],
            'id = ?',
            [$flowState['id']]
        );

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
        } elseif ($currentNode['node_type'] === 'guild_select') {
            $this->handleGuildSelectResponse($conversationId, $currentNode, $messageText, $flow);
        } elseif (in_array($currentNode['node_type'], ['question', 'collect_field'], true)) {
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

            $this->dispatchMenuPresentation($conversationId, $node, $conv);

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

    /**
     * Nó dinâmico Loja/Unidade Guild: ramifica pelo que o contato tem.
     * 0 lojas => executa `no_store_node_id`. 1 loja => lista unidades.
     * N lojas => lista lojas, depois unidades da escolhida.
     */
    private function executeGuildSelect(int $conversationId, array $node, array $conv): void
    {
        $flowId = (int) ($conv['flow_id'] ?? $node['flow_id']);
        $contactId = (int) ($conv['contact_id'] ?? 0);
        $stores = $contactId > 0 ? Contact::getStores($contactId) : [];

        if ($stores === []) {
            $this->executeGuildNext($conversationId, $flowId, $node, $this->guildNodeRef($node['config']['no_store_node_id'] ?? 0));
            return;
        }

        if (count($stores) === 1) {
            $this->presentGuildUnits($conversationId, $node, $conv, $stores[0]['customer_id'], $stores[0]['network_name']);
            return;
        }

        $partial = $this->guildPartial($conversationId, (int) $node['id']);
        $validLoja = $this->guildValidLoja($stores, $partial);
        if ($validLoja !== null) {
            $networkName = $validLoja;
            foreach ($stores as $s) {
                if ((string) $s['customer_id'] === $validLoja) { $networkName = $s['customer_id'] . ' - ' . $s['network_name']; break; }
            }
            $this->presentGuildUnits($conversationId, $node, $conv, $validLoja, $networkName);
            return;
        }

        $this->presentGuildOptions(
            $conversationId, $node, $conv,
            $node['config']['store_prompt'] ?? $node['content'] ?? 'Qual loja deseja atendimento?',
            array_map(
                fn($s, $i) => ['label' => $s['customer_id'] . ' - ' . $s['network_name'], 'value' => $s['customer_id'], 'sort_order' => $i],
                $stores, array_keys($stores)
            )
        );
    }

    /**
     * Resposta do cliente num nó guild_select: etapa loja ou etapa unidade.
     */
    private function handleGuildSelectResponse(int $conversationId, array $node, string $response, array $flow): void
    {
        $conv = Conversation::find($conversationId);
        if (!$conv) return;

        $contactId = (int) ($conv['contact_id'] ?? 0);
        $stores = $contactId > 0 ? Contact::getStores($contactId) : [];
        if ($stores === []) {
            $this->executeGuildNext($conversationId, (int) $flow['id'], $node, $this->guildNodeRef($node['config']['no_store_node_id'] ?? 0));
            return;
        }

        $partial = $this->guildPartial($conversationId, (int) $node['id']);
        $validLoja = $this->guildValidLoja($stores, $partial);
        if (count($stores) > 1 && $validLoja === null) {
            $options = array_map(
                fn($s, $i) => ['label' => $s['customer_id'] . ' - ' . $s['network_name'], 'value' => $s['customer_id'], 'sort_order' => $i],
                $stores, array_keys($stores)
            );
            $hit = $this->guildMatchOption($options, $response);
            if (!$hit) {
                $this->guildReprompt($conversationId, $node, $conv);
                return;
            }
            Flow::saveAnswer($conversationId, (int) $node['id'], null, 'guild_loja:' . $hit['value']);
            $this->presentGuildUnits($conversationId, $node, $conv, $hit['value'], $hit['label']);
            return;
        }

        $customerId = $validLoja ?? $stores[0]['customer_id'];
        $this->guildResolveUnit($conversationId, $node, $conv, $flow, $customerId, $response);
    }

    /**
     * Confere a unidade contra a lista atual (sempre fresca) e conclui.
     */
    private function guildResolveUnit(int $conversationId, array $node, array $conv, array $flow, string $customerId, string $response): void
    {
        try {
            $result = $this->guildService()->getStores($customerId);
        } catch (GuildException $e) {
            $this->guildRegisterFailure($conversationId, $node, $flow, $e->getMessage());
            return;
        }

        $names = array_column($result['stores'] ?? [], 'name');
        $options = array_map(
            fn($n, $i) => ['label' => $n, 'value' => $n, 'sort_order' => $i],
            $names, array_keys($names)
        );
        $hit = $this->guildMatchOption($options, $response);
        if (!$hit) {
            $this->guildReprompt($conversationId, $node, $conv);
            return;
        }

        Flow::saveAnswer($conversationId, (int) $node['id'], null, (string) $hit['value']);
        if (($node['config']['save_unit'] ?? true)) {
            Conversation::update($conversationId, ['unit' => (string) $hit['value']]);
        }
        $this->logExecution($conversationId, (int) $flow['id'], (int) $node['id'], 'guild_unit_selected', ['unit' => $hit['value']]);
        $this->executeGuildNext($conversationId, (int) $flow['id'], $node, $this->guildNodeRef($node['config']['next_node_id'] ?? 0));
    }

    /**
     * Apresenta as unidades de uma loja (busca ao vivo).
     */
    private function presentGuildUnits(int $conversationId, array $node, array $conv, string $customerId, string $networkName): void
    {
        $flowId = (int) ($conv['flow_id'] ?? $node['flow_id']);
        $flow = Flow::find($flowId) ?: ['id' => $flowId, 'nodes' => []];
        try {
            $result = $this->guildService()->getStores($customerId);
        } catch (GuildException $e) {
            $this->guildRegisterFailure($conversationId, $node, $flow, $e->getMessage());
            return;
        }

        $names = array_column($result['stores'] ?? [], 'name');
        if ($names === []) {
            $this->guildRegisterFailure($conversationId, $node, $flow, 'Loja sem unidades.');
            return;
        }

        $prompt = $node['config']['unit_prompt'] ?? $node['content'] ?? ('Unidades de ' . $networkName . ':');
        $this->presentGuildOptions(
            $conversationId, $node, $conv, $prompt,
            array_map(
                fn($n, $i) => ['label' => $n, 'value' => $n, 'sort_order' => $i],
                $names, array_keys($names)
            )
        );
    }

    /**
     * Apresenta opções reaproveitando button_list/list_menu/texto por canal.
     */
    private function presentGuildOptions(int $conversationId, array $node, array $conv, string $prompt, array $options): void
    {
        $presentation = $node['config']['presentation'] ?? 'list_menu';
        $aliases = ['text' => 'menu', 'buttons' => 'button_list', 'list' => 'list_menu'];
        $presentation = $aliases[$presentation] ?? $presentation;
        if (!in_array($presentation, ['button_list', 'list_menu', 'menu'], true)) {
            $presentation = 'list_menu';
        }
        // Limites dos interativos no WhatsApp (botões 3, lista 10):
        // acima disso, texto numerado.
        if (($presentation === 'button_list' && count($options) > 3)
            || ($presentation === 'list_menu' && count($options) > 10)) {
            $presentation = 'menu';
        }
        $this->dispatchMenuPresentation($conversationId, [
            'node_type' => $presentation,
            'content' => $prompt,
            'options' => $options,
            'config' => ['list_title' => 'Opções'],
        ], $conv);
        Flow::saveFlowState($conversationId, (int) ($conv['flow_id'] ?? $node['flow_id']), (int) $node['id']);
    }

    /**
     * Executa o próximo nó configurado (ou finaliza com evento se ausente).
     */
    private function executeGuildNext(int $conversationId, int $flowId, array $node, int $nextNodeId): void
    {
        if ($nextNodeId > 0) {
            $flow = Flow::find($flowId);
            if ($flow) {
                foreach ($flow['nodes'] as $n) {
                    if ((int) $n['id'] === $nextNodeId) {
                        Flow::saveFlowState($conversationId, $flowId, $nextNodeId);
                        $this->executeNode($conversationId, $n);
                        return;
                    }
                }
            }
        }
        Conversation::addEvent($conversationId, 'flow_completed', 'Etapa Guild concluída');
        Flow::completeFlowState($conversationId);
    }

    /**
     * Casa resposta com opção dinâmica em duas passadas: primeiro label/valor
     * exatos em TODAS as opções (um ID digitado vence a posição), depois o
     * número posicional — como nos menus.
     */
    private function guildMatchOption(array $options, string $response): ?array
    {
        $resp = mb_strtolower(trim($response));
        $raw = trim($response);
        foreach ($options as $option) {
            $label = mb_strtolower(trim((string) ($option['label'] ?? '')));
            if ($label !== '' && $label === $resp) return $option;
            if ((string) ($option['value'] ?? '') !== '' && (string) $option['value'] === $raw) return $option;
        }
        if ($raw === '') return null;
        foreach ($options as $i => $option) {
            if ((int) ($option['sort_order'] ?? $i) + 1 === (int) $raw) return $option;
        }
        return null;
    }

    /**
     * Repete a pergunta atual do nó.
     */
    private function guildReprompt(int $conversationId, array $node, array $conv): void
    {
        $flowId = (int) ($conv['flow_id'] ?? $node['flow_id']);
        $stores = Contact::getStores((int) ($conv['contact_id'] ?? 0));
        $partial = $this->guildPartial($conversationId, (int) $node['id']);
        if (count($stores) > 1 && empty($partial['loja'])) {
            $prompt = $node['config']['store_prompt'] ?? $node['content'] ?? 'Qual loja deseja atendimento?';
        } else {
            $prompt = $node['config']['unit_prompt'] ?? $node['content'] ?? 'Qual unidade?';
        }
        $this->dispatchOutboundMessage(
            $conversationId, 'text',
            $node['config']['invalid_message'] ?? 'Opção inválida. Por favor, escolha uma opção válida:'
        );
        $this->executeGuildSelect($conversationId, $node, array_merge($conv, ['_reprompt' => $prompt]));
    }

    /**
     * Falha da Guild: registra tentativa; na 3ª (max_attempts) vai ao handoff.
     */
    private function guildRegisterFailure(int $conversationId, array $node, array $flow, string $reason): void
    {
        $flowId = (int) ($flow['id'] ?? $node['flow_id']);
        Flow::saveAnswer($conversationId, (int) $node['id'], null, 'guild_error');
        $attempts = 0;
        foreach (Flow::getAnswers($conversationId, (int) $node['id']) as $a) {
            if (($a['answer_text'] ?? '') === 'guild_error') $attempts++;
        }
        $max = (int) ($node['config']['max_attempts'] ?? 3);
        $this->logExecution($conversationId, $flowId, (int) $node['id'], 'guild_error', ['reason' => $reason, 'attempt' => $attempts]);
        if ($attempts >= max(1, $max)) {
            foreach (($flow['nodes'] ?? []) as $n) {
                if (($n['node_type'] ?? '') === 'handoff') {
                    Flow::saveFlowState($conversationId, $flowId, (int) $n['id']);
                    $this->executeNode($conversationId, $n);
                    return;
                }
            }
            Conversation::update($conversationId, ['status' => 'new']);
            Flow::completeFlowState($conversationId);
            return;
        }
        $this->dispatchOutboundMessage(
            $conversationId, 'text',
            $node['config']['error_message'] ?? 'Falha ao buscar as opções. Tente novamente.'
        );
    }

    /**
     * Parcial da etapa: ['loja' => 'guild_loja:<customer_id>'] ou [].
     */
    private function guildPartial(int $conversationId, int $nodeId): array
    {
        $out = [];
        foreach (Flow::getAnswers($conversationId, $nodeId) as $a) {
            $t = (string) ($a['answer_text'] ?? '');
            if (str_starts_with($t, 'guild_loja:')) $out['loja'] = $t;
        }
        return $out;
    }

    /**
     * Loja da parcial validada contra a lista atual (null se obsoleta).
     */
    private function guildValidLoja(array $stores, array $partial): ?string
    {
        if (empty($partial['loja'])) return null;
        $cid = (string) preg_replace('/^guild_loja:/', '', (string) $partial['loja']);
        foreach ($stores as $s) {
            if (($s['customer_id'] ?? null) !== null && (string) $s['customer_id'] === $cid) return $cid;
        }
        return null;
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
            case 'button_list':
            case 'list_menu':
                $this->dispatchMenuPresentation($conversationId, $node, $conv);
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

            case 'guild_select':
                $this->executeGuildSelect($conversationId, $node, $conv);
                break;

            case 'image':
            case 'audio':
            case 'video':
                $config = $node['config'] ?? [];
                $fileUrl = $config['file_url'] ?? '';
                if ($fileUrl) {
                    $meta = $this->buildMediaMetaFromFlowConfig($fileUrl, $config);
                    $caption = $this->processTemplate((string) ($config['caption'] ?? $config['text'] ?? ''), $conv);
                    if ($caption !== '') {
                        $meta['caption'] = $caption;
                    }
                    $this->dispatchOutboundMessage($conversationId, $node['node_type'], json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                }
                $this->goToNextNode($conversationId, $node);
                break;

            case 'send_file':
                $config = $node['config'] ?? [];
                $fileUrl = $config['file_url'] ?? '';
                if ($fileUrl) {
                    $meta = $this->buildMediaMetaFromFlowConfig($fileUrl, $config);
                    if (empty($meta['name'])) {
                        $meta['name'] = basename(parse_url($fileUrl, PHP_URL_PATH) ?: '') ?: 'arquivo';
                    }
                    $caption = $this->processTemplate((string) ($config['caption'] ?? $config['text'] ?? ''), $conv);
                    if ($caption !== '') {
                        $meta['caption'] = $caption;
                    }
                    $this->dispatchOutboundMessage($conversationId, 'file', json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                }
                $this->goToNextNode($conversationId, $node);
                break;

            case 'delay':
                $config = $node['config'] ?? [];
                $seconds = (int) ($config['seconds'] ?? 2);
                // Implementar delay assíncrono usando timestamp
                $delayUntil = date('Y-m-d H:i:s', strtotime("+{$seconds} seconds"));
                Database::getInstance()->update(
                    'conversation_flow_states',
                    ['timeout_at' => $delayUntil],
                    'conversation_id = ? AND is_active = 1',
                    [$conversationId]
                );
                $this->logExecution($conversationId, $node['flow_id'], $node['id'], 'delay_scheduled', ['seconds' => $seconds, 'until' => $delayUntil]);
                // Não avança imediatamente - será processado por worker
                break;

            case 'condition':
                $config = $node['config'] ?? [];
                $variable = $config['variable'] ?? '';
                $expected = $config['expected'] ?? '';
                $operator = $config['operator'] ?? 'equals';
                $matched = false;

                if ($variable && $expected) {
                    $answer = Flow::getLastAnswer($conversationId, $node['id']);
                    $fieldValue = $answer['answer_text'] ?? '';
                    $matched = $this->evaluateCondition($fieldValue, $expected, $operator);
                }
                $this->routeBinaryDecision($conversationId, $node, $matched, 'condition_evaluated', [
                    'variable' => $variable,
                    'expected' => $expected,
                    'operator' => $operator,
                    'matched' => $matched,
                ]);
                break;

            case 'day_of_week':
                $config = $node['config'] ?? [];
                $selectedDays = $config['days'] ?? [1,2,3,4,5];
                $currentDay = (int) date('w');
                $matched = in_array($currentDay, $selectedDays);
                $this->routeBinaryDecision($conversationId, $node, $matched, 'day_of_week', [
                    'current' => $currentDay,
                    'selected' => $selectedDays,
                    'matched' => $matched,
                ]);
                break;

            case 'time_range':
                $config = $node['config'] ?? [];
                $start = $config['start_time'] ?? '08:00';
                $end = $config['end_time'] ?? '18:00';
                $now = date('H:i');
                // Suporta intervalos que cruzam meia-noite: se end <= start,
                // o intervalo é válido quando $now >= start OU $now <= end.
                $matched = ($start <= $end)
                    ? ($now >= $start && $now <= $end)
                    : ($now >= $start || $now <= $end);
                $this->routeBinaryDecision($conversationId, $node, $matched, 'time_range', [
                    'now' => $now,
                    'start' => $start,
                    'end' => $end,
                    'matched' => $matched,
                ]);
                break;

            case 'assign_department':
                $config = $node['config'] ?? [];
                $departmentId = $config['department_id'] ?? null;
                if ($departmentId) {
                    $conv = Conversation::find($conversationId);
                    $update = ['department_id' => $departmentId];
                    $resolved = \App\Models\Inbox::resolveInboxForDepartment(
                        (int) $departmentId,
                        isset($conv['inbox_id']) ? (int) $conv['inbox_id'] : null
                    );
                    if ($resolved && $resolved !== (int) ($conv['inbox_id'] ?? 0)) {
                        $update['inbox_id'] = $resolved;
                    }
                    Conversation::update($conversationId, $update);
                    $dept = Department::find($departmentId);
                    $msg = "Departamento definido como: " . ($dept['name'] ?? ('#' . $departmentId));
                    if (!empty($update['inbox_id'])) {
                        $newInbox = \App\Models\Inbox::find((int) $update['inbox_id']);
                        $msg .= " (caixa " . ($newInbox['name'] ?? ('#' . $update['inbox_id'])) . ")";
                    }
                    Conversation::addEvent($conversationId, 'department_changed', $msg);
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

            case 'notify':
                $config = $node['config'] ?? [];
                $phones = $config['phones'] ?? (isset($config['phone_number']) ? [$config['phone_number']] : []);
                $template = $config['message_template'] ?? '';

                if (empty($phones)) {
                    $this->logExecution($conversationId, $node['flow_id'] ?? 0, $node['id'], 'notify_skipped', ['reason' => 'no_phones']);
                    Conversation::addEvent($conversationId, 'flow_notification', "Notificação ignorada: nenhum telefone destino configurado");
                } elseif (empty($template)) {
                    $this->logExecution($conversationId, $node['flow_id'] ?? 0, $node['id'], 'notify_skipped', ['reason' => 'no_template']);
                    Conversation::addEvent($conversationId, 'flow_notification', "Notificação ignorada: mensagem vazia");
                } else {
                    $message = $this->processTemplate($template, $conv);
                    $channelId = $config['notify_channel_id'] ?? $conv['channel_id'] ?? null;

                    if (!$channelId) {
                        $this->logExecution($conversationId, $node['flow_id'] ?? 0, $node['id'], 'notify_skipped', ['reason' => 'no_channel']);
                        Conversation::addEvent($conversationId, 'flow_notification', "Notificação ignorada: sem canal WhatsApp disponível");
                    } else {
                        $waService = new \App\Services\WhatsAppService();
                        $sent = [];
                        $invalid = [];
                        foreach ($phones as $phone) {
                            $phoneClean = preg_replace('/\D/', '', $phone);
                            if (strlen($phoneClean) < 10) {
                                $invalid[] = $phone;
                                continue;
                            }
                            $result = $waService->sendToPhone((int) $channelId, $phoneClean, $message);
                            if ($result) {
                                $sent[] = $phoneClean;
                            } else {
                                $invalid[] = $phone;
                            }
                        }
                        $this->logExecution($conversationId, $node['flow_id'] ?? 0, $node['id'], 'notify_sent', [
                            'phones' => $phones,
                            'sent_count' => count($sent),
                            'invalid_count' => count($invalid),
                        ]);
                        Conversation::addEvent($conversationId, 'flow_notification',
                            "Notificação enviada para " . count($sent) . " número(s)" . (count($invalid) > 0 ? " (" . count($invalid) . " inválido(s))" : ''));
                    }
                }
                $this->goToNextNode($conversationId, $node);
                break;

            case 'handoff':
                $this->dispatchOutboundMessage($conversationId, 'text', $node['content'] ?? 'Um de nossos atendentes vai atender você em breve.');
                Conversation::update($conversationId, ['status' => 'new']);
                Flow::completeFlowState($conversationId);
                Conversation::addEvent($conversationId, 'flow_completed', 'Fluxo finalizado, encaminhado para atendimento humano');
                $this->logExecution($conversationId, $node['flow_id'], $node['id'], 'handoff_completed', ['message' => $node['content'] ?? 'default']);
                break;

            case 'end':
                Flow::completeFlowState($conversationId);
                Conversation::addEvent($conversationId, 'flow_completed', 'Fluxo finalizado');
                break;

            case 'finish':
                // Fecha a conversa PRIMEIRO para que completeFlowState não
                // aplique a tag "Aberto" em uma conversa encerrada.
                Conversation::update($conversationId, [
                    'status' => 'closed',
                    'close_reason' => 'system',
                    'closed_at' => date('Y-m-d H:i:s'),
                ]);
                Flow::completeFlowState($conversationId);
                Conversation::addEvent($conversationId, 'flow_completed', 'Fluxo finalizado, conversa encerrada pelo sistema');
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

    /**
     * Envia a apresentação do nó de menu conforme o tipo:
     * button_list => botões interativos, list_menu => lista interativa,
     * menu => texto + lista numerada.
     */
    private function dispatchMenuPresentation(int $conversationId, array $node, array $conv): void
    {
        $content = $this->processTemplate($node['content'] ?? '', $conv);
        if (trim($content) === '') {
            $content = 'Escolha uma opção:';
        }

        if ($node['node_type'] === 'button_list') {
            $buttons = [];
            foreach ($node['options'] ?? [] as $opt) {
                $buttons[] = ['id' => $opt['value'] ?? $opt['label'], 'label' => $opt['label']];
            }
            $this->dispatchOutboundMessage($conversationId, 'button_list', json_encode(['text' => $content, 'buttons' => $buttons], JSON_UNESCAPED_UNICODE));
            return;
        }

        if ($node['node_type'] === 'list_menu') {
            $items = [];
            foreach ($node['options'] ?? [] as $opt) {
                $items[] = ['id' => $opt['value'] ?? $opt['label'], 'label' => $opt['label']];
            }
            $listTitle = $node['config']['list_title'] ?? 'Opções';
            $this->dispatchOutboundMessage($conversationId, 'list_menu', json_encode(['text' => $content, 'title' => $listTitle, 'items' => $items], JSON_UNESCAPED_UNICODE));
            return;
        }

        $this->dispatchOutboundMessage($conversationId, 'text', $content);

        $optionsList = [];
        foreach ($node['options'] as $i => $opt) {
            $optionsList[] = ($i + 1) . ' - ' . $opt['label'];
        }
        $this->dispatchOutboundMessage($conversationId, 'text', implode("\n", $optionsList));
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
                $result = $service->sendOutbound($conversationId, $messageId, $type, $content);
                
                if ($result) {
                    Database::getInstance()->update(
                        'messages',
                        ['delivery_status' => 'sent'],
                        'id = ?',
                        [$messageId]
                    );
                } else {
                    $this->scheduleRetry($messageId);
                }
            } catch (\Throwable $e) {
                error_log("FlowEngine WhatsApp outbound error: " . $e->getMessage());
                $this->scheduleRetry($messageId);
            }
        }

        return $messageId;
    }

    private function scheduleRetry(int $messageId): void
    {
        $maxRetries = (int) $this->getSetting('flow_max_retries', '3');
        $backoffSeconds = (int) $this->getSetting('flow_retry_backoff_seconds', '60');
        
        $message = Database::getInstance()->fetch("SELECT retry_count FROM messages WHERE id = ?", [$messageId]);
        if (!$message) return;
        
        $currentRetry = $message['retry_count'] ?? 0;
        if ($currentRetry >= $maxRetries) {
            Database::getInstance()->update(
                'messages',
                ['delivery_status' => 'failed'],
                'id = ?',
                [$messageId]
            );
            return;
        }
        
        $newRetryCount = $currentRetry + 1;
        $delay = $backoffSeconds * pow(2, $currentRetry); // Backoff exponencial
        $nextRetryAt = date('Y-m-d H:i:s', strtotime("+{$delay} seconds"));
        
        Database::getInstance()->update(
            'messages',
            [
                'retry_count' => $newRetryCount,
                'next_retry_at' => $nextRetryAt,
                'delivery_status' => 'pending',
            ],
            'id = ?',
            [$messageId]
        );
    }

    public function processPendingRetries(): void
    {
        $pendingMessages = Database::getInstance()->fetchAll(
            "SELECT m.*, c.id as conversation_id, c.channel_id
             FROM messages m
             JOIN conversations c ON c.id = m.conversation_id
             WHERE m.delivery_status = 'pending'
               AND m.next_retry_at IS NOT NULL
               AND m.next_retry_at <= NOW()
               AND m.direction = 'outbound'
               AND m.retry_count < ?
             LIMIT 50",
            [(int) $this->getSetting('flow_max_retries', '3')]
        );

        foreach ($pendingMessages as $msg) {
            try {
                $conv = Conversation::find($msg['conversation_id']);
                if (!$conv) continue;
                
                if (($conv['channel_type'] ?? '') === 'whatsapp') {
                    $service = new WhatsAppService();
                    $result = $service->sendOutbound($msg['conversation_id'], $msg['id'], $msg['type'], $msg['content']);
                    
                    if ($result) {
                        Database::getInstance()->update(
                            'messages',
                            ['delivery_status' => 'sent', 'next_retry_at' => null],
                            'id = ?',
                            [$msg['id']]
                        );
                    } else {
                        $this->scheduleRetry($msg['id']);
                    }
                }
            } catch (\Throwable $e) {
                error_log("Retry outbound error: " . $e->getMessage());
                $this->scheduleRetry($msg['id']);
            }
        }
    }

    private function processTemplate(string $content, array $conversation): string
    {
        $contact = Contact::find($conversation['contact_id'] ?? 0);

        $lastMsg = Database::getInstance()->fetch(
            "SELECT content FROM messages WHERE conversation_id = ? AND direction = 'inbound' ORDER BY id DESC LIMIT 1",
            [$conversation['id']]
        );

        $hour = (int) date('H');
        $saudacao = $hour >= 5 && $hour < 12 ? 'Bom dia' : ($hour < 18 ? 'Boa tarde' : 'Boa noite');

        $replacements = [
            '{saudacao}' => $saudacao,
            '{nome}' => $contact['name'] ?? $conversation['contact_name'] ?? 'Cliente',
            '{email}' => $contact['email'] ?? $conversation['contact_email'] ?? '',
            '{telefone}' => $contact['phone'] ?? $conversation['contact_phone'] ?? '',
            '{departamento}' => $conversation['department_name'] ?? '',
            '{atendente}' => $conversation['assigned_user_name'] ?? 'Atendente',
            '{empresa}' => $contact['company'] ?? '',
            '{documento}' => $contact['document'] ?? '',
            '{mensagem}' => $lastMsg['content'] ?? '',
            '{data}' => date('d/m/Y'),
            '{hora}' => date('H:i'),
            '{data_hora}' => date('d/m/Y H:i'),
        ];

        // Suporte a condicionais simples: {if:campo}texto{/if}
        $content = preg_replace_callback('/\{if:([^}]+)\}(.+?)\{\/if\}/s', function($matches) use ($replacements) {
            $field = trim($matches[1]);
            $value = $replacements['{' . $field . '}'] ?? '';
            return !empty($value) ? $matches[2] : '';
        }, $content);

        // Suporte a else: {if:campo}texto{else}alternativo{/if}
        $content = preg_replace_callback('/\{if:([^}]+)\}(.+?)\{else\}(.+?)\{\/if\}/s', function($matches) use ($replacements) {
            $field = trim($matches[1]);
            $value = $replacements['{' . $field . '}'] ?? '';
            return !empty($value) ? $matches[2] : $matches[3];
        }, $content);

        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }

    private function handleTimeout(int $conversationId, array $flowState): void
    {
        $this->logExecution($conversationId, $flowState['flow_id'], $flowState['current_node_id'], 'timeout_triggered');
        
        $flow = Flow::find($flowState['flow_id']);
        if (!$flow) {
            Flow::completeFlowState($conversationId);
            return;
        }

        // Encontrar nó de handoff ou end
        $handoffNode = null;
        foreach ($flow['nodes'] as $node) {
            if ($node['node_type'] === 'handoff') {
                $handoffNode = $node;
                break;
            }
        }

        if ($handoffNode) {
            $this->executeNode($conversationId, $handoffNode);
        } else {
            // Fallback: encaminhar para atendimento humano
            $this->dispatchOutboundMessage($conversationId, 'text', 'Tempo esgotado. Um atendente irá ajudá-lo em breve.');
            Conversation::update($conversationId, ['status' => 'new']);
            Flow::completeFlowState($conversationId);
            Conversation::addEvent($conversationId, 'flow_timeout', 'Fluxo finalizado por timeout');
        }
    }

    public function processPendingDelays(): void
    {
        $pendingStates = Database::getInstance()->fetchAll(
            "SELECT fs.* FROM conversation_flow_states fs
             WHERE fs.is_active = 1
               AND fs.timeout_at IS NOT NULL
               AND fs.timeout_at <= NOW()
             LIMIT 100"
        );

        foreach ($pendingStates as $state) {
            $flow = Flow::find($state['flow_id']);
            if (!$flow) continue;

            $currentNode = null;
            foreach ($flow['nodes'] as $node) {
                if ($node['id'] === $state['current_node_id']) {
                    $currentNode = $node;
                    break;
                }
            }

            if ($currentNode && $currentNode['node_type'] === 'delay') {
                // Delay agendado: avança o fluxo
                $this->logExecution($state['conversation_id'], $flow['id'], $currentNode['id'], 'delay_completed');
                $this->goToNextNode($state['conversation_id'], $currentNode);

                // O timeout_at herdado do nó delay já expirou. Se o novo nó
                // for de interação (aguardando resposta do cliente), renova o
                // timeout — senão o próximo tick do worker fecharia o fluxo
                // imediatamente (handoff prematuro).
                $this->refreshInteractionTimeoutIfNeeded($state['conversation_id']);
            } else {
                // Timeout em nó de interação (pergunta/menu/coleta aguardando resposta):
                // finaliza o fluxo automaticamente (handoff ou encaminhamento humano),
                // o que troca a tag "Fluxo" pela "Aberto".
                $this->handleTimeout($state['conversation_id'], $state);
            }
        }
    }

    /**
     * Após avançar de um nó delay, renova o timeout de interação (flow_timeout_minutes)
     * caso o fluxo tenha parado num nó que aguarda resposta do cliente.
     */
    private function refreshInteractionTimeoutIfNeeded(int $conversationId): void
    {
        $state = Flow::getActiveFlowState($conversationId);
        if (!$state) {
            return;
        }
        $flow = Flow::find((int) $state['flow_id']);
        if (!$flow) {
            return;
        }
        $currentNode = null;
        foreach ($flow['nodes'] as $node) {
            if ((int) $node['id'] === (int) $state['current_node_id']) {
                $currentNode = $node;
                break;
            }
        }
        if (!$currentNode) {
            return;
        }
        if (!in_array($currentNode['node_type'], ['question', 'menu', 'button_list', 'list_menu', 'collect_field', 'guild_select'], true)) {
            return;
        }

        $timeoutMinutes = (int) $this->getSetting('flow_timeout_minutes', '30');
        Database::getInstance()->update(
            'conversation_flow_states',
            ['timeout_at' => date('Y-m-d H:i:s', strtotime("+{$timeoutMinutes} minutes"))],
            'id = ?',
            [(int) $state['id']]
        );
    }

    /**
     * Roteia um nó de decisão binária (condition / day_of_week / time_range)
     * para o ramo "matched" (options[0]) ou "not_matched" (options[1]).
     *
     * - Loga a avaliação com o event_type e payload fornecidos.
     * - Persiste o estado no próximo nó e o executa.
     * - Se faltar a 2ª opção e a condição não for matched, fica na 1ª
     *   (mantém compatibilidade com fluxos antigos de 1 opção só).
     * - Se nenhum próximo nó existir, finaliza o fluxo.
     */
    private function routeBinaryDecision(int $conversationId, array $node, bool $matched, string $eventType, array $logData): void
    {
        $this->logExecution($conversationId, $node['flow_id'], $node['id'], $eventType, $logData);

        $options = $node['options'] ?? [];
        if ($matched) {
            $nextNodeId = $options[0]['next_node_id'] ?? null;
        } else {
            $nextNodeId = $options[1]['next_node_id'] ?? ($options[0]['next_node_id'] ?? null);
        }

        if (!$nextNodeId) {
            Flow::completeFlowState($conversationId);
            return;
        }

        $flow = Flow::find($node['flow_id']);
        if (!$flow) {
            Flow::completeFlowState($conversationId);
            return;
        }

        foreach ($flow['nodes'] as $n) {
            if ($n['id'] === $nextNodeId) {
                Flow::saveFlowState($conversationId, $flow['id'], $nextNodeId);
                $this->executeNode($conversationId, $n);
                return;
            }
        }

        Flow::completeFlowState($conversationId);
    }

    private function evaluateCondition(string $actual, string $expected, string $operator): bool
    {
        $actual = trim($actual);
        $expected = trim($expected);

        return match ($operator) {
            'equals' => mb_strtolower($actual) === mb_strtolower($expected),
            'not_equals' => mb_strtolower($actual) !== mb_strtolower($expected),
            'contains' => str_contains(mb_strtolower($actual), mb_strtolower($expected)),
            'not_contains' => !str_contains(mb_strtolower($actual), mb_strtolower($expected)),
            'starts_with' => str_starts_with(mb_strtolower($actual), mb_strtolower($expected)),
            'ends_with' => str_ends_with(mb_strtolower($actual), mb_strtolower($expected)),
            'greater_than' => is_numeric($actual) && is_numeric($expected) && (float)$actual > (float)$expected,
            'less_than' => is_numeric($actual) && is_numeric($expected) && (float)$actual < (float)$expected,
            'greater_equal' => is_numeric($actual) && is_numeric($expected) && (float)$actual >= (float)$expected,
            'less_equal' => is_numeric($actual) && is_numeric($expected) && (float)$actual <= (float)$expected,
            'regex' => preg_match($expected, $actual) === 1,
            'in' => in_array(mb_strtolower($actual), array_map('mb_strtolower', explode(',', $expected))),
            'not_in' => !in_array(mb_strtolower($actual), array_map('mb_strtolower', explode(',', $expected))),
            'empty' => empty($actual),
            'not_empty' => !empty($actual),
            default => mb_strtolower($actual) === mb_strtolower($expected),
        };
    }

    public function cleanupOldStates(): void
    {
        $cleanupDays = (int) $this->getSetting('flow_cleanup_days', '90');
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$cleanupDays} days"));

        // Limpar estados de fluxo inativos antigos
        Database::getInstance()->execute(
            "DELETE FROM conversation_flow_states 
             WHERE is_active = 0 
               AND (finished_at IS NULL OR finished_at < ?)",
            [$cutoffDate]
        );

        // Limpar logs de execução antigos
        Database::getInstance()->execute(
            "DELETE FROM flow_execution_logs 
             WHERE created_at < ?",
            [$cutoffDate]
        );

        $cutoffDate2 = date('Y-m-d H:i:s', strtotime("-" . ($cleanupDays * 2) . " days"));
        Database::getInstance()->execute(
            "DELETE FROM flow_answers 
             WHERE created_at < ?",
            [$cutoffDate2]
        );
    }
}
