<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\View;
use App\Models\Inbox;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppGroup;
use App\Services\WhatsAppService;

/**
 * Caixa de Grupos WhatsApp: grupos aprendidos via webhook, alerta de menção
 * ao número da conexão e envio de mensagens ao grupo.
 */
class WhatsAppGroupController
{
    public function index(Request $request): void
    {
        $connectionId = (int) ($request->input('connection') ?? 0);
        $search = trim((string) ($request->input('search') ?? ''));
        $groups = WhatsAppGroup::allWithDetails($connectionId ?: null, $search ?: null);
        $connections = WhatsAppConnection::allWithDetails();

        View::renderWithLayout('whatsapp/groups', 'main', [
            'title' => 'Grupos WhatsApp',
            'activePage' => 'wa_groups',
            'groups' => $groups,
            'connections' => $connections,
            'filterConnection' => $connectionId ?: '',
            'filterSearch' => $search,
            'inboxes' => Inbox::all(),
            'isManager' => Auth::isManager(),
        ]);
    }

    public function show(Request $request, int $id): void
    {
        $group = WhatsAppGroup::find($id);
        if (!$group) {
            View::renderWithLayout('errors/error', 'main', [
                'title' => 'Grupo não encontrado',
                'activePage' => 'wa_groups',
                'message' => 'Grupo não encontrado.',
            ]);
            return;
        }
        // Abrir o grupo marca as menções como lidas (caixa + sino do usuário).
        WhatsAppGroup::markMentionsRead($id);
        \App\Services\NotificationService::markGroupNotificationsRead($id, (int) Auth::id());

        $connection = WhatsAppConnection::find((int) $group['connection_id']);
        View::renderWithLayout('whatsapp/group_show', 'main', [
            'title' => $group['name'] ?: 'Grupo WhatsApp',
            'activePage' => 'wa_groups',
            'group' => WhatsAppGroup::find($id),
            'connection' => $connection,
            'mentions' => WhatsAppGroup::mentions($id, 100),
            'members' => WhatsAppGroup::members($id),
            'membersSyncedAt' => WhatsAppGroup::membersSyncedAt($id),
            'inboxes' => Inbox::all(),
            'isManager' => Auth::isManager(),
            'conversationId' => WhatsAppGroup::openConversationId($id),
        ]);
    }

    /**
     * POST /whatsapp/groups/{id}/monitor — monitora ou arquiva o grupo.
     *
     * Arquivado: o grupo continua registrado (nome, JID, histórico já
     * espelhado), mas deixa de espelhar mensagem na caixa e de disparar
     * alerta de menção.
     */
    public function toggleMonitor(Request $request, int $id): void
    {
        $group = WhatsAppGroup::find($id);
        if (!$group) {
            View::json(['error' => 'Grupo não encontrado'], 404);
            return;
        }
        $monitored = $request->input('monitored');
        $monitored = $monitored === null
            ? (!WhatsAppGroup::isMonitored($group))
            : ($monitored ? true : false);
        WhatsAppGroup::setMonitored($id, $monitored);
        \App\Core\Session::setFlash(
            'success',
            $monitored
                ? 'Grupo monitorado: as mensagens voltam a aparecer na caixa e as menções avisam a equipe.'
                : 'Grupo arquivado: as mensagens não são mais espelhadas na caixa e as menções não avisam mais.'
        );
        $back = $_SERVER['HTTP_REFERER'] ?? null;
        if ($back && str_starts_with($back, base_url())) {
            header('Location: ' . $back);
            exit;
        }
        View::redirect(url('whatsapp/groups/' . $id));
    }

    /**
     * POST /whatsapp/groups/{id}/topic-window — janela de silêncio (horas)
     * que abre uma nova conversa para o grupo. 0 = uma conversa só.
     */
    public function setTopicWindow(Request $request, int $id): void
    {
        if (!WhatsAppGroup::find($id)) {
            View::json(['error' => 'Grupo não encontrado'], 404);
            return;
        }
        $hours = max(0, min(720, (int) ($request->input('topic_window_hours') ?? 0)));
        WhatsAppGroup::setTopicWindow($id, $hours);
        \App\Core\Session::setFlash(
            'success',
            $hours > 0
                ? 'Novo tópico a cada ' . $hours . 'h de silêncio no grupo.'
                : 'O grupo volta a usar uma única conversa.'
        );
        $back = $_SERVER['HTTP_REFERER'] ?? null;
        if ($back && str_starts_with($back, base_url())) {
            header('Location: ' . $back);
            exit;
        }
        View::redirect(url('whatsapp/groups/' . $id));
    }

