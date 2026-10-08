<?php

namespace App\Models;

use App\Core\Database;

/**
 * Nomes de contatos aprendidos dos webhooks (pushName/notifyName).
 * O /group/info do provedor não retorna nomes; quem já falou tem nome
 * conhecido aqui e aparece nas menções (@Nome @telefone).
 */
class WhatsAppContactName
{
    public static function learn(string $phone, string $name): void
    {
        $phone = preg_replace('/\D/', '', $phone);
        $name = trim($name);
        if ($phone === '' || strlen($phone) < 8 || $name === '') {
            return;
        }
        // Não sobrescreve nome manual? Não há edição manual: último visto vence.
        try {
            Database::getInstance()->query(
                "INSERT INTO whatsapp_contact_names (phone_digits, name) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE name = VALUES(name)",
                [$phone, mb_substr($name, 0, 255)]
            );
        } catch (\Throwable $e) {
            error_log('contact_names learn error: ' . $e->getMessage());
        }
    }

    public static function resolve(string $phone): ?string
    {
        $phone = preg_replace('/\D/', '', $phone);
        if ($phone === '') {
            return null;
        }
        try {
            $row = Database::getInstance()->fetch(
                "SELECT name FROM whatsapp_contact_names WHERE phone_digits = ? LIMIT 1",
                [$phone]
            );
        } catch (\Throwable $e) {
            return null;
        }
        return $row['name'] ?? null;
    }
}
