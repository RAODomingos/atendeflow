<?php

// Parte de app/Core/Helper.php — funções de ui.
// Carregado automaticamente pelo Helper.php (não incluir diretamente).

/**
 * Converte "#rgb" ou "#rrggbb" em [r, g, b].
 * Cores inválidas caem no cinza neutro (#6c757d).
 */
function hex_to_rgb(string $hex): array
{
    $hex = ltrim(trim($hex), '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
        return [108, 117, 125];
    }
    return [
        (int) hexdec(substr($hex, 0, 2)),
        (int) hexdec(substr($hex, 2, 2)),
        (int) hexdec(substr($hex, 4, 2)),
    ];
}

/** Luminância relativa de uma cor RGB (WCAG 2.1). */
function relative_luminance(int $r, int $g, int $b): float
{
    $f = static function (float $v): float {
        $v /= 255;
        return $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
    };
    return 0.2126 * $f($r) + 0.7152 * $f($g) + 0.0722 * $f($b);
}

/** Razão de contraste entre duas luminâncias relativas. */
function contrast_ratio(float $l1, float $l2): float
{
    $hi = max($l1, $l2);
    $lo = min($l1, $l2);
    return ($hi + 0.05) / ($lo + 0.05);
}

/**
 * Par (fundo, texto) com contraste garantido — WCAG AA (>= 4,5:1).
 *
 * Mantém a cor escolhida pelo usuário no chip. Se nem texto branco nem
 * texto escuro passarem de 4,5:1 sobre ela, escurece o fundo o suficiente
 * para o texto branco passar.
 *
 * @return array{0:string,1:string} [cor de fundo, cor de texto]
 */
function readable_pair(string $hex): array
{
    [$r, $g, $b] = hex_to_rgb($hex);
    $lum = relative_luminance($r, $g, $b);
    $darkLum = relative_luminance(32, 31, 30);   // var(--text-primary)

    if (contrast_ratio(1.0, $lum) >= 4.5) {
        return [$hex, '#ffffff'];
    }
    if (contrast_ratio($lum, $darkLum) >= 4.5) {
        return [$hex, '#201f1e'];
    }

    // Nenhum dos dois passa: escurece o fundo até o branco passar.
    $f = 1.0;
    for ($i = 0; $i < 60; $i++) {
        $f -= 0.01;
        if ($f <= 0.05) {
            break;
        }
        $l = relative_luminance((int) round($r * $f), (int) round($g * $f), (int) round($b * $f));
        if (contrast_ratio(1.0, $l) >= 4.6) {
            break;
        }
    }
    return [sprintf('#%02x%02x%02x', (int) round($r * $f), (int) round($g * $f), (int) round($b * $f)), '#ffffff'];
}

/**
 * Cor de texto legível sobre um fundo claro tingido com $hex.
 * Escurece $hex até atingir 4,5:1 sobre a mistura (fundo = $tint da cor
 * sobre branco).
 */
function readable_text_on_tint(string $hex, float $tint = 0.125): string
{
    [$r, $g, $b] = hex_to_rgb($hex);
    $bgLum = relative_luminance(
        (int) round($r * $tint + 255 * (1 - $tint)),
        (int) round($g * $tint + 255 * (1 - $tint)),
        (int) round($b * $tint + 255 * (1 - $tint))
    );
    $f = 1.0;
    for ($i = 0; $i < 80; $i++) {
        $l = relative_luminance((int) round($r * $f), (int) round($g * $f), (int) round($b * $f));
        if (contrast_ratio($l, $bgLum) >= 4.6) {
            break;
        }
        $f -= 0.01;
        if ($f <= 0.1) {
            break;
        }
    }
    return sprintf('#%02x%02x%02x', (int) round($r * $f), (int) round($g * $f), (int) round($b * $f));
}

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

/**
 * Cor de texto para uma cor de fundo sólida — escolhe a de maior
 * contraste (branco ou ink primário).
 *
 * ATENÇÃO: para chips preenchidos com cor sólida prefira readable_pair(),
 * que também escurece o fundo quando nenhuma das duas passa de 4,5:1.
 */
function contrast_color(string $hex): string
{
    [$r, $g, $b] = hex_to_rgb($hex);
    $lum = relative_luminance($r, $g, $b);
    $darkLum = relative_luminance(32, 31, 30);
    return contrast_ratio($lum, $darkLum) > contrast_ratio(1.0, $lum) ? '#201f1e' : '#ffffff';
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

/**
 * Texto de preview da conversa na lista.
 *
 * Webhooks e fluxos gravam o payload JSON como conteúdo da mensagem. Mostrar
 * esse JSON bruto na lista é ilegível ("{"title":"Avalie seu atendimento"…}")
 * e ainda ocupa a linha inteira. Aqui extrai o texto útil do payload; quando
 * não há texto legível, devolve um rótulo coerente.
 */
function conversation_preview_text(?string $raw, int $max = 80): string
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return 'Sem mensagens';
    }

    $first = $raw[0];
    if ($first === '{' || $first === '[') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            foreach (['prompt', 'message', 'text', 'body', 'content', 'description'] as $key) {
                if (isset($decoded[$key]) && is_string($decoded[$key]) && trim($decoded[$key]) !== '') {
                    return truncate(trim($decoded[$key]), $max);
                }
            }
            // Botão/ação de fluxo: mostra o rótulo da primeira opção
            foreach (['buttons', 'options', 'actions', 'items'] as $key) {
                if (!empty($decoded[$key]) && is_array($decoded[$key])) {
                    $opt = reset($decoded[$key]);
                    if (is_array($opt)) {
                        foreach (['title', 'label', 'text'] as $k) {
                            if (!empty($opt[$k]) && is_string($opt[$k])) {
                                return truncate(trim($opt[$k]), $max);
                            }
                        }
                    }
                }
            }
            if (!empty($decoded['title']) && is_string($decoded['title'])) {
                return truncate(trim($decoded['title']), $max);
            }
            return 'Mensagem automática';
        }
    }

    return truncate($raw, $max);
}

/**
 * Bloco de transcrição abaixo do player de áudio.
 * Com texto salvo: exibe. Sem texto: botão sob demanda (handler global em app-enhancements.js).
 */
function audio_transcription_html(array $msg): string
{
    $text = trim((string) ($msg['transcription'] ?? ''));
    if ($text !== '') {
        return '<div class="msg-transcription"><strong>Transcrição:</strong> ' . e($text) . '</div>';
    }
    $id = (int) ($msg['id'] ?? 0);
    if ($id <= 0) {
        return '';
    }
    return '<button type="button" class="btn-transcribe" data-transcribe-id="' . $id . '"'
        . ' title="Ao transcrever, o áudio é enviado à OpenAI">Transcrever</button>'
        . '<div class="msg-transcription" hidden></div>';
}