    /**
     * POST /whatsapp/groups/{id}/refresh-members — busca a lista de
     * participantes no provedor e guarda em cache (o autocomplete de
     * @menção usa o cache em vez de chamar o provedor a cada troca).
     */
    public function refreshMembers(Request $request, int $id): void
    {
        $group = WhatsAppGroup::find($id);
        if (!$group) {
            View::json(['error' => 'Grupo não encontrado'], 404);
            return;
        }
        $members = (new WhatsAppService())->fetchGroupMembersForDisplay($id);
        if ($members === null) {
            View::json(['error' => 'Lista de participantes indisponível no momento'], 502);
            return;
        }
        WhatsAppGroup::saveMembers($id, $members);
        View::json([
            'members' => $members,
            'fetched_at' => date('c'),
            'cached_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function toggleAlert(Request $request, int $id): void
    {
        $group = WhatsAppGroup::find($id);
        if (!$group) {
            View::json(['error' => 'Grupo não encontrado'], 404);
            return;
        }
        $enabled = $request->input('mention_alert');
        $enabled = $enabled === null ? (empty($group['mention_alert']) ? 1 : 0) : ($enabled ? 1 : 0);
        WhatsAppGroup::update($id, ['mention_alert' => $enabled]);
        $back = $_SERVER['HTTP_REFERER'] ?? null;
        if ($back && str_starts_with($back, base_url())) {
            header('Location: ' . $back);
            exit;
        }
        View::redirect(url('whatsapp/groups/' . $id));
    }

    public function setInbox(Request $request, int $id): void
    {
        $group = WhatsAppGroup::find($id);
        if (!$group) {
            View::json(['error' => 'Grupo não encontrado'], 404);
            return;
        }
        $inboxId = (int) ($request->input('inbox_id') ?? 0);
        if ($inboxId > 0 && !Inbox::find($inboxId)) {
            \App\Core\Session::setFlash('error', 'Caixa inválida.');
            View::redirect(url('whatsapp/groups'));
            return;
        }
        WhatsAppGroup::setInbox($id, $inboxId > 0 ? $inboxId : null);
        $inboxName = $inboxId > 0 ? ((Inbox::find($inboxId)['name'] ?? null) ?: 'caixa selecionada') : 'caixa do canal (padrão)';
        \App\Core\Session::setFlash('success', 'Grupo movido para ' . $inboxName . '. As conversas do grupo aparecem nessa caixa e o sino avisa quando marcarem o número.');
        $back = $_SERVER['HTTP_REFERER'] ?? null;
        if ($back && str_starts_with($back, base_url())) {
            header('Location: ' . $back);
            exit;
        }
        View::redirect(url('whatsapp/groups/' . $id));
    }

    /**
     * GET /whatsapp/groups/{id}/members — participantes em tempo real via
     * provedor (sem persistir). Erro vira 502 com "lista indisponível";
     * o envio segue funcionando normalmente.
     */
    public function members(Request $request, int $id): void
    {
        $group = WhatsAppGroup::find($id);
        if (!$group) {
            View::json(['error' => 'Grupo não encontrado'], 404);
            return;
        }
        $connection = WhatsAppConnection::find((int) $group['connection_id']);
        if (!$connection) {
            View::json(['error' => 'Conexão do grupo não encontrada'], 502);
            return;
        }
        $members = (new WhatsAppService())->fetchGroupMembersForDisplay($id);
        if ($members === null) {
            View::json(['error' => 'Lista de participantes indisponível no momento'], 502);
            return;
        }
        View::json(['members' => $members, 'fetched_at' => date('c')]);
    }

    /**
     * Mapeia participantes brutos do provedor p/ exibição: resolve LID via
     * mapa aprendido, preserva `lid` quando sem telefone conhecido.
     *
     * @param array<int, array{phone?:string, lid?:?string, name?:?string, is_admin?:bool}> $raw
     * @return array<int, array{phone:string, lid:?string, name:?string, is_admin:bool}>
     */
    public static function mapMembersForDisplay(array $raw): array
    {
        $members = [];
        foreach ($raw as $row) {
            $phone = (string) ($row['phone'] ?? '');
            $lid = $row['lid'] ?? null;
            $lid = is_string($lid) && $lid !== '' ? preg_replace('/\D/', '', $lid) : null;
            if ($phone === '' && $lid !== null && $lid !== '') {
                $resolved = \App\Models\WhatsAppLidMap::resolve($lid);
                if ($resolved && !\App\Models\WhatsAppLidMap::isLid($resolved)) {
                    $phone = $resolved;
                }
            }
            // Nome aprendido do tráfego (o fetch do provedor não traz nomes).
            $name = isset($row['name']) && $row['name'] !== '' ? (string) $row['name'] : null;
            if ($name === null && $phone !== '') {
                $name = \App\Models\WhatsAppContactName::resolve($phone);
            }
            $members[] = [
                'phone' => $phone,
                'lid' => $lid,
                'name' => $name,
                'is_admin' => (bool) ($row['is_admin'] ?? false),
            ];
        }
        return $members;
    }

    public function send(Request $request, int $id): void
    {
        $text = trim((string) ($request->input('message') ?? ''));
        if ($text === '') {
            View::json(['error' => 'Digite uma mensagem'], 422);
            return;
        }
        $mentionsRaw = $request->input('mentions');
        if (!is_array($mentionsRaw)) {
            $mentionsRaw = $mentionsRaw === null || $mentionsRaw === '' ? [] : [$mentionsRaw];
        }
        $mentions = array_values(array_filter(array_map(
            fn($m) => trim((string) $m, "@ \t"),
            $mentionsRaw
        ), fn($m) => $m !== ''));
        try {
            $result = (new WhatsAppService())->sendGroupMessage($id, $text, $mentions);
            $acceptsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
                || ($request->input('_format') === 'json');
            if ($acceptsJson) {
                View::json(['ok' => true, 'provider_message_id' => $result['provider_message_id'] ?? null]);
                return;
            }
            \App\Core\Session::setFlash('success', 'Mensagem enviada ao grupo.');
        } catch (\Throwable $e) {
            $acceptsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
            if ($acceptsJson) {
                View::json(['error' => $e->getMessage()], 500);
                return;
            }
            \App\Core\Session::setFlash('error', 'Falha ao enviar: ' . $e->getMessage());
        }
        View::redirect(url('whatsapp/groups/' . $id));
    }

    public function markRead(Request $request, int $id): void
    {
        WhatsAppGroup::markMentionsRead($id);
        \App\Services\NotificationService::markGroupNotificationsRead($id, (int) Auth::id());
        View::json(['ok' => true]);
    }
}
