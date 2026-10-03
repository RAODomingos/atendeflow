<?php

use App\Core\Router;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\InboxController;
use App\Controllers\LibraryController;
use App\Controllers\ContactController;
use App\Controllers\DepartmentController;
use App\Controllers\FlowController;
use App\Controllers\UserController;
use App\Controllers\WebChatController;
use App\Controllers\SettingsController;
use App\Controllers\WhatsAppController;
use App\Controllers\WhatsAppGroupController;
use App\Controllers\CsatController;
use App\Controllers\ReportsController;
use App\Controllers\WikiController;
/** @var Router $router */
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->post('/logout', [AuthController::class, 'logout']);

// Public widget routes (no auth required)
$router->get('/widget/demo/{key}', [WebChatController::class, 'demo']);
$router->get('/widget/chat.js', [WebChatController::class, 'widgetJs']);
$router->get('/widget/chat.css', [WebChatController::class, 'widgetCss']);
$router->post('/api/webchat/session', [WebChatController::class, 'session']);
$router->post('/api/webchat/resume', [WebChatController::class, 'resume']);
$router->get('/api/webchat/messages', [WebChatController::class, 'messages']);
$router->post('/api/webchat/messages', [WebChatController::class, 'messages']);
$router->post('/api/webchat/csat', [WebChatController::class, 'csat']);
$router->post('/api/webchat/messages/{id}/edit', [WebChatController::class, 'editMessage']);
$router->post('/api/webchat/messages/{id}/delete', [WebChatController::class, 'deleteMessage']);
$router->post('/api/webchat/messages/{id}/reaction', [WebChatController::class, 'reactMessage']);

// Public CSAT evaluation page (no auth required — link sent to customers)
$router->get('/csat/{token}', [CsatController::class, 'show']);
$router->post('/csat/{token}', [CsatController::class, 'submit']);

// Public WhatsApp webhook (called by WAHA / Evolution API — no auth)
$router->post('/webhooks/whatsapp', [WhatsAppController::class, 'webhook']);

// Public Wiki API (front-end externo hospedado em qualquer lugar — auth via X-API-Key)
$router->get('/api/wiki/categories', [\App\Controllers\Api\WikiApiController::class, 'categories']);
$router->get('/api/wiki/articles', [\App\Controllers\Api\WikiApiController::class, 'articles']);
$router->get('/api/wiki/articles/{slug}', [\App\Controllers\Api\WikiApiController::class, 'article']);
$router->get('/api/wiki/config', [\App\Controllers\Api\WikiApiController::class, 'config']);
$router->get('/api/wiki/chat-config', [\App\Controllers\Api\WikiApiController::class, 'chatConfig']);

