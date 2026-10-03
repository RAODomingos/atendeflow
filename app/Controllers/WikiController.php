<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Core\Database;
use App\Models\Setting;
use App\Models\WebchatWidget;
use App\Models\WikiArticle;
use App\Models\WikiCategory;

/**
 * Módulo Wiki / Base de Conhecimento — área administrativa.
 *
 * Integrado ao OminiDesk (substitui a pasta wiki/admin legada):
 *  - GET  /wiki                  → dashboard + dados de integração (URL + Key)
 *  - CRUD de categorias e artigos (gerente+)
 *  - POST /wiki/settings/regenerate-key (admin) → gera nova API Key
 */
class WikiController
{
    // ── Dashboard ──────────────────────────────────────────────
    public function index(Request $request): void
    {
        View::renderWithLayout('wiki/index', 'main', [
            'title' => 'Base de Conhecimento (Wiki)',
            'activePage' => 'wiki',
            'categories' => WikiCategory::all(),
            'articles' => WikiArticle::all(),
            'totalArticles' => WikiArticle::countAll(),
            'apiBaseUrl' => $this->apiBaseUrl(),
            'apiKey' => $this->apiKey(),
            'portalUrl' => self::portalUrl(),
        ]);
    }

    // ── Categorias ─────────────────────────────────────────────
    public function categories(Request $request): void
    {
        View::renderWithLayout('wiki/categories', 'main', [
            'title' => 'Wiki — Categorias',
            'activePage' => 'wiki',
            'categories' => WikiCategory::all(),
            'apiBaseUrl' => $this->apiBaseUrl(),
            'apiKey' => $this->apiKey(),
        ]);
    }

    public function categoryForm(Request $request, ?int $id = null): void
    {
        $category = $id ? WikiCategory::find($id) : null;
        if ($id && !$category) {
            Session::setFlash('error', 'Categoria não encontrada.');
            View::redirect('/wiki/categories');
        }
        View::renderWithLayout('wiki/category_form', 'main', [
            'title' => $category ? 'Editar categoria' : 'Nova categoria',
            'activePage' => 'wiki',
            'category' => $category,
        ]);
    }

    public function storeCategory(Request $request): void
    {
        $title = trim((string) $request->post('title'));
        $slug = trim((string) $request->post('slug')) ?: slugify($title);

        if ($title === '') {
            Session::setFlash('error', 'Informe o título da categoria.');
            View::redirect('/wiki/categories/create');
        }
        if (WikiCategory::slugExists($slug)) {
            $slug .= '-' . time();
        }

        WikiCategory::create([
            'slug' => $slug,
            'title' => $title,
            'description' => trim((string) $request->post('description')) ?: null,
            'icon_svg' => trim((string) $request->post('icon_svg')) ?: null,
            'sort_order' => (int) $request->post('sort_order', 0),
        ]);
        Session::setFlash('success', 'Categoria criada.');
        View::redirect('/wiki/categories');
    }

    public function updateCategory(Request $request, int $id): void
    {
        $category = WikiCategory::find($id);
        if (!$category) {
            Session::setFlash('error', 'Categoria não encontrada.');
            View::redirect('/wiki/categories');
        }
        $title = trim((string) $request->post('title'));
        $slug = trim((string) $request->post('slug')) ?: slugify($title);
        if ($title === '') {
            Session::setFlash('error', 'Informe o título da categoria.');
            View::redirect('/wiki/categories/' . $id . '/edit');
        }
        if (WikiCategory::slugExists($slug, $id)) {
            $slug .= '-' . time();
        }

        WikiCategory::update($id, [
            'slug' => $slug,
            'title' => $title,
            'description' => trim((string) $request->post('description')) ?: null,
            'icon_svg' => trim((string) $request->post('icon_svg')) ?: null,
            'sort_order' => (int) $request->post('sort_order', 0),
        ]);
        Session::setFlash('success', 'Categoria atualizada.');
        View::redirect('/wiki/categories');
    }

    public function deleteCategory(Request $request, int $id): void
    {
        WikiCategory::delete($id);
        Session::setFlash('success', 'Categoria removida. Artigos vinculados ficaram sem categoria.');
        View::redirect('/wiki/categories');
    }

