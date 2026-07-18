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
use App\Controllers\CsatController;
use App\Controllers\ReportsController;
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

// Public CSAT evaluation page (no auth required — link sent to customers)
$router->get('/csat/{token}', [CsatController::class, 'show']);
$router->post('/csat/{token}', [CsatController::class, 'submit']);

// Public WhatsApp webhook (called by WAHA / Evolution API — no auth)
$router->post('/webhooks/whatsapp', [WhatsAppController::class, 'webhook']);

$router->group('', function (Router $router) {
    $router->get('/', [DashboardController::class, 'index']);

    // Inbox
    $router->get('/inbox', [InboxController::class, 'index']);
    $router->get('/inbox/mine', [InboxController::class, 'mine']);
    $router->get('/inbox/unassigned', [InboxController::class, 'unassigned']);
    $router->get('/inbox/new', [InboxController::class, 'newConversation']);
    $router->post('/inbox/new', [InboxController::class, 'createConversation']);
    $router->get('/inbox/{id}/panel', [InboxController::class, 'conversationPanel']);
    $router->get('/inbox/{id}', [InboxController::class, 'show']);
    $router->post('/inbox/{id}/messages', [InboxController::class, 'sendMessage']);
    $router->post('/inbox/{id}/settings', [InboxController::class, 'updateSettings']);
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
    $router->post('/inbox/{id}/read', [InboxController::class, 'markRead']);
    $router->post('/inbox/{id}/snooze', [InboxController::class, 'snooze']);
    $router->post('/inbox/{id}/csat', [InboxController::class, 'csat']);
    $router->post('/inbox/{id}/merge', [InboxController::class, 'merge']);
    $router->post('/inbox/{id}/macro', [InboxController::class, 'applyMacro']);
    $router->post('/inbox/bulk', [InboxController::class, 'bulk']);

    // Configurações gerais (Business Hours, CSAT, ausência)
    $router->post('/settings', [SettingsController::class, 'saveGeneral']);

    // Configurações de Notificações e Sons
    $router->get('/settings/notifications', [SettingsController::class, 'notifications']);
    $router->post('/settings/notifications', [SettingsController::class, 'saveNotifications']);

    // Contacts
    $router->get('/contacts', [ContactController::class, 'index']);
    $router->get('/contacts/create', [ContactController::class, 'create']);
    $router->post('/contacts/create', [ContactController::class, 'store']);
    $router->get('/contacts/{id}', [ContactController::class, 'show']);
    $router->get('/contacts/{id}/edit', [ContactController::class, 'edit']);
    $router->post('/contacts/{id}/merge', [ContactController::class, 'merge']);
    $router->post('/contacts/{id}/update', [ContactController::class, 'update']);
    $router->post('/contacts/{id}/delete', [ContactController::class, 'destroy']);

    // Departments
    $router->get('/departments', [DepartmentController::class, 'index']);
    $router->post('/departments/create', [DepartmentController::class, 'store']);
    $router->get('/departments/{id}', [DepartmentController::class, 'show']);
    $router->post('/departments/{id}/update', [DepartmentController::class, 'update']);
    $router->post('/departments/{id}/users', [DepartmentController::class, 'addUser']);
    $router->post('/departments/{id}/users/{userId}/remove', [DepartmentController::class, 'removeUser']);
    $router->post('/departments/{id}/delete', [DepartmentController::class, 'destroy']);

    // Flows
    $router->get('/flows', [FlowController::class, 'index']);
    $router->get('/flows/create', [FlowController::class, 'create']);
    $router->post('/flows/create', [FlowController::class, 'store']);
    $router->get('/flows/{id}/edit', [FlowController::class, 'edit']);
    $router->post('/flows/{id}/edit', [FlowController::class, 'update']);
    $router->post('/flows/{id}/publish', [FlowController::class, 'publish']);
    $router->post('/flows/{id}/duplicate', [FlowController::class, 'duplicate']);
    $router->post('/flows/{id}/delete', [FlowController::class, 'destroy']);

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

    // Channels & Settings
    $router->get('/channels', [SettingsController::class, 'channels']);
    $router->post('/channels/create', [SettingsController::class, 'createChannel']);
    $router->post('/channels/{id}/update', [SettingsController::class, 'updateChannel']);
    $router->post('/channels/{id}/delete', [SettingsController::class, 'deleteChannel']);
    $router->post('/channels/widget/{id}/regenerate-key', [SettingsController::class, 'regenerateWidgetKey']);
    $router->get('/settings', [SettingsController::class, 'general']);

    // WhatsApp (provider-agnostic)
    $router->post('/whatsapp/{id}/connect', [WhatsAppController::class, 'connect']);
    $router->get('/whatsapp/{id}/status', [WhatsAppController::class, 'status']);
    $router->post('/whatsapp/{id}/disconnect', [WhatsAppController::class, 'disconnect']);

    // Inboxes management
    $router->get('/inboxes', [SettingsController::class, 'inboxes']);
    $router->get('/inboxes/create', [SettingsController::class, 'inboxForm']);
    $router->post('/inboxes', [SettingsController::class, 'createInbox']);
    $router->get('/inboxes/{id}/edit', [SettingsController::class, 'inboxForm']);
    $router->post('/inboxes/{id}', [SettingsController::class, 'updateInbox']);
    $router->post('/inboxes/{id}/delete', [SettingsController::class, 'deleteInbox']);

    // Personal inbox (any user)
    $router->post('/inbox/personal', [InboxController::class, 'createPersonal']);

    // Macros
    $router->get('/macros', [InboxController::class, 'macros']);
    $router->post('/macros', [InboxController::class, 'storeMacro']);

    // Library (tags + canned responses)
    $router->get('/library', [LibraryController::class, 'index']);
    $router->post('/library/tags', [LibraryController::class, 'storeTag']);
    $router->get('/library/tags/{id}/edit', [LibraryController::class, 'editTag']);
    $router->post('/library/tags/{id}', [LibraryController::class, 'updateTag']);
    $router->post('/library/tags/{id}/delete', [LibraryController::class, 'deleteTag']);
    $router->post('/library/canned', [LibraryController::class, 'storeCanned']);
    $router->get('/library/canned/{id}/edit', [LibraryController::class, 'editCanned']);
    $router->post('/library/canned/{id}', [LibraryController::class, 'updateCanned']);
    $router->post('/library/canned/{id}/delete', [LibraryController::class, 'deleteCanned']);

    // Reports
    $router->get('/reports', [ReportsController::class, 'index']);
    $router->get('/reports/conversations', [ReportsController::class, 'conversations']);
    $router->get('/reports/agents', [ReportsController::class, 'agents']);
    $router->get('/reports/csat', [ReportsController::class, 'csat']);

}, ['auth', 'csrf']);

