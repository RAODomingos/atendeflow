<?php

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\View;
use App\Models\Setting;
use App\Models\WikiArticle;
use App\Models\WikiCategory;
use App\Controllers\WikiController;

/**
 * API pública do Wiki — consumida pelo front-end hospedado em qualquer lugar.
 *
 * Autenticação: header `X-API-Key: <wiki_api_key>` OU query `?api_key=<key>`.
 * CORS aberto (`*`) pois o front fica em outro domínio/hospedagem.
 *
 *  GET /api/wiki/categories
 *  GET /api/wiki/articles[?category=slug][&featured=1][&search=...]
 *  GET /api/wiki/articles/{slug}
 *  GET /api/wiki/config  (retorna apiBase para imagens + contadores; exige key)
 */
class WikiApiController
{
    public function handleCors(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: X-API-Key, Content-Type');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
    }

    private function authenticate(): void
    {
        $this->handleCors();

        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        $lower = [];
        foreach ($headers as $k => $v) {
            $lower[strtolower($k)] = $v;
        }
        $provided = $lower['x-api-key'] ?? $_GET['api_key'] ?? null;
        $expected = (string) Setting::get('wiki_api_key', '');

        if ($expected === '' || !hash_equals($expected, (string) $provided)) {
            View::json(['error' => 'Unauthorized - Invalid API key. Informe o header X-API-Key ou ?api_key=.'], 401);
        }
    }

    public function categories(Request $request): void
    {
        $this->authenticate();
        $cats = WikiCategory::all(true);
        View::json(array_map(function (array $c) {
            return [
                'id' => (int) $c['id'],
                'slug' => $c['slug'],
                'title' => $c['title'],
                'description' => $c['description'],
                'icon_svg' => $c['icon_svg'],
                'sort_order' => (int) $c['sort_order'],
                'article_count' => (int) ($c['article_count'] ?? 0),
            ];
        }, $cats));
    }

    public function articles(Request $request): void
    {
        $this->authenticate();

        $category = trim((string) ($request->get('category') ?? ''));
        $search = trim((string) ($request->get('search') ?? ''));
        $featured = $request->get('featured') ? true : false;
        $slug = trim((string) ($request->get('slug') ?? ''));

        // Compat com o front legado: ?slug=xxx retorna 1 artigo
        if ($slug !== '') {
            $this->article($request, $slug);
            return;
        }

        if ($search !== '') {
            $rows = WikiArticle::search($search);
        } elseif ($featured) {
            $rows = WikiArticle::featured(6);
        } elseif ($category !== '') {
            $rows = WikiArticle::all(['category_slug' => $category]);
        } else {
            $rows = WikiArticle::all();
        }

        View::json(array_map([$this, 'serializeArticle'], $rows));
    }

    public function article(Request $request, string $slug): void
    {
        $this->authenticate();
        $article = WikiArticle::findBySlug($slug);
        if (!$article) {
            View::json(['error' => 'Artigo não encontrado'], 404);
        }
        WikiArticle::registerView(
            (int) $article['id'],
            $request->ip(),
            $request->userAgent(),
            $_SERVER['HTTP_REFERER'] ?? null
        );
        View::json($this->serializeArticle($article));
    }

    /**
     * Retorna dados de configuração para o front externo montar URLs absolutas.
     */
    public function config(Request $request): void
    {
        $this->authenticate();
        View::json([
            'api_base' => rtrim(base_url('/'), '/'),
            'uploads_base' => base_url('uploads/wiki'),
            'articles_count' => WikiArticle::countAll(),
            'categories_count' => count(WikiCategory::all(false)),
        ]);
    }

    /**
     * Config do ChatWeb no portal (o front injeta o widget sozinho se enabled).
     * Reaproveita a mesma Key da API do Wiki.
     */
    public function chatConfig(Request $request): void
    {
        $this->authenticate();
        View::json(WikiController::chatConfig());
    }

    private function serializeArticle(array $a): array
    {
        return [
            'id' => (int) $a['id'],
            'slug' => $a['slug'],
            'category_id' => $a['category_id'] !== null ? (int) $a['category_id'] : null,
            'category_slug' => $a['category_slug'] ?? null,
            'category_title' => $a['category_title'] ?? null,
            'title' => $a['title'],
            'description' => $a['description'],
            'content' => $a['content'],
            // Sempre URL absoluta → imagens funcionam com front em outro domínio
            'cover_image' => $this->absoluteUrl($a['cover_image'] ?? null),
            'content_absolute' => $this->absolutizeUploads((string) ($a['content'] ?? '')),
            'featured' => (int) ($a['featured'] ?? 0),
            'sort_order' => (int) ($a['sort_order'] ?? 0),
            'published_at' => $a['published_at'],
            'view_count' => (int) ($a['view_count'] ?? 0),
            'created_at' => $a['created_at'] ?? null,
            'updated_at' => $a['updated_at'] ?? null,
        ];
    }

    private function absoluteUrl(?string $url): ?string
    {
        if (!$url) {
            return null;
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }
        return base_url(ltrim($url, '/'));
    }

    /**
     * Reescreve `src="/uploads/..."` e `src="uploads/..."` dentro do HTML do
     * artigo para URLs absolutas (https://seu-atendeflow.com/uploads/...),
     * senão as imagens quebram quando o front está em outro domínio.
     */
    private function absolutizeUploads(string $html): string
    {
        if ($html === '') {
            return $html;
        }
        $base = rtrim(base_url('/'), '/');
        $html = preg_replace('#src=(["\'])/uploads/#i', 'src=$1' . $base . '/uploads/', $html);
        $html = preg_replace('#src=(["\'])(?!https?://|data:|/)(uploads/)#i', 'src=$1' . $base . '/$2', $html);
        return $html;
    }
}
