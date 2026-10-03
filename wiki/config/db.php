<?php
// ============================================================
//  config/db.php — Conexão com o banco de dados (PDO)
//  Edite as constantes abaixo com seus dados de acesso.
// ============================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'wiki');
define('DB_USER', 'root');       // ← altere para seu usuário
define('DB_PASS', '');           // ← altere para sua senha
define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['error' => 'Erro de conexão com o banco de dados.']));
        }
    }
    return $pdo;
}
