<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Contact;

/**
 * Resolve variáveis (placeholders) em mensagens antes do envio.
 *
 * Suporta a sintaxe proposta "{{ contact.name }}" e também os tokens
 * legados dos fluxos ("{nome}", "{email}", etc.).
 *
 * Tokens disponíveis:
 *   {{ contact.name }}      {{ contact.email }}     {{ contact.phone }}
 *   {{ contact.company }}   {{ contact.document }}
 *   {{ department.name }}   {{ department.color }}
 *   {{ agent.name }}
 *   {{ conversation.subject }}   {{ conversation.id }}
 */
class TemplateService
{
    /**
     * Resolve as variáveis de $content para a conversa informada.
     * Se o conteúdo não tiver placeholders, é retornado sem alteração.
     */
    public static function render(string $content, int $conversationId): string
    {
        if (!str_contains($content, '{{') && !str_contains($content, '{')) {
            return $content;
        }

        $conv = Conversation::find($conversationId);
        if (!$conv) {
            return $content;
        }

        $contact = Contact::find((int) $conv['contact_id']) ?? [];

        $vars = [
            'contact.name'        => $contact['name'] ?? ($conv['contact_name'] ?? 'Cliente'),
            'contact.email'       => $contact['email'] ?? ($conv['contact_email'] ?? ''),
            'contact.phone'       => $contact['phone'] ?? ($conv['contact_phone'] ?? ''),
            'contact.company'     => $contact['company'] ?? '',
            'contact.document'    => $contact['document'] ?? '',
            'department.name'     => $conv['department_name'] ?? '',
            'department.color'    => $conv['department_color'] ?? '',
            'agent.name'          => $conv['assigned_user_name'] ?? '',
            'conversation.subject' => $conv['subject'] ?? '',
            'conversation.id'     => $conv['id'] ?? '',
        ];

        // Tokens legados utilizados pelos fluxos (FlowEngineService::processTemplate)
        $legacy = [
            '{nome}'      => $vars['contact.name'],
            '{email}'     => $vars['contact.email'],
            '{telefone}'  => $vars['contact.phone'],
            '{departamento}' => $vars['department.name'],
            '{atendente}' => $vars['agent.name'],
        ];

        $rendered = str_replace(array_keys($legacy), array_values($legacy), $content);

        $rendered = preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/u', function (array $m) use ($vars) {
            $key = mb_strtolower($m[1]);
            return $vars[$key] ?? $m[0];
        }, $rendered);

        return $rendered;
    }
}
