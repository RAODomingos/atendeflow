<?php

namespace App\Services;

use App\Core\Database;
use App\Models\Setting;

/**
 * Verifica o horário de funcionamento e fornece a mensagem de ausência.
 *
 * Suporta regras gerais (department_id NULL) e por departamento. O horário é
 * avaliado no fuso configurado em `business_hours_timezone`.
 */
class BusinessHoursService
{
    public static function isEnabled(): bool
    {
        return Setting::get('business_hours_enabled', '0') === '1';
    }

    public static function timezone(): string
    {
        return trim((string) Setting::get('business_hours_timezone', 'America/Sao_Paulo')) ?: 'America/Sao_Paulo';
    }

    /**
     * Retorna true se o atendimento está aberto agora para o departamento.
     * Quando o recurso está desativado, considera-se sempre aberto.
     */
    public static function isOpen(?int $departmentId): bool
    {
        if (!self::isEnabled()) {
            return true;
        }

        try {
            $now = new \DateTime('now', new \DateTimeZone(self::timezone()));
        } catch (\Throwable $e) {
            $now = new \DateTime();
        }

        $day = (int) $now->format('w'); // 0=Dom .. 6=Sáb
        $cur = $now->format('H:i:s');

        $rule = self::ruleFor($departmentId, $day) ?? self::ruleFor(null, $day);
        if (!$rule) {
            return true; // sem regra cadastrada => aberto
        }
        if (empty($rule['is_open'])) {
            return false;
        }

        return $cur >= $rule['open_time'] && $cur <= $rule['close_time'];
    }

    private static function ruleFor(?int $departmentId, int $day): ?array
    {
        $db = Database::getInstance();
        if ($departmentId) {
            $r = $db->fetch(
                "SELECT * FROM business_hours WHERE department_id = ? AND day_of_week = ?",
                [$departmentId, $day]
            );
            if ($r) {
                return $r;
            }
        }
        return $db->fetch(
            "SELECT * FROM business_hours WHERE department_id IS NULL AND day_of_week = ?",
            [$day]
        );
    }

    /**
     * Carrega as 7 regras (0..6) de um escopo (departamento ou global).
     */
    public static function scheduleFor(?int $departmentId): array
    {
        $db = Database::getInstance();
        $rows = $departmentId
            ? $db->fetchAll("SELECT * FROM business_hours WHERE department_id = ? ORDER BY day_of_week", [$departmentId])
            : $db->fetchAll("SELECT * FROM business_hours WHERE department_id IS NULL ORDER BY day_of_week");
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['day_of_week']] = $r;
        }
        return $map;
    }

    public static function absenceMessage(): string
    {
        $msg = trim((string) Setting::get('absence_message', ''));
        return $msg ?: 'Olá! Estamos fora do nosso horário de atendimento. Retornaremos assim que possível.';
    }
}
