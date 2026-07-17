<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Models\Department;
use App\Models\User;

class DepartmentController
{
    public function index(Request $request): void
    {
        $departments = Department::all();
        View::renderWithLayout('departments/index', 'main', [
            'title' => 'Departamentos',
            'activePage' => 'departments',
            'departments' => $departments,
        ]);
    }

    public function show(Request $request, int $id): void
    {
        $department = Department::find($id);
        if (!$department) {
            Session::setFlash('error', 'Departamento não encontrado.');
            View::redirect('/departments');
        }

        $users = Department::getUsers($id);
        $allUsers = User::all();

        View::renderWithLayout('departments/show', 'main', [
            'title' => $department['name'],
            'activePage' => 'departments',
            'department' => $department,
            'users' => $users,
            'allUsers' => $allUsers,
        ]);
    }

    public function store(Request $request): void
    {
        $name = $request->post('name');
        if (empty(trim($name ?? ''))) {
            Session::setFlash('error', 'O nome do departamento é obrigatório.');
            View::back();
        }

        Department::create([
            'name' => $name,
            'description' => $request->post('description'),
            'color' => $request->post('color', '#4A90D9'),
        ]);

        Session::setFlash('success', 'Departamento criado com sucesso.');
        View::redirect('/departments');
    }

    public function update(Request $request, int $id): void
    {
        $name = $request->post('name');
        if (empty(trim($name ?? ''))) {
            Session::setFlash('error', 'O nome do departamento é obrigatório.');
            View::back();
        }

        Department::update($id, [
            'name' => $name,
            'description' => $request->post('description'),
            'color' => $request->post('color', '#4A90D9'),
        ]);

        Session::setFlash('success', 'Departamento atualizado.');
        View::redirect('/departments');
    }

    public function addUser(Request $request, int $id): void
    {
        $userId = $request->post('user_id');
        $isManager = $request->post('is_manager') ? true : false;

        if ($userId) {
            Department::addUser($id, (int) $userId, $isManager);
            Session::setFlash('success', 'Usuário adicionado ao departamento.');
        }

        View::redirect("/departments/{$id}");
    }

    public function removeUser(Request $request, int $id, int $userId): void
    {
        Department::removeUser($id, $userId);
        Session::setFlash('success', 'Usuário removido do departamento.');
        View::redirect("/departments/{$id}");
    }

    public function destroy(Request $request, int $id): void
    {
        Department::delete($id);
        Session::setFlash('success', 'Departamento removido.');
        View::redirect('/departments');
    }

    public function apiList(Request $request): void
    {
        $departments = Department::all();
        View::json($departments);
    }

    public function apiUsers(Request $request, int $id): void
    {
        View::json(Department::getUsers($id));
    }
}
