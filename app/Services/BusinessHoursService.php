<?php

namespace App\Services;

use App\Core\Database;

/**
 * Horário de funcionamento 100% por departamento.
 *
 * Cada departamento tem sua grade em `business_hours` (department_id = id)
 * e seu config em `departments` (business_hours_enabled, business_hours_timezone,
 * absence_message). Não há mais fallback global: conversa sem departamento
 * ou departamento sem regra é considerada em horário (aberta).
 */
class BusinessHoursService
{
    public const DEFAULT_MESSAGE = 'Olá! Estamos fora do nosso horário de atendimento. Retornaremos assim que possível.';

    private static function deptConfig(?int $departmentId): ?array
    {
        if (!$departmentId) {
            return null;
        }
        return Database::getInstance()->fetch(
            "SELECT business_hours_enabled, business_hours_timezone, absence_message
             FROM departments WHERE id = ?",
            [$departmentId]
        ) ?: null;
    }

    public static function isEnabled(?int $departmentId = null): bool
    {
        $cfg = self::deptConfig($departmentId);
        if (!$cfg) {
            return false;
        }
        return (int) ($cfg['business_hours_enabled'] ?? 0) === 1;
    }

    public static function timezone(?int $departmentId = null): string
    {
        $cfg = self::deptConfig($departmentId);
        $tz = trim((string) ($cfg['business_hours_timezone'] ?? ''));
        return $tz ?: 'America/Sao_Paulo';
    }

    /**
     * Retorna true se o atendimento está aberto agora para o departamento.
     * Sem departamento, recurso desativado ou sem regra => sempre aberto.
     */
    public static function isOpen(?int $departmentId): bool
    {
        if (!self::isEnabled($departmentId)) {
            return true;
        }

        try {
            $now = new \DateTime('now', new \DateTimeZone(self::timezone($departmentId)));
        } catch (\Throwable $e) {
            $now = new \DateTime();
        }

        $day = (int) $now->format('w'); // 0=Dom .. 6=Sáb
        $cur = $now->format('H:i:s');

        $rule = self::ruleFor($departmentId, $day);
        if (!$rule) {
            return true; // sem regra cadastrada => aberto
        }
        if (empty($rule['is_open'])) {
            return false;
        }

        $open = substr((string) ($rule['open_time'] ?? '00:00:00'), 0, 8);
        $close = substr((string) ($rule['close_time'] ?? '23:59:59'), 0, 8);
        return $cur >= $open && $cur <= $close;
    }

    private static function ruleFor(?int $departmentId, int $day): ?array
    {
        if (!$departmentId) {
            return null;
        }
        return Database::getInstance()->fetch(
            "SELECT * FROM business_hours WHERE department_id = ? AND day_of_week = ?",
            [$departmentId, $day]
        ) ?: null;
    }

    /**
     * Carrega as 7 regras (0..6) do departamento.
     */
    public static function scheduleFor(?int $departmentId): array
    {
        if (!$departmentId) {
            return [];
        }
        $db = Database::getInstance();
        $rows = $db->fetchAll(
            "SELECT * FROM business_hours WHERE department_id = ? ORDER BY day_of_week",
            [$departmentId]
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['day_of_week']] = $r;
        }
        return $map;
    }

    /**
     * Grava a grade semanal do departamento (substitui os 7 dias).
     * @param array $days [0..6 => ['is_open'=>bool,'open_time'=>'HH:MM','close_time'=>'HH:MM']]
     */
    public static function saveSchedule(int $departmentId, array $days): void
    {
        $db = Database::getInstance();
        $db->delete('business_hours', 'department_id = ?', [$departmentId]);
        foreach (range(0, 6) as $d) {
            $row = $days[$d] ?? [];
            $open = trim((string) ($row['open_time'] ?? '08:00'));
            $close = trim((string) ($row['close_time'] ?? '18:00'));
            $db->insert('business_hours', [
                'department_id' => $departmentId,
                'day_of_week' => $d,
                'open_time' => strlen($open) === 5 ? $open . ':00' : ($open ?: '08:00:00'),
                'close_time' => strlen($close) === 5 ? $close . ':00' : ($close ?: '18:00:00'),
                'is_open' => !empty($row['is_open']) ? 1 : 0,
            ]);
        }
    }

    public static function absenceMessage(?int $departmentId = null): string
    {
        $cfg = self::deptConfig($departmentId);
        $msg = trim((string) ($cfg['absence_message'] ?? ''));
        return $msg ?: self::DEFAULT_MESSAGE;
    }
}
