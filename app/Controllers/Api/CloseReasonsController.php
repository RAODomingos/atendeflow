<?php

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\View;
use App\Models\CloseReason;

class CloseReasonsController
{
    public function index(Request $request): void
    {
        $onlyActive = $request->input('all') !== '1';
        View::json(['reasons' => CloseReason::all($onlyActive)]);
    }

    public function store(Request $request): void
    {
        if (!Auth::isAdmin()) {
            View::json(['error' => 'Apenas administradores podem cadastrar motivos'], 403);
            return;
        }
        $code = trim((string) $request->post('code', ''));
        $label = trim((string) $request->post('label', ''));
        if ($code === '' || $label === '') {
            View::json(['error' => 'Informe o código e o rótulo do motivo'], 422);
            return;
        }
        $code = preg_replace('/[^a-z0-9_]/', '', strtolower($code));
        if ($code === '') {
            View::json(['error' => 'Código inválido (use letras minúsculas, números e _)'], 422);
            return;
        }
        $id = CloseReason::create([
            'code' => $code,
            'label' => $label,
            'description' => trim((string) $request->post('description', '')) ?: null,
            'icon' => trim((string) $request->post('icon', '')) ?: 'fa-tag',
            'color' => trim((string) $request->post('color', '')) ?: '#6c757d',
            'sort_order' => (int) $request->post('sort_order', 0),
            'is_active' => 1,
        ]);
        View::json(['ok' => true, 'id' => $id]);
    }

    public function update(Request $request, int $id): void
    {
        if (!Auth::isAdmin()) {
            View::json(['error' => 'Apenas administradores podem editar motivos'], 403);
            return;
        }
        $row = CloseReason::find($id);
        if (!$row) {
            View::json(['error' => 'Motivo não encontrado'], 404);
            return;
        }
        $data = [];
        foreach (['label', 'description', 'icon', 'color'] as $f) {
            if ($request->post($f) !== null) $data[$f] = trim((string) $request->post($f));
        }
        if (isset($data['color']) && !preg_match('/^#[0-9a-fA-F]{3,7}$/', $data['color'])) {
            $data['color'] = '#6c757d';
        }
        if ($request->post('sort_order') !== null) $data['sort_order'] = (int) $request->post('sort_order');
        if ($request->post('is_active') !== null) $data['is_active'] = (int) $request->post('is_active') ? 1 : 0;
        CloseReason::update($id, $data);
        View::json(['ok' => true]);
    }

    public function delete(Request $request, int $id): void
    {
        if (!Auth::isAdmin()) {
            View::json(['error' => 'Apenas administradores podem remover motivos'], 403);
            return;
        }
        CloseReason::delete($id);
        View::json(['ok' => true]);
    }
}