$router->group('', function (Router $router) {
    $router->get('/', [DashboardController::class, 'index']);

    // Inbox
    $router->get('/inbox', [InboxController::class, 'index']);
    $router->get('/inbox/mine', [InboxController::class, 'mine']);
    $router->get('/inbox/unassigned', [InboxController::class, 'unassigned']);
    $router->get('/inbox/chatbot', [InboxController::class, 'chatbot']); // redireciona para /inbox
    $router->get('/inbox/new', [InboxController::class, 'newConversation']);
    $router->post('/inbox/new', [InboxController::class, 'createConversation']);
    $router->get('/inbox/{id}/panel', [InboxController::class, 'conversationPanel']);
    $router->get('/inbox/{id}/pdf', [InboxController::class, 'downloadPdf']);
    $router->get('/inbox/{id}', [InboxController::class, 'show']);
    $router->post('/inbox/{id}/messages', [InboxController::class, 'sendMessage']);
    $router->post('/inbox/{id}/settings', [InboxController::class, 'updateSettings']);
    $router->post('/inbox/{id}/subject', [InboxController::class, 'updateSubject']);
    $router->post('/inbox/{id}/unit', [InboxController::class, 'updateUnit']);
    $router->post('/inbox/{id}/assign', [InboxController::class, 'assign']);
    $router->post('/inbox/{id}/transfer', [InboxController::class, 'transfer']);
    $router->post('/inbox/{id}/status', [InboxController::class, 'changeStatus']);
    $router->post('/inbox/{id}/priority', [InboxController::class, 'changePriority']);
    $router->post('/inbox/{id}/tags', [InboxController::class, 'addTag']);
    $router->post('/inbox/{id}/untag', [InboxController::class, 'removeTag']);
    $router->post('/inbox/{id}/notes', [InboxController::class, 'addInternalNote']);
    $router->post('/inbox/{id}/messages/{mid}/edit', [InboxController::class, 'editMessage']);
    $router->post('/inbox/{id}/messages/{mid}/delete', [InboxController::class, 'deleteMessage']);
    $router->post('/inbox/{id}/messages/{mid}/reaction', [InboxController::class, 'sendReaction']);
    $router->post('/inbox/{id}/messages/{mid}/retry', [InboxController::class, 'retryMessage']);
    $router->post('/inbox/{id}/read', [InboxController::class, 'markRead']);
    $router->post('/inbox/{id}/snooze', [InboxController::class, 'snooze']);
    $router->post('/inbox/{id}/merge', [InboxController::class, 'merge']);
    $router->post('/inbox/{id}/macro', [InboxController::class, 'applyMacro']);
    $router->post('/inbox/bulk', [InboxController::class, 'bulk']);

    // Configurações gerais (admin: CSAT global)
    $router->post('/settings', [SettingsController::class, 'saveGeneral'], ['admin']);

    // Configurações de Notificações e Sons (admin: contém SLA global)
    $router->get('/settings/notifications', [SettingsController::class, 'notifications'], ['admin']);
    $router->post('/settings/notifications', [SettingsController::class, 'saveNotifications'], ['admin']);

    // Contacts (exclusão é gerente+)
    $router->get('/contacts', [ContactController::class, 'index']);
    $router->get('/contacts/create', [ContactController::class, 'create']);
    $router->post('/contacts/create', [ContactController::class, 'store']);
    $router->get('/contacts/{id}', [ContactController::class, 'show']);
    $router->get('/contacts/{id}/stores', [ContactController::class, 'apiContactStores']);
    $router->get('/contacts/{id}/pdf', [ContactController::class, 'downloadPdf']);
    $router->get('/contacts/{id}/edit', [ContactController::class, 'edit']);
    $router->post('/contacts/{id}/merge', [ContactController::class, 'merge']);
    $router->post('/contacts/{id}/update', [ContactController::class, 'update']);
    $router->post('/contacts/{id}/delete', [ContactController::class, 'destroy'], ['manager']);

    // Departments (gestão é gerente+; ver é qualquer autenticado)
    $router->get('/departments', [DepartmentController::class, 'index']);
    $router->post('/departments/create', [DepartmentController::class, 'store'], ['manager']);
    $router->get('/departments/{id}', [DepartmentController::class, 'show']);
    $router->post('/departments/{id}/update', [DepartmentController::class, 'update'], ['manager']);
    $router->post('/departments/{id}/business-hours', [DepartmentController::class, 'saveBusinessHours'], ['manager']);
    $router->post('/departments/{id}/users', [DepartmentController::class, 'addUser'], ['manager']);
    $router->post('/departments/{id}/users/{userId}/remove', [DepartmentController::class, 'removeUser'], ['manager']);
    $router->post('/departments/{id}/delete', [DepartmentController::class, 'destroy'], ['manager']);

    // Flows (edição é gerente+)
    $router->get('/flows', [FlowController::class, 'index']);
    $router->get('/flows/create', [FlowController::class, 'create']);
    $router->post('/flows/create', [FlowController::class, 'store'], ['manager']);
    $router->get('/flows/{id}/edit', [FlowController::class, 'edit']);
    $router->post('/flows/{id}/edit', [FlowController::class, 'update'], ['manager']);
    $router->post('/flows/{id}/publish', [FlowController::class, 'publish'], ['manager']);
    $router->post('/flows/{id}/duplicate', [FlowController::class, 'duplicate'], ['manager']);
    $router->post('/flows/{id}/delete', [FlowController::class, 'destroy'], ['manager']);

    // Users (admin only)
    $router->get('/users', [UserController::class, 'index'], ['admin']);
    $router->get('/users/create', [UserController::class, 'create'], ['admin']);
    $router->post('/users/create', [UserController::class, 'store'], ['admin']);
    $router->get('/users/{id}/edit', [UserController::class, 'edit'], ['admin']);
    $router->post('/users/{id}/edit', [UserController::class, 'update'], ['admin']);
    $router->post('/users/{id}/delete', [UserController::class, 'destroy'], ['admin']);

    // Profile
    $router->get('/profile', [UserController::class, 'profile']);
    $router->post('/profile', [UserController::class, 'updateProfile']);

    // Notifications (web JSON API)
    $router->get('/notifications', [\App\Controllers\Api\NotificationsController::class, 'index']);
    $router->post('/notifications/{id}/read', [\App\Controllers\Api\NotificationsController::class, 'markRead']);
    $router->post('/notifications/read-all', [\App\Controllers\Api\NotificationsController::class, 'markAllRead']);
    $router->get('/notifications/unread-count', [\App\Controllers\Api\NotificationsController::class, 'unreadCount']);

    // Channels & Settings (admin)
    $router->get('/channels', [SettingsController::class, 'channels'], ['admin']);
    $router->post('/channels/create', [SettingsController::class, 'createChannel'], ['admin']);
    $router->post('/channels/{id}/update', [SettingsController::class, 'updateChannel'], ['admin']);
    $router->post('/channels/{id}/delete', [SettingsController::class, 'deleteChannel'], ['admin']);
    $router->post('/channels/widget/{id}/regenerate-key', [SettingsController::class, 'regenerateWidgetKey'], ['admin']);
    $router->get('/settings', [SettingsController::class, 'general'], ['admin']);

    // WhatsApp (admin: conexões do provedor)
    $router->post('/whatsapp/{id}/connect', [WhatsAppController::class, 'connect'], ['admin']);
    $router->get('/whatsapp/{id}/status', [WhatsAppController::class, 'status'], ['admin']);
    $router->post('/whatsapp/{id}/disconnect', [WhatsAppController::class, 'disconnect'], ['admin']);

    // Grupos WhatsApp (leitura: qualquer autenticado; gestão: gerente+)
    $router->get('/whatsapp/groups', [WhatsAppGroupController::class, 'index']);
    $router->get('/whatsapp/groups/{id}', [WhatsAppGroupController::class, 'show']);
    $router->post('/whatsapp/groups/{id}/read', [WhatsAppGroupController::class, 'markRead']);
    $router->post('/whatsapp/groups/{id}/alert', [WhatsAppGroupController::class, 'toggleAlert'], ['manager']);
    $router->post('/whatsapp/groups/{id}/inbox', [WhatsAppGroupController::class, 'setInbox'], ['manager']);
    $router->post('/whatsapp/groups/{id}/send', [WhatsAppGroupController::class, 'send'], ['manager']);

    // Inboxes management (admin)
    $router->get('/inboxes', [SettingsController::class, 'inboxes'], ['admin']);
    $router->get('/inboxes/create', [SettingsController::class, 'inboxForm'], ['admin']);
    $router->post('/inboxes', [SettingsController::class, 'createInbox'], ['admin']);
    $router->get('/inboxes/{id}/edit', [SettingsController::class, 'inboxForm'], ['admin']);
    $router->post('/inboxes/{id}', [SettingsController::class, 'updateInbox'], ['admin']);
    $router->post('/inboxes/{id}/delete', [SettingsController::class, 'deleteInbox'], ['admin']);

    // Personal inbox (any user)
    $router->post('/inbox/personal', [InboxController::class, 'createPersonal']);

    // Macros (criação é gerente+)
    $router->get('/macros', [InboxController::class, 'macros']);
    $router->post('/macros', [InboxController::class, 'storeMacro'], ['manager']);
    $router->get('/macros/{id}/edit', [InboxController::class, 'editMacro']);
    $router->post('/macros/{id}', [InboxController::class, 'updateMacro'], ['manager']);
    $router->post('/macros/{id}/delete', [InboxController::class, 'deleteMacro'], ['manager']);

    // Library (tags + canned responses; escrita é gerente+)
    $router->get('/library', [LibraryController::class, 'index']);
    $router->post('/library/tags', [LibraryController::class, 'storeTag'], ['manager']);
    $router->get('/library/tags/{id}/edit', [LibraryController::class, 'editTag']);
    $router->post('/library/tags/{id}', [LibraryController::class, 'updateTag'], ['manager']);
    $router->post('/library/tags/{id}/delete', [LibraryController::class, 'deleteTag'], ['manager']);
    $router->post('/library/canned', [LibraryController::class, 'storeCanned'], ['manager']);
    $router->get('/library/canned/{id}/edit', [LibraryController::class, 'editCanned']);
    $router->post('/library/canned/{id}', [LibraryController::class, 'updateCanned'], ['manager']);
    $router->post('/library/canned/{id}/delete', [LibraryController::class, 'deleteCanned'], ['manager']);

    // Assuntos predefinidos (admin)
    $router->get('/settings/subjects', [SettingsController::class, 'subjects'], ['admin']);
    $router->post('/settings/subjects/create', [SettingsController::class, 'createSubject'], ['admin']);
    $router->post('/settings/subjects/{id}/update', [SettingsController::class, 'updateSubject'], ['admin']);
    $router->post('/settings/subjects/{id}/delete', [SettingsController::class, 'deleteSubject'], ['admin']);

    // Motivos de encerramento (admin)
    $router->get('/settings/close-reasons', [SettingsController::class, 'closeReasons'], ['admin']);
    $router->post('/settings/close-reasons/create', [SettingsController::class, 'createCloseReason'], ['admin']);
    $router->post('/settings/close-reasons/{id}/update', [SettingsController::class, 'updateCloseReason'], ['admin']);
    $router->post('/settings/close-reasons/{id}/delete', [SettingsController::class, 'deleteCloseReason'], ['admin']);

    // Wiki / Base de Conhecimento (leitura: qualquer autenticado; escrita: gerente+)
    $router->get('/wiki', [WikiController::class, 'index']);
    $router->get('/wiki/categories', [WikiController::class, 'categories']);
    $router->get('/wiki/categories/create', [WikiController::class, 'categoryForm']);
    $router->post('/wiki/categories/create', [WikiController::class, 'storeCategory'], ['manager']);
    $router->get('/wiki/categories/{id}/edit', [WikiController::class, 'categoryForm']);
    $router->post('/wiki/categories/{id}/edit', [WikiController::class, 'updateCategory'], ['manager']);
    $router->post('/wiki/categories/{id}/delete', [WikiController::class, 'deleteCategory'], ['manager']);
    $router->post('/wiki/categories/reorder', [WikiController::class, 'reorderCategories'], ['manager']);
    $router->get('/wiki/articles', [WikiController::class, 'articles']);
    $router->get('/wiki/articles/create', [WikiController::class, 'articleForm']);
    $router->post('/wiki/articles/create', [WikiController::class, 'storeArticle'], ['manager']);
    $router->get('/wiki/articles/{id}/edit', [WikiController::class, 'articleForm']);
    $router->post('/wiki/articles/{id}/edit', [WikiController::class, 'updateArticle'], ['manager']);
    $router->post('/wiki/articles/{id}/delete', [WikiController::class, 'deleteArticle'], ['manager']);
    $router->post('/wiki/articles/reorder', [WikiController::class, 'reorderArticles'], ['manager']);
    $router->get('/wiki/settings', [WikiController::class, 'settings'], ['admin']);
    $router->post('/wiki/settings/regenerate-key', [WikiController::class, 'regenerateKey'], ['admin']);
    $router->post('/wiki/settings/chat', [WikiController::class, 'saveChat'], ['admin']);
    $router->post('/wiki/settings/portal', [WikiController::class, 'savePortal'], ['admin']);

    // Reports (gerente+)
    $router->get('/reports', [ReportsController::class, 'index'], ['manager']);
    $router->get('/reports/conversations', [ReportsController::class, 'conversations'], ['manager']);
    $router->get('/reports/agents', [ReportsController::class, 'agents'], ['manager']);
    $router->get('/reports/csat', [ReportsController::class, 'csat'], ['manager']);
    $router->get('/reports/timeline', [ReportsController::class, 'timeline'], ['manager']);
    $router->get('/reports/timeline/pdf', [ReportsController::class, 'timelinePdf'], ['manager']);
    $router->get('/reports/timeline/csv', [ReportsController::class, 'timelineCsv'], ['manager']);

}, ['auth', 'csrf']);

