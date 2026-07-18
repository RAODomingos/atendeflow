<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Models\Department;
use App\Models\Flow;
use App\Models\Inbox;
use App\Models\User;

class SettingsController
{
    public function channels(Request $request): void
    {
        $channels = Database::getInstance()->fetchAll(
            "SELECT ch.*, d.name as department_name
             FROM channels ch
             LEFT JOIN departments d ON d.id = ch.department_id
             ORDER BY ch.type, ch.name"
        );

        $whatsappConnectionIds = array_column(
            Database::getInstance()->fetchAll("SELECT channel_id FROM whatsapp_connections"),
            'channel_id'
        );

        $whatsappConnections = \App\Models\WhatsAppConnection::allWithDetails();

        $emailChannelIds = array_column(
            Database::getInstance()->fetchAll("SELECT channel_id FROM email_accounts"),
            'channel_id'
        );

        $webchatWidgets = Database::getInstance()->fetchAll(
            "SELECT w.*, ch.name as channel_name, ch.department_id,
                    d.name as department_name, f.name as flow_name
             FROM webchat_widgets w
             JOIN channels ch ON ch.id = w.channel_id
             LEFT JOIN departments d ON d.id = COALESCE(w.department_id, ch.department_id)
             LEFT JOIN flows f ON f.id = w.flow_id
             ORDER BY w.created_at DESC"
        );

        $flows = Flow::all();

        View::renderWithLayout('settings/channels', 'main', [
            'title' => 'Canais de Atendimento',
            'activePage' => 'channels',
            'channels' => $channels,
            'whatsappConnectionIds' => $whatsappConnectionIds,
            'whatsappConnections' => $whatsappConnections,
            'emailChannelIds' => $emailChannelIds,
            'emailAccounts' => [],
            'webchatWidgets' => $webchatWidgets,
            'departments' => Department::all(),
            'flows' => $flows,
            'whatsappProviders' => \App\Services\WhatsApp\WhatsAppManager::availableProviders(),
            'defaultProvider' => \App\Services\WhatsApp\WhatsAppManager::defaultProviderName(),
        ]);
    }

    public function general(Request $request): void
    {
        $deptParam = $request->input('dept');
        $deptId = ($deptParam === '' || $deptParam === null) ? null : (int) $deptParam;

        $schedule = \App\Services\BusinessHoursService::scheduleFor($deptId);

        View::renderWithLayout('settings/general', 'main', [
            'title' => 'Configurações',
            'settings' => \App\Models\Setting::all(),
            'departments' => Department::all(),
            'currentDept' => $deptId,
            'schedule' => $schedule,
        ]);
    }

    public function notifications(Request $request): void
    {
        $userId = Auth::id();
        $prefs = \App\Models\UserPreference::getAll($userId);

        View::renderWithLayout('settings/notifications', 'main', [
            'title' => 'Notificações e Sons',
            'activePage' => 'settings_notifications',
            'prefs' => $prefs,
        ]);
    }

    public function saveNotifications(Request $request): void
    {
        $userId = Auth::id();

        \App\Models\UserPreference::set($userId, 'sound_enabled', (bool) $request->post('sound_enabled'));
        \App\Models\UserPreference::set($userId, 'sound_new_message', $request->post('sound_new_message') ?: 'default');
        \App\Models\UserPreference::set($userId, 'sound_new_conversation', $request->post('sound_new_conversation') ?: 'default');
        \App\Models\UserPreference::set($userId, 'browser_notif_enabled', (bool) $request->post('browser_notif_enabled'));

        Session::setFlash('success', 'Preferências de notificação salvas.');
        View::redirect('/settings/notifications');
    }

    public function saveGeneral(Request $request): void
    {
        \App\Models\Setting::set('business_hours_enabled', $request->post('business_hours_enabled') ? '1' : '0');
        \App\Models\Setting::set(
            'business_hours_timezone',
            trim((string) $request->post('business_hours_timezone')) ?: 'America/Sao_Paulo'
        );
        \App\Models\Setting::set(
            'absence_message',
            trim((string) $request->post('absence_message'))
                ?: 'Olá! Estamos fora do nosso horário de atendimento. Retornaremos assim que possível.'
        );
        \App\Models\Setting::set('csat_enabled', $request->post('csat_enabled') ? '1' : '0');
        \App\Models\Setting::set(
            'csat_message',
            trim((string) $request->post('csat_message'))
                ?: 'Olá {{contact.name}}, sua conversa foi resolvida! Por favor, avalie seu atendimento de 1 a 5 estrelas.'
        );

        $deptParam = $request->post('bh_department_id');
        $deptId = ($deptParam === '' || $deptParam === null) ? null : (int) $deptParam;

        Database::getInstance()->delete(
            'business_hours',
            'department_id ' . ($deptId === null ? 'IS NULL' : '= ?'),
            $deptId === null ? [] : [$deptId]
        );

        $days = $request->post('bh') ?: [];
        foreach (range(0, 6) as $d) {
            $row = $days[$d] ?? [];
            Database::getInstance()->insert('business_hours', [
                'department_id' => $deptId,
                'day_of_week' => $d,
                'open_time' => !empty($row['open_time']) ? $row['open_time'] : '08:00:00',
                'close_time' => !empty($row['close_time']) ? $row['close_time'] : '18:00:00',
                'is_open' => !empty($row['is_open']) ? 1 : 0,
            ]);
        }

        Session::setFlash('success', 'Configurações salvas.');
        View::redirect('/settings' . ($deptId ? '?dept=' . $deptId : ''));
    }

