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
}
