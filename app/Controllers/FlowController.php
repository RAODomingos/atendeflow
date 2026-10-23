<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Models\Department;
use App\Models\Flow;

class FlowController
{
    public function index(Request $request): void
    {
        $flows = Flow::all();

        View::renderWithLayout('flows/index', 'main', [
            'title' => 'Fluxos de Atendimento',
            'activePage' => 'flows',
            'flows' => $flows,
        ]);
    }

    public function create(Request $request): void
    {
        $departments = Department::all();
        $tags = Database::getInstance()->fetchAll("SELECT * FROM tags ORDER BY name");
        $users = \App\Models\User::all();
        $whatsappChannels = Database::getInstance()->fetchAll(
            "SELECT c.id, c.name, wc.instance_name
             FROM channels c
             JOIN whatsapp_connections wc ON wc.channel_id = c.id
             WHERE c.type = 'whatsapp' AND c.is_active = 1
             ORDER BY c.name"
        );

        View::renderWithLayout('flows/form', 'main', [
            'title' => 'Novo Fluxo',
            'activePage' => 'flows',
            'flow' => null,
            'departments' => $departments,
            'tags' => $tags,
            'users' => $users,
            'whatsappChannels' => $whatsappChannels,
        ]);
    }

    public function store(Request $request): void
    {
        $name = $request->post('name');
        if (empty(trim($name ?? ''))) {
            Session::setFlash('error', 'O nome do fluxo é obrigatório.');
            View::back();
        }

        $flowId = Flow::create([
            'name' => $name,
            'description' => $request->post('description'),
            'channel_scope' => $request->post('channel_scope', 'all'),
            'created_by' => Auth::id(),
        ]);

        $this->saveNodes($flowId, $request);

        Session::setFlash('success', 'Fluxo criado com sucesso.');
        View::redirect('/flows');
    }

    public function edit(Request $request, int $id): void
    {
        $flow = Flow::find($id);
        if (!$flow) {
            Session::setFlash('error', 'Fluxo não encontrado.');
            View::redirect('/flows');
        }

        $departments = Department::all();
        $tags = Database::getInstance()->fetchAll("SELECT * FROM tags ORDER BY name");
        $users = \App\Models\User::all();
        $whatsappChannels = Database::getInstance()->fetchAll(
            "SELECT c.id, c.name, wc.instance_name
             FROM channels c
             JOIN whatsapp_connections wc ON wc.channel_id = c.id
             WHERE c.type = 'whatsapp' AND c.is_active = 1
             ORDER BY c.name"
        );

        View::renderWithLayout('flows/form', 'main', [
            'title' => 'Editar Fluxo: ' . $flow['name'],
            'activePage' => 'flows',
            'flow' => $flow,
            'departments' => $departments,
            'tags' => $tags,
            'users' => $users,
            'whatsappChannels' => $whatsappChannels,
        ]);
    }

    public function update(Request $request, int $id): void
    {
        $flow = Flow::find($id);
        if (!$flow) {
            Session::setFlash('error', 'Fluxo não encontrado.');
            View::redirect('/flows');
        }

        Flow::update($id, [
            'name' => $request->post('name'),
            'description' => $request->post('description'),
            'channel_scope' => $request->post('channel_scope', 'all'),
            'is_active' => $request->post('is_active') ? 1 : 0,
        ]);

        // Remove existing nodes and recreate
        foreach ($flow['nodes'] as $node) {
            Flow::deleteNode($node['id']);
        }

        $this->saveNodes($id, $request);

        Session::setFlash('success', 'Fluxo atualizado com sucesso.');
        View::redirect('/flows');
    }

    public function publish(Request $request, int $id): void
    {
        Flow::update($id, ['is_active' => 1]);
        Session::setFlash('success', 'Fluxo publicado com sucesso.');
        View::redirect('/flows');
    }

