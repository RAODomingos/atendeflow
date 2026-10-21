<?php

namespace App\Services;

use App\Core\Database;
use App\Models\Conversation;
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

    /**
     * Executa as ações de escalonamento.
     *
     * @param array $measurement Resultado de SlaMeasurement::measure().
     * @param array $conv        Linha de `conversations`.
     * @return array{tag: bool, managers: int, autoassign: ?int, whatsapp: bool}
     */
    public static function escalate(int $conversationId, array $measurement, array $conv): array
    {
        $result = [
            'tag' => false,
            'managers' => 0,
            'autoassign' => null,
            'whatsapp' => false,
        ];

        $inboxId = !empty($conv['inbox_id']) ? (int) $conv['inbox_id'] : null;
        $cfg = SlaService::configFor($inboxId)['config'] ?? [];

        // 1) tag
        if (($cfg['escalate_tag'] ?? '1') === '1') {
            $tagId = Tag::ensureByName(self::TAG_NAME, '#dc2626');
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
                'SLA de primeira resposta violado',
                'A conversa #' . $conversationId . ' furou o SLA de primeira resposta.',
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
            $result['whatsapp'] = self::notifyWhatsapp($conversationId, $measurement, $conv, $cfg);
        }

        Conversation::addEvent(
            $conversationId,
            'sla_escalated',
            'SLA escalonado',
            null,
            $result
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
        if ($inboxId <= 0) {
            return null;
        }

        // Candidatos: membros da caixa + do departamento. Admins ficam de
        // fora: eles aparecem na lista de acesso por visibilidade, não
        // por escala.
        $candidates = Database::getInstance()->fetchAll(
            "SELECT u.id FROM users u
             LEFT JOIN inbox_users iu ON iu.user_id = u.id AND iu.inbox_id = ?
             LEFT JOIN department_users du ON du.user_id = u.id
             LEFT JOIN inbox_departments idp ON idp.department_id = du.department_id AND idp.inbox_id = ?
             WHERE u.is_active = 1 AND u.role != 'admin'
               AND (iu.id IS NOT NULL OR idp.id IS NOT NULL)
             GROUP BY u.id
             ORDER BY u.id ASC",
            [$inboxId, $inboxId]
        );

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
     * Sem telefone cadastrado o aviso é pulado com log — o mesmo
     * tratamento do nó `notify` do flow engine.
     */
    private static function notifyWhatsapp(int $conversationId, array $measurement, array $conv, array $cfg): bool
    {
        $deptId = !empty($conv['department_id']) ? (int) $conv['department_id'] : 0;

        $phone = '';
        if ($deptId > 0) {
            $row = Database::getInstance()->fetch(
                "SELECT sla_escalation_phone FROM departments WHERE id = ?",
                [$deptId]
            );
            $phone = trim((string) ($row['sla_escalation_phone'] ?? ''));
        }

        if ($phone === '') {
            error_log('SLA escalonado: departamento sem telefone de plantão, aviso não enviado');
            return false;
        }

        $phoneClean = preg_replace('/\D/', '', $phone);
        if (strlen((string) $phoneClean) < 10) {
            error_log('SLA escalonado: telefone de plantão inválido, aviso não enviado');
            return false;
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
            return false;
        }

        $message = self::renderMessage(
            (string) ($cfg['escalation_message'] ?? ''),
            $measurement,
            $conversationId
        );

        $wa = new WhatsAppService();
        return (bool) $wa->sendToPhone($channelId, (string) $phoneClean, $message);
    }

    /**
     * Renderiza o template do aviso: tokens do SLA primeiro, depois as
     * variáveis da conversa ({{contact.name}}, {{conversation.protocol}}…).
     */
    private static function renderMessage(string $template, array $measurement, int $conversationId): string
    {
        if ($template === '') {
            $template = SlaService::DEFAULT_CONFIG['escalation_message'];
        }

        $message = str_replace(
            ['{{sla.wait}}', '{{sla.limit}}', '{{sla.type}}'],
            [
                self::humanizeSeconds((int) ($measurement['response_seconds'] ?? 0)),
                self::humanizeSeconds((int) ($measurement['response_threshold'] ?? 0)),
                'primeira resposta',
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
