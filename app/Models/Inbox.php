<?php

namespace App\Models;

use App\Core\Database;
use App\Core\Auth;

class Inbox
{
    public static function all(): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT * FROM inboxes WHERE is_active = 1 ORDER BY type, name"
        );
    }

    public static function find(int $id): ?array
    {
        return Database::getInstance()->fetch("SELECT * FROM inboxes WHERE id = ?", [$id]);
    }

    public static function create(array $data): int
    {
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        return Database::getInstance()->insert('inboxes', $data);
    }

    public static function update(int $id, array $data): int
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        return Database::getInstance()->update('inboxes', $data, 'id = ?', [$id]);
    }

    public static function delete(int $id): int
    {
        return Database::getInstance()->delete('inboxes', 'id = ?', [$id]);
    }

    public static function getChannels(int $inboxId): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT c.* FROM channels c
             JOIN inbox_channels ic ON ic.channel_id = c.id
             WHERE ic.inbox_id = ? ORDER BY c.name",
            [$inboxId]
        );
    }

    public static function setChannels(int $inboxId, array $channelIds): void
    {
        $db = Database::getInstance();
        $db->delete('inbox_channels', 'inbox_id = ?', [$inboxId]);
        foreach (array_unique(array_filter($channelIds)) as $cid) {
            $db->insert('inbox_channels', ['inbox_id' => $inboxId, 'channel_id' => (int) $cid]);
        }
    }

    public static function getDepartments(int $inboxId): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT d.* FROM departments d
             JOIN inbox_departments idp ON idp.department_id = d.id
             WHERE idp.inbox_id = ? ORDER BY d.name",
            [$inboxId]
        );
    }

    public static function setDepartments(int $inboxId, array $deptIds): void
    {
        $db = Database::getInstance();
        $db->delete('inbox_departments', 'inbox_id = ?', [$inboxId]);
        foreach (array_unique(array_filter($deptIds)) as $did) {
            $db->insert('inbox_departments', ['inbox_id' => $inboxId, 'department_id' => (int) $did]);
        }
    }

    public static function getUsers(int $inboxId): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT DISTINCT u.* FROM users u
             LEFT JOIN inbox_users iu ON iu.user_id = u.id AND iu.inbox_id = ?
             LEFT JOIN department_users du ON du.user_id = u.id
             LEFT JOIN inbox_departments idp ON idp.department_id = du.department_id AND idp.inbox_id = ?
             WHERE (iu.id IS NOT NULL OR idp.id IS NOT NULL OR u.role = 'admin')
               AND u.is_active = 1
             ORDER BY u.name",
            [$inboxId, $inboxId]
        );
    }

    public static function setUsers(int $inboxId, array $userIds): void
    {
        $db = Database::getInstance();
        $db->delete('inbox_users', 'inbox_id = ?', [$inboxId]);
        foreach (array_unique(array_filter($userIds)) as $uid) {
            $db->insert('inbox_users', ['inbox_id' => $inboxId, 'user_id' => (int) $uid]);
        }
    }

    /**
     * Donos explícitos da caixa (linhas de inbox_users).
     *
     * getUsers() devolve o time expandido (donos + membros dos departamentos
     * vinculados + admins). Para definir a pertinência de uma caixa pessoal
     * vale apenas quem foi declarado dono.
     */
    public static function getExplicitUsers(int $inboxId): array
    {
        return array_map('intval', array_column(
            Database::getInstance()->fetchAll(
                "SELECT user_id FROM inbox_users WHERE inbox_id = ?",
                [$inboxId]
            ),
            'user_id'
        ));
    }

    /**
     * Escopo de conversa aceito por uma caixa.
     *
     * A natureza da conversa é o eixo que separa a caixa de grupos da caixa
     * de mensagens gerais: as duas cobrem os mesmos setores, mas uma quer
     * as conversas de grupo do WhatsApp e a outra as mensagens avulsas.
     */
    public const SCOPES = ['all', 'groups', 'non_groups'];

    public static function normalizeScope(?string $scope): string
    {
        $scope = strtolower(trim((string) $scope));
        return in_array($scope, self::SCOPES, true) ? $scope : 'all';
    }

    /**
     * Baldes de pertinência vazios.
     *
     * Os três baldes são a natureza da conversa:
     * - any:       conversas de qualquer natureza (caixa sem restrição)
     * - groups:    somente conversas de grupo do WhatsApp
     * - nonGroups: somente mensagens gerais (fora de grupo)
     *
     * 'all' é o atalho de "sem restrição nenhuma" (admin).
     *
     * @return array{all:bool, any:array, groups:array, nonGroups:array}
     */
    public static function emptySets(): array
    {
        $bucket = static fn (): array => [
            'departments' => [],
            'channels'    => [],
            'users'       => [],
            'groups'      => [],
        ];
        return [
            'all'       => false,
            'any'       => $bucket(),
            'groups'    => $bucket(),
            'nonGroups' => $bucket(),
        ];
    }

    /**
     * Mapa de pertinência das caixas informadas.
     *
     * A caixa é uma visão sobre as conversas, não um container: uma conversa
     * pertence à caixa pelo departamento, pelo canal, pelo responsável ou pelo
     * grupo de WhatsApp ligado a ela — nunca por um ponteiro guardado na
     * própria conversa. Por isso o mapa é derivado das caixas (e não das
     * conversas).
     *
     * - departamental: cobre os departamentos vinculados e os canais vinculados
     * - pessoal:      cobre as conversas atribuídas aos donos e os canais
     *                 vinculados (a linha que o atendende trata sozinho)
     *
     * O scope (natureza) decide em que balde a caixa entra: 'all' (padrão),
     * 'groups' (só grupo) ou 'non_groups' (só mensagem geral).
     *
     * @return array<int, array{type:string, scope:string, departments:int[], channels:int[], users:int[], groups:int[]}>
     */
    public static function loadMembershipMap(array $inboxIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $inboxIds))));
        if (empty($ids)) {
            return [];
        }
        $db = Database::getInstance();
        $ph = rtrim(str_repeat('?,', count($ids)), ',');

        $map = [];
        $hasScope = self::hasScopeColumn();
        $sql = $hasScope
            ? "SELECT id, type, conversation_scope FROM inboxes WHERE id IN ({$ph})"
            : "SELECT id, type FROM inboxes WHERE id IN ({$ph})";
        foreach ($db->fetchAll($sql, $ids) as $row) {
            $map[(int) $row['id']] = [
                'type'        => (string) ($row['type'] ?? 'department'),
                'scope'       => self::normalizeScope($row['conversation_scope'] ?? 'all'),
                'departments' => [],
                'channels'    => [],
                'users'       => [],
                'groups'      => [],
            ];
        }

        foreach ($db->fetchAll("SELECT inbox_id, department_id FROM inbox_departments WHERE inbox_id IN ({$ph})", $ids) as $row) {
            $iid = (int) $row['inbox_id'];
            if (isset($map[$iid])) {
                $map[$iid]['departments'][] = (int) $row['department_id'];
            }
        }
        foreach ($db->fetchAll("SELECT inbox_id, channel_id FROM inbox_channels WHERE inbox_id IN ({$ph})", $ids) as $row) {
            $iid = (int) $row['inbox_id'];
            if (isset($map[$iid])) {
                $map[$iid]['channels'][] = (int) $row['channel_id'];
            }
        }
        foreach ($db->fetchAll("SELECT inbox_id, user_id FROM inbox_users WHERE inbox_id IN ({$ph})", $ids) as $row) {
            $iid = (int) $row['inbox_id'];
            if (isset($map[$iid])) {
                $map[$iid]['users'][] = (int) $row['user_id'];
            }
        }
        // Grupos de WhatsApp ligados explicitamente a cada caixa: é o vínculo
        // que o gestor escolhe na tela de grupos ("conversas deste grupo
        // entram nesta caixa").
        try {
            foreach ($db->fetchAll("SELECT id, inbox_id FROM whatsapp_groups WHERE inbox_id IN ({$ph})", $ids) as $row) {
                $iid = (int) $row['inbox_id'];
                if (isset($map[$iid])) {
                    $map[$iid]['groups'][] = (int) $row['id'];
                }
            }
        } catch (\Throwable $e) {
            // Base sem a tabela de grupos: a caixa continua valendo pelos
            // outros eixos.
        }

        return $map;
    }

    /**
     * Conjuntos de pertinência de um conjunto de caixas, já achatados.
     *
     * "Achatar" é o que permite listar várias caixas numa única query: em vez
     * de um OR por caixa, vira um IN por eixo (departamento / canal / dono /
     * grupo). As caixas caem em três baldes conforme a natureza das
     * conversas que elas querem (all / groups / non_groups), para que o
     * predicado continue sendo uma query só.
     *
     * @return array{all:bool, any:array, groups:array, nonGroups:array}
     */
    public static function setsFromMap(array $map, array $inboxIds): array
    {
        $sets = self::emptySets();
        foreach ($inboxIds as $id) {
            $entry = $map[(int) $id] ?? null;
            if (!$entry) {
                continue;
            }
            $isPersonal = ($entry['type'] ?? 'department') === 'personal';
            // Caixa pessoal é sempre "qualquer conversa atribuída aos donos":
            // a natureza não muda o que é uma caixa pessoal.
            $bucket = 'any';
            if (!$isPersonal) {
                $bucket = match (self::normalizeScope($entry['scope'] ?? 'all')) {
                    'groups'     => 'groups',
                    'non_groups' => 'nonGroups',
                    default      => 'any',
                };
            }
            if ($isPersonal) {
                // Caixa pessoal: pertence a ela a conversa atribuída a um
                // dos donos declarados.
                $sets[$bucket]['users'] = array_merge($sets[$bucket]['users'], $entry['users']);
            } else {
                $sets[$bucket]['departments'] = array_merge($sets[$bucket]['departments'], $entry['departments']);
            }
            $sets[$bucket]['channels'] = array_merge($sets[$bucket]['channels'], $entry['channels']);
            $sets[$bucket]['groups'] = array_merge($sets[$bucket]['groups'], $entry['groups']);
        }
        foreach ($sets as $bucket => $axes) {
            if (!is_array($axes)) {
                continue;
            }
            foreach ($axes as $axis => $values) {
                $sets[$bucket][$axis] = array_values(array_unique($values));
            }
        }
        return $sets;
    }

    /**
     * Conjuntos de pertinência das caixas informadas (atalho).
     *
     * @return array{all:bool, any:array, groups:array, nonGroups:array}
     */
    public static function membershipSets(array $inboxIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $inboxIds))));
        if (empty($ids)) {
            return self::emptySets();
        }
        return self::setsFromMap(self::loadMembershipMap($ids), $ids);
    }

    /**
     * A base já tem a coluna inboxes.conversation_scope?
     * Bases antigas não têm — aí toda caixa vale para qualquer natureza.
     */
    private static ?bool $scopeColCache = null;

    public static function hasScopeColumn(): bool
    {
        if (self::$scopeColCache !== null) {
            return self::$scopeColCache;
        }
        try {
            $row = Database::getInstance()->fetch("SHOW COLUMNS FROM inboxes LIKE 'conversation_scope'");
            self::$scopeColCache = !empty($row);
        } catch (\Throwable $e) {
            self::$scopeColCache = false;
        }
        return self::$scopeColCache;
    }

    /**
     * Resolve o perfil de SLA (a caixa que dá as regras de atendimento) de uma
     * conversa que chega por um canal.
     *
     * O perfil é derivado do departamento da conversa — o canal não decide
     * mais para onde a conversa é roteada, apenas de qual time ela vem.
     * Quando a conversa não tem departamento, cai na caixa departamental
     * vinculada ao canal.
     */
    public static function resolveProfileFor(?int $channelId, ?int $departmentId = null): ?int
    {
        if ($departmentId) {
            $byDept = self::resolveProfileForDepartment($departmentId);
            if ($byDept) {
                return $byDept;
            }
        }
        if (!$channelId) {
            return null;
        }
        $row = Database::getInstance()->fetch(
            "SELECT ic.inbox_id FROM inbox_channels ic
             JOIN inboxes i ON i.id = ic.inbox_id
             WHERE ic.channel_id = ? AND i.is_active = 1 AND i.type <> 'personal'
             ORDER BY i.id ASC
             LIMIT 1",
            [$channelId]
        );
        return $row ? (int) $row['inbox_id'] : null;
    }

    /**
     * Caixa departamental que dá o perfil de SLA de um departamento.
     * Caixas pessoais nunca são escolhidas: perfil é regra de equipe.
     */
    public static function resolveProfileForDepartment(int $departmentId): ?int
    {
        $row = Database::getInstance()->fetch(
            "SELECT i.id FROM inboxes i
              JOIN inbox_departments idp ON idp.inbox_id = i.id AND idp.department_id = ?
              WHERE i.is_active = 1 AND i.type <> 'personal'
              ORDER BY i.id ASC
              LIMIT 1",
            [$departmentId]
        );
        return $row ? (int) $row['id'] : null;
    }

    /**
     * Caixas que o usuário pode ver.
     * Admin vê todas; demais veem departamento (membro) + pessoal (dono).
     */
    public static function getUserInboxes(int $userId): array
    {
        if (Auth::isAdmin()) {
            return self::all();
        }
        return Database::getInstance()->fetchAll(
            "SELECT DISTINCT i.* FROM inboxes i
             LEFT JOIN inbox_departments idp ON idp.inbox_id = i.id
             LEFT JOIN department_users du ON du.department_id = idp.department_id AND du.user_id = ?
             LEFT JOIN inbox_users iu ON iu.inbox_id = i.id AND iu.user_id = ?
             WHERE i.is_active = 1
               AND (du.id IS NOT NULL OR iu.id IS NOT NULL)
             ORDER BY i.type, i.name",
            [$userId, $userId]
        );
    }

    public static function canAccess(int $inboxId, int $userId): bool
    {
        if (Auth::isAdmin()) {
            return true;
        }
        $row = Database::getInstance()->fetch(
            "SELECT 1 FROM inboxes i
             LEFT JOIN inbox_departments idp ON idp.inbox_id = i.id
             LEFT JOIN department_users du ON du.department_id = idp.department_id AND du.user_id = ?
             LEFT JOIN inbox_users iu ON iu.inbox_id = i.id AND iu.user_id = ?
             WHERE i.id = ? AND (du.id IS NOT NULL OR iu.id IS NOT NULL)
             LIMIT 1",
            [$userId, $userId, $inboxId]
        );
        return (bool) $row;
    }

    /**
     * Caixas pessoais criadas pelo usuário (onde ele é o dono),
     * independente de ser admin. Usado em "Minha Caixa".
     */
    public static function getOwnedPersonalInboxes(int $userId): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT i.* FROM inboxes i
             JOIN inbox_users iu ON iu.inbox_id = i.id AND iu.user_id = ?
             WHERE i.type = 'personal' AND i.is_active = 1
             ORDER BY i.name",
            [$userId]
        );
    }
}
