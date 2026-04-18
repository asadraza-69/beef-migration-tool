<?php

namespace Nudelsalat\Schema;

class PostgreSQLSchemaEditor extends SchemaEditor
{
    protected function quoteTableName(string $name): string
    {
        if (str_contains($name, '.')) {
            $parts = explode('.', $name);
            return '"' . implode('"."', $parts) . '"';
        }
        return '"' . $name . '"';
    }

    public function createTable(Table $table): void
    {
        $columnDefs = [];
        foreach ($table->getColumns() as $column) {
            $columnDefs[] = $this->getColumnSql($column);
        }

        $sql = sprintf(
            "CREATE TABLE %s (%s)",
            $this->quoteTableName($table->name),
            implode(', ', $columnDefs)
        );

        $this->execute($sql);
    }

    public function deleteTable(string $name): void
    {
        $sql = sprintf("DROP TABLE %s CASCADE", $this->quoteTableName($name));
        $this->execute($sql);
    }

    public function renameTable(string $oldName, string $newName): void
    {
        $sql = sprintf("ALTER TABLE %s RENAME TO %s", $this->quoteTableName($oldName), $this->quoteTableName($newName));
        $this->execute($sql);
    }

    public function addColumn(string $tableName, Column $column): void
    {
        $sql = sprintf(
            "ALTER TABLE %s ADD COLUMN %s",
            $this->quoteTableName($tableName),
            $this->getColumnSql($column)
        );
        $this->execute($sql);
    }

    public function removeColumn(string $tableName, string $columnName): void
    {
        $sql = sprintf(
            "ALTER TABLE %s DROP COLUMN \"%s\" CASCADE",
            $this->quoteTableName($tableName),
            $columnName
        );
        $this->execute($sql);
    }

    public function renameColumn(string $tableName, string $oldName, Column $newColumn): void
    {
        $sql = sprintf(
            "ALTER TABLE %s RENAME COLUMN \"%s\" TO \"%s\"",
            $this->quoteTableName($tableName),
            $oldName,
            $newColumn->name
        );
        $this->execute($sql);
    }

    public function alterColumn(string $tableName, Column $column): void
    {
        // PostgreSQL requires separate ALTER commands for type, nullability, etc.
        $safeName = $this->quoteTableName($tableName);
        $base = "ALTER TABLE {$safeName} ALTER COLUMN \"{$column->name}\"";
        
        // Change Type
        $type = $this->mapType($column->type);
        $this->execute("{$base} TYPE {$type}");

        // Change Nullability
        $nullSql = $column->nullable ? "DROP NOT NULL" : "SET NOT NULL";
        $this->execute("{$base} {$nullSql}");

        // Change Default
        if ($column->default !== null) {
            $defaultSql = "SET DEFAULT " . $this->quote($column->default);
            $this->execute("{$base} {$defaultSql}");
        } else {
            $this->execute("{$base} DROP DEFAULT");
        }
    }

    public function addIndex(string $tableName, Index $index): void
    {
        $sql = sprintf(
            "CREATE %s INDEX \"%s\" ON %s (%s)",
            $index->unique ? 'UNIQUE' : '',
            $index->name,
            $this->quoteTableName($tableName),
            implode(', ', array_map(fn($c) => "\"$c\"", $index->columns))
        );
        $this->execute($sql);
    }

    public function removeIndex(string $tableName, string $indexName): void
    {
        // Index names in Postgres are global but drop might need schema name too if we want to be safe
        $sql = sprintf("DROP INDEX \"%s\"", $indexName);
        $this->execute($sql);
    }

    public function addConstraint(string $tableName, Constraint $constraint): void
    {
        if ($constraint->type === 'unique') {
            $sql = sprintf(
                "ALTER TABLE %s ADD CONSTRAINT \"%s\" UNIQUE (%s)",
                $this->quoteTableName($tableName),
                $constraint->name,
                implode(', ', array_map(fn($c) => "\"$c\"", $constraint->columns))
            );
        } else {
            $sql = sprintf(
                "ALTER TABLE %s ADD CONSTRAINT \"%s\" CHECK (%s)",
                $this->quoteTableName($tableName),
                $constraint->name,
                $constraint->checkSql
            );
        }
        $this->execute($sql);
    }

    public function removeConstraint(string $tableName, string $constraintName): void
    {
        $sql = sprintf("ALTER TABLE %s DROP CONSTRAINT \"%s\"", $this->quoteTableName($tableName), $constraintName);
        $this->execute($sql);
    }