    public function reorderCategories(Request $request): void
    {
        $order = $request->post('order');
        if (is_string($order)) {
            $order = json_decode($order, true);
        }
        if (is_array($order)) {
            WikiCategory::reorder(array_map('intval', $order));
        }
        View::json(['ok' => true]);
    }

    // ── Artigos ────────────────────────────────────────────────
    public function articles(Request $request): void
    {
        $filters = [
            'q' => trim((string) $request->get('q', '')),
            'category_slug' => trim((string) $request->get('cat', '')),
            'featured' => $request->get('featured') ? 1 : 0,
        ];
        View::renderWithLayout('wiki/articles', 'main', [
            'title' => 'Wiki — Artigos',
            'activePage' => 'wiki',
            'articles' => WikiArticle::all($filters),
            'categories' => WikiCategory::all(false),
            'filters' => $filters,
            'portalUrl' => self::portalUrl(),
        ]);
    }

    public function articleForm(Request $request, ?int $id = null): void
    {
        $article = $id ? WikiArticle::find($id) : null;
        if ($id && !$article) {
            Session::setFlash('error', 'Artigo não encontrado.');
            View::redirect('/wiki/articles');
        }
        View::renderWithLayout('wiki/article_form', 'main', [
            'title' => $article ? 'Editar artigo' : 'Novo artigo',
            'activePage' => 'wiki',
            'article' => $article,
            'categories' => WikiCategory::all(false),
        ]);
    }

    public function storeArticle(Request $request): void
    {
        $title = trim((string) $request->post('title'));
        if ($title === '') {
            Session::setFlash('error', 'Informe o título do artigo.');
            View::redirect('/wiki/articles/create');
        }
        $slug = trim((string) $request->post('slug')) ?: slugify($title);
        if (WikiArticle::slugExists($slug)) {
            $slug .= '-' . time();
        }
        $categoryId = $request->post('category_id') ? (int) $request->post('category_id') : null;

        WikiArticle::create([
            'slug' => $slug,
            'category_id' => $categoryId,
            'title' => $title,
            'description' => trim((string) $request->post('description')) ?: null,
            'content' => (string) $request->post('content', ''),
            'cover_image' => $this->normalizeCover((string) $request->post('cover_image', '')),
            'featured' => $request->post('featured') ? 1 : 0,
            'sort_order' => (int) $request->post('sort_order', 0),
            'published_at' => trim((string) $request->post('published_at')) ?: date('Y-m-d'),
        ]);
        Session::setFlash('success', 'Artigo criado.');
        View::redirect('/wiki/articles');
    }

    public function updateArticle(Request $request, int $id): void
    {
        $article = WikiArticle::find($id);
        if (!$article) {
            Session::setFlash('error', 'Artigo não encontrado.');
            View::redirect('/wiki/articles');
        }
        $title = trim((string) $request->post('title'));
        if ($title === '') {
            Session::setFlash('error', 'Informe o título do artigo.');
            View::redirect('/wiki/articles/' . $id . '/edit');
        }
        $slug = trim((string) $request->post('slug')) ?: slugify($title);
        if (WikiArticle::slugExists($slug, $id)) {
            $slug .= '-' . time();
        }

        WikiArticle::update($id, [
            'slug' => $slug,
            'category_id' => $request->post('category_id') ? (int) $request->post('category_id') : null,
            'title' => $title,
            'description' => trim((string) $request->post('description')) ?: null,
            'content' => (string) $request->post('content', ''),
            'cover_image' => $this->normalizeCover((string) $request->post('cover_image', '')),
            'featured' => $request->post('featured') ? 1 : 0,
            'sort_order' => (int) $request->post('sort_order', 0),
            'published_at' => trim((string) $request->post('published_at')) ?: $article['published_at'],
        ]);
        Session::setFlash('success', 'Artigo atualizado.');
        View::redirect('/wiki/articles');
    }

    public function deleteArticle(Request $request, int $id): void
    {
        WikiArticle::delete($id);
        Session::setFlash('success', 'Artigo removido.');
        View::redirect('/wiki/articles');
    }

