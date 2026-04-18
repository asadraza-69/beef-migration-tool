<?php

namespace Nudelsalat\Database;

use PDO;

class Seeder
{
    protected PDO $pdo;
    protected string $tableName;
    protected array $data = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function run(): void
    {
        $this->seed();
    }

    protected function seed(): void
    {
        // Override in subclass
    }

    protected function table(string $table): self
    {
        $this->tableName = $table;
        return $this;
    }

    protected function insert(array $records): void
    {
        if (empty($records)) return;
        
        $columns = array_keys($records[0]);
        $placeholders = array_map(fn($col) => ":$col", $columns);
        
        $sql = sprintf(
            "INSERT INTO %s (%s) VALUES (%s)",
            $this->quoteIdentifier($this->tableName),
            implode(', ', array_map([$this, 'quoteIdentifier'], $columns)),
            implode(', ', $placeholders)
        );
        
        $stmt = $this->pdo->prepare($sql);
        
        foreach ($records as $record) {
            $stmt->execute($record);
        }
    }

    protected function create(string $table, callable $callback): void
    {
        $this->tableName = $table;
        $record = $callback();
        
        if (is_array($record)) {
            $this->insert([$record]);
        }
    }

    protected function batch(string $table, array $records): void
    {
        $this->tableName = $table;
        $this->insert($records);
    }

    protected function has(string $table, array $where): bool
    {
        $conditions = array_map(fn($col) => "$col = :$col", array_keys($where));
        
        $sql = sprintf(
            "SELECT 1 FROM %s WHERE %s LIMIT 1",
            $this->quoteIdentifier($table),
            implode(' AND ', $conditions)
        );
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($where);
        
        return (bool) $stmt->fetch();
    }

    protected function count(string $table): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM " . $this->quoteIdentifier($table));
        return (int) $stmt->fetchColumn();
    }

    protected function deleteAll(string $table): void
    {
        $this->pdo->exec("DELETE FROM " . $this->quoteIdentifier($table));
    }

    protected function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    // Static method to run seeder class
    public static function call(string $seederClass, PDO $pdo): void
    {
        $seeder = new $seederClass($pdo);
        $seeder->run();
    }
}