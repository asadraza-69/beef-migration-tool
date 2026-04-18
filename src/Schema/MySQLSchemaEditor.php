<?php

namespace Nudelsalat\Schema;

class MySQLSchemaEditor extends SchemaEditor
{
    public function createTable(Table $table): void
    {
        $columnDefs = [];
        foreach ($table->getColumns() as $column) {
            $columnDefs[] = $this->getColumnSql($column);
        }

        $sql = sprintf(
            "CREATE TABLE `%s` (%s) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            $table->name,
            implode(', ', $columnDefs)
        );

        $this->execute($sql);
    }

    public function deleteTable(string $name): void
    {
        $sql = sprintf("DROP TABLE `%s`", $name);
        $this->execute($sql);
    }

    public function renameTable(string $oldName, string $newName): void
    {
        $sql = sprintf("RENAME TABLE `%s` TO `%s`", $oldName, $newName);
        $this->execute($sql);
    }

    public function addColumn(string $tableName, Column $column): void
    {
        $sql = sprintf(
            "ALTER TABLE `%s` ADD COLUMN %s",
            $tableName,
            $this->getColumnSql($column)
        );
        $this->execute($sql);
    }

    public function removeColumn(string $tableName, string $columnName): void
    {
        $sql = sprintf(
            "ALTER TABLE `%s` DROP COLUMN `%s`",
            $tableName,
            $columnName
        );
        $this->execute($sql);
    }

    public function renameColumn(string $tableName, string $oldName, Column $newColumn): void
    {
        $sql = sprintf(
            "ALTER TABLE `%s` RENAME COLUMN `%s` TO `%s`",
            $tableName,
            $oldName,
            $newColumn->name
        );
        $this->execute($sql);
    }

    public function alterColumn(string $tableName, Column $column): void
    {
        $sql = sprintf(
            "ALTER TABLE `%s` MODIFY COLUMN %s",
            $tableName,
            $this->getColumnSql($column)
        );
        $this->execute($sql);
    }

    public function addIndex(string $tableName, Index $index): void
    {
        $sql = sprintf(
            "CREATE %s INDEX `%s` ON `%s` (%s)",
            $index->unique ? 'UNIQUE' : '',
            $index->name,
            $tableName,
            implode(', ', array_map(fn($c) => "`$c`", $index->columns))
        );
        $this->execute($sql);
    }

    public function removeIndex(string $tableName, string $indexName): void
    {
        $sql = sprintf("DROP INDEX `%s` ON `%s`", $indexName, $tableName);
        $this->execute($sql);
    }

    public function addConstraint(string $tableName, Constraint $constraint): void
    {
        if ($constraint->type === 'unique') {
            $sql = sprintf(
                "ALTER TABLE `%s` ADD CONSTRAINT `%s` UNIQUE (%s)",
                $tableName,
                $constraint->name,
                implode(', ', array_map(fn($c) => "`$c`", $constraint->columns))
            );
        } else {
            $sql = sprintf(
                "ALTER TABLE `%s` ADD CONSTRAINT `%s` CHECK (%s)",
                $tableName,
                $constraint->name,
                $constraint->checkSql
            );
        }
        $this->execute($sql);
    }

    public function removeConstraint(string $tableName, string $constraintName): void
    {
        $sql = sprintf("ALTER TABLE `%s` DROP CONSTRAINT `%s`", $tableName, $constraintName);
        $this->execute($sql);
    }

    public function addForeignKey(string $tableName, string $columnName, string $toTable, string $toColumn, string $onDelete): void
    {
        $fkName = "fk_{$tableName}_{$columnName}";
        $sql = sprintf(
            "ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`%s`) ON DELETE %s",
            $tableName,
            $fkName,
            $columnName,
            $toTable,
            $toColumn,
            $onDelete
        );
        $this->execute($sql);
    }

    public function removeForeignKey(string $tableName, string $constraintName): void
    {
        $sql = sprintf(
            "ALTER TABLE `%s` DROP FOREIGN KEY `%s`",
            $tableName,
            $constraintName
        );
        $this->execute($sql);
    }