    public function reorderArticles(Request $request): void
    {
        $order = $request->post('order');
        if (is_string($order)) {
            $order = json_decode($order, true);
        }
        if (is_array($order)) {
            WikiArticle::reorder(array_map('intval', $order));
        }
        View::json(['ok' => true]);
    }

    /**
     * Upload de imagem (capa / editor Quill).
     * Rota: POST /api/wiki/upload (grupo auth sem CSRF, gerente+).
     * Retorna { "url": "<absolute-url>" } — o front externo usa a URL absoluta.
     */
    public function upload(Request $request): void
    {
        $uploaded = save_uploaded_file('file', [
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        ], 'wiki');

        if (!$uploaded) {
            View::json(['error' => 'Upload inválido. Use JPG, PNG, GIF ou WEBP (máx. 10 MB).'], 400);
        }
        View::json(['url' => $uploaded['url']]);
    }

    // ── Integração / API Key ───────────────────────────────────
    public function settings(Request $request): void
    {
        View::renderWithLayout('wiki/settings', 'main', [
            'title' => 'Wiki — Integração (URL + Key)',
            'activePage' => 'wiki',
            'apiBaseUrl' => $this->apiBaseUrl(),
            'apiKey' => $this->apiKey(),
            'totalArticles' => WikiArticle::countAll(),
            'totalCategories' => count(WikiCategory::all(false)),
            'portalUrl' => self::portalUrl(),
            'chat' => self::chatConfig(),
            'chatWidgets' => self::chatWidgets(),
        ]);
    }

    public function regenerateKey(Request $request): void
    {
        Setting::set('wiki_api_key', bin2hex(random_bytes(32)));
        Session::setFlash('success', 'Nova chave da API do Wiki gerada. Atualize o front-end externo.');
        View::redirect('/wiki/settings');
    }

    // ── ChatWeb no portal ──────────────────────────────────────
    public function saveChat(Request $request): void
    {
        $enabled = $request->post('enabled') ? '1' : '0';
        $key = trim((string) $request->post('widget_key'));
        $title = trim((string) $request->post('title')) ?: 'Atendimento';
        $color = trim((string) $request->post('color')) ?: '#2f6fed';
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = '#2f6fed';
        }
        $position = $request->post('position') === 'left' ? 'left' : 'right';

        if ($enabled === '1') {
            if ($key === '') {
                Session::setFlash('error', 'Informe a chave do widget do ChatWeb ou desative a integração.');
                View::redirect('/wiki/settings');
            }
            $exists = Database::getInstance()->fetch(
                "SELECT id FROM webchat_widgets WHERE widget_key = ?",
                [$key]
            );
            if (!$exists) {
                Session::setFlash('error', 'Widget não encontrado. Confira a chave em Configurações → Canais → ChatWeb.');
                View::redirect('/wiki/settings');
            }
        }