    public function createChannel(Request $request): void
    {
        $type = $request->post('type');
        $name = $request->post('name');
        $departmentId = $request->post('department_id');

        if (empty(trim($name ?? ''))) {
            Session::setFlash('error', 'Nome do canal é obrigatório.');
            View::redirect('/channels');
        }

        $channelId = Database::getInstance()->insert('channels', [
            'type' => $type ?: 'webchat',
            'name' => $name,
            'department_id' => $departmentId ? (int) $departmentId : null,
        ]);

        if ($type === 'webchat') {
            Database::getInstance()->insert('webchat_widgets', [
                'channel_id' => $channelId,
                'widget_key' => bin2hex(random_bytes(16)),
                'title' => $name,
                'department_id' => $departmentId ? (int) $departmentId : null,
                'ask_name' => 1,
                'require_name' => 1,
                'ask_email' => $request->post('ask_email') ? 1 : 0,
                'require_email' => $request->post('require_email') ? 1 : 0,
                'ask_phone' => $request->post('ask_phone') ? 1 : 0,
                'require_phone' => $request->post('ask_phone') ? 1 : 0,
                'ask_cnpj' => $request->post('ask_cnpj') ? 1 : 0,
                'require_cnpj' => $request->post('require_cnpj') ? 1 : 0,
            ]);
        } elseif ($type === 'whatsapp') {
            \App\Models\WhatsAppConnection::create([
                'channel_id' => $channelId,
                'provider' => $request->post('provider') ?: \App\Services\WhatsApp\WhatsAppManager::defaultProviderName(),
                'instance_name' => $request->post('instance_name') ?: null,
                'status' => 'disconnected',
            ]);
        }

        Session::setFlash('success', 'Canal criado com sucesso.');
        View::redirect('/channels');
    }

    public function updateChannel(Request $request, int $id): void
    {
        $channel = Database::getInstance()->fetch("SELECT * FROM channels WHERE id = ?", [$id]);
        if (!$channel) {
            Session::setFlash('error', 'Canal não encontrado.');
            View::redirect('/channels');
        }

        $name = $request->post('name');
        $departmentId = $request->post('department_id');

        if (empty(trim($name ?? ''))) {
            Session::setFlash('error', 'Nome do canal é obrigatório.');
            View::redirect('/channels');
        }

        Database::getInstance()->update('channels', [
            'name' => $name,
            'department_id' => $departmentId ? (int) $departmentId : null,
        ], 'id = ?', [$id]);

        if ($channel['type'] === 'webchat') {
            $widget = Database::getInstance()->fetch(
                "SELECT id FROM webchat_widgets WHERE channel_id = ?", [$id]
            );
            if ($widget) {
                Database::getInstance()->update('webchat_widgets', [
                    'title' => $request->post('widget_title') ?: $name,
                    'department_id' => $departmentId ? (int) $departmentId : null,
                    'is_active' => $request->post('is_active') ? 1 : 0,
                    'flow_id' => $request->post('flow_id') ? (int) $request->post('flow_id') : null,
                    'ask_email' => $request->post('ask_email') ? 1 : 0,
                    'require_email' => $request->post('require_email') ? 1 : 0,
                    'ask_phone' => $request->post('ask_phone') ? 1 : 0,
                    'require_phone' => $request->post('require_phone') ? 1 : 0,
                    'ask_cnpj' => $request->post('ask_cnpj') ? 1 : 0,
                    'require_cnpj' => $request->post('require_cnpj') ? 1 : 0,
                ], 'id = ?', [$widget['id']]);
            }
        }

        Session::setFlash('success', 'Canal atualizado com sucesso.');
        View::redirect('/channels');
    }

    public function deleteChannel(Request $request, int $id): void
    {
        $channel = Database::getInstance()->fetch("SELECT * FROM channels WHERE id = ?", [$id]);

        if ($channel && $channel['type'] === 'whatsapp') {
            $connection = \App\Models\WhatsAppConnection::findByChannel($id);
            if ($connection) {
                try {
                    $provider = \App\Services\WhatsApp\WhatsAppManager::forConnection($connection);
                    $provider->deleteConnection($connection);
                } catch (\Throwable $e) {
                    // Se o provedor falhar, ainda removemos o registro local.
                }
            }
        }

        Database::getInstance()->delete('channels', 'id = ?', [$id]);
        Session::setFlash('success', 'Canal removido.');
        View::redirect('/channels');
    }

