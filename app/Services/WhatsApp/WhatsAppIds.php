<?php

namespace App\Services\WhatsApp;

/**
 * Normalização de identificadores do WhatsApp (telefone, LID, JID de grupo).
 *
 * Fica fora dos providers de propósito: UAZAPI, WAHA e os provedores
 * self-hosted (wwebjs/zapo) entregam identificadores em formatos diferentes
 * (`@c.us`, `@s.whatsapp.net`, `@lid`, com ou sem sufixo de dispositivo), mas o
 * AtendeFlow precisa que "telefone do cliente", "lid" e "grupo" signifiquem
 * sempre a mesma coisa — inclusive para quem só fala o protocolo direto
 * (zapo), onde o par LID/telefone chega em dois campos (`remoteJid` e
 * `remoteJidAlt`).
 */
final class WhatsAppIds
{
    /**
     * Dígitos de um identificador. Remove os sufixos `@c.us`,
     * `@s.whatsapp.net`, `@lid`, `@g.us` e o sufixo de dispositivo (`:93`).
     */
    public static function digits(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/@s\.whatsapp\.net$|@c\.us$|@lid$|@g\.us$/', '', $value);
        $value = preg_replace('/:\d+$/', '', $value);
        return (string) preg_replace('/\D/', '', $value);
    }

    /**
     * Telefone normalizado ou null quando não há dígitos utilizáveis.
     */
    public static function phone(?string $value): ?string
    {
        $digits = self::digits($value);
        return $digits === '' ? null : $digits;
    }

    /**
     * true quando o identificador é um LID: sufixo `@lid` ou id longo
     * (LIDs do WhatsApp Web têm mais de 14 dígitos; telefones têm de 8 a 14).
     */
    public static function isLid(?string $value): bool
    {
        if ($value === null || trim($value) === '') {
            return false;
        }
        if (str_contains($value, '@lid')) {
            return true;
        }
        $digits = self::digits($value);
        return $digits !== '' && strlen($digits) > 14;
    }

    /**
     * true quando o identificador tem cara de telefone (8 a 14 dígitos).
     */
    public static function isPhone(?string $value): bool
    {
        $digits = self::digits($value);
        return $digits !== '' && strlen($digits) >= 8 && strlen($digits) <= 14;
    }

    /**
     * Separa o par LID <-> telefone a partir dos dois identificadores que o
     * WhatsApp entrega juntos (remoteJid/remoteJidAlt, participant/participantAlt).
     *
     * Regra: quem tem `@lid` é o LID; o outro é o telefone quando tem cara de
     * telefone. Sem marcador explícito, id com mais de 14 dígitos é LID.
     * O telefone tem prioridade — é o dado que o AtendeFlow usa para
     * identificar o cliente.
     *
     * @return array{phone:?string, lid:?string}
     */
    public static function splitLidAndPhone(?string $primary, ?string $alt = null): array
    {
        $phone = null;
        $lid = null;

        foreach ([$primary, $alt] as $raw) {
            if ($raw === null || trim($raw) === '') {
                continue;
            }
            $digits = self::digits($raw);
            if ($digits === '') {
                continue;
            }
            if (str_contains($raw, '@lid')) {
                if ($lid === null) {
                    $lid = $digits;
                }
                continue;
            }
            $len = strlen($digits);
            if ($len >= 8 && $len <= 14) {
                if ($phone === null) {
                    $phone = $digits;
                }
                continue;
            }
            if ($len > 14) {
                if ($lid === null) {
                    $lid = $digits;
                }
                continue;
            }
            // Curto demais para telefone: guarda como telefone só se não houver outro.
            if ($phone === null) {
                $phone = $digits;
            }
        }

        return ['phone' => $phone, 'lid' => $lid];
    }

    /**
     * Mapeia participantes brutos (de qualquer provider) para o shape padrão.
     *
     * Aceita entradas só-telefone (sem LID) e varre o objeto inteiro porque o
     * formato muda entre provedores (WAHA, UAZAPI, zapo).
     *
     * @return array<int, array{phone:string, lid:?string, name:?string, is_admin:bool}>
     */
    public static function mapParticipants(array $raw): array
    {
        $out = [];
        foreach ($raw as $p) {
            if (!is_array($p)) {
                continue;
            }
            // Varre o objeto do participante: um valor é o LID (>14 dígitos),
            // outro é o telefone (8-14 dígitos).
            $lid = '';
            $phone = '';
            $stack = [$p];
            while ($stack) {
                $v = array_pop($stack);
                if (is_array($v)) {
                    foreach ($v as $sv) {
                        $stack[] = $sv;
                    }
                    continue;
                }
                if (!is_string($v) || $v === '') {
                    continue;
                }
                // Ignora JID do grupo (@g.us) se vier aninhado no participante.
                if (str_contains($v, '@g.us')) {
                    continue;
                }
                $digits = preg_replace('/\D/', '', $v);
                if ($digits === '') {
                    continue;
                }
                if (strlen($digits) > 14 && $lid === '') {
                    $lid = $digits;
                } elseif (strlen($digits) >= 8 && strlen($digits) <= 14 && $phone === '') {
                    $phone = $digits;
                }
            }
            if (($lid === '' && $phone === '') || $lid === $phone) {
                continue;
            }
            $name = null;
            foreach (['name', 'pushName', 'pushname', 'notifyName', 'wa_contactName'] as $nk) {
                if (!empty($p[$nk]) && is_string($p[$nk])) {
                    $name = $p[$nk];
                    break;
                }
            }
            $admin = $p['isAdmin'] ?? $p['is_admin'] ?? $p['admin'] ?? false;
            $out[] = [
                'phone' => $phone,
                'lid' => $lid !== '' ? $lid : null,
                'name' => $name,
                'is_admin' => (bool) $admin,
            ];
        }
        return $out;
    }
}
