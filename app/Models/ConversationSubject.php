<?php

namespace App\Models;

use App\Core\Database;

/**
 * Assuntos de conversa, opcionalmente por departamento
 * (department_id NULL = todos os departamentos, igual respostas prontas).
 */
class ConversationSubject
{
    public static function all(): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT s.*, d.name as department_name FROM conversation_subjects s
             LEFT JOIN departments d ON d.id = s.department_id
             ORDER BY s.sort_order ASC, s.name ASC"
        );
    }

    public static function find(int $id): ?array
    {
        return Database::getInstance()->fetch(
            "SELECT * FROM conversation_subjects WHERE id = ?",
            [$id]
        );
    }

    /**
     * Assuntos ativos visíveis para um departamento: os dele + os "Todos".
     * Sem departamento (null/0): todos os ativos.
     */
    public static function forDepartment(?int $departmentId): array
    {
        if (empty($departmentId)) {
            return Database::getInstance()->fetchAll(
                "SELECT * FROM conversation_subjects WHERE is_active = 1 ORDER BY sort_order ASC, name ASC"
            );
        }
        return Database::getInstance()->fetchAll(
            "SELECT * FROM conversation_subjects
             WHERE is_active = 1 AND (department_id IS NULL OR department_id = ?)
             ORDER BY sort_order ASC, name ASC",
            [$departmentId]
        );
    }
}