// Internal JSON API routes (auth only, no CSRF — called via fetch())
$router->group('', function (Router $router) {
    $router->get('/api/conversations', [InboxController::class, 'apiConversations']);
    $router->get('/api/conversations/{id}', [InboxController::class, 'apiConversation']);
    $router->get('/api/conversations/{id}/messages', [InboxController::class, 'apiMessages']);
    $router->get('/api/departments/{id}/users', [DepartmentController::class, 'apiUsers']);
    $router->get('/api/contacts/search', [\App\Controllers\ContactController::class, 'apiSearch']);
    $router->get('/api/canned-responses', [InboxController::class, 'apiCanned']);
    $router->post('/api/conversations', [InboxController::class, 'apiCreateConversation']);
    $router->get('/api/macros', [InboxController::class, 'apiMacros']);

    // Consolidated unread summary for global notification badge
    $router->get('/api/unread-summary', [\App\Controllers\Api\UnreadSummaryController::class, 'index']);

    // Unread conversations list for the bell dropdown
    $router->get('/api/unread-conversations', [\App\Controllers\Api\UnreadConversationsController::class, 'index']);

    // User notification preferences
    $router->get('/api/user-preferences', [\App\Controllers\Api\UserPreferencesController::class, 'index']);

    // Server-Sent Events for real-time notifications
    $router->get('/realtime/events', [\App\Controllers\Api\RealtimeController::class, 'events']);

    // Dashboard stats (auto-refresh)
    $router->get('/api/dashboard-stats', [\App\Controllers\Api\DashboardStatsController::class, 'index']);
}, ['auth']);