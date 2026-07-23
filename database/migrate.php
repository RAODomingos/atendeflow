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

    echo "Migration complete.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
