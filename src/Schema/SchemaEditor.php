<?php

namespace Nudelsalat\Schema;

abstract class SchemaEditor
{
    protected \PDO $pdo;
    /** @var string[] */
    public array $deferredSql = [];
    private bool $dryRun = false;
    /** @var callable|null */
    private $sqlLogger = null;

    public function supportsTransactions(): bool
    {
        return true;
    }

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    abstract public function createTable(Table $table): void;
    abstract public function deleteTable(string $name): void;
    abstract public function renameTable(string $oldName, string $newName): void;
    abstract public function addColumn(string $tableName, Column $column): void;
    abstract public function removeColumn(string $tableName, string $columnName): void;
    abstract public function renameColumn(string $tableName, string $oldName, Column $newColumn): void;
    abstract public function alterColumn(string $tableName, Column $column): void;

    abstract public function addIndex(string $tableName, Index $index): void;
    abstract public function removeIndex(string $tableName, string $indexName): void;

    abstract public function addConstraint(string $tableName, Constraint $constraint): void;
    abstract public function removeConstraint(string $tableName, string $constraintName): void;

    abstract public function addForeignKey(string $tableName, string $columnName, string $toTable, string $toColumn, string $onDelete): void;
    abstract public function removeForeignKey(string $tableName, string $constraintName): void;

    /**
     * Rename an index on a table.
     *
     * @param string $tableName The name of the table
     * @param string $oldName The old index name
     * @param string $newName The new index name
     */
    abstract public function renameIndex(string $tableName, string $oldName, string $newName): void;

    /**
     * Alter unique_together constraints on a table.
     *
     * @param string $tableName The name of the table
     * @param array $oldUnique Array of field names for the old unique constraint (empty to remove)
     * @param array $newUnique Array of field names for the new unique constraint (empty to add)
     */
    abstract public function alterUniqueTogether(string $tableName, array $oldUnique, array $newUnique): void;

    /**
     * Alter index_together on a table.
     *
     * @param string $tableName The name of the table
     * @param array $oldIndex Array of field names for the old index (empty to remove)
     * @param array $newIndex Array of field names for the new index (empty to add)
     */
    abstract public function alterIndexTogether(string $tableName, array $oldIndex, array $newIndex): void;

    /**
     * Add a field to a table (used by order_with_respect_to).
     *
     * @param string $tableName The name of the table
     * @param string $className The field class name
     * @param array $config The field configuration
     */
    abstract public function addField(string $tableName, string $className, array $config): void;

    /**
     * Remove a field from a table (used by order_with_respect_to).
     *
     * @param string $tableName The name of the table
     * @param string $fieldName The name of the field to remove
     */
    abstract public function removeField(string $tableName, string $fieldName): void;

    /**
     * Check if the database supports table comments.
     *
     * @return bool True if comments are supported
     */
    public function supportsComments(): bool
    {
        return false;
    }

    /**
     * Set comment on a table.
     *
     * @param string $tableName The table name
     * @param string|null $comment The comment to set, or null to remove
     */
    public function setTableComment(string $tableName, ?string $comment): void
    {
        // Default implementation does nothing - subclasses override if supported
    }

    public function flushDeferredSql(): void
    {
        foreach ($this->deferredSql as $sql) {
            $this->execute($sql);
        }
        $this->deferredSql = [];
    }

    public function runRawSql(string $sql): void
    {
        $this->execute($sql);
    }

    public function enableDryRun(?callable $sqlLogger = null): void
    {
        $this->dryRun = true;
        $this->sqlLogger = $sqlLogger;
    }

    protected function execute(string $sql): void
    {
        if ($this->sqlLogger) {
            ($this->sqlLogger)($sql);
        }

        if ($this->dryRun) {
            return;
        }

        $this->pdo->exec($sql);
    }

    protected function quote(mixed $value): string
    {
        if (is_string($value)) {
            return $this->pdo->quote($value);
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value === null) {
            return 'NULL';
        }
        return (string)$value;
    }
}
