<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Models\CannedResponse;
use App\Models\ConversationSubject;
use App\Models\Department;
use App\Models\Tag;

class LibraryController
{
    public function index(Request $request): void
    {
        View::renderWithLayout('library/index', 'main', [
            'title' => 'Tags e Mensagens Prontas',
            'activePage' => 'library',
            'tags' => Tag::all(),
            'canned' => CannedResponse::all(),
            'subjects' => ConversationSubject::all(),
            'departments' => Department::all(),
            'editTag' => null,
            'editCanned' => null,
            'editSubject' => null,
        ]);
    }

    public function editTag(Request $request, int $id): void
    {
        $tag = Tag::find($id);
        if (!$tag) {
            Session::setFlash('error', 'Etiqueta não encontrada.');
            View::redirect('/library');
        }
        View::renderWithLayout('library/index', 'main', [
            'title' => 'Editar Etiqueta',
            'activePage' => 'library',
            'tags' => Tag::all(),
            'canned' => CannedResponse::all(),
            'subjects' => ConversationSubject::all(),
            'departments' => Department::all(),
            'editTag' => $tag,
            'editCanned' => null,
            'editSubject' => null,
        ]);
    }

    public function storeTag(Request $request): void
    {
        $name = trim((string) $request->post('name'));
        $color = $this->sanitizeColor((string) $request->post('color'));

        if ($name === '' || mb_strlen($name) > 50 || strpbrk($name, '<>&"\'') !== false) {
            Session::setFlash('error', 'Nome de etiqueta inválido (máx. 50 caracteres, sem <>&"\' ).');
            View::redirect('/library');
        }

        $existing = Database::getInstance()->fetch("SELECT id FROM tags WHERE name = ?", [$name]);
        if ($existing) {
            Session::setFlash('error', 'Já existe uma etiqueta com esse nome.');
            View::redirect('/library');
        }

        Tag::create(['name' => $name, 'color' => $color]);
        Session::setFlash('success', 'Etiqueta criada.');
        View::redirect('/library');
    }

    public function updateTag(Request $request, int $id): void
    {
        $tag = Tag::find($id);
        if (!$tag) {
            Session::setFlash('error', 'Etiqueta não encontrada.');
            View::redirect('/library');
        }
        $name = trim((string) $request->post('name'));
        if ($name === '' || mb_strlen($name) > 50 || strpbrk($name, '<>&"\'') !== false) {
            Session::setFlash('error', 'Nome de etiqueta inválido (máx. 50 caracteres, sem <>&"\' ).');
            View::redirect('/library');
        }
        $color = $this->sanitizeColor((string) $request->post('color'));
        Database::getInstance()->update('tags', ['name' => $name, 'color' => $color], 'id = ?', [$id]);
        Session::setFlash('success', 'Etiqueta atualizada.');
        View::redirect('/library');
    }

    public function deleteTag(Request $request, int $id): void
    {
        Database::getInstance()->delete('tags', 'id = ?', [$id]);
        Session::setFlash('success', 'Etiqueta removida.');
        View::redirect('/library');
    }

    public function editCanned(Request $request, int $id): void
    {
        $canned = Database::getInstance()->fetch("SELECT * FROM canned_responses WHERE id = ?", [$id]);
        if (!$canned) {
            Session::setFlash('error', 'Mensagem não encontrada.');
            View::redirect('/library');
        }
        View::renderWithLayout('library/index', 'main', [
            'title' => 'Editar Mensagem Pronta',
            'activePage' => 'library',
            'tags' => Tag::all(),
            'canned' => CannedResponse::all(),
            'subjects' => ConversationSubject::all(),
            'departments' => Department::all(),
            'editTag' => null,
            'editCanned' => $canned,
            'editSubject' => null,
        ]);
    }

    public function storeCanned(Request $request): void
    {
        $title = trim((string) $request->post('title'));
        $content = trim((string) $request->post('content'));
        $departmentId = $request->post('department_id') ? (int) $request->post('department_id') : null;

        if ($title === '' || $content === '') {
            Session::setFlash('error', 'Informe o título e o conteúdo da mensagem.');
            View::redirect('/library');
        }

        CannedResponse::create([
            'title' => $title,
            'content' => $content,
            'department_id' => $departmentId,
            'user_id' => Auth::id(),
        ]);
        Session::setFlash('success', 'Mensagem pronta criada.');
        View::redirect('/library');
    }