    public function regenerateWidgetKey(Request $request, int $id): void
    {
        Database::getInstance()->update(
            'webchat_widgets',
            ['widget_key' => bin2hex(random_bytes(16))],
            'id = ?',
            [$id]
        );
        Session::setFlash('success', 'Chave do widget regenerada.');
        View::redirect('/channels');
    }

    public function inboxes(Request $request): void
    {
        $inboxes = Inbox::all();
        foreach ($inboxes as &$inbox) {
            $inbox['channels'] = Inbox::getChannels($inbox['id']);
            $inbox['departments'] = Inbox::getDepartments($inbox['id']);
            $inbox['users'] = Inbox::getUsers($inbox['id']);
        }
        unset($inbox);

        View::renderWithLayout('settings/inboxes', 'main', [
            'title' => 'Caixas de Entrada',
            'activePage' => 'inboxes',
            'inboxes' => $inboxes,
            'departments' => Department::all(),
            'channels' => \App\Models\Channel::getConnected(),
            'users' => User::all(),
        ]);
    }

    public function inboxForm(Request $request, ?int $id = null): void
    {
        $inbox = $id ? Inbox::find($id) : null;

        if ($id && !$inbox) {
            Session::setFlash('error', 'Caixa não encontrada.');
            View::redirect('/inboxes');
        }

        $channels = \App\Models\Channel::getConnected();
        $linkedChannelIds = [];

        if ($inbox) {
            $linkedChannelIds = array_column(Inbox::getChannels($inbox['id']), 'id');
            // Mantém canais já vinculados mesmo que percam a conexão (evita desvincular ao editar)
            $presentIds = array_column($channels, 'id');
            foreach ($linkedChannelIds as $lid) {
                if (!in_array($lid, $presentIds, true)) {
                    $ch = Database::getInstance()->fetch(
                        "SELECT id, type, name, is_active FROM channels WHERE id = ?",
                        [$lid]
                    );
                    if ($ch) {
                        $channels[] = $ch;
                    }
                }
            }
        }

        $data = [
            'title' => $inbox ? 'Editar Caixa' : 'Nova Caixa',
            'activePage' => 'inboxes',
            'inbox' => $inbox,
            'departments' => Department::all(),
            'channels' => $channels,
            'users' => User::all(),
        ];

        if ($inbox) {
            $data['linkedChannels'] = $linkedChannelIds;
            $data['linkedDepartments'] = array_column(Inbox::getDepartments($inbox['id']), 'id');
            $data['linkedUsers'] = array_column(Inbox::getUsers($inbox['id']), 'id');
        }

        View::renderWithLayout('settings/inbox_form', 'main', $data);
    }

    public function createInbox(Request $request): void
    {
        $name = trim((string) $request->post('name'));
        $type = $request->post('type') ?: 'department';

        if (empty($name)) {
            Session::setFlash('error', 'Informe o nome da caixa.');
            View::redirect('/inboxes/create');
        }

        if (!in_array($type, ['department', 'personal'])) {
            $type = 'department';
        }

        $inboxId = Inbox::create([
            'name' => $name,
            'type' => $type,
            'is_active' => 1,
        ]);

        $this->syncInboxLinks($inboxId, $request);

        if ($type === 'personal') {
            Inbox::setUsers($inboxId, [Auth::id()]);
        }

        Session::setFlash('success', 'Caixa criada com sucesso.');
        View::redirect('/inboxes');
    }

    public function updateInbox(Request $request, int $id): void
    {
        $inbox = Inbox::find($id);
        if (!$inbox) {
            Session::setFlash('error', 'Caixa não encontrada.');
            View::redirect('/inboxes');
        }

        $name = trim((string) $request->post('name'));
        if (empty($name)) {
            Session::setFlash('error', 'Informe o nome da caixa.');
            View::redirect('/inboxes/' . $id . '/edit');
        }

        $type = $request->post('type') ?: $inbox['type'];
        if (!in_array($type, ['department', 'personal'])) {
            $type = $inbox['type'];
        }

        Inbox::update($id, ['name' => $name, 'type' => $type]);
        $this->syncInboxLinks($id, $request);

        Session::setFlash('success', 'Caixa atualizada com sucesso.');
        View::redirect('/inboxes');
    }

    public function deleteInbox(Request $request, int $id): void
    {
        $inbox = Inbox::find($id);
        if (!$inbox) {
            Session::setFlash('error', 'Caixa não encontrada.');
            View::redirect('/inboxes');
        }

        Inbox::delete($id);
        Session::setFlash('success', 'Caixa removida.');
        View::redirect('/inboxes');
    }

    private function syncInboxLinks(int $inboxId, Request $request): void
    {
        $channels = $request->post('channels') ?: [];
        if (!is_array($channels)) {
            $channels = [];
        }
        Inbox::setChannels($inboxId, array_map('intval', $channels));

        $departments = $request->post('departments') ?: [];
        if (!is_array($departments)) {
            $departments = [];
        }
        Inbox::setDepartments($inboxId, array_map('intval', $departments));

        $users = $request->post('users') ?: [];
        if (!is_array($users)) {
            $users = [];
        }
        Inbox::setUsers($inboxId, array_map('intval', $users));
    }
}
