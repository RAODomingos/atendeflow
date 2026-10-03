<?php

namespace App\Services;

use App\Core\Auth;
use App\Models\Conversation;
use App\Models\Contact;

/**
 * Resolve variáveis (placeholders) em mensagens antes do envio.
 *
 * Suporta a sintaxe proposta "{{ contact.name }}" e também os tokens
 * legados dos fluxos ("{nome}", "{email}", etc.).
 *
 * Tokens disponíveis:
 *   {{ contact.name }}  {{ contact.first_name }} {{ contact.email }} {{ contact.phone }}
 *   {{ contact.company }} {{ contact.document }}
 *   {{ department.name }} {{ department.color }}
 *   {{ agent.name }}  {{ agent.email }}
 *   {{ conversation.subject }} {{ conversation.id }} {{ conversation.protocol }}
 *   {{ date }} {{ time }} {{ greeting }}
 *   {{ csat.link }}     (página pública de avaliação da conversa)
 */
class TemplateService
{
    /**
     * Lista de variáveis p/ exibir na UI (macros + respostas rápidas).
     * Cada item: token + label + exemplo.
     *
     * @return array<int, array{token:string,label:string,example:string}>
     */
    public static function availableVariables(): array
    {
        return [
            ['token' => '{{ contact.name }}', 'label' => 'Nome do cliente', 'example' => 'Maria Silva'],
            ['token' => '{{ contact.first_name }}', 'label' => 'Primeiro nome do cliente', 'example' => 'Maria'],
            ['token' => '{{ contact.email }}', 'label' => 'E-mail do cliente', 'example' => 'maria@email.com'],
            ['token' => '{{ contact.phone }}', 'label' => 'Telefone do cliente', 'example' => '(11) 99999-0000'],
            ['token' => '{{ contact.company }}', 'label' => 'Empresa do cliente', 'example' => 'Acme LTDA'],
            ['token' => '{{ agent.name }}', 'label' => 'Nome do atendente', 'example' => 'João Atendente'],
            ['token' => '{{ department.name }}', 'label' => 'Setor / departamento', 'example' => 'Suporte'],
            ['token' => '{{ conversation.protocol }}', 'label' => 'Protocolo da conversa', 'example' => '20251002-123'],
            ['token' => '{{ date }}', 'label' => 'Data de hoje', 'example' => '02/10/2026'],
            ['token' => '{{ time }}', 'label' => 'Hora atual', 'example' => '14:30'],
            ['token' => '{{ greeting }}', 'label' => 'Saudação (Bom dia/Boa tarde/Boa noite)', 'example' => 'Boa tarde'],
            ['token' => '{{ csat.link }}', 'label' => 'Link de avaliação (CSAT)', 'example' => 'https://.../csat/xxx'],
        ];
    }

    /**
     * Resolve as variáveis de $content para a conversa informada.
     * Se o conteúdo não tiver placeholders, é retornado sem alteração.
     */
    public static function render(string $content, int $conversationId): string
    {
        if (!str_contains($content, '{{') && !str_contains($content, '{')) {
            return $content;
        }

        $vars = self::varsForConversation($conversationId);
        if ($vars === null) {
            return $content;
        }

        // Tokens legados utilizados pelos fluxos (FlowEngineService::processTemplate)
        $legacy = [
            '{nome}'      => $vars['contact.name'],
            '{email}'     => $vars['contact.email'],
            '{telefone}'  => $vars['contact.phone'],
            '{departamento}' => $vars['department.name'],
            '{atendente}' => $vars['agent.name'],
            '{empresa}'   => $vars['contact.company'],
            '{protocolo}' => $vars['conversation.protocol'],
            '{data}'      => $vars['date'],
            '{hora}'      => $vars['time'],
            '{saudacao}'  => $vars['greeting'],
        ];

        $rendered = str_replace(array_keys($legacy), array_values($legacy), $content);

        $rendered = preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/u', function (array $m) use ($vars) {
            $key = mb_strtolower($m[1]);
            return $vars[$key] ?? $m[0];
        }, $rendered);

        return $rendered;
    }

    /**
     * Renderiza a legenda (caption) dentro de um conteúdo de mídia serializado
     * em JSON ({"url":..., "caption":...}). Usado p/ macros com foto/vídeo/
     * arquivo cuja legenda contém variáveis.
     */
    public static function renderMediaContent(string $content, int $conversationId): string
    {
        $decoded = json_decode($content, true);
        if (!is_array($decoded) || empty($decoded['url'])) {
            // Não é mídia JSON: trata como texto comum.
            return self::render($content, $conversationId);
        }
        if (isset($decoded['caption']) && is_string($decoded['caption']) && $decoded['caption'] !== '') {
            $decoded['caption'] = self::render($decoded['caption'], $conversationId);
            return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $content;
    }

    /**
     * Monta o mapa de variáveis p/ a conversa. Retorna null se não achar.
     */
    private static function varsForConversation(int $conversationId): ?array
    {
        $conv = Conversation::find($conversationId);
        if (!$conv) {
            return null;
        }

        $contact = Contact::find((int) $conv['contact_id']) ?? [];
        $contactName = (string) ($contact['name'] ?? ($conv['contact_name'] ?? 'Cliente'));
        $firstName = trim((string) explode(' ', $contactName)[0]);

        // Atendente: responsável da conversa; se vazio, usa o usuário logado.
        $agentName = (string) ($conv['assigned_user_name'] ?? '');
        $agentEmail = '';
        if ($agentName === '') {
            try {
                $me = Auth::user();
                $agentName = (string) ($me['name'] ?? '');
                $agentEmail = (string) ($me['email'] ?? '');
            } catch (\Throwable $e) {
                $agentName = '';
            }
        }

        $protocol = (string) ($conv['protocol'] ?? '');
        if ($protocol === '' && function_exists('format_protocol') && isset($conv['protocol'])) {
            $protocol = format_protocol((string) $conv['protocol']);
        }

        $hour = (int) date('H');
        $greeting = $hour < 12 ? 'Bom dia' : ($hour < 18 ? 'Boa tarde' : 'Boa noite');

        return [
            'contact.name'        => $contactName,
            'contact.first_name'  => $firstName !== '' ? $firstName : $contactName,
            'contact.email'       => $contact['email'] ?? ($conv['contact_email'] ?? ''),
            'contact.phone'       => $contact['phone'] ?? ($conv['contact_phone'] ?? ''),
            'contact.company'     => $contact['company'] ?? '',
            'contact.document'    => $contact['document'] ?? '',
            'department.name'     => $conv['department_name'] ?? '',
            'department.color'    => $conv['department_color'] ?? '',
            'agent.name'          => $agentName,
            'agent.email'         => $agentEmail,
            'conversation.subject' => $conv['subject'] ?? '',
            'conversation.id'     => $conv['id'] ?? '',
            'conversation.protocol' => $protocol,
            'protocol'            => $protocol,
            'date'                => date('d/m/Y'),
            'time'                => date('H:i'),
            'greeting'            => $greeting,
            'saudacao'            => $greeting,
            'csat.link'           => !empty($conv['public_id']) ? base_url('csat/' . $conv['public_id']) : '',
        ];
    }
}
