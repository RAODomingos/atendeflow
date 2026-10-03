<?php
// Runner de migrations compatível com MySQL 8 (sem ADD/DROP COLUMN/INDEX IF EXISTS,
// sem DELIMITER via PDO). Idempotente via tabela schema_migrations.
$host = getenv('DB_HOST') ?: '127.0.0.1';
$dbname = getenv('DB_DATABASE') ?: 'atendeflow';
$user = getenv('DB_USERNAME') ?: 'root';
$pass = getenv('DB_PASSWORD') ?: '';
$port = getenv('DB_PORT') ?: '3306';

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // NOTA: manter EMULATE_PREPARES=true (default). SHOW COLUMNS/TABLES não
    // aceita placeholder '?' em prepared statement nativo (erro 1064).

    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        name VARCHAR(255) PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    $applied = $pdo->query("SELECT name FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $appliedMap = array_fill_keys($applied, true);

    // SHOW ... LIKE não aceita placeholder em prepare nativo; interpola com
    // whitelist estrita (\w+) pois os nomes vêm do nosso próprio parser.
    $qi = function (string $ident): string {
        if (!preg_match('/^\w+$/', $ident)) throw new Exception("Identificador inválido: $ident");
        return "`" . $ident . "`";
    };
    $hasColumn = function (string $table, string $column) use ($pdo, $qi): bool {
        try {
            $rows = $pdo->query("SHOW COLUMNS FROM " . $qi($table) . " LIKE '" . str_replace("'", "''", $column) . "'")->fetchAll();
            return count($rows) > 0;
        } catch (Exception $e) {
            return false;
        }
    };
    $hasIndex = function (string $table, string $index) use ($pdo): bool {
        try {
            $rows = $pdo->query("SHOW INDEX FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                if (($r['Key_name'] ?? '') === $index) return true;
            }
            return false;
        } catch (Exception $e) {
            return false;
        }
    };
    $tableExists = function (string $table) use ($pdo, $qi): bool {
        try {
            $rows = $pdo->query("SHOW TABLES LIKE '" . str_replace("'", "''", $table) . "'")->fetchAll();
            return count($rows) > 0;
        } catch (Exception $e) {
            return false;
        }
    };

    // Divide o SQL em statements, respeitando bodies de TRIGGER (BEGIN...END)
    // e literais de string ('...', "..."). NÃO remove # inline (cores hex como '#fff').
    $splitStatements = function (string $sql): array {
        $lines = preg_split('/\R/', $sql);
        $clean = [];
        foreach ($lines as $line) {
            $t = ltrim($line);
            if (preg_match('/^DELIMITER\b/i', trim($line))) continue; // diretiva de cliente, não SQL
            if (str_starts_with($t, '--') || str_starts_with($t, '#')) continue; // comentário de linha inteira
            $clean[] = $line;
        }
        $sql = implode("\n", $clean);
        // normaliza terminador legado de trigger (DELIMITER // removido acima)
        $sql = preg_replace('/\bEND\s*\/\/+\s*;?/i', 'END;', $sql);
        $stmts = [];
        $buf = '';
        $inTrigger = false;
        $inS = false; // aspas simples
        $inD = false; // aspas duplas
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            $prev = $i > 0 ? $sql[$i - 1] : '';
            if ($ch === "'" && !$inD && $prev !== '\\') $inS = !$inS;
            elseif ($ch === '"' && !$inS && $prev !== '\\') $inD = !$inD;
            $buf .= $ch;
            if (!$inS && !$inD && preg_match('/CREATE\s+TRIGGER\b/i', $buf) && !$inTrigger) {
                $inTrigger = true;
            }
            if ($ch === ';' && !$inS && !$inD) {
                if ($inTrigger) {
                    // fim do trigger = END; (ou END// legado já sem DELIMITER)
                    if (preg_match('/\bEND\s*;?\s*\/*\s*$/i', trim($buf))) {
                        $s = trim($buf);
                        $s = rtrim($s, '/');
                        $s = trim($s);
                        if ($s !== '' && $s !== ';') $stmts[] = $s;
                        $buf = '';
                        $inTrigger = false;
                    }
                    // senão: ; interno do BEGIN...END, continua acumulando
                } else {
                    $s = trim($buf);
                    if ($s !== '' && $s !== ';') $stmts[] = $s;
                    $buf = '';
                }
            }
        }
        $rest = trim($buf);
        if ($rest !== '' && $rest !== ';') $stmts[] = $rest;
        return $stmts;
    };

    // Executa um statement traduzindo sintaxe MariaDB-only para MySQL 8.
    // Também torna ADD COLUMN/INDEX simples idempotentes (re-run seguro).
    // Erros 1060/1061/1091/1826 (já existe / não existe p/ drop) são
    // tolerados por statement para permitir re-runs parciais.
    $execCompat = null;
    $execCompat = function (string $stmt) use ($pdo, $hasColumn, $hasIndex, &$execCompat): void {
        $s = trim($stmt);
        if ($s === '') return;
        // normaliza terminador legado de trigger (DELIMITER // removido no split)
        $s = preg_replace('/\bEND\s*\/\/\s*;?\s*$/i', 'END;', $s);

        try {
        // ALTER multi-ADD com IF NOT EXISTS: quebra em ADDs individuais
        // ex: ALTER TABLE messages ADD COLUMN IF NOT EXISTS a ..., ADD COLUMN IF NOT EXISTS b ...;
        if (preg_match('/^ALTER\s+TABLE\s+`?(\w+)`?\s+(.*)$/is', $s, $m)) {
            $table = $m[1];
            $body = rtrim(trim($m[2]), ';');
            // separa por vírgula no nível superior (fora de parênteses)
            $parts = [];
            $depth = 0;
            $cur = '';
            $blen = strlen($body);
            for ($i = 0; $i < $blen; $i++) {
                $ch = $body[$i];
                if ($ch === '(') $depth++;
                if ($ch === ')') $depth--;
                if ($ch === ',' && $depth === 0) {
                    $parts[] = trim($cur);
                    $cur = '';
                } else {
                    $cur .= $ch;
                }
            }
            if (trim($cur) !== '') $parts[] = trim($cur);
            if (count($parts) > 1) {
                foreach ($parts as $p) {
                    $execCompat("ALTER TABLE `$table` $p");
                }
                return;
            }
            // ADD COLUMN IF NOT EXISTS col DEF...
            if (preg_match('/^ADD\s+(?:COLUMN\s+)?IF\s+NOT\s+EXISTS\s+`?(\w+)`?\s*(.*)$/is', $body, $c)) {
                $col = $c[1];
                $def = trim($c[2]);
                if ($hasColumn($table, $col)) return;
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
                return;
            }
            // ADD INDEX IF NOT EXISTS name (...)
            if (preg_match('/^ADD\s+(?:INDEX|KEY)\s+IF\s+NOT\s+EXISTS\s+`?(\w+)`?\s*(.*)$/is', $body, $c)) {
                $idx = $c[1];
                $def = trim($c[2]);
                if ($hasIndex($table, $idx)) return;
                $pdo->exec("ALTER TABLE `$table` ADD INDEX `$idx` $def");
                return;
            }
            // DROP COLUMN IF EXISTS col
            if (preg_match('/^DROP\s+(?:COLUMN\s+)?IF\s+EXISTS\s+`?(\w+)`?\s*$/i', $body, $c)) {
                $col = $c[1];
                if (!$hasColumn($table, $col)) return;
                $pdo->exec("ALTER TABLE `$table` DROP COLUMN `$col`");
                return;
            }
            // ADD COLUMN simples (sem IF NOT EXISTS): idempotente no re-run.
            // Lookahead negativo: não confundir ADD INDEX/KEY/FOREIGN/... com coluna.
            if (preg_match('/^ADD\s+(?:COLUMN\s+)?(?!INDEX\b|KEY\b|UNIQUE\b|FOREIGN\b|CONSTRAINT\b|PRIMARY\b|CHECK\b|FULLTEXT\b|SPATIAL\b)`?(\w+)`?\s+(.*)$/is', $body, $c)) {
                $col = $c[1];
                if ($hasColumn($table, $col)) return;
                $pdo->exec($s);
                return;
            }
            // ADD INDEX simples (sem IF NOT EXISTS): idempotente no re-run
            if (preg_match('/^ADD\s+(?:UNIQUE\s+)?(?:INDEX|KEY)\s+`?(\w+)`?\s*\(.*\)\s*$/is', $body, $c)) {
                if ($hasIndex($table, $c[1])) return;
                $pdo->exec($s);
                return;
            }
        }
        $pdo->exec($s);
        } catch (PDOException $e) {
            // Re-run seguro: objeto já existe / já removido / seed já inserido.
            // 1050 tabela existe, 1060 coluna existe, 1061 índice existe,
            // 1062 seed duplicado, 1091 não existe p/ drop, 1826 FK duplicada.
            $code = (int) ($e->errorInfo[1] ?? $e->getCode());
            if (in_array($code, [1050, 1060, 1061, 1062, 1091, 1826], true)) return;
            throw $e;
        }
    };

    $ensureColumn = function (string $table, string $column, string $definition) use ($pdo, $hasColumn): void {
        if ($hasColumn($table, $column)) {
            echo "  [=] $table.$column já existe\n";
            return;
        }
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        echo "  [+] $table.$column\n";
    };

    $files = glob(__DIR__ . '/migrations/*.sql') ?: [];
    sort($files);
    $failures = 0;

    foreach ($files as $file) {
        $name = basename($file);
        if (isset($appliedMap[$name])) {
            echo "Skipped (applied): $name\n";
            continue;
        }
        echo "Running: $name... ";
        $sql = file_get_contents($file);
        try {
            foreach ($splitStatements($sql) as $stmt) {
                $execCompat($stmt);
            }
            $pdo->prepare("INSERT INTO schema_migrations (name) VALUES (?)")->execute([$name]);
            echo "OK\n";
        } catch (Exception $e) {
            $failures++;
            echo "FAILED (" . $e->getMessage() . ")\n";
        }
    }

    // Colunas incrementais fora de migrations (idempotentes via SHOW COLUMNS)
    $ensureColumn('inboxes', 'timezone', "VARCHAR(50) NULL DEFAULT 'America/Sao_Paulo'");
    $ensureColumn('inboxes', 'away_message_enabled', "TINYINT(1) NOT NULL DEFAULT 0");
    $ensureColumn('inboxes', 'away_message', "TEXT NULL");
    $ensureColumn('inboxes', 'greeting_enabled', "TINYINT(1) NOT NULL DEFAULT 1");
    $ensureColumn('inboxes', 'greeting_message', "VARCHAR(500) NULL");
    $ensureColumn('inboxes', 'sla_enabled', "TINYINT(1) NULL DEFAULT NULL");
    $ensureColumn('inboxes', 'sla_attention_minutes', "INT NULL DEFAULT NULL");
    $ensureColumn('inboxes', 'sla_alert_minutes', "INT NULL DEFAULT NULL");
    $ensureColumn('inboxes', 'sla_color_normal', "VARCHAR(7) NULL DEFAULT NULL");
    $ensureColumn('inboxes', 'sla_color_attention', "VARCHAR(7) NULL DEFAULT NULL");
    $ensureColumn('inboxes', 'sla_color_alert', "VARCHAR(7) NULL DEFAULT NULL");
    $ensureColumn('inboxes', 'sla_color_normal_text', "VARCHAR(7) NULL DEFAULT NULL");
    $ensureColumn('inboxes', 'sla_color_attention_text', "VARCHAR(7) NULL DEFAULT NULL");
    $ensureColumn('inboxes', 'sla_color_alert_text', "VARCHAR(7) NULL DEFAULT NULL");
    $ensureColumn('inboxes', 'sla_sound_attention', "VARCHAR(20) NULL DEFAULT NULL");
    $ensureColumn('inboxes', 'sla_sound_alert', "VARCHAR(20) NULL DEFAULT NULL");
    $ensureColumn('channels', 'flow_id', "BIGINT UNSIGNED NULL");
    $ensureColumn('conversations', 'unit', "VARCHAR(255) NULL");
    $ensureColumn('conversations', 'substatus', "VARCHAR(100) NULL");

    if (!$tableExists('conversation_substatuses')) {
        $pdo->exec("CREATE TABLE conversation_substatuses (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            color VARCHAR(7) NULL DEFAULT '#6c757d',
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        echo "  [+] conversation_substatuses\n";
    }
    if (!$tableExists('conversation_subjects')) {
        $pdo->exec("CREATE TABLE conversation_subjects (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
        echo "  [+] conversation_subjects\n";
    }

    echo $failures === 0 ? "Migration complete.\n" : "Migration complete with $failures failure(s).\n";
    exit($failures === 0 ? 0 : 1);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
