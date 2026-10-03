<?php

// Parte de app/Core/Helper.php — funções de ui.
// Carregado automaticamente pelo Helper.php (não incluir diretamente).

function csat_stars(?array $csat, int $max = 5): string
{
    if (empty($csat) || empty($csat['rating'])) {
        return '';
    }
    $rating = (int) $csat['rating'];
    $html = '<span class="csat-stars" title="Avalia&ccedil;&atilde;o: ' . $rating . '/5">';
    for ($i = 1; $i <= $max; $i++) {
        $on = $i <= $rating ? ' on' : '';
        $html .= '<i class="fas fa-star csat-star' . $on . '"></i>';
    }
    $html .= '</span>';
    return $html;
}

function contrast_color(string $hex): string
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $l = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    return $l > 0.5 ? '#1a1a2e' : '#ffffff';
}

function event_icon(string $type): string
{
    $map = [
        'created' => 'fa-plus-circle',
        'assigned' => 'fa-user-check',
        'transferred' => 'fa-exchange-alt',
        'status_changed' => 'fa-flag',
        'priority_changed' => 'fa-arrow-up',
        'snoozed' => 'fa-clock',
        'csat' => 'fa-star',
        'merged' => 'fa-code-branch',
        'tag_added' => 'fa-tag',
        'tag_removed' => 'fa-tag',
        'note_added' => 'fa-sticky-note',
    ];
    return $map[$type] ?? 'fa-circle';
}

function event_color(string $type): string
{
    $map = [
        'created' => '#22c55e',
        'assigned' => '#3b82f6',
        'transferred' => '#f59e0b',
        'status_changed' => '#8b5cf6',
        'priority_changed' => '#eab308',
        'snoozed' => '#6b7280',
        'csat' => '#f59e0b',
        'merged' => '#ef4444',
        'tag_added' => '#14b8a6',
        'tag_removed' => '#9ca3af',
        'note_added' => '#6b7280',
    ];
    return $map[$type] ?? 'var(--primary)';
}
