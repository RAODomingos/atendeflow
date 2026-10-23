<?php

namespace App\Models;

use App\Core\Database;

class CannedResponse
{
    public static function all(): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT cr.*, d.name as department_name, u.name as user_name
             FROM canned_responses cr
             LEFT JOIN departments d ON d.id = cr.department_id
             LEFT JOIN users u ON u.id = cr.user_id
             ORDER BY cr.title"
        );
    }

    public static function getForContext(?int $departmentId, ?int $userId): array
    {
        // Visibilidade:
        // - global (department_id NULL + user_id NULL): todos veem
        // - departamento: department_id = contexto → todos do departamento veem,
        //   independente de quem criou (user_id é só auditoria)
        // - pessoal: user_id = usuário atual → só o dono vê (qualquer departamento)
        // O AND antigo `(dept NULL OU =X) AND (user NULL OU =Y)` escondia mensagens
        // do departamento criadas por outro colega. Por isso aqui é OR.
        $clauses = ["(cr.department_id IS NULL AND cr.user_id IS NULL)"];
        $params = [];
        if ($departmentId) {
            $clauses[] = "(cr.department_id = ?)";
            $params[] = $departmentId;
        } elseif ($userId) {
            // Sem contexto de departamento (conversa sem dept): mostra também
            // as mensagens dos departamentos dos quais o usuário participa.
            $clauses[] = "(cr.department_id IN (SELECT department_id FROM department_users WHERE user_id = ?))";
            $params[] = $userId;
        }
        if ($userId) {
            $clauses[] = "(cr.user_id = ?)";
            $params[] = $userId;
        }
        $sql = "SELECT * FROM canned_responses cr WHERE (" . implode(" OR ", $clauses) . ") ORDER BY title";
        return Database::getInstance()->fetchAll($sql, $params);
    }

    public static function find(int $id): ?array
    {
        return Database::getInstance()->fetch("SELECT * FROM canned_responses WHERE id = ?", [$id]);
    }

    public static function create(array $data): int
    {
        return Database::getInstance()->insert('canned_responses', $data);
    }

    public static function update(int $id, array $data): int
    {
        return Database::getInstance()->update('canned_responses', $data, 'id = ?', [$id]);
    }

    public static function delete(int $id): int
    {
        return Database::getInstance()->delete('canned_responses', 'id = ?', [$id]);
    }
}
