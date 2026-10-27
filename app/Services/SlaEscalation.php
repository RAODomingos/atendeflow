<?php

namespace App\Services;

use App\Core\Database;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Tag;

/**
 * Escalonamento na violação do SLA de primeira resposta.
 *
 * Dispara UMA vez por conversa, no momento em que a violação é
 * detectada pelo worker. As ações são configuráveis (global + por
 * caixa) e independentes:
 *
 *  1. tag "SLA violado" — deixa a conversa visível na listagem
 *  2. notificação in-app aos gerentes do departamento
 *  3. atribuição automática ao atendente menos carregado
 *  4. aviso por WhatsApp ao plantão do departamento
 *
 * O SLA de resolução NÃO escala: notificar o gerente a cada fechamento
 * lento não gera ação útil.
 */
class SlaEscalation
{
    /** Tag de sistema aplicada na conversa violada. */
    public const TAG_NAME = 'SLA violado';

    /** Tag de sistema aplicada quando o cliente reativa e fica sem resposta. */
    public const TAG_NAME_PENDING = 'Sem resposta do cliente';

    /** Tag de sistema aplicada quando uma menção em grupo fica sem resposta. */
    public const TAG_NAME_GROUP_MENTION = 'Menção em grupo';

    /** Violação da 1ª resposta (dispara uma vez por conversa). */
    public const KIND_FIRST_RESPONSE = 'first_response';

    /** Cliente sem resposta (dispara uma vez por mensagem do cliente). */
    public const KIND_PENDING = 'pending_response';

    /** Menção em grupo sem resposta (dispara uma vez por menção). */
    public const KIND_GROUP_MENTION = 'group_mention';

    /**
     * Executa as ações de escalonamento.
     *
     * @param array $measurement Resultado de SlaMeasurement::measure().
     * @param array $conv        Linha de `conversations`.
     * @param string $kind        KIND_FIRST_RESPONSE ou KIND_PENDING.
     * @return array{tag: bool, managers: int, autoassign: ?int, whatsapp: bool}
     */
    public static function escalate(
        int $conversationId,
        array $measurement,
        array $conv,
        string $kind = self::KIND_FIRST_RESPONSE
    ): array {
        $result = [
            'tag' => false,
            'managers' => 0,
            'autoassign' => null,
            'whatsapp' => false,
            'whatsapp_sent' => 0,
        ];

        $isPending = $kind === self::KIND_PENDING;
        $isGroupMention = $kind === self::KIND_GROUP_MENTION;

        $inboxId = !empty($conv['inbox_id']) ? (int) $conv['inbox_id'] : null;
        $cfg = SlaService::configFor($inboxId)['config'] ?? [];

        // 1) tag
        if (($cfg['escalate_tag'] ?? '1') === '1') {
            $tagId = Tag::ensureByName(
                $isGroupMention
                    ? self::TAG_NAME_GROUP_MENTION
                    : ($isPending ? self::TAG_NAME_PENDING : self::TAG_NAME),
                $isGroupMention ? '#7c3aed' : ($isPending ? '#f59e0b' : '#dc2626')
            );
            Conversation::addTag($conversationId, $tagId);
            $result['tag'] = true;
        }

        // 2) gerentes do departamento
        if (($cfg['escalate_managers'] ?? '1') === '1' && !empty($conv['department_id'])) {
            $managers = Database::getInstance()->fetchAll(
                "SELECT du.user_id FROM department_users du
                 JOIN users u ON u.id = du.user_id AND u.is_active = 1
                 WHERE du.department_id = ? AND du.is_manager = 1",
                [(int) $conv['department_id']]
            );
            $ids = array_map(fn($r) => (int) $r['user_id'], $managers);
            $created = NotificationService::notifySystem(
                $ids,
                $isGroupMention
                    ? 'Menção em grupo sem resposta'
                    : ($isPending ? 'Cliente sem resposta' : 'SLA de primeira resposta violado'),
                $isGroupMention
                    ? 'A menção no grupo da conversa #' . $conversationId . ' está sem resposta há '
                        . self::humanizeSeconds((int) ($measurement['pending_seconds'] ?? 0)) . '.'
                    : ($isPending
                        ? 'A conversa #' . $conversationId . ' está sem resposta há '
                            . self::humanizeSeconds((int) ($measurement['pending_seconds'] ?? 0)) . '.'
                        : 'A conversa #' . $conversationId . ' furou o SLA de primeira resposta.'),
                $conversationId
            );
            $result['managers'] = count($created);
        }

        // 3) atribuição automática
        if (($cfg['escalate_autoassign'] ?? '0') === '1') {
            $result['autoassign'] = self::autoAssign($conversationId, $conv);
        }

        // 4) aviso por WhatsApp
        if (($cfg['escalate_whatsapp'] ?? '0') === '1') {
            $sent = self::notifyWhatsapp($conversationId, $measurement, $conv, $cfg, $kind);
            $result['whatsapp'] = $sent > 0;
            $result['whatsapp_sent'] = $sent;
        }

        Conversation::addEvent(
            $conversationId,
            'sla_escalated',
            $isGroupMention
                ? 'Menção em grupo — SLA escalonado'
                : ($isPending ? 'Cliente sem resposta — SLA escalonado' : 'SLA escalonado'),
            null,
            array_merge($result, ['kind' => $kind])
        );

        return $result;
    }

    /**
     * Atribui a conversa ao atendente menos carregado.
     *
     * Regra de conservatism: NUNCA tira a conversa de quem já está
     * atendendo — só atribui quando ela ainda não tem responsável (ou
     * o responsável está inativo).
     *
     * @return int|null id do atendente escolhido
     */
    private static function autoAssign(int $conversationId, array $conv): ?int
    {
        // Conversa de grupo não tem atendente: o escalonamento do SLA de
        // grupo avisa os gerentes e marca a tag, mas não escala para
        // "alguém" — quem responde ao grupo é a equipe, não um responsável.
        if (!empty($conv['group_id'])) {
            return null;
        }

        // Conversa encerrada não entra na escala: atribuir um atendente a
        // ela reabriria a conversa (assign grava status='open') sem limpar
        // o closed_at, tirando a conversa da lista de encerradas.
        $status = (string) ($conv['status'] ?? '');
        if (!in_array($status, ['new', 'open', 'waiting_customer', 'waiting_internal'], true)) {
            return null;
        }

        $assigned = !empty($conv['assigned_user_id']) ? (int) $conv['assigned_user_id'] : 0;
        if ($assigned > 0) {
            $row = Database::getInstance()->fetch(
                "SELECT is_active FROM users WHERE id = ?",
                [$assigned]
            );
            if ($row && (int) $row['is_active'] === 1) {
                return null; // já tem alguém atendendo
            }
        }

        $inboxId = !empty($conv['inbox_id']) ? (int) $conv['inbox_id'] : 0;
        $departmentId = !empty($conv['department_id']) ? (int) $conv['department_id'] : 0;

        // Candidatos: o time dono da conversa. A caixa é o perfil (SLA)
        // desse time, não o dono do atendimento — então quem escala é o
        // departamento. Sem departamento, cai nos donos da caixa. Admins
        // ficam de fora: aparecem na lista por visibilidade, não por escala.
        $candidates = [];
        if ($departmentId > 0) {
            $candidates = Database::getInstance()->fetchAll(
                "SELECT u.id FROM users u
                 JOIN department_users du ON du.user_id = u.id AND du.department_id = ?
                 WHERE u.is_active = 1 AND u.role != 'admin'
                 GROUP BY u.id
                 ORDER BY u.id ASC",
                [$departmentId]
            );
        } elseif ($inboxId > 0) {
            $candidates = Database::getInstance()->fetchAll(
                "SELECT u.id FROM users u
                 JOIN inbox_users iu ON iu.user_id = u.id AND iu.inbox_id = ?
                 WHERE u.is_active = 1 AND u.role != 'admin'
                 GROUP BY u.id
                 ORDER BY u.id ASC",
                [$inboxId]
            );
        }

        if (!$candidates) {
            return null;
        }

        $best = null;
        $bestLoad = PHP_INT_MAX;
        foreach ($candidates as $c) {
            $uid = (int) $c['id'];
            $load = (int) (Database::getInstance()->fetch(
                "SELECT COUNT(*) c FROM conversations
                 WHERE assigned_user_id = ?
                   AND status IN ('new','open','waiting_customer','waiting_internal')",
                [$uid]
            )['c'] ?? 0);
            // Empate resolve pelo menor id (a query vem ordenada por id).
            if ($load < $bestLoad) {
                $bestLoad = $load;
                $best = $uid;
            }
        }

        if ($best === null) {
            return null;
        }

        (new ConversationService())->assign($conversationId, $best);
        return $best;
    }

