<?php

namespace App\Models;

use App\Core\Database;

class BusinessHours
{
    public static function getByInbox(int $inboxId): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT * FROM inbox_business_hours WHERE inbox_id = ? ORDER BY day_of_week",
            [$inboxId]
        );
    }

    public static function upsert(int $inboxId, int $dayOfWeek, bool $isOpen, ?string $openTime, ?string $closeTime): void
    {
        $db = Database::getInstance();
        $existing = $db->fetch(
            "SELECT id FROM inbox_business_hours WHERE inbox_id = ? AND day_of_week = ?",
            [$inboxId, $dayOfWeek]
        );

        $data = [
            'inbox_id' => $inboxId,
            'day_of_week' => $dayOfWeek,
            'is_open' => $isOpen ? 1 : 0,
            'open_time' => $openTime,
            'close_time' => $closeTime,
        ];

        if ($existing) {
            $db->update('inbox_business_hours', $data, 'id = ?', [$existing['id']]);
        } else {
            $db->insert('inbox_business_hours', $data);
        }
    }

    public static function delete(int $inboxId): void
    {
        Database::getInstance()->delete('inbox_business_hours', 'inbox_id = ?', [$inboxId]);
    }

    /**
     * Verifica se o inbox está em horário comercial agora
     */
    public static function isWithinBusinessHours(int $inboxId): bool
    {
        $inbox = Inbox::find($inboxId);
        $timezone = $inbox['timezone'] ?? 'America/Sao_Paulo';
        $now = new \DateTime('now', new \DateTimeZone($timezone));
        $dayOfWeek = (int) $now->format('w');
        $currentTime = $now->format('H:i:s');

        $hours = Database::getInstance()->fetch(
            "SELECT * FROM inbox_business_hours WHERE inbox_id = ? AND day_of_week = ?",
            [$inboxId, $dayOfWeek]
        );

        if (!$hours) {
            return true; // No config = always open
        }

        if (!$hours['is_open']) {
            return false;
        }

        if ($hours['open_time'] && $hours['close_time']) {
            return $currentTime >= $hours['open_time'] && $currentTime <= $hours['close_time'];
        }

        return true;
    }

    public static function saveBatch(int $inboxId, array $hours): void
    {
        $db = Database::getInstance();
        $db->delete('inbox_business_hours', 'inbox_id = ?', [$inboxId]);

        foreach ($hours as $h) {
            $db->insert('inbox_business_hours', [
                'inbox_id' => $inboxId,
                'day_of_week' => (int) ($h['day_of_week'] ?? 0),
                'is_open' => !empty($h['is_open']) ? 1 : 0,
                'open_time' => $h['open_time'] ?? null,
                'close_time' => $h['close_time'] ?? null,
            ]);
        }
    }
}