    protected function getColumnSql(Column $column): string
    {
        $type = $this->mapType($column->type);
        $nullable = $column->nullable ? 'NULL' : 'NOT NULL';
        $default = $column->default !== null ? "DEFAULT " . $this->quote($column->default) : '';
        $autoIncrement = $column->autoIncrement ? 'AUTO_INCREMENT' : '';
        $primaryKey = $column->primaryKey ? 'PRIMARY KEY' : '';

        return sprintf(
            "`%s` %s %s %s %s %s",
            $column->name,
            $type,
            $nullable,
            $default,
            $autoIncrement,
            $primaryKey
        );
    }

    protected function mapType(string $type): string
    {
        return match (strtolower($type)) {
            'int', 'integer' => 'INT',
            'string', 'varchar' => 'VARCHAR(255)',
            'text' => 'TEXT',
            'boolean', 'bool' => 'TINYINT(1)',
            'datetime' => 'DATETIME',
            'decimal' => 'DECIMAL(19, 4)',
            default => strtoupper($type),
        };
    }

    protected function quote(mixed $value): string
    {
        if (is_string($value)) {
            return "'" . addslashes($value) . "'";
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value === null) {
            return 'NULL';
        }
        return (string)$value;
    }

    public function alterUniqueTogether(string $tableName, array $oldUnique, array $newUnique): void
    {
        // First remove old unique constraint if exists
        if (!empty($oldUnique)) {
            $constraintName = 'uniq_' . $tableName . '_' . implode('_', $oldUnique);
            $this->execute("ALTER TABLE `{$tableName}` DROP INDEX `{$constraintName}`");
        }

        // Then add new unique constraint if exists
        if (!empty($newUnique)) {
            $columns = implode(', ', array_map(fn($c) => "`$c`", $newUnique));
            $this->execute("ALTER TABLE `{$tableName}` ADD UNIQUE ({$columns})");
        }
    }

    public function alterIndexTogether(string $tableName, array $oldIndex, array $newIndex): void
    {
        // First remove old index if exists
        if (!empty($oldIndex)) {
            $indexName = 'idx_' . $tableName . '_' . implode('_', $oldIndex);
            $this->execute("DROP INDEX `{$indexName}` ON `{$tableName}`");
        }

        // Then add new index if exists
        if (!empty($newIndex)) {
            $columns = implode(', ', array_map(fn($c) => "`$c`", $newIndex));
            $this->execute("CREATE INDEX `idx_" . $tableName . "_" . implode('_', $newIndex) . "` ON `{$tableName}` ({$columns})");
        }
    }

    public function addField(string $tableName, string $className, array $config): void
    {
        // Map common field classes to column definitions
        $column = new Column(
            $config['name'],
            $className === 'IntegerField' ? 'int' : 'string',
            $config['nullable'] ?? true,
            $config['default'] ?? null,
            $config['autoIncrement'] ?? false,
            $config['primaryKey'] ?? false
        );
        $this->addColumn($tableName, $column);
    }

    public function removeField(string $tableName, string $fieldName): void
    {
        $this->removeColumn($tableName, $fieldName);
    }

    public function supportsComments(): bool
    {
        return true;
    }

    public function setTableComment(string $tableName, ?string $comment): void
    {
        if ($comment === null) {
            $this->execute("ALTER TABLE `{$tableName}` COMMENT = ''");
        } else {
            $this->execute("ALTER TABLE `{$tableName}` COMMENT = " . $this->quote($comment));
        }
    }

    public function renameIndex(string $tableName, string $oldName, string $newName): void
    {
        // MySQL doesn't have a direct RENAME INDEX, so we drop and recreate
        $this->execute("DROP INDEX `{$oldName}` ON `{$tableName}`");
        
        // Get index details from the new state to recreate - this is a simplification
        // In a full implementation, we'd need to preserve the index type and columns
    }
}