    /**
     * Avisa o plantão do departamento por WhatsApp.
     *
     * O departamento pode ter VÁRIOS telefones de alerta (gestores da
     * rotação): todos recebem o mesmo aviso. Sem telefone cadastrado o
     * aviso é pulado com log — o mesmo tratamento do nó `notify` do
     * flow engine.
     *
     * @param string $kind KIND_FIRST_RESPONSE, KIND_PENDING ou
     *        KIND_GROUP_MENTION (usa o tempo de espera do ciclo da menção,
     *        não o da 1ª resposta).
     * @return int quantidade de telefones que receberam o aviso
     */
    private static function notifyWhatsapp(
        int $conversationId,
        array $measurement,
        array $conv,
        array $cfg,
        string $kind = self::KIND_FIRST_RESPONSE
    ): int {
        $deptId = !empty($conv['department_id']) ? (int) $conv['department_id'] : 0;

        $phones = [];
        if ($deptId > 0) {
            $row = Database::getInstance()->fetch(
                "SELECT sla_escalation_phone FROM departments WHERE id = ?",
                [$deptId]
            );
            $phones = Department::normalizeAlertPhones($row['sla_escalation_phone'] ?? '');
        }

        if (!$phones) {
            error_log('SLA escalonado: departamento sem telefone de plantão, aviso não enviado');
            return 0;
        }

        $channelId = !empty($conv['channel_id']) ? (int) $conv['channel_id'] : 0;
        if ($channelId <= 0 && $deptId > 0) {
            $row = Database::getInstance()->fetch(
                "SELECT id FROM channels WHERE type = 'whatsapp' AND department_id = ? ORDER BY id ASC LIMIT 1",
                [$deptId]
            );
            $channelId = (int) ($row['id'] ?? 0);
        }
        if ($channelId <= 0) {
            error_log('SLA escalonado: sem canal WhatsApp disponível, aviso não enviado');
            return 0;
        }

        $message = self::renderMessage(
            (string) ($cfg['escalation_message'] ?? ''),
            $measurement,
            $conversationId,
            $kind
        );

        $wa = new WhatsAppService();
        $sent = 0;
        foreach ($phones as $phone) {
            if ($wa->sendToPhone($channelId, $phone, $message)) {
                $sent++;
            } else {
                error_log("SLA escalonado: aviso não enviado para o telefone {$phone}");
            }
        }

        return $sent;
    }

    /**
     * Renderiza o template do aviso: tokens do SLA primeiro, depois as
     * variáveis da conversa ({{contact.name}}, {{conversation.protocol}}…).
     *
     * No alerta de "cliente sem resposta" e no de "menção em grupo" o tempo
     * de espera e o limite são os do ciclo pendente (a menção), e o tipo
     * muda — o mesmo template serve para os casos.
     */
    private static function renderMessage(
        string $template,
        array $measurement,
        int $conversationId,
        string $kind = self::KIND_FIRST_RESPONSE
    ): string {
        if ($template === '') {
            $template = SlaService::DEFAULT_CONFIG['escalation_message'];
        }

        $isPending = $kind === self::KIND_PENDING || $kind === self::KIND_GROUP_MENTION;
        $wait = $isPending
            ? (int) ($measurement['pending_seconds'] ?? 0)
            : (int) ($measurement['response_seconds'] ?? 0);
        $limit = $isPending
            ? (int) ($measurement['pending_threshold'] ?? 0)
            : (int) ($measurement['response_threshold'] ?? 0);

        $type = match ($kind) {
            self::KIND_GROUP_MENTION => 'resposta à menção',
            self::KIND_PENDING => 'resposta ao cliente',
            default => 'primeira resposta',
        };

        $message = str_replace(
            ['{{sla.wait}}', '{{sla.limit}}', '{{sla.type}}'],
            [
                self::humanizeSeconds($wait),
                self::humanizeSeconds($limit),
                $type,
            ],
            $template
        );

        return TemplateService::render($message, $conversationId);
    }

    /** 5400 => "1h30" */
    private static function humanizeSeconds(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return (int) round($seconds / 60) . ' min';
        }
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        return $minutes > 0 ? "{$hours}h{$minutes}" : "{$hours}h";
    }
}
