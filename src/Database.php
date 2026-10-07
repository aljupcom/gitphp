<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

final class Database
{
    private ?PDO $pdo = null;

    /** @var array<string, mixed> */
    private array $config;

    /** @param array<string, mixed> $config */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function connection(): PDO
    {
        if ($this->pdo !== null) return $this->pdo;

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->config['host']     ?? '127.0.0.1',
            $this->config['port']     ?? 3306,
            $this->config['database'] ?? 'gitphp',
            $this->config['charset']  ?? 'utf8mb4',
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => true,
        ];

        if (! empty($this->config['persistent'])) $options[PDO::ATTR_PERSISTENT] = true;

        try {
            $this->pdo = new PDO(
                $dsn,
                $this->config['username'] ?? 'root',
                $this->config['password'] ?? '',
                $options,
            );
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }

        return $this->pdo;
    }

    /**
     * Execute a raw SQL query with optional bindings and return the PDOStatement.
     * @param array<int|string, mixed> $bindings
     */
    public function query(string $sql, array $bindings = []): PDOStatement
    {
        $stmt = $this->connection()->prepare($sql);
        $stmt->execute($bindings);
        return $stmt;
    }

    /**
     * Alias for query() — executes a statement (INSERT, UPDATE, DELETE, etc.).
     * @param array<int|string, mixed> $bindings
     */
    public function execute(string $sql, array $bindings = []): PDOStatement
    {
        return $this->query($sql, $bindings);
    }

    /**
     * Fetch all rows from a query.
     * @param array<int|string, mixed> $bindings
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string $sql, array $bindings = []): array
    {
        return $this->query($sql, $bindings)->fetchAll();
    }

    /**
     * Fetch a single row from a query.
     * @param array<int|string, mixed> $bindings
     * @return array<string, mixed>|false
     */
    public function fetchOne(string $sql, array $bindings = []): array|false
    {
        return $this->query($sql, $bindings)->fetch();
    }

    /**
     * Fetch a single scalar value from the first column of the first row.
     * @param array<int|string, mixed> $bindings
     */
    public function fetchValue(string $sql, array $bindings = []): mixed
    {
        return $this->query($sql, $bindings)->fetchColumn();
    }

    /**
     * Fetch a specific column from the first row.
     * @param array<int|string, mixed> $bindings
     */
    public function fetchColumn(string $sql, array $bindings = [], int $column = 0): mixed
    {
        return $this->query($sql, $bindings)->fetchColumn($column);
    }

    /** Get the last inserted ID. */
    public function lastInsertId(): string
    {
        return $this->connection()->lastInsertId();
    }

    /** Begin a transaction. */
    public function beginTransaction(): bool
    {
        return $this->connection()->beginTransaction();
    }

    /** Commit a transaction. */
    public function commit(): bool
    {
        return $this->connection()->commit();
    }

    /** Roll back a transaction. */
    public function rollBack(): bool
    {
        return $this->connection()->rollBack();
    }
}
