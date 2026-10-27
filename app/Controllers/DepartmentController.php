<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Models\Department;
use App\Models\User;

class DepartmentController
{
    /**
     * Lista de departamentos (aba Departamentos em Configurações — admin).
     */
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

        View::renderWithLayout('settings/departments', 'main', [
            'title' => 'Departamentos',
            'activePage' => 'settings_departments',
            'departments' => $departments,
            'stats' => [
                'total' => $totalDepts,
                'users' => $totalUsers,
                'open' => $openConvs,
                'closed' => max(0, $totalConvs - $openConvs),
            ],
        ]);
    }

    /**
     * URL antiga da listagem — mantém bookmarks funcionando.
     */
    public function redirectIndex(Request $request): void
    {
        View::redirect('/settings/departments');
    }

    public function show(Request $request, int $id): void
    {
        $department = Department::find($id);
        if (!$department) {
            Session::setFlash('error', 'Departamento não encontrado.');
            View::redirect('/settings/departments');
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
            'activePage' => 'settings_departments',
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
            'alertPhones' => Department::normalizeAlertPhones($department['sla_escalation_phone'] ?? ''),
        ]);
    }

    public function store(Request $request): void
    {
        $name = $request->post('name');
        if (empty(trim($name ?? ''))) {
            Session::setFlash('error', 'O nome do departamento é obrigatório.');
            View::back();
        }

        $phones = $this->parseAlertPhones($request);
        if ($phones === null) {
            View::back();
        }

        Department::create([
            'name' => $name,
            'description' => $request->post('description'),
            'color' => $request->post('color', '#4A90D9'),
            'sla_escalation_phone' => $phones,
        ]);

        Session::setFlash('success', 'Departamento criado com sucesso.');
        View::redirect('/settings/departments');
    }

    public function update(Request $request, int $id): void
    {
        $name = $request->post('name');
        if (empty(trim($name ?? ''))) {
            Session::setFlash('error', 'O nome do departamento é obrigatório.');
            View::back();
        }

        $data = [
            'name' => $name,
            'description' => $request->post('description'),
            'color' => $request->post('color', '#4A90D9'),
        ];

        // Os telefones de alerta são editados em outro formulário (card
        // "Alertas de SLA"). Só sobrescreve quando o campo veio no POST,
        // senão o salvar do nome/apagaria a lista.
        if ($request->post('sla_escalation_phone') !== null) {
            $phones = $this->parseAlertPhones($request);
            if ($phones === null) {
                View::back();
            }
            $data['sla_escalation_phone'] = $phones;
        }

        Department::update($id, $data);

        Session::setFlash('success', 'Departamento atualizado.');
        View::redirect('/settings/departments');
    }

    /**
     * Salva os telefones de alerta de SLA do departamento.
     *
     * Os números ficam no departamento (`sla_escalation_phone`) e são
     * avisados por WhatsApp quando o SLA é violado — a lista pode ter
     * quantos números o usuário quiser (um por linha).
     */
    public function saveAlertPhones(Request $request, int $id): void
    {
        $department = Department::find($id);
        if (!$department) {
            Session::setFlash('error', 'Departamento não encontrado.');
            View::redirect('/settings/departments');
        }

        $phones = $this->parseAlertPhones($request);
        if ($phones === null) {
            View::back();
        }

        Department::update($id, ['sla_escalation_phone' => $phones]);

        $count = count(Department::normalizeAlertPhones($phones));
        Session::setFlash(
            'success',
            $count > 0
                ? "{$count} telefone(s) de alerta salvo(s)."
                : 'Telefones de alerta removidos.'
        );
        View::redirect("/departments/{$id}");
    }

    /**
     * Lê e valida a lista de telefones enviada no formulário.
     *
     * @return string|null lista normalizada (vazia = sem telefone) ou
     *         null quando há algum número inválido (já grava o aviso)
     */
    private function parseAlertPhones(Request $request): ?string
    {
        $raw = (string) ($request->post('sla_escalation_phone') ?? '');
        $invalid = Department::invalidAlertPhones($raw);
        if ($invalid) {
            Session::setFlash(
                'error',
                'Telefone inválido: ' . implode(', ', $invalid) . '. Use DDI + DDD + número (mínimo 10 dígitos).'
            );
            return null;
        }

        $phones = Department::normalizeAlertPhones($raw);
        return $phones ? implode(', ', $phones) : null;
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
            View::redirect('/settings/departments');
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
        View::redirect('/settings/departments');
    }

    public function apiUsers(Request $request, int $id): void
    {
        View::json(Department::getUsers($id));
    }
}
