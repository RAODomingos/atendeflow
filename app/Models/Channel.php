<?php

namespace App\Models;

use App\Core\Database;

class Channel
{
    /**
     * Canais realmente conectados (com conexão ativa):
     * - webchat: possui widget ativo
     * - whatsapp: conexão com status 'connected'
     * - email: conta de e-mail ativa
     * Outros tipos são considerados conectados se estiverem ativos.
     */
    public static function getConnected(): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT ch.id, ch.type, ch.name, ch.is_active
             FROM channels ch
             WHERE ch.is_active = 1
               AND (
                   (ch.type = 'webchat' AND EXISTS (
                       SELECT 1 FROM webchat_widgets w
                       WHERE w.channel_id = ch.id AND w.is_active = 1))
                   OR (ch.type = 'whatsapp' AND EXISTS (
                       SELECT 1 FROM whatsapp_connections wc
                       WHERE wc.channel_id = ch.id AND wc.status = 'connected'))
                   OR (ch.type = 'email' AND EXISTS (
                       SELECT 1 FROM email_accounts ea
                       WHERE ea.channel_id = ch.id AND ea.is_active = 1))
                   OR (ch.type NOT IN ('webchat', 'whatsapp', 'email'))
               )
             ORDER BY ch.type, ch.name"
        );
    }
}