    /**
     * Envia uma mídia para os nós de mídia do fluxo (imagem/áudio/vídeo/arquivo).
     * Retorna a URL armazenada para salvar em config.file_url do nó.
     */
    public function uploadMedia(Request $request): void
    {
        $uploaded = save_uploaded_file('file', null, 'flows');
        if (!$uploaded) {
            View::json(['error' => 'Arquivo inválido ou tipo não permitido (máx. 10 MB).'], 422);
            return;
        }

        // A Uazapi (free plan) é capaz de aceitar PNG e JPEG como image; o
        // provider faz fallback de conversão para JPEG apenas se a Uazapi
        // rejeitar. Mantemos o arquivo no formato original aqui.
        //
        // Importante: retornamos também o 'path' (caminho relativo no disco,
        // ex.: 'flows/abc.webm') para que o FlowEngineService monte o meta JSON
        // com o path local. Sem isso, o UazapiProvider só teria a URL pública
        // (ngrok, IP dinâmico) e a Uazapi não conseguiria baixá-la.

        View::json([
            'url'  => $uploaded['url'],
            'name' => $uploaded['name'],
            'size' => $uploaded['size'],
            'type' => $uploaded['type'],
            'mime' => $uploaded['mime'] ?? '',
            'path' => $uploaded['path'] ?? null,
        ]);
    }

    public function duplicate(Request $request, int $id): void
    {
        $newFlowId = Flow::duplicate($id, Auth::id());
        if ($newFlowId) {
            Session::setFlash('success', 'Fluxo duplicado com sucesso.');
        } else {
            Session::setFlash('error', 'Erro ao duplicar fluxo.');
        }
        View::redirect('/flows');
    }

    public function destroy(Request $request, int $id): void
    {
        Flow::delete($id);
        Session::setFlash('success', 'Fluxo removido.');
        View::redirect('/flows');
    }

    private function saveNodes(int $flowId, Request $request): void
    {
        $nodesJson = $request->post('nodes');
        if (empty($nodesJson)) return;

        $nodes = json_decode($nodesJson, true);
        if (!is_array($nodes)) return;

        // Validar nós antes de salvar
        $validationErrors = $this->validateNodes($nodes);
        if (!empty($validationErrors)) {
            Session::setFlash('error', 'Erros de validação: ' . implode(', ', $validationErrors));
            View::back();
        }

        $frontToDb = [];

        foreach ($nodes as $nodeData) {
            $config = $nodeData['config'] ?? [];

            $frontId = $nodeData['id'] ?? bin2hex(random_bytes(8));
            $type = $nodeData['type'] ?? 'message';

            $nodeId = Flow::addNode($flowId, [
                'node_key' => bin2hex(random_bytes(16)),
                'node_type' => $type,
                'title' => $nodeData['title'] ?? '',
                'content' => $nodeData['content'] ?? '',
                'config' => !empty($config) ? json_encode($config) : null,
                'position_x' => (int) ($nodeData['x'] ?? 0),
                'position_y' => (int) ($nodeData['y'] ?? 0),
            ]);

            $frontToDb[$frontId] = $nodeId;

            if (!empty($nodeData['options'])) {
                foreach ($nodeData['options'] as $i => $opt) {
                    Flow::addOption($nodeId, [
                        'label' => $opt['label'] ?? '',
                        'value' => $opt['value'] ?? ($opt['label'] ?? ''),
                        'sort_order' => $i,
                        'next_node_id' => null,
                    ]);
                }
            }

            if ($type === 'start') {
                Database::getInstance()->update('flows', ['start_node_id' => $nodeId], 'id = ?', [$flowId]);
            }
        }

        foreach ($nodes as $nodeData) {
            if (empty($nodeData['options']) || empty($nodeData['id'])) continue;
            $nodeId = $frontToDb[$nodeData['id']] ?? null;
            if (!$nodeId) continue;

            $options = Database::getInstance()->fetchAll(
                "SELECT * FROM flow_options WHERE node_id = ? ORDER BY sort_order",
                [$nodeId]
            );

            foreach ($options as $i => $option) {
                $optData = $nodeData['options'][$i] ?? null;
                if ($optData && !empty($optData['next_node_id'])) {
                    $nextDbId = $frontToDb[$optData['next_node_id']] ?? null;
                    if ($nextDbId) {
                        Database::getInstance()->update(
                            'flow_options',
                            ['next_node_id' => $nextDbId],
                            'id = ?',
                            [$option['id']]
                        );
                    }
                }
            }
        }

        // Refs de saída guardadas no config (ex.: guild_select) também usam
        // ids do canvas e precisam do mesmo remapeamento.
        foreach ($nodes as $nodeData) {
            if (($nodeData['type'] ?? '') !== 'guild_select' || empty($nodeData['id'])) continue;
            $nodeId = $frontToDb[$nodeData['id']] ?? null;
            if (!$nodeId) continue;
            $cfg = $nodeData['config'] ?? [];
            if (!is_array($cfg)) continue;
            $changed = false;
            foreach (['no_store_node_id', 'next_node_id'] as $key) {
                if (!empty($cfg[$key]) && isset($frontToDb[$cfg[$key]])) {
                    $cfg[$key] = $frontToDb[$cfg[$key]];
                    $changed = true;
                }
            }
            if ($changed) {
                Database::getInstance()->update(
                    'flow_nodes',
                    ['config' => json_encode($cfg)],
                    'id = ?',
                    [$nodeId]
                );
            }
        }
    }