// Internal JSON API routes (auth only, no CSRF — called via fetch())
$router->group('', function (Router $router) {
    $router->get('/api/conversations', [InboxController::class, 'apiConversations']);
    $router->get('/api/conversations-list', [InboxController::class, 'conversationsListFragment']);
    $router->post('/api/flows/upload-media', [FlowController::class, 'uploadMedia'], ['manager']);
    $router->post('/api/macros/upload', [InboxController::class, 'uploadMacroMedia'], ['manager']);
    $router->post('/api/wiki/upload', [WikiController::class, 'upload'], ['manager']);
    $router->get('/api/conversations/{id}', [InboxController::class, 'apiConversation']);
    $router->get('/api/conversations/{id}/messages', [InboxController::class, 'apiMessages']);
    $router->get('/api/departments/{id}/users', [DepartmentController::class, 'apiUsers']);
    $router->get('/api/contacts/search', [\App\Controllers\ContactController::class, 'apiSearch']);
    $router->get('/api/guild/stores', [\App\Controllers\ContactController::class, 'apiGuildStores']);
    $router->get('/api/canned-responses', [InboxController::class, 'apiCanned']);
    $router->get('/api/wiki/suggest', [WikiController::class, 'suggest']);
    $router->post('/api/conversations', [InboxController::class, 'apiCreateConversation']);
    $router->get('/api/macros', [InboxController::class, 'apiMacros']);

    // Consolidated unread summary for global notification badge
    $router->get('/api/unread-summary', [\App\Controllers\Api\UnreadSummaryController::class, 'index']);

    // Open conversation counts per inbox for the sidebar badges (live)
    $router->get('/api/inbox-counts', [\App\Controllers\Api\InboxCountsController::class, 'index']);

    // Unread conversations list for the bell dropdown
    $router->get('/api/unread-conversations', [\App\Controllers\Api\UnreadConversationsController::class, 'index']);
    $router->post('/api/messages/read-all', [\App\Controllers\Api\MessagesController::class, 'readAll']);
    $router->post('/api/messages/{id}/read', [\App\Controllers\Api\MessagesController::class, 'markRead']);
    $router->post('/api/conversations/{id}/typing', [\App\Controllers\Api\MessagesController::class, 'typing']);

    // User notification preferences
    $router->get('/api/user-preferences', [\App\Controllers\Api\UserPreferencesController::class, 'index']);
    $router->post('/api/user-preferences', [\App\Controllers\Api\UserPreferencesController::class, 'update']);

    // Custom notification sounds (upload por usuário: mp3/wav/ogg/m4a)
    $router->get('/api/sounds', [\App\Controllers\Api\SoundsController::class, 'index']);
    $router->post('/api/sounds/upload', [\App\Controllers\Api\SoundsController::class, 'upload']);
    $router->post('/api/sounds/{id}/delete', [\App\Controllers\Api\SoundsController::class, 'destroy']);

    // SLA config (tempo, cores, sons) consumido pelo frontend da lista de conversas
    $router->get('/api/sla/config', [\App\Controllers\Api\SlaController::class, 'config']);

    // Status das conexões WhatsApp (barra de alerta do topo)
    $router->get('/api/whatsapp-status', [\App\Controllers\Api\WhatsappStatusController::class, 'index']);

    // Motivos de encerramento (escrita é admin)
    $router->get('/api/close-reasons', [\App\Controllers\Api\CloseReasonsController::class, 'index']);
    $router->post('/api/close-reasons', [\App\Controllers\Api\CloseReasonsController::class, 'store'], ['admin']);
    $router->post('/api/close-reasons/{id}/update', [\App\Controllers\Api\CloseReasonsController::class, 'update'], ['admin']);
    $router->post('/api/close-reasons/{id}/delete', [\App\Controllers\Api\CloseReasonsController::class, 'delete'], ['admin']);

    // Dropdown de notificações estruturadas (menções, atribuições, novas conversas)
    $router->get('/api/notifications/dropdown', [\App\Controllers\Api\NotificationsController::class, 'dropdown']);

    // Server-Sent Events for real-time notifications
    $router->get('/realtime/events', [\App\Controllers\Api\RealtimeController::class, 'events']);

    // Dashboard stats (auto-refresh)
    $router->get('/api/dashboard-stats', [\App\Controllers\Api\DashboardStatsController::class, 'index']);
}, ['auth']);