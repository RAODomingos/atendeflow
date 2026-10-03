<?php

namespace App\Models;

use App\Core\Database;

/**
 * Mapa LID -> telefone aprendido dos webhooks.
 * Em grupos, participantes e mencionados chegam como @lid; o telefone real
 * aparece em campos *_pn/chatlid ou via endpoint resolvePhone (WAHA).
 */
class WhatsAppLidMap
{
    /**
     * LIDs têm 15+ dígitos; telefones (com DDI) têm até ~14.
     */
    public static function isLid(string $digits): bool
    {
        $digits = preg_replace('/\D/', '', $digits);
        return strlen($digits) > 14;
    }

    public static function learn(?string $lid, ?string $phone): void
    {
        $lid = preg_replace('/\D/', '', (string) $lid);
        $phone = preg_replace('/\D/', '', (string) $phone);
        if ($lid === '' || $phone === '' || $lid === $phone) {
            return;
        }
        try {
            Database::getInstance()->query(
                "INSERT INTO whatsapp_lid_map (lid_digits, phone_digits) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE phone_digits = VALUES(phone_digits)",
                [$lid, $phone]
            );
        } catch (\Throwable $e) {
            error_log('lid_map learn error: ' . $e->getMessage());
        }
    }

    public static function resolve(string $digits): ?string
    {
        $digits = preg_replace('/\D/', '', $digits);
        if ($digits === '' || !self::isLid($digits)) {
            return $digits !== '' ? $digits : null;
        }
        try {
            $row = Database::getInstance()->fetch(
                "SELECT phone_digits FROM whatsapp_lid_map WHERE lid_digits = ? LIMIT 1",
                [$digits]
            );
        } catch (\Throwable $e) {
            return null;
        }
        return $row['phone_digits'] ?? null;
    }
}
