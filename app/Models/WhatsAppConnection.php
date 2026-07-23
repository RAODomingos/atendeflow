<?php

namespace App\Models;

use App\Core\Database;

class WhatsAppConnection
{
    public static function findByChannel(int $channelId): ?array
    {
        return Database::getInstance()->fetch(
            "SELECT * FROM whatsapp_connections WHERE channel_id = ? LIMIT 1",
            [$channelId]
        );
    }

    public static function findByProviderId(string $provider, string $providerId): ?array
    {
        return Database::getInstance()->fetch(
            "SELECT wc.* FROM whatsapp_connections wc
             WHERE wc.provider = ? AND (wc.instance_name = ? OR wc.instance_id = ?) LIMIT 1",
            [$provider, $providerId, $providerId]
        );
    }

    public static function find(int $id): ?array
    {
        return Database::getInstance()->fetch(
            "SELECT * FROM whatsapp_connections WHERE id = ?",
            [$id]
        );
    }

    public static function create(array $data): int
    {
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        return Database::getInstance()->insert('whatsapp_connections', $data);
    }

    public static function update(int $id, array $data): int
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        return Database::getInstance()->update('whatsapp_connections', $data, 'id = ?', [$id]);
    }

    public static function delete(int $id): int
    {
        return Database::getInstance()->delete('whatsapp_connections', 'id = ?', [$id]);
    }

    /**
     * Lista de conexões já com o nome do canal e do departamento (para a UI).
     */
    public static function allWithDetails(): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT wc.*, ch.name as channel_name, ch.flow_id, d.name as department_name, f.name as flow_name
             FROM whatsapp_connections wc
             JOIN channels ch ON ch.id = wc.channel_id
             LEFT JOIN departments d ON d.id = ch.department_id
             LEFT JOIN flows f ON f.id = ch.flow_id
             ORDER BY wc.created_at DESC"
        );
    }
}
