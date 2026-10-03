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
use App\Models\Conversation;
use App\Models\WebchatWidget;

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
            'webchatWidgets' => $webchatWidgets,
            'departments' => Department::all(),
            'flows' => $flows,
            'whatsappProviders' => \App\Services\WhatsApp\WhatsAppManager::availableProviders(),
            'defaultProvider' => \App\Services\WhatsApp\WhatsAppManager::defaultProviderName(),
        ]);
    }

    public function general(Request $request): void
    {
        View::renderWithLayout('settings/general', 'main', [
            'title' => 'Configurações',
            'settings' => \App\Models\Setting::all(),
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
            'sla' => \App\Services\SlaService::config(),
            'customSounds' => array_map(
                [\App\Models\NotificationSound::class, 'toApi'],
                \App\Models\NotificationSound::forUser($userId)
            ),
        ]);
    }

    public function saveNotifications(Request $request): void
    {
        $userId = Auth::id();

        \App\Models\UserPreference::set($userId, 'sound_enabled', (bool) $request->post('sound_enabled'));
        \App\Models\UserPreference::set($userId, 'sound_new_message', \App\Controllers\Api\UserPreferencesController::sanitizeSoundValue($userId, $request->post('sound_new_message') ?: 'default'));
        \App\Models\UserPreference::set($userId, 'sound_new_conversation', \App\Controllers\Api\UserPreferencesController::sanitizeSoundValue($userId, $request->post('sound_new_conversation') ?: 'default'));
        \App\Models\UserPreference::set($userId, 'sound_mention', \App\Controllers\Api\UserPreferencesController::sanitizeSoundValue($userId, $request->post('sound_mention') ?: 'default'));
        \App\Models\UserPreference::set($userId, 'browser_notif_enabled', (bool) $request->post('browser_notif_enabled'));

        // SLA — Padrão do Sistema (cores de espera e sons de alerta)
        $slaAttention = max(1, (int) $request->post('sla_attention_minutes'));
        \App\Services\SlaService::setAll([
            'enabled' => $request->post('sla_enabled') ? '1' : '0',
            'attention_minutes' => $slaAttention,
            'alert_minutes' => max($slaAttention + 1, (int) $request->post('sla_alert_minutes')),
            'color_normal' => trim((string) $request->post('sla_color_normal')) ?: '#dcfce7',
            'color_attention' => trim((string) $request->post('sla_color_attention')) ?: '#fef3c7',
            'color_alert' => trim((string) $request->post('sla_color_alert')) ?: '#fee2e2',
            'color_normal_text' => trim((string) $request->post('sla_color_normal_text')) ?: '#166534',
            'color_attention_text' => trim((string) $request->post('sla_color_attention_text')) ?: '#92400e',
            'color_alert_text' => trim((string) $request->post('sla_color_alert_text')) ?: '#991b1b',
            'sound_attention' => in_array($request->post('sla_sound_attention'), ['default','soft','sharp','silent'], true)
                ? (string) $request->post('sla_sound_attention') : 'default',
            'sound_alert' => in_array($request->post('sla_sound_alert'), ['default','soft','sharp','silent'], true)
                ? (string) $request->post('sla_sound_alert') : 'default',
        ]);

        Session::setFlash('success', 'Preferências de notificação salvas.');
        View::redirect('/settings/notifications');
    }

    public function saveGeneral(Request $request): void
    {
        \App\Models\Setting::set('csat_enabled', $request->post('csat_enabled') ? '1' : '0');
        \App\Models\Setting::set(
            'csat_message',
            trim((string) $request->post('csat_message'))
                ?: 'Olá {{contact.name}}, sua conversa foi resolvida! Responda aqui mesmo com uma nota de 1 a 5.'
        );

        // SLA agora fica em /settings/notifications (saveNotifications).

        Session::setFlash('success', 'Configurações salvas.');
        View::redirect('/settings');
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
            'flow_id' => $request->post('flow_id') ? (int) $request->post('flow_id') : null,
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
                'require_phone' => $request->post('require_phone') ? 1 : 0,
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
            'flow_id' => $request->post('flow_id') ? (int) $request->post('flow_id') : null,
        ], 'id = ?', [$id]);

        if ($channel['type'] === 'webchat') {
            $widget = WebchatWidget::findByChannel($id);
            if ($widget) {
                $widgetData = [
                    'title' => $request->post('widget_title') ?: $name,
                    'department_id' => $departmentId ? (int) $departmentId : null,
                    'is_active' => $request->post('is_active') ? 1 : 0,
                    'flow_id' => $request->post('flow_id') ? (int) $request->post('flow_id') : null,
                    'ask_name' => $request->post('ask_name') ? 1 : 0,
                    'require_name' => $request->post('require_name') ? 1 : 0,
                    'ask_email' => $request->post('ask_email') ? 1 : 0,
                    'require_email' => $request->post('require_email') ? 1 : 0,
                    'ask_phone' => $request->post('ask_phone') ? 1 : 0,
                    'require_phone' => $request->post('require_phone') ? 1 : 0,
                    'ask_cnpj' => $request->post('ask_cnpj') ? 1 : 0,
                    'require_cnpj' => $request->post('require_cnpj') ? 1 : 0,
                ];

                $file = $request->file('avatar');
                $removeAvatar = $request->post('remove_avatar');
                if (!empty($file['tmp_name'])) {
                    $uploaded = save_uploaded_file('avatar', null, 'widgets');
                    if ($uploaded) {
                        $widgetData['avatar_url'] = upload_url($uploaded['path']);
                    }
                } elseif ($removeAvatar) {
                    $widgetData['avatar_url'] = null;
                }

                WebchatWidget::update((int) $widget['id'], $widgetData);
            }
        } elseif ($channel['type'] === 'whatsapp') {
            $connection = \App\Models\WhatsAppConnection::findByChannel($id);
            if ($connection) {
                $connData = [];
                if ($request->post('provider')) {
                    $connData['provider'] = $request->post('provider');
                }
                if ($request->post('instance_name') !== null) {
                    $connData['instance_name'] = $request->post('instance_name') ?: null;
                }
                if (!empty($connData)) {
                    \App\Models\WhatsAppConnection::update((int) $connection['id'], $connData);
                }
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
        WebchatWidget::regenerateKey($id);
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

        $channels = \App\Models\Channel::allActiveForLinking();
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

        $sla = \App\Services\SlaService::configFor($id);
        $slaForm = $this->slaFormState($inbox, $sla);

        $data = [
            'title' => $inbox ? 'Editar Caixa' : 'Nova Caixa',
            'activePage' => 'settings',
            'subPage' => 'inboxes',
            'inbox' => $inbox,
            'departments' => Department::all(),
            'channels' => $channels,
            'users' => User::all(),
            'slaConfig' => $sla['config'],
            'slaHasOverride' => $sla['is_custom'],
            'slaForm' => $slaForm,
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

        $data = [
            'name' => $name,
            'type' => $type,
            'is_active' => 1,
        ];
        $data = array_merge($data, $this->collectInboxSla($request));
        $inboxId = Inbox::create($data);

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

        $data = array_merge(
            ['name' => $name, 'type' => $type],
            $this->collectInboxSla($request)
        );
        Inbox::update($id, $data);
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

    /**
     * Lê o POST e devolve os campos de SLA por caixa. Se a caixa
     * deve usar o padrão do sistema, retorna NULL em todos — assim
     * o SlaService::configFor() cai no global.
     */
    private function collectInboxSla(Request $request): array
    {
        $fields = [
            'sla_enabled', 'sla_attention_minutes', 'sla_alert_minutes',
            'sla_color_normal', 'sla_color_attention', 'sla_color_alert',
            'sla_color_normal_text', 'sla_color_attention_text', 'sla_color_alert_text',
            'sla_sound_attention', 'sla_sound_alert',
        ];

        if ($request->post('sla_use_global')) {
            // "Usar padrão do sistema" marcado: zera os overrides.
            return array_fill_keys($fields, null);
        }

        $data = [];
        $data['sla_enabled'] = $request->post('sla_enabled') ? 1 : 0;
        $data['sla_attention_minutes'] = max(1, (int) $request->post('sla_attention_minutes'));
        $alertMin = max((int) $data['sla_attention_minutes'] + 1, (int) $request->post('sla_alert_minutes'));
        $data['sla_alert_minutes'] = $alertMin;

        $colorMap = [
            'sla_color_normal' => 'sla_color_normal',
            'sla_color_attention' => 'sla_color_attention',
            'sla_color_alert' => 'sla_color_alert',
            'sla_color_normal_text' => 'sla_color_normal_text',
            'sla_color_attention_text' => 'sla_color_attention_text',
            'sla_color_alert_text' => 'sla_color_alert_text',
        ];
        foreach ($colorMap as $field => $key) {
            $val = trim((string) $request->post($key));
            $data[$field] = $val !== '' ? $val : null;
        }

        $data['sla_sound_attention'] = in_array($request->post('sla_sound_attention'), \App\Services\SlaService::SOUND_PROFILES, true)
            ? (string) $request->post('sla_sound_attention')
            : 'default';
        $data['sla_sound_alert'] = in_array($request->post('sla_sound_alert'), \App\Services\SlaService::SOUND_PROFILES, true)
            ? (string) $request->post('sla_sound_alert')
            : 'default';

        return $data;
    }

    /**
     * Prepara o estado inicial do card de SLA no formulário da
     * caixa. Se não há override, exibe o "usar padrão" marcado
     * e mostra os valores globais como preview. Se já há override,
     * pré-popula com os valores da caixa.
     */
    private function slaFormState(?array $inbox, array $sla): array
    {
        $cfg = $sla['config'];
        $hasOverride = !empty($inbox) && $sla['is_custom'];

        $values = [
            'enabled' => $hasOverride ? ($inbox['sla_enabled'] ?? 1) : ($cfg['enabled'] === '1' ? 1 : 0),
            'attention_minutes' => $hasOverride ? (int) ($inbox['sla_attention_minutes'] ?? $cfg['attention_minutes']) : (int) $cfg['attention_minutes'],
            'alert_minutes' => $hasOverride ? (int) ($inbox['sla_alert_minutes'] ?? $cfg['alert_minutes']) : (int) $cfg['alert_minutes'],
            'color_normal' => $hasOverride ? ($inbox['sla_color_normal'] ?? $cfg['color_normal']) : $cfg['color_normal'],
            'color_attention' => $hasOverride ? ($inbox['sla_color_attention'] ?? $cfg['color_attention']) : $cfg['color_attention'],
            'color_alert' => $hasOverride ? ($inbox['sla_color_alert'] ?? $cfg['color_alert']) : $cfg['color_alert'],
            'color_normal_text' => $hasOverride ? ($inbox['sla_color_normal_text'] ?? $cfg['color_normal_text']) : $cfg['color_normal_text'],
            'color_attention_text' => $hasOverride ? ($inbox['sla_color_attention_text'] ?? $cfg['color_attention_text']) : $cfg['color_attention_text'],
            'color_alert_text' => $hasOverride ? ($inbox['sla_color_alert_text'] ?? $cfg['color_alert_text']) : $cfg['color_alert_text'],
            'sound_attention' => $hasOverride ? ($inbox['sla_sound_attention'] ?? $cfg['sound_attention']) : $cfg['sound_attention'],
            'sound_alert' => $hasOverride ? ($inbox['sla_sound_alert'] ?? $cfg['sound_alert']) : $cfg['sound_alert'],
        ];

        return [
            'use_global' => !$hasOverride,
            'values' => $values,
        ];
    }

    public function subjects(Request $request): void
    {
        $subjects = Database::getInstance()->fetchAll(
            "SELECT * FROM conversation_subjects ORDER BY sort_order ASC, name ASC"
        );
        View::renderWithLayout('settings/subjects', 'main', [
            'title' => 'Assuntos Predefinidos',
            'activePage' => 'settings',
            'subjects' => $subjects,
        ]);
    }

    public function createSubject(Request $request): void
    {
        $name = $request->post('name', '');
        if (empty($name)) {
            Session::setFlash('error', 'Nome é obrigatório.');
            View::redirect('/settings/subjects');
            return;
        }
        Database::getInstance()->execute(
            "INSERT INTO conversation_subjects (name, sort_order) VALUES (?, ?)",
            [$name, (int) $request->post('sort_order', 0)]
        );
        Session::setFlash('success', 'Assunto criado com sucesso.');
        View::redirect('/settings/subjects');
    }

    public function updateSubject(Request $request, int $id): void
    {
        $name = $request->post('name', '');
        if (empty($name)) {
            Session::setFlash('error', 'Nome é obrigatório.');
            View::redirect('/settings/subjects');
            return;
        }
        Database::getInstance()->execute(
            "UPDATE conversation_subjects SET name = ?, sort_order = ?, is_active = ? WHERE id = ?",
            [$name, (int) $request->post('sort_order', 0), (int) $request->post('is_active', 1), $id]
        );
        Session::setFlash('success', 'Assunto atualizado com sucesso.');
        View::redirect('/settings/subjects');
    }

    public function deleteSubject(Request $request, int $id): void
    {
        Database::getInstance()->execute("DELETE FROM conversation_subjects WHERE id = ?", [$id]);
        Session::setFlash('success', 'Assunto removido.');
        View::redirect('/settings/subjects');
    }

    public function closeReasons(Request $request): void
    {
        $reasons = \App\Models\CloseReason::all(false);
        View::renderWithLayout('settings/close_reasons', 'main', [
            'title' => 'Motivos de Encerramento',
            'activePage' => 'settings',
            'reasons' => $reasons,
        ]);
    }

    public function createCloseReason(Request $request): void
    {
        $code = trim((string) $request->post('code', ''));
        $label = trim((string) $request->post('label', ''));
        if ($code === '' || $label === '') {
            Session::setFlash('error', 'Código e rótulo são obrigatórios.');
            View::redirect('/settings/close-reasons');
            return;
        }
        $code = preg_replace('/[^a-z0-9_]/', '', strtolower($code));
        \App\Models\CloseReason::create([
            'code' => $code,
            'label' => $label,
            'description' => trim((string) $request->post('description', '')) ?: null,
            'icon' => trim((string) $request->post('icon', 'fa-tag')) ?: 'fa-tag',
            'color' => trim((string) $request->post('color', '#6c757d')) ?: '#6c757d',
            'sort_order' => (int) $request->post('sort_order', 0),
            'is_active' => 1,
        ]);
        Session::setFlash('success', 'Motivo criado com sucesso.');
        View::redirect('/settings/close-reasons');
    }

    public function updateCloseReason(Request $request, int $id): void
    {
        $current = \App\Models\CloseReason::find($id);
        if (!$current) {
            Session::setFlash('error', 'Motivo não encontrado.');
            View::redirect('/settings/close-reasons');
            return;
        }
        $label = trim((string) $request->post('label', ''));
        $codeRaw = trim((string) $request->post('code', ''));
        $code = preg_replace('/[^a-z0-9_]/', '', strtolower($codeRaw));
        if ($label === '' || $code === '') {
            Session::setFlash('error', 'Código e rótulo são obrigatórios (código: letras minúsculas, números e _).');
            View::redirect('/settings/close-reasons');
            return;
        }
        $existing = \App\Models\CloseReason::findByCode($code);
        if ($existing && (int) $existing['id'] !== $id) {
            Session::setFlash('error', 'Já existe outro motivo com este código.');
            View::redirect('/settings/close-reasons');
            return;
        }
        $body = $request->post() ?? [];
        $data = [
            'code' => $code,
            'label' => $label,
            'description' => trim((string) $request->post('description', '')) ?: null,
            'icon' => trim((string) $request->post('icon', 'fa-tag')) ?: 'fa-tag',
            'color' => trim((string) $request->post('color', '#6c757d')) ?: '#6c757d',
            'sort_order' => (int) $request->post('sort_order', 0),
            // Checkbox desmarcado não é enviado: ausência = inativo
            'is_active' => array_key_exists('is_active', $body) ? ((int) $request->post('is_active', 0) ? 1 : 0) : 0,
        ];
        if ($data['color'] && !preg_match('/^#[0-9a-fA-F]{3,7}$/', $data['color'])) {
            $data['color'] = '#6c757d';
        }
        \App\Models\CloseReason::update($id, $data);
        // Se o código mudou, atualiza conversas que usavam o código antigo
        // para não perder o vínculo com o histórico.
        $oldCode = (string) ($current['code'] ?? '');
        if ($oldCode !== '' && $oldCode !== $code) {
            try {
                Database::getInstance()->execute(
                    "UPDATE conversations SET close_reason = ? WHERE close_reason = ?",
                    [$code, $oldCode]
                );
            } catch (\Throwable $e) {
                error_log('updateCloseReason propagate code error: ' . $e->getMessage());
            }
        }
        Session::setFlash('success', 'Motivo atualizado.');
        View::redirect('/settings/close-reasons');
    }

    public function deleteCloseReason(Request $request, int $id): void
    {
        \App\Models\CloseReason::delete($id);
        Session::setFlash('success', 'Motivo removido.');
        View::redirect('/settings/close-reasons');
    }

}
