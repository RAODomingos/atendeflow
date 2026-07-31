<?php
$host = '127.0.0.1';
$dbname = 'atendeflow';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $files = glob(__DIR__ . '/migrations/*.sql');
    sort($files);

    foreach ($files as $file) {
        $name = basename($file);
        echo "Running: $name... ";
        $sql = file_get_contents($file);
        try {
            $pdo->exec($sql);
            echo "OK\n";
        } catch (Exception $e) {
            echo "SKIPPED (" . $e->getMessage() . ")\n";
        }
    }

    // Apply inbox columns
    $pdo->exec("ALTER TABLE inboxes ADD COLUMN IF NOT EXISTS timezone VARCHAR(50) NULL DEFAULT 'America/Sao_Paulo' AFTER is_active");
    $pdo->exec("ALTER TABLE inboxes ADD COLUMN IF NOT EXISTS away_message_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER timezone");
    $pdo->exec("ALTER TABLE inboxes ADD COLUMN IF NOT EXISTS away_message TEXT NULL AFTER away_message_enabled");
    $pdo->exec("ALTER TABLE inboxes ADD COLUMN IF NOT EXISTS greeting_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER away_message");
    $pdo->exec("ALTER TABLE inboxes ADD COLUMN IF NOT EXISTS greeting_message VARCHAR(500) NULL AFTER greeting_enabled");
    $pdo->exec("ALTER TABLE channels ADD COLUMN IF NOT EXISTS flow_id BIGINT UNSIGNED NULL AFTER department_id");

    // Conversation unit, substatus + substatuses table
    $pdo->exec("ALTER TABLE conversations ADD COLUMN IF NOT EXISTS unit VARCHAR(255) NULL AFTER subject");
    $pdo->exec("ALTER TABLE conversations ADD COLUMN IF NOT EXISTS substatus VARCHAR(100) NULL AFTER status");
    $pdo->exec("CREATE TABLE IF NOT EXISTS conversation_substatuses (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        color VARCHAR(7) NULL DEFAULT '#6c757d',
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS conversation_subjects (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    echo "Migration complete.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
