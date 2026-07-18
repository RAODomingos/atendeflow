<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Models\User;
use App\Models\Department;

class UserController
{
    public function index(Request $request): void
    {
        $users = User::all();
        $departments = Department::all();

        View::renderWithLayout('users/index', 'main', [
            'title' => 'Usuários',
            'activePage' => 'users',
            'users' => $users,
            'departments' => $departments,
        ]);
    }

    public function create(Request $request): void
    {
        $departments = Department::all();
        View::renderWithLayout('users/form', 'main', [
            'title' => 'Novo Usuário',
            'activePage' => 'users',
            'user' => null,
            'departments' => $departments,
        ]);
    }

    public function store(Request $request): void
    {
        $name = $request->post('name');
        $email = $request->post('email');
        $password = $request->post('password');
        $role = $request->post('role', 'agent');

        $errors = $request->validate([
            'name' => 'required|min:3',
            'email' => 'required|email',
            'password' => 'required|min:6',
        ]);

        if (!empty($errors)) {
            Session::setFlash('errors', $errors);
            Session::setFlash('old_name', $name);
            Session::setFlash('old_email', $email);
            View::back();
        }

        if (User::findByEmail($email)) {
            Session::setFlash('error', 'Este e-mail já está em uso.');
            View::back();
        }

        $userId = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => $role,
            'avatar' => $this->processAvatar($request),
        ]);

        $departmentIds = $request->post('department_ids', []);
        if (is_array($departmentIds)) {
            foreach ($departmentIds as $deptId) {
                Department::addUser((int) $deptId, $userId);
            }
        }

        Session::setFlash('success', 'Usuário criado com sucesso.');
        View::redirect('/users');
    }

    public function edit(Request $request, int $id): void
    {
        $user = User::find($id);
        if (!$user) {
            Session::setFlash('error', 'Usuário não encontrado.');
            View::redirect('/users');
        }

        $departments = Department::all();
        $userDeptIds = User::getDepartmentIds($id);

        View::renderWithLayout('users/form', 'main', [
            'title' => 'Editar Usuário',
            'activePage' => 'users',
            'user' => $user,
            'departments' => $departments,
            'userDeptIds' => $userDeptIds,
        ]);
    }

    public function update(Request $request, int $id): void
    {
        $data = [
            'name' => $request->post('name'),
            'email' => $request->post('email'),
            'role' => $request->post('role', 'agent'),
            'is_active' => $request->post('is_active') ? 1 : 0,
        ];

        $password = $request->post('password');
        if (!empty($password)) {
            $data['password'] = $password;
        }

        $signature = $request->post('signature');
        if ($signature !== null) {
            $data['signature'] = $signature;
        }

        $avatar = $this->processAvatar($request);
        if ($avatar !== null) {
            $data['avatar'] = $avatar;
        }

        User::update($id, $data);

        // Sync departments
        Database::getInstance()->delete('department_users', 'user_id = ?', [$id]);
        $departmentIds = $request->post('department_ids', []);
        if (is_array($departmentIds)) {
            foreach ($departmentIds as $deptId) {
                Department::addUser((int) $deptId, $id);
            }
        }

        Session::setFlash('success', 'Usuário atualizado com sucesso.');
        View::redirect('/users');
    }

    public function destroy(Request $request, int $id): void
    {
        if ($id === Auth::id()) {
            Session::setFlash('error', 'Você não pode remover seu próprio usuário.');
            View::back();
        }

        User::delete($id);
        Session::setFlash('success', 'Usuário removido.');
        View::redirect('/users');
    }

    public function profile(Request $request): void
    {
        $user = Auth::user();
        $departments = Department::getUserDepartments($user['id']);
        $convCount = Database::getInstance()->fetch(
            "SELECT COUNT(*) as total FROM conversations WHERE assigned_user_id = ?", [$user['id']]
        );

        View::renderWithLayout('users/profile', 'main', [
            'title' => 'Meu Perfil',
            'activePage' => 'profile',
            'user' => $user,
            'departments' => $departments,
            'convCount' => (int) ($convCount['total'] ?? 0),
        ]);
    }

    public function updateProfile(Request $request): void
    {
        $userId = Auth::id();
        $name = $request->post('name');

        if (empty(trim($name ?? ''))) {
            Session::setFlash('error', 'O nome é obrigatório.');
            View::back();
        }

        $data = ['name' => $name, 'email' => $request->post('email')];

        $signature = $request->post('signature');
        if ($signature !== null) {
            $data['signature'] = $signature;
        }

        $password = $request->post('password');
        if (!empty($password)) {
            $data['password'] = $password;
        }

        $avatar = $this->processAvatar($request);
        if ($avatar !== null) {
            $data['avatar'] = $avatar;
        }

        User::update($userId, $data);

        // Atualiza session com dados atualizados
        Session::set('user_name', $name);
        if (isset($data['email'])) {
            Session::set('user_email', $data['email']);
        }
        if (!empty($password)) {
            // refetch user to get fresh data
            $updatedUser = User::find($userId);
            if ($updatedUser) {
                Session::set('user_name', $updatedUser['name']);
                Session::set('user_email', $updatedUser['email']);
            }
        }

        Session::setFlash('success', 'Perfil atualizado com sucesso.');
        View::redirect('/profile');
    }

    private function processAvatar(Request $request): ?string
    {
        if ($request->post('remove_avatar')) {
            return '';
        }
        $file = $request->file('avatar');
        if (empty($file['tmp_name'])) {
            return null;
        }
        $saved = save_uploaded_file('avatar', ['image' => ['jpg', 'jpeg', 'png', 'gif', 'webp']], 'avatars');
        return $saved ? $saved['path'] : null;
    }
}
