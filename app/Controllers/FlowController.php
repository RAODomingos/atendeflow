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

        View::renderWithLayout('flows/form', 'main', [
            'title' => 'Novo Fluxo',
            'activePage' => 'flows',
            'flow' => null,
            'departments' => $departments,
            'tags' => $tags,
            'users' => $users,
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

        View::renderWithLayout('flows/form', 'main', [
            'title' => 'Editar Fluxo: ' . $flow['name'],
            'activePage' => 'flows',
            'flow' => $flow,
            'departments' => $departments,
            'tags' => $tags,
            'users' => $users,
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
    }

}