    public function addForeignKey(string $tableName, string $columnName, string $toTable, string $toColumn, string $onDelete): void
    {
        $fkName = "fk_{$tableName}_{$columnName}";
        if (str_contains($fkName, '.')) {
             $fkName = str_replace('.', '_', $fkName);
        }

        $sql = sprintf(
            "ALTER TABLE %s ADD CONSTRAINT \"%s\" FOREIGN KEY (\"%s\") REFERENCES %s (\"%s\") ON DELETE %s",
            $this->quoteTableName($tableName),
            $fkName,
            $columnName,
            $this->quoteTableName($toTable),
            $toColumn,
            $onDelete
        );
        $this->execute($sql);
    }

    public function removeForeignKey(string $tableName, string $constraintName): void
    {
        $sql = sprintf("ALTER TABLE %s DROP CONSTRAINT \"%s\"", $this->quoteTableName($tableName), $constraintName);
        $this->execute($sql);
    }

    protected function getColumnSql(Column $column): string
    {
        $type = $this->mapType($column->type);
        
        // Primary Key & Auto Increment handling
        if ($column->primaryKey && $column->autoIncrement) {
             $type = 'SERIAL';
             return sprintf("\"%s\" %s PRIMARY KEY", $column->name, $type);
        }

        $nullable = $column->nullable ? 'NULL' : 'NOT NULL';
        $default = $column->default !== null ? "DEFAULT " . $this->quote($column->default) : '';
        $primaryKey = $column->primaryKey ? 'PRIMARY KEY' : '';

        return trim(sprintf(
            "\"%s\" %s %s %s %s",
            $column->name,
            $type,
            $nullable,
            $default,
            $primaryKey
        ));
    }

    protected function mapType(string $type): string
    {
        $type = strtolower($type);
        if (str_contains($type, 'varchar')) return strtoupper($type);
        
        return match ($type) {
            'int', 'integer' => 'INTEGER',
            'string' => 'VARCHAR(255)',
            'text' => 'TEXT',
            'boolean', 'bool' => 'BOOLEAN',
            'datetime' => 'TIMESTAMP',
            'decimal' => 'NUMERIC(19, 4)',
            default => strtoupper($type),
        };
    }

    protected function quote(mixed $value): string
    {
        if (is_string($value)) {
            return $this->pdo->quote($value);
        }
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }
        if ($value === null) {
            return 'NULL';
        }
        return (string)$value;
    }

    public function alterUniqueTogether(string $tableName, array $oldUnique, array $newUnique): void
    {
        $table = $this->quoteTableName($tableName);

        // Remove old unique constraint
        if (!empty($oldUnique)) {
            $constraintName = 'uniq_' . $tableName . '_' . implode('_', $oldUnique);
            $this->execute("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS \"{$constraintName}\"");
        }

        // Add new unique constraint
        if (!empty($newUnique)) {
            $columns = implode(', ', array_map(fn($c) => "\"$c\"", $newUnique));
            $constraintName = 'uniq_' . $tableName . '_' . implode('_', $newUnique);
            $this->execute("ALTER TABLE {$table} ADD CONSTRAINT \"{$constraintName}\" UNIQUE ({$columns})");
        }
    }

    public function alterIndexTogether(string $tableName, array $oldIndex, array $newIndex): void
    {
        $table = $this->quoteTableName($tableName);

        // Remove old index
        if (!empty($oldIndex)) {
            $indexName = 'idx_' . $tableName . '_' . implode('_', $oldIndex);
            $this->execute("DROP INDEX IF EXISTS \"{$indexName}\"");
        }

        // Add new index
        if (!empty($newIndex)) {
            $columns = implode(', ', array_map(fn($c) => "\"$c\"", $newIndex));
            $indexName = 'idx_' . $tableName . '_' . implode('_', $newIndex);
            $this->execute("CREATE INDEX \"{$indexName}\" ON {$table} ({$columns})");
        }
    }

    public function addField(string $tableName, string $className, array $config): void
    {
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
        $table = $this->quoteTableName($tableName);
        if ($comment === null) {
            $this->execute("COMMENT ON TABLE {$table} IS NULL");
        } else {
            $this->execute("COMMENT ON TABLE {$table} IS " . $this->quote($comment));
        }
    }

    public function renameIndex(string $tableName, string $oldName, string $newName): void
    {
        $table = $this->quoteTableName($tableName);
        $this->execute("ALTER INDEX \"{$oldName}\" RENAME TO \"{$newName}\"");
    }
}
