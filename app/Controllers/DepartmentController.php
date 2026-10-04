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
        $db = Database::getInstance();
        $totalDepts = count($departments);
        $totalUsers = (int) ($db->fetch(
            "SELECT COUNT(DISTINCT user_id) as t FROM department_users"
        )['t'] ?? 0);
        $openConvs = (int) ($db->fetch(
            "SELECT COUNT(*) as t FROM conversations WHERE status NOT IN ('resolved','closed','spam') AND department_id IS NOT NULL"
        )['t'] ?? 0);
        $totalConvs = (int) ($db->fetch(
            "SELECT COUNT(*) as t FROM conversations WHERE department_id IS NOT NULL"
        )['t'] ?? 0);

        View::renderWithLayout('departments/index', 'main', [
            'title' => 'Departamentos',
            'activePage' => 'departments',
            'departments' => $departments,
            'stats' => [
                'total' => $totalDepts,
                'users' => $totalUsers,
                'open' => $openConvs,
                'closed' => max(0, $totalConvs - $openConvs),
            ],
        ]);
    }

    public function show(Request $request, int $id): void
    {
        $department = Department::find($id);
        if (!$department) {
            Session::setFlash('error', 'Departamento não encontrado.');
            View::redirect('/departments');
        }

        if (!Auth::isManager()) {
            $member = \App\Core\Database::getInstance()->fetch(
                "SELECT 1 FROM department_users WHERE department_id = ? AND user_id = ? LIMIT 1",
                [$id, Auth::id()]
            );
            if (!$member) {
                Session::setFlash('error', 'Voce nao tem acesso a este departamento.');
                View::redirect('/departments');
            }
        }

        $filters = [
            'year'    => (int) $request->get('year', 0),
            'month'   => (int) $request->get('month', 0),
            'status'  => trim((string) $request->get('status', '')) ?: null,
        ];

        $users = Department::getUsers($id);
        $allUsers = Department::getUsersNotInDepartment($id);

        $conversations = Department::getConversations($id, $filters);
        $statusCounts  = Department::countConversationsByStatus($id, $filters);
        $availableMonths = Department::getAvailableMonths($id);

        $bh = \App\Services\BusinessHoursService::class;

        View::renderWithLayout('departments/show', 'main', [
            'title' => $department['name'],
            'activePage' => 'departments',
            'department' => $department,
            'users' => $users,
            'allUsers' => $allUsers,
            'conversations' => $conversations,
            'statusCounts' => $statusCounts,
            'availableMonths' => $availableMonths,
            'filters' => $filters,
            'bhEnabled' => (int) ($department['business_hours_enabled'] ?? 0) === 1,
            'bhTimezone' => $department['business_hours_timezone'] ?? 'America/Sao_Paulo',
            'bhAbsence' => $department['absence_message'] ?? '',
            'bhSchedule' => $bh::scheduleFor($id),
            'bhIsOpen' => $bh::isOpen($id),
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

    /**
     * Salva o horário de funcionamento do departamento
     * (ativação, fuso, mensagem de ausência e grade semanal).
     */
    public function saveBusinessHours(Request $request, int $id): void
    {
        $department = Department::find($id);
        if (!$department) {
            Session::setFlash('error', 'Departamento não encontrado.');
            View::redirect('/departments');
        }

        Department::update($id, [
            'business_hours_enabled' => $request->post('business_hours_enabled') ? 1 : 0,
            'business_hours_timezone' => trim((string) $request->post('business_hours_timezone')) ?: 'America/Sao_Paulo',
            'absence_message' => trim((string) $request->post('absence_message')) ?: null,
        ]);

        \App\Services\BusinessHoursService::saveSchedule($id, (array) ($request->post('bh') ?: []));

        Session::setFlash('success', 'Horário de funcionamento salvo.');
        View::redirect("/departments/{$id}");
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

    public function apiUsers(Request $request, int $id): void
    {
        View::json(Department::getUsers($id));
    }
}
