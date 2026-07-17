<?php

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Request;
use App\Core\View;
use App\Models\User;

class UsersController
{
    /**
     * GET /api/v2/users
     */
    public function index(Request $request): void
    {
        $users = User::all();
        // Remove passwords from response
        $users = array_map(function ($u) {
            unset($u['password']);
            return $u;
        }, $users);
        View::json($users);
    }

    /**
     * GET /api/v2/users/{id}
     */
    public function show(Request $request, int $id): void
    {
        $user = User::find($id);
        if (!$user) {
            View::json(['error' => 'Usuário não encontrado'], 404);
            return;
        }
        unset($user['password']);
        View::json($user);
    }

    /**
     * POST /api/v2/users
     */
    public function store(Request $request): void
    {
        $name = trim((string) $request->post('name'));
        $email = trim((string) $request->post('email'));
        $password = $request->post('password');

        if (empty($name) || empty($email) || empty($password)) {
            View::json(['error' => 'Nome, email e senha são obrigatórios'], 400);
            return;
        }

        $existing = User::findByEmail($email);
        if ($existing) {
            View::json(['error' => 'Email já cadastrado'], 409);
            return;
        }

        $id = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => $request->post('role', 'agent'),
        ]);

        $user = User::find($id);
        unset($user['password']);
        View::json($user, 201);
    }

    /**
     * PUT /api/v2/users/{id}
     */
    public function update(Request $request, int $id): void
    {
        $user = User::find($id);
        if (!$user) {
            View::json(['error' => 'Usuário não encontrado'], 404);
            return;
        }

        $data = array_filter([
            'name' => $request->post('name'),
            'email' => $request->post('email'),
            'role' => $request->post('role'),
            'avatar' => $request->post('avatar'),
            'signature' => $request->post('signature'),
        ]);

        if ($request->post('password')) {
            $data['password'] = $request->post('password');
        }

        User::update($id, $data);
        $updated = User::find($id);
        unset($updated['password']);
        View::json($updated);
    }

    /**
     * DELETE /api/v2/users/{id}
     */
    public function destroy(Request $request, int $id): void
    {
        $user = User::find($id);
        if (!$user) {
            View::json(['error' => 'Usuário não encontrado'], 404);
            return;
        }
        User::delete($id);
        View::json(['ok' => true]);
    }
}