        Setting::set('wiki_chat_enabled', $enabled);
        Setting::set('wiki_chat_widget_key', $key);
        Setting::set('wiki_chat_title', $title);
        Setting::set('wiki_chat_color', $color);
        Setting::set('wiki_chat_position', $position);
        Session::setFlash('success', 'Integração do ChatWeb atualizada.');
        View::redirect('/wiki/settings');
    }

    /**
     * URL pública do portal (link que o cliente abre). Configurável em
     * /wiki/settings; padrão: portal servido pelo próprio OminiDesk.
     */
    public static function portalUrl(): string
    {
        $url = trim((string) Setting::get('wiki_portal_url', ''));
        if ($url === '') {
            $url = rtrim(base_url('/'), '/') . '/wiki-frontend/index.html';
        }
        return rtrim($url, '/');
    }

    public function savePortal(Request $request): void
    {
        $url = trim((string) $request->post('portal_url'));
        if ($url === '') {
            $url = rtrim(base_url('/'), '/') . '/wiki-frontend/index.html';
        }
        Setting::set('wiki_portal_url', rtrim($url, '/'));
        Session::setFlash('success', 'URL do portal atualizada. Os links enviados no chat usam esse endereço.');
        View::redirect('/wiki/settings');
    }

    /**
     * Sugestão de artigos para o atendente (GET /api/wiki/suggest?q=).
     * Rota interna autenticada — retorna o LINK DO CLIENTE (portal público).
     */
    public function suggest(Request $request): void
    {
        $q = trim((string) $request->get('q', ''));
        $rows = $q === '' ? WikiArticle::all() : WikiArticle::search($q, 20);
        $base = self::portalUrl();
        View::json(array_map(function (array $a) use ($base) {
            return [
                'id' => (int) $a['id'],
                'slug' => $a['slug'],
                'title' => $a['title'],
                'description' => $a['description'],
                'category' => $a['category_title'] ?? null,
                'url' => $base . '#article/' . $a['slug'],
            ];
        }, array_slice($rows, 0, 10)));
    }

    /**
     * Widgets de ChatWeb disponíveis (para o seletor da tela de integração).
     */
    public static function chatWidgets(): array
    {
        try {
            return WebchatWidget::all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Config efetiva do ChatWeb no portal: valores salvos + fallbacks
     * (primeiro widget ativo / padrões). Usada pela tela admin e pela API.
     */
    public static function chatConfig(): array
    {
        $widgets = self::chatWidgets();
        $firstActive = null;
        foreach ($widgets as $w) {
            if (!empty($w['is_active'])) {
                $firstActive = $w;
                break;
            }
        }
        $key = trim((string) Setting::get('wiki_chat_widget_key', ''));
        $widget = null;
        foreach ($widgets as $w) {
            if ($w['widget_key'] === $key && $key !== '') {
                $widget = $w;
                break;
            }
        }
        if (!$widget) {
            $widget = $firstActive;
        }
        if ($key === '' && $widget) {
            $key = (string) $widget['widget_key'];
        }
        return [
            'enabled' => Setting::get('wiki_chat_enabled', '1') === '1',
            'widget_key' => $key,
            'title' => (string) Setting::get('wiki_chat_title', $widget['title'] ?? 'Atendimento'),
            'color' => (string) Setting::get('wiki_chat_color', $widget['color_primary'] ?? '#2f6fed'),
            'position' => (string) Setting::get('wiki_chat_position', $widget['position'] ?? 'right'),
            'api_url' => rtrim(base_url('/'), '/'),
            'avatar_url' => $widget['avatar_url'] ?? null,
            'fields' => [
                'name' => [
                    'ask' => $widget ? !empty($widget['ask_name']) : true,
                    'required' => $widget ? !empty($widget['require_name']) : true,
                ],
                'email' => [
                    'ask' => !empty($widget['ask_email'] ?? null),
                    'required' => !empty($widget['require_email'] ?? null),
                ],
                'phone' => [
                    'ask' => !empty($widget['ask_phone'] ?? null),
                    'required' => !empty($widget['require_phone'] ?? null),
                ],
                'cnpj' => [
                    'ask' => !empty($widget['ask_cnpj'] ?? null),
                    'required' => !empty($widget['require_cnpj'] ?? null),
                ],
            ],
        ];
    }

    // ── Helpers ────────────────────────────────────────────────
    private function apiKey(): string
    {
        $key = (string) Setting::get('wiki_api_key', '');
        if ($key === '') {
            $key = bin2hex(random_bytes(32));
            Setting::set('wiki_api_key', $key);
        }
        return $key;
    }

    private function apiBaseUrl(): string
    {
        return rtrim(base_url('/'), '/');
    }

    /**
     * Normaliza a capa: aceita URL absoluta, caminho /uploads/... ou relativo.
     * Converte caminho relativo em URL absoluta para o front externo funcionar.
     */
    private function normalizeCover(string $cover): ?string
    {
        $cover = trim($cover);
        if ($cover === '') {
            return null;
        }
        if (str_starts_with($cover, 'http://') || str_starts_with($cover, 'https://')) {
            return $cover;
        }
        if (str_starts_with($cover, '/uploads/') || str_starts_with($cover, 'uploads/')) {
            return base_url(ltrim($cover, '/'));
        }
        return $cover;
    }
}
