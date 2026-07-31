<?php

namespace App\Core;

use PDO;
use PDOException;
use Exception;

class Database
{
    private static ?Database $instance = null;
    private $connection;
    
    private function __construct()
    {
        $this->connectPDO();
    }

    private function connectPDO(): void
    {
        $host = env('DB_HOST', '127.0.0.1');
        $port = env('DB_PORT', '3306');
        $dbname = env('DB_DATABASE', 'atendeflow');
        $username = env('DB_USERNAME', 'root');
        $password = env('DB_PASSWORD', '');
        $charset = env('DB_CHARSET', 'utf8mb4');

        try {
            $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=$charset";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
            ];
            $this->connection = new PDO($dsn, $username, $password, $options);
        } catch (PDOException $e) {
            throw new Exception("Connection failed: " . $e->getMessage());
        }
    }

    private function shouldReconnect(PDOException $e): bool
    {
        $msg = $e->getMessage();
        return str_contains($msg, 'server has gone away')
            || str_contains($msg, 'MySQL server has gone away')
            || str_contains($msg, 'gone away')
            || in_array($e->getCode(), ['HY000', '08006', '08001', '2006'], true);
    }

    private function reconnect(): void
    {
        $this->connection = null;
        $this->connectPDO();
    }

    private function ensureConnection(): void
    {
        if ($this->connection instanceof PDO) {
            return;
        }
        $this->connectPDO();
    }
    
    public static function connect(): self
    {
        return self::getInstance();
    }

    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function query(string $sql, array $params = [])
    {
        $this->ensureConnection();

        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            if ($this->shouldReconnect($e)) {
                $this->reconnect();
                $stmt = $this->connection->prepare($sql);
                $stmt->execute($params);
                return $stmt;
            }
            throw $e;
        }
    }
    
    public function fetch(string $sql, array $params = []): ?array
    {
        $this->ensureConnection();

        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch();
            return $result === false ? null : $result;
        } catch (PDOException $e) {
            if ($this->shouldReconnect($e)) {
                $this->reconnect();
                $stmt = $this->connection->prepare($sql);
                $stmt->execute($params);
                $result = $stmt->fetch();
                return $result === false ? null : $result;
            }
            throw $e;
        }
    }
    
    public function fetchAll(string $sql, array $params = [])
    {
        $this->ensureConnection();

        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            if ($this->shouldReconnect($e)) {
                $this->reconnect();
                $stmt = $this->connection->prepare($sql);
                $stmt->execute($params);
                return $stmt->fetchAll();
            }
            throw $e;
        }
    }
    
    public function insert(string $table, array $data): int
    {
        $columns = implode(', ', array_map(fn($col) => "`{$col}`", array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        $this->query($sql, array_values($data));
        return (int) $this->connection->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $params = []): int
    {
        $sets = implode(', ', array_map(fn($col) => "`{$col}` = ?", array_keys($data)));
        $sql = "UPDATE {$table} SET {$sets} WHERE {$where}";
        $stmt = $this->query($sql, array_merge(array_values($data), $params));
        return $stmt->rowCount();
    }

    public function delete(string $table, string $where, array $params = []): int
    {
        $sql = "DELETE FROM {$table} WHERE {$where}";
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    public function execute(string $sql, array $params = []): void
    {
        $this->ensureConnection();

        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
        } catch (PDOException $e) {
            if ($this->shouldReconnect($e)) {
                $this->reconnect();
                $stmt = $this->connection->prepare($sql);
                $stmt->execute($params);
                return;
            }
            throw $e;
        }
    }

    public function lastInsertId()
    {
        return $this->connection->lastInsertId();
    }
    
    public function beginTransaction()
    {
        $this->connection->beginTransaction();
    }
    
    public function commit()
    {
        $this->connection->commit();
    }
    
    public function rollBack()
    {
        $this->connection->rollBack();
    }
    
    public function __destruct()
    {
        $this->connection = null;
    }
}