    public function updateCanned(Request $request, int $id): void
    {
        $canned = Database::getInstance()->fetch("SELECT * FROM canned_responses WHERE id = ?", [$id]);
        if (!$canned) {
            Session::setFlash('error', 'Mensagem não encontrada.');
            View::redirect('/library');
        }
        $title = trim((string) $request->post('title'));
        $content = trim((string) $request->post('content'));
        if ($title === '' || $content === '') {
            Session::setFlash('error', 'Informe o título e o conteúdo da mensagem.');
            View::redirect('/library');
        }
        $departmentId = $request->post('department_id') ? (int) $request->post('department_id') : null;
        Database::getInstance()->update(
            'canned_responses',
            ['title' => $title, 'content' => $content, 'department_id' => $departmentId],
            'id = ?',
            [$id]
        );
        Session::setFlash('success', 'Mensagem pronta atualizada.');
        View::redirect('/library');
    }

    public function deleteCanned(Request $request, int $id): void
    {
        Database::getInstance()->delete('canned_responses', 'id = ?', [$id]);
        Session::setFlash('success', 'Mensagem pronta removida.');
        View::redirect('/library');
    }

    public function editSubject(Request $request, int $id): void
    {
        $subject = ConversationSubject::find($id);
        if (!$subject) {
            Session::setFlash('error', 'Assunto não encontrado.');
            View::redirect('/library');
        }
        View::renderWithLayout('library/index', 'main', [
            'title' => 'Editar Assunto',
            'activePage' => 'library',
            'tags' => Tag::all(),
            'canned' => CannedResponse::all(),
            'subjects' => ConversationSubject::all(),
            'departments' => Department::all(),
            'editTag' => null,
            'editCanned' => null,
            'editSubject' => $subject,
        ]);
    }

    public function storeSubject(Request $request): void
    {
        $name = trim((string) $request->post('name'));
        $sortOrder = (int) ($request->post('sort_order') ?? 0);
        $departmentId = $request->post('department_id') ? (int) $request->post('department_id') : null;
        if ($name === '' || mb_strlen($name) > 255) {
            Session::setFlash('error', 'Informe o nome do assunto (máx. 255 caracteres).');
            View::redirect('/library');
        }
        if ($departmentId !== null && !Department::find($departmentId)) {
            Session::setFlash('error', 'Departamento inválido.');
            View::redirect('/library');
        }
        Database::getInstance()->insert('conversation_subjects', [
            'name' => $name,
            'sort_order' => $sortOrder,
            'department_id' => $departmentId,
        ]);
        Session::setFlash('success', 'Assunto criado.');
        View::redirect('/library');
    }

    public function updateSubject(Request $request, int $id): void
    {
        $subject = ConversationSubject::find($id);
        if (!$subject) {
            Session::setFlash('error', 'Assunto não encontrado.');
            View::redirect('/library');
        }
        $name = trim((string) $request->post('name'));
        $sortOrder = (int) ($request->post('sort_order') ?? 0);
        $departmentId = $request->post('department_id') ? (int) $request->post('department_id') : null;
        $isActive = $request->post('is_active') ? 1 : 0;
        if ($name === '' || mb_strlen($name) > 255) {
            Session::setFlash('error', 'Informe o nome do assunto (máx. 255 caracteres).');
            View::redirect('/library');
        }
        if ($departmentId !== null && !Department::find($departmentId)) {
            Session::setFlash('error', 'Departamento inválido.');
            View::redirect('/library');
        }
        Database::getInstance()->update(
            'conversation_subjects',
            ['name' => $name, 'sort_order' => $sortOrder, 'department_id' => $departmentId, 'is_active' => $isActive],
            'id = ?',
            [$id]
        );
        Session::setFlash('success', 'Assunto atualizado.');
        View::redirect('/library');
    }

    public function deleteSubject(Request $request, int $id): void
    {
        Database::getInstance()->delete('conversation_subjects', 'id = ?', [$id]);
        Session::setFlash('success', 'Assunto removido.');
        View::redirect('/library');
    }

    private function sanitizeColor(string $color): string
    {
        $color = trim($color);
        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color)) {
            return $color;
        }
        return '#6c757d';
    }
}
