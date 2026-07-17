<?php

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\View;
use App\Models\Tag;
use App\Models\CannedResponse;
use App\Models\Macro;

class LibraryController
{
    /**
     * GET /api/v2/tags
     */
    public function tags(Request $request): void
    {
        View::json(Tag::all());
    }

    /**
     * POST /api/v2/tags
     */
    public function storeTag(Request $request): void
    {
        $name = trim((string) $request->post('name'));
        if (empty($name)) {
            View::json(['error' => 'Nome é obrigatório'], 400);
            return;
        }
        $id = Tag::create([
            'name' => $name,
            'color' => $request->post('color', '#6c757d'),
        ]);
        View::json(Tag::find($id), 201);
    }

    /**
     * PUT /api/v2/tags/{id}
     */
    public function updateTag(Request $request, int $id): void
    {
        $tag = Tag::find($id);
        if (!$tag) {
            View::json(['error' => 'Tag não encontrada'], 404);
            return;
        }
        Tag::update($id, array_filter([
            'name' => $request->post('name'),
            'color' => $request->post('color'),
        ]));
        View::json(Tag::find($id));
    }

    /**
     * DELETE /api/v2/tags/{id}
     */
    public function destroyTag(Request $request, int $id): void
    {
        $tag = Tag::find($id);
        if (!$tag) {
            View::json(['error' => 'Tag não encontrada'], 404);
            return;
        }
        Tag::delete($id);
        View::json(['ok' => true]);
    }

    /**
     * GET /api/v2/canned-responses
     */
    public function cannedResponses(Request $request): void
    {
        $deptId = $request->input('department_id') ? (int) $request->input('department_id') : null;
        View::json(CannedResponse::getForContext($deptId, Auth::id()));
    }

    /**
     * POST /api/v2/canned-responses
     */
    public function storeCanned(Request $request): void
    {
        $title = trim((string) $request->post('title'));
        $content = trim((string) $request->post('content'));
        if (empty($title) || empty($content)) {
            View::json(['error' => 'Título e conteúdo são obrigatórios'], 400);
            return;
        }
        $id = CannedResponse::create([
            'title' => $title,
            'content' => $content,
            'department_id' => $request->post('department_id') ? (int) $request->post('department_id') : null,
            'user_id' => Auth::id(),
        ]);
        View::json(CannedResponse::find($id), 201);
    }

    /**
     * DELETE /api/v2/canned-responses/{id}
     */
    public function destroyCanned(Request $request, int $id): void
    {
        CannedResponse::delete($id);
        View::json(['ok' => true]);
    }
}
