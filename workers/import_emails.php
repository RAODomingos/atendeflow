<?php
/**
 * Email import worker (IMAP)
 * Run: php workers/import_emails.php
 */

require_once __DIR__ . '/../app/Core/Autoloader.php';
require_once __DIR__ . '/../app/Core/Helper.php';

use App\Core\Database;

Database::connect();

echo "[Worker] Verificando e-mails...\n";

$accounts = Database::getInstance()->fetchAll(
    "SELECT * FROM email_accounts WHERE is_active = 1"
);

foreach ($accounts as $account) {
    echo "  Lendo {$account['email']}...\n";

    try {
        $mailbox = imap_open(
            "{{$account['imap_host']}:{$account['imap_port']}/imap/{$account['imap_encryption']}}INBOX",
            $account['imap_username'],
            $account['imap_password_encrypted'] // In production, decrypt this
        );

        if ($mailbox) {
            $emails = imap_search($mailbox, 'UNSEEN');

            if ($emails) {
                echo "  " . count($emails) . " novos e-mails encontrados.\n";

                foreach ($emails as $emailId) {
                    $header = imap_headerinfo($mailbox, $emailId);
                    $body = imap_body($mailbox, $emailId);

                    $from = $header->from[0]->mailbox . '@' . $header->from[0]->host;
                    $subject = $header->subject ?? '(sem assunto)';

                    echo "    De: {$from} - {$subject}\n";

                    // TODO: Create conversation and message from email
                }
            }

            imap_close($mailbox);
        }
    } catch (\Exception $e) {
        echo "  Erro: {$e->getMessage()}\n";
    }
}
