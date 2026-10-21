<?php
/**
 * Teste end-to-end das notificações contra o MySQL real.
 *
 *   php tests/e2e_notifications.php
 *
 * Verifica o dedup de "nova mensagem" (coluna message_id indexada),
 * cria dados temporários e limpa tudo no fim.
 */
$loader = require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/autoload.php';
require_once __DIR__ . '/../app/Core/Helper.php';

$appConfig = require __DIR__ . '/../config/app.php';
date_default_timezone_set($appConfig['timezone'] ?? 'America/Sao_Paulo');

\App\Core\Database::connect();
$db = \App\Core\Database::getInstance();

$fail = 0;
function check(string $name, bool $cond, string $detail = ''): void
{
    global $fail;
    if ($cond) {
        echo "ok - $name" . ($detail !== '' ? " ($detail)" : '') . PHP_EOL;
    } else {
        $fail++;
        echo "FALHOU - $name" . ($detail !== '' ? " ($detail)" : '') . PHP_EOL;
    }
}

$convId = null;
$contactId = null;
$userId = null;

try {
    $userId = $db->insert('users', [
        'name' => 'TESTE NOTIF E2E',
        'email' => 'teste-notif-e2e@atendeflow.local',
        'password' => password_hash('Teste@12345', PASSWORD_BCRYPT),
        'role' => 'agent',
        'is_active' => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    $contactId = $db->insert('contacts', [
        'name' => 'TESTE NOTIF E2E',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $channelId = (int) $db->fetch("SELECT id FROM channels WHERE type = 'webchat' ORDER BY id LIMIT 1")['id'];
    $convId = $db->insert('conversations', [
        'public_id' => bin2hex(random_bytes(16)),
        'contact_id' => $contactId,
        'channel_id' => $channelId,
        'assigned_user_id' => $userId,
        'status' => 'open',
        'source' => 'webchat',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    // ── dedup: mesma mensagem duas vezes => 1 notificação ────────
    \App\Services\NotificationService::notifyNewMessage($convId, 4242, 'primeira');
    \App\Services\NotificationService::notifyNewMessage($convId, 4242, 'primeira de novo');

    $row = $db->fetch(
        "SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND conversation_id = ?",
        [$userId, $convId]
    );
    check('a mesma mensagem nao duplica notificacao', (int) $row['c'] === 1, 'linhas=' . $row['c']);

    $row = $db->fetch(
        "SELECT message_id FROM notifications WHERE user_id = ? AND conversation_id = ?",
        [$userId, $convId]
    );
    check('a coluna message_id foi preenchida', (int) ($row['message_id'] ?? 0) === 4242, 'message_id=' . var_export($row['message_id'] ?? null, true));

    // ── mensagem diferente => nova notificação ───────────────────
    \App\Services\NotificationService::notifyNewMessage($convId, 4343, 'segunda');
    $row = $db->fetch(
        "SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND conversation_id = ?",
        [$userId, $convId]
    );
    check('mensagem diferente gera nova notificacao', (int) $row['c'] === 2, 'linhas=' . $row['c']);

    // ── o indice do dedup existe de fato ─────────────────────────
    $idx = $db->fetchAll("SHOW INDEX FROM notifications WHERE Key_name = 'idx_notif_msg'");
    $cols = array_column($idx, 'Column_name');
    check('o indice idx_notif_msg cobre user_id, conversation_id e message_id', $cols === ['user_id', 'conversation_id', 'message_id'], implode(',', $cols));

    // ── o dropdown do sino continua funcionando ──────────────────
    $list = \App\Models\Notification::getForDropdown($userId, 10);
    check('o dropdown lista as notificacoes', count($list) === 2, 'itens=' . count($list));
    check('o dropdown nao expoe colunas que nao usa', !array_key_exists('message_id', $list[0]));
} catch (Throwable $e) {
    $fail++;
    echo 'ERRO: ' . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
} finally {
    if ($convId) {
        $db->execute("DELETE FROM notifications WHERE conversation_id = ?", [$convId]);
        $db->execute("DELETE FROM conversations WHERE id = ?", [$convId]);
    }
    if ($contactId) {
        $db->execute("DELETE FROM contacts WHERE id = ?", [$contactId]);
    }
    if ($userId) {
        $db->execute("DELETE FROM users WHERE id = ?", [$userId]);
    }
    echo 'cleanup ok' . PHP_EOL;
}

exit($fail > 0 ? 1 : 0);
