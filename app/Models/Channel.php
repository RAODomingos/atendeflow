<?php

namespace App\Models;

use App\Core\Database;

class Channel
{
    public static function getWhatsapp(): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT ch.id, ch.type, ch.name
             FROM channels ch
             WHERE ch.type = 'whatsapp' AND ch.is_active = 1
               AND EXISTS (
                   SELECT 1 FROM whatsapp_connections wc
                   WHERE wc.channel_id = ch.id AND wc.status = 'connected')
             ORDER BY ch.name"
        );
    }

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
                   OR (ch.type NOT IN ('webchat', 'whatsapp'))
               )
             ORDER BY ch.type, ch.name"
        );
    }

    /**
     * Todos os canais ativos para VINCULAÇÃO (form da caixa): inclui WhatsApp
     * desconectado — vínculo é configuração e não deve sumir quando a sessão
     * cai. Traz status da conexão para a UI sinalizar.
     */
    public static function allActiveForLinking(): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT ch.id, ch.type, ch.name, ch.is_active,
                    wc.status as connection_status, wc.phone_number
             FROM channels ch
             LEFT JOIN whatsapp_connections wc ON wc.channel_id = ch.id AND ch.type = 'whatsapp'
             LEFT JOIN webchat_widgets w ON w.channel_id = ch.id AND ch.type = 'webchat'
             WHERE ch.is_active = 1
             ORDER BY ch.type, ch.name"
        );
    }
}
