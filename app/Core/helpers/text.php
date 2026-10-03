<?php

// Parte de app/Core/Helper.php — funções de text.
// Carregado automaticamente pelo Helper.php (não incluir diretamente).

function truncate(string $text, int $limit = 100): string
{
    if (mb_strlen($text) <= $limit) {
        return $text;
    }
    return mb_substr($text, 0, $limit) . '...';
}

/**
 * Escapa o texto e transforma URLs em links clicáveis (nova aba).
 * Centraliza a lógica antes repetida nas views do inbox.
 */

function linkify(string $text): string
{
    return (string) preg_replace(
        '@(https?://[^\s<]+)@',
        '<a href="$1" target="_blank" rel="noopener">$1</a>',
        e($text)
    );
}

function linkify_br(string $text): string
{
    return nl2br(linkify($text));
}

/**
 * Resolve avatar (caminho relativo "avatars/x.png" ou URL absoluta)
 * para URL pública. Mesmo comportamento do avatarSrc() do painel.
 */

function time_elapsed(string $datetime): string
{
    $now = new DateTime();
    $then = new DateTime($datetime);
    $diff = $now->diff($then);

    if ($diff->y > 0) return "{$diff->y}a";
    if ($diff->m > 0) return "{$diff->m}mes";
    if ($diff->d > 0) return "{$diff->d}d";
    if ($diff->h > 0) return "{$diff->h}h";
    if ($diff->i > 0) return "{$diff->i}min";
    return "agora";
}

function format_datetime(string $datetime): string
{
    $dt = new DateTime($datetime);
    return $dt->format('d/m/Y H:i');
}

/**
 * Formata o protocolo do atendimento (só dígitos, sem traço — ex.: 260914145).
 */

function format_protocol(?string $protocol): string
{
    return preg_replace('/\D/', '', (string) $protocol);
}

function format_date(string $datetime): string
{
    if (!$datetime) return '-';
    $dt = new DateTime($datetime);
    return $dt->format('d/m/Y');
}

function format_date_sep(string $datetime): string
{
    $dt = new DateTime($datetime);
    $now = new DateTime();
    $today = (new DateTime())->setTime(0, 0, 0);
    $yesterday = (clone $today)->modify('-1 day');
    $target = (clone $dt)->setTime(0, 0, 0);

    if ($target == $today) return 'Hoje';
    if ($target == $yesterday) return 'Ontem';
    return $dt->format('d/m/Y');
}

function format_time(string $datetime): string
{
    $dt = new DateTime($datetime);
    $now = new DateTime();
    $today = (new DateTime())->setTime(0, 0, 0);
    $yesterday = (clone $today)->modify('-1 day');
    $target = (clone $dt)->setTime(0, 0, 0);

    if ($target == $today) return $dt->format('H:i');
    if ($target == $yesterday) return 'Ontem ' . $dt->format('H:i');
    return $dt->format('d/m H:i');
}

function status_badge(string $status): string
{
    $labels = [
        'new' => 'Novo',
        'open' => 'Aberto',
        'waiting_customer' => 'Em atendimento',
        'waiting_internal' => 'Aguardando Interno',
        'resolved' => 'Resolvido',
        'closed' => 'Fechado',
        'spam' => 'Spam',
    ];

    $label = $labels[$status] ?? $status;

    return "<span class=\"status-badge status-{$status}\">{$label}</span>";
}

function priority_badge(string $priority): string
{
    $labels = [
        'low' => 'Baixa',
        'normal' => 'Normal',
        'high' => 'Alta',
        'urgent' => 'Urgente',
    ];
    $label = $labels[$priority] ?? ucfirst($priority);
    return "<span class=\"priority-badge priority-{$priority}\">{$label}</span>";
}

function channel_icon(string $type): string
{
    $icons = [
        'whatsapp' => 'fab fa-whatsapp',
        'webchat' => 'fas fa-comment-dots',
        'email' => 'fas fa-envelope',
    ];
    return $icons[$type] ?? 'fas fa-comment';
}

function slugify(string $text): string
{
    $text = preg_replace('~[^\p{L}\p{N}]+~u', '-', $text);
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    $text = strtolower(trim($text, '-'));
    return $text ?: 'sem-nome';
}
