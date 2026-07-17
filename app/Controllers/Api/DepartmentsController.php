<?php

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Request;
use App\Core\View;
use App\Models\Department;
use App\Models\User;

class DepartmentsController
{
    /**
     * GET /api/v2/departments
     */
    public function index(Request $request): void
    {
        $departments = Department::all();
        View::json($departments);
    }

    /**
     * GET /api/v2/departments/{id}
     */
    public function show(Request $request, int $id): void
    {
        $department = Department::find($id);
        if (!$department) {
            View::json(['error' => 'Departamento não encontrado'], 404);
            return;
        }
        $department['users'] = Department::getUsers($id);
        View::json($department);
    }

    /**
     * GET /api/v2/departments/{id}/users
     */
    public function users(Request $request, int $id): void
    {
        View::json(Department::getUsers($id));
    }

    /**
     * POST /api/v2/departments
     */
    public function store(Request $request): void
    {
        $name = trim((string) $request->post('name'));
        if (empty($name)) {
            View::json(['error' => 'Nome é obrigatório'], 400);
            return;
        }

        $id = Department::create([
            'name' => $name,
            'description' => $request->post('description'),
            'color' => $request->post('color', '#4A90D9'),
        ]);

        View::json(Department::find($id), 201);
    }

    /**
     * PUT /api/v2/departments/{id}
     */
    public function update(Request $request, int $id): void
    {
        $department = Department::find($id);
        if (!$department) {
            View::json(['error' => 'Departamento não encontrado'], 404);
            return;
        }

        Department::update($id, array_filter([
            'name' => $request->post('name'),
            'description' => $request->post('description'),
            'color' => $request->post('color'),
        ]));

        View::json(Department::find($id));
    }

    /**
     * DELETE /api/v2/departments/{id}
     */
    public function destroy(Request $request, int $id): void
    {
        $department = Department::find($id);
        if (!$department) {
            View::json(['error' => 'Departamento não encontrado'], 404);
            return;
        }
        Department::delete($id);
        View::json(['ok' => true]);
    }

    /**
     * POST /api/v2/departments/{id}/users
     */
    public function addUser(Request $request, int $id): void
    {
        $userId = (int) $request->post('user_id');
        $isManager = (bool) $request->post('is_manager', false);
        if ($userId) {
            Department::addUser($id, $userId, $isManager);
        }
        View::json(['ok' => true]);
    }

    /**
     * DELETE /api/v2/departments/{id}/users/{userId}
     */
    public function removeUser(Request $request, int $id, int $userId): void
    {
        Department::removeUser($id, $userId);
        View::json(['ok' => true]);
    }
}
