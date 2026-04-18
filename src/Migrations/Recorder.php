<?php

namespace Nudelsalat\Migrations;

class Recorder
{
    private \PDO $pdo;
    private string $table = 'nudelsalat_migrations';

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function ensureSchema(): void
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        $sql = match ($driver) {
            'sqlite' => "CREATE TABLE IF NOT EXISTS {$this->quoteIdentifier($this->table)} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration VARCHAR(255) NOT NULL,
                checksum VARCHAR(64) DEFAULT NULL,
                applied_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            'pgsql' => "CREATE TABLE IF NOT EXISTS {$this->quoteIdentifier($this->table)} (
                id BIGSERIAL PRIMARY KEY,
                migration VARCHAR(255) NOT NULL,
                checksum VARCHAR(64) DEFAULT NULL,
                applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            default => "CREATE TABLE IF NOT EXISTS {$this->quoteIdentifier($this->table)} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL,
                checksum VARCHAR(64) DEFAULT NULL,
                applied_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        };
        
        $this->pdo->exec($sql);

        // Migration logic for existing tables: check if 'checksum' column exists
        try {
            $this->pdo->query("SELECT checksum FROM {$this->quoteIdentifier($this->table)} LIMIT 1");
        } catch (\Exception $e) {
            $alter = match ($driver) {
                'mysql' => "ALTER TABLE {$this->quoteIdentifier($this->table)} ADD COLUMN checksum VARCHAR(64) DEFAULT NULL AFTER migration",
                default => "ALTER TABLE {$this->quoteIdentifier($this->table)} ADD COLUMN checksum VARCHAR(64) DEFAULT NULL",
            };
            $this->pdo->exec($alter);
        }
    }

    public function recordApplied(string $migrationName, ?string $checksum = null): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->quoteIdentifier($this->table)} (migration, checksum) VALUES (:name, :checksum)"
        );
        $stmt->execute(['name' => $migrationName, 'checksum' => $checksum]);
    }

    public function recordUnapplied(string $migrationName): void
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM {$this->quoteIdentifier($this->table)} WHERE migration = :name"
        );
        $stmt->execute(['name' => $migrationName]);
    }

    public function recordFakeMigration(string $migrationName): void
    {
        $this->ensureSchema();
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->quoteIdentifier($this->table)} (migration, checksum) VALUES (:name, :checksum)"
        );
        // Use empty checksum for fake migrations (they won't be checked)
        $stmt->execute(['name' => $migrationName, 'checksum' => '']);
    }

    /** @return array Map of [migrationName => checksum] */
    public function getAppliedChecksums(): array
    {
        $this->ensureSchema();
        $stmt = $this->pdo->query("SELECT migration, checksum FROM {$this->quoteIdentifier($this->table)}");
        return $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    /** @return string[] List of applied migration names */
    public function getAppliedMigrations(): array
    {
        return array_keys($this->getAppliedChecksums());
    }

    private function quoteIdentifier(string $name): string
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        return match ($driver) {
            'pgsql' => '"' . str_replace('"', '""', $name) . '"',
            default => '`' . str_replace('`', '``', $name) . '`',
        };
    }
}