    private function validateNodes(array $nodes): array
    {
        $errors = [];
        $hasStartNode = false;
        $nodeKeys = [];

        foreach ($nodes as $index => $node) {
            $type = $node['type'] ?? '';
            $config = $node['config'] ?? [];

            // Validar que existe pelo menos um nó start
            if ($type === 'start') {
                $hasStartNode = true;
            }

            // Validar chaves duplicadas
            $nodeKey = $node['id'] ?? '';
            if (in_array($nodeKey, $nodeKeys)) {
                $errors[] = "Nó duplicado: {$nodeKey}";
            }
            $nodeKeys[] = $nodeKey;

            // Validações específicas por tipo
            switch ($type) {
                case 'start':
                    // Nó start pode ter opções para definir o próximo nó
                    break;

                case 'menu':
                case 'button_list':
                case 'list_menu':
                    if (empty($node['options'])) {
                        $errors[] = "Nó {$type} deve ter pelo menos uma opção";
                    }
                    foreach ($node['options'] ?? [] as $opt) {
                        if (empty($opt['label'])) {
                            $errors[] = "Opção sem label no nó {$type}";
                        }
                    }
                    break;

                case 'condition':
                    if (empty($config['variable'])) {
                        $errors[] = "Nó condition deve ter variável definida";
                    }
                    if (empty($config['operator'])) {
                        $errors[] = "Nó condition deve ter operador definido";
                    }
                    if (count($node['options'] ?? []) < 2) {
                        $errors[] = "Nó condition deve ter pelo menos 2 opções (true/false)";
                    }
                    break;

                case 'delay':
                    if (!isset($config['seconds']) || !is_numeric($config['seconds'])) {
                        $errors[] = "Nó delay deve ter segundos definidos";
                    }
                    if (($config['seconds'] ?? 0) < 0 || ($config['seconds'] ?? 0) > 3600) {
                        $errors[] = "Nó delay deve ter segundos entre 0 e 3600";
                    }
                    break;

                case 'day_of_week':
                    if (empty($config['days']) || !is_array($config['days'])) {
                        $errors[] = "Nó day_of_week deve ter pelo menos um dia selecionado";
                    }
                    if (count($node['options'] ?? []) < 2) {
                        $errors[] = "Nó day_of_week deve ter pelo menos 2 opções (dentro/fora)";
                    }
                    break;

                case 'time_range':
                    if (empty($config['start_time'])) {
                        $errors[] = "Nó time_range deve ter horário início definido";
                    }
                    if (empty($config['end_time'])) {
                        $errors[] = "Nó time_range deve ter horário fim definido";
                    }
                    if (count($node['options'] ?? []) < 2) {
                        $errors[] = "Nó time_range deve ter pelo menos 2 opções (dentro/fora)";
                    }
                    break;

                case 'assign_department':
                    if (empty($config['department_id'])) {
                        $errors[] = "Nó assign_department deve ter department_id definido";
                    }
                    break;

                case 'assign_user':
                    if (empty($config['user_id'])) {
                        $errors[] = "Nó assign_user deve ter user_id definido";
                    }
                    break;

                case 'add_tag':
                    if (empty($config['tag_id'])) {
                        $errors[] = "Nó add_tag deve ter tag_id definido";
                    }
                    break;

                case 'notify':
                    $phones = $config['phones'] ?? (isset($config['phone_number']) ? [$config['phone_number']] : []);
                    $validPhones = array_filter($phones, fn($p) => strlen(preg_replace('/\D/', '', $p)) >= 10);
                    if (empty($validPhones)) {
                        $errors[] = "Nó notify deve ter pelo menos um telefone destino válido (mín. 10 dígitos)";
                    }
                    if (empty($config['message_template'])) {
                        $errors[] = "Nó notify deve ter mensagem definida";
                    }
                    break;

                case 'image':
                case 'audio':
                case 'video':
                case 'send_file':
                    if (empty($config['file_url'])) {
                        $errors[] = "Nó {$type} deve ter file_url definido";
                    }
                    if (!filter_var($config['file_url'] ?? '', FILTER_VALIDATE_URL)) {
                        $errors[] = "Nó {$type} deve ter URL válida";
                    }
                    break;

                case 'end':
                case 'finish':
                    // Sem validação adicional
                    break;
            }
        }

        if (!$hasStartNode) {
            $errors[] = "Fluxo deve ter um nó start";
        }

        $cycle = $this->detectRunawayCycle($nodes);
        if ($cycle !== null) {
            $errors[] = "Fluxo tem ciclo que avanca sozinho ({$cycle}). Use um no de menu, espera ou pergunta para voltar a um no anterior.";
        }

        return $errors;
    }

    /**
     * Tipos de no que avancam sozinhos, sem resposta do cliente.
     */
    private const AUTO_ADVANCE_NODES = [
        'start', 'message', 'condition', 'day_of_week', 'time_range',
        'image', 'audio', 'video', 'send_file',
        'assign_department', 'assign_user', 'add_tag', 'notify',
    ];

    /**
     * Detecta ciclo perigoso no grafo do fluxo (ids do canvas).
     *
     * A execucao de nos e recursiva: um fluxo A -> B -> Apublished e
     * ativado travava o worker em recursao infinita (memoria esgotada).
     *
     * So e perigoso o ciclo que passa apenas por nos que avancam sozinhos:
     * um ciclo que passa por um menu (ou por um delay) so se repete quando
     * o cliente age, e esse e um fluxo legitimo.
     *
     * @return string|null descricao do ciclo perigoso (null = nenhum)
     */
    private function detectRunawayCycle(array $nodes): ?string
    {
        $graph = [];
        $types = [];

        foreach ($nodes as $node) {
            $id = (string) ($node['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $types[$id] = (string) ($node['type'] ?? '');

            $edges = [];
            foreach (($node['options'] ?? []) as $opt) {
                $next = $opt['next_node_id'] ?? null;
                if ($next !== null && $next !== '') {
                    $edges[] = (string) $next;
                }
            }
            $cfg = $node['config'] ?? [];
            if (is_array($cfg)) {
                foreach (['next_node_id', 'no_store_node_id'] as $key) {
                    if (!empty($cfg[$key])) {
                        $edges[] = (string) $cfg[$key];
                    }
                }
            }
            $graph[$id] = $edges;
        }

        $state = []; // 0 = nao visitado, 1 = na pilha, 2 = concluido
        $stack = [];

        $visit = function (string $node) use (&$visit, &$state, &$stack, $graph, $types): ?string {
            $state[$node] = 1;
            $stack[] = $node;

            foreach ($graph[$node] ?? [] as $next) {
                if (!isset($graph[$next])) {
                    continue; // destino fora do grafo
                }

                $nextState = $state[$next] ?? 0;

                if ($nextState === 1) {
                    // Ciclo: recorta de $next ate o topo da pilha.
                    $cycle = [];
                    $started = false;
                    foreach ($stack as $n) {
                        if ($n === $next) {
                            $started = true;
                        }
                        if ($started) {
                            $cycle[] = $n;
                        }
                    }
                    $cycle[] = $next;

                    $autoAdvance = true;
                    foreach ($cycle as $n) {
                        if (!in_array($types[$n] ?? '', self::AUTO_ADVANCE_NODES, true)) {
                            $autoAdvance = false;
                            break;
                        }
                    }
                    if (!$autoAdvance) {
                        continue; // ciclo legitimo (aguarda o cliente)
                    }
                    return implode(' -> ', $cycle);
                }

                if ($nextState === 0) {
                    $found = $visit($next);
                    if ($found !== null) {
                        return $found;
                    }
                }
            }

            array_pop($stack);
            $state[$node] = 2;
            return null;
        };

        foreach (array_keys($graph) as $node) {
            if (($state[$node] ?? 0) === 0) {
                $found = $visit($node);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

}
