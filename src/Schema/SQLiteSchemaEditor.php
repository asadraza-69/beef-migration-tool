<?php

namespace Nudelsalat\Schema;

class SQLiteSchemaEditor extends SchemaEditor
{
    public function createTable(Table $table, ?string $dbName = null): void
    {
        $columnDefs = [];
        foreach ($table->getColumns() as $column) {
            $columnDefs[] = $this->getColumnSql($column);
        }

        $sql = sprintf(
            "CREATE TABLE \"%s\" (%s%s)",
            $dbName ?? $table->name,
            implode(', ', $columnDefs),
            $this->getForeignKeysSql($table)
        );

        $this->execute($sql);
    }

    private function getForeignKeysSql(Table $table): string
    {
        $fks = $table->getForeignKeys();
        if (empty($fks)) return '';

        $sql = "";
        foreach ($fks as $fk) {
            $sql .= sprintf(
                ", CONSTRAINT \"%s\" FOREIGN KEY (\"%s\") REFERENCES \"%s\" (\"%s\") ON DELETE %s",
                $fk->name,
                $fk->column,
                $fk->toTable,
                $fk->toColumn,
                $fk->onDelete
            );
        }
        return $sql;
    }

    public function deleteTable(string $name): void
    {
        if ($this->tableExists($name)) {
            $sql = sprintf("DROP TABLE \"%s\"", $name);
            $this->execute($sql);
            $this->clearTableCache();
        }
    }

    public function renameTable(string $oldName, string $newName): void
    {
        if ($oldName === $newName) {
            return;
        }

        if ($this->tableExists($oldName)) {
            $sql = sprintf("ALTER TABLE \"%s\" RENAME TO \"%s\"", $oldName, $newName);
            $this->execute($sql);
            $this->clearTableCache();
        }
    }

    public function addColumn(string $tableName, Column $column): void
    {
        $sql = sprintf(
            "ALTER TABLE \"%s\" ADD COLUMN %s",
            $tableName,
            $this->getColumnSql($column)
        );
        $this->execute($sql);
    }

    public function removeColumn(string $tableName, string $columnName): void
    {
        $this->rebuildTable($tableName, function(Table $table) use ($columnName) {
            $table->removeColumn($columnName);
        });
    }

    public function renameColumn(string $tableName, string $oldName, Column $newColumn): void
    {
        // SQLite 3.25.0+ supports RENAME COLUMN, but for compatibility 
        // and because we already need rebuildTable for other things, we can use rebuildTable.
        $this->rebuildTable($tableName, function(Table $table) use ($oldName, $newColumn) {
            $column = $table->getColumn($oldName);
            if ($column) {
                $table->removeColumn($oldName);
                $column->name = $newColumn->name;
                $table->addColumn($column);
            }
        }, [$oldName => $newColumn->name]);
    }

    public function alterColumn(string $tableName, Column $column): void
    {
        $this->rebuildTable($tableName, function(Table $table) use ($column) {
            $table->addColumn($column); // addColumn handles replacement in current Table impl
        });
    }

    public function addIndex(string $tableName, Index $index): void
    {
        $sql = sprintf(
            "CREATE %s INDEX \"%s\" ON \"%s\" (%s)",
            $index->unique ? 'UNIQUE' : '',
            $index->name,
            $tableName,
            implode(', ', array_map(fn($c) => "\"$c\"", $index->columns))
        );
        $this->execute($sql);
    }

    public function removeIndex(string $tableName, string $indexName): void
    {
        $sql = sprintf("DROP INDEX \"%s\"", $indexName);
        $this->execute($sql);
    }

    public function addConstraint(string $tableName, Constraint $constraint): void
    {
        $this->rebuildTable($tableName, function(Table $table) use ($constraint) {
            $table->addConstraint($constraint);
        });
    }

    public function removeConstraint(string $tableName, string $constraintName): void
    {
        $this->rebuildTable($tableName, function(Table $table) use ($constraintName) {
            $table->removeConstraint($constraintName);
        });
    }

    public function addForeignKey(string $tableName, string $columnName, string $toTable, string $toColumn, string $onDelete, ?string $fkName = null): void
    {
        $constraintName = $fkName ?? "fk_{$tableName}_{$columnName}";
        $this->rebuildTable($tableName, function(Table $table) use ($constraintName, $columnName, $toTable, $toColumn, $onDelete) {
            $table->addForeignKey(new ForeignKeyConstraint($constraintName, $columnName, $toTable, $toColumn, $onDelete));
        });
    }

    public function removeForeignKey(string $tableName, string $constraintName): void
    {
        $this->rebuildTable($tableName, function(Table $table) use ($constraintName) {
            $table->removeForeignKey($constraintName);
        });
    }

    /**
     * @param callable $schemaModifier Callback that mutates a Table object.
     * @param array $renames Map of [old_col_name => new_col_name] for data copying.
     */
    private function rebuildTable(string $tableName, callable $schemaModifier, array $renames = []): void
    {
        // 1. Get current structure
        $oldTable = $this->introspectTable($tableName);
        
        // 2. Clone and modify
        $newTable = Table::fromArray($oldTable->toArray());
        $schemaModifier($newTable);
        
        $tempName = "new__{$tableName}";
        
        // 3. Create new table
        $newTable->name = $tempName;
        $this->createTable($newTable);
        
        // 4. Copy data
        $oldCols = array_keys($oldTable->getColumns());
        $newCols = array_keys($newTable->getColumns());
        
        $commonCols = [];
        $srcCols = [];
        $dstCols = [];
        
        foreach ($newCols as $newCol) {
            $oldCol = array_search($newCol, $renames) ?: $newCol;
            if (in_array($oldCol, $oldCols)) {
                $srcCols[] = "\"{$oldCol}\"";
                $dstCols[] = "\"{$newCol}\"";
            }
        }
        
        if (!empty($dstCols)) {
            $sql = sprintf(
                "INSERT INTO \"%s\" (%s) SELECT %s FROM \"%s\"",
                $tempName,
                implode(', ', $dstCols),
                implode(', ', $srcCols),
                $tableName
            );
            $this->execute($sql);
        }
        
        // 5. Swap
        $this->deleteTable($tableName);
        $this->renameTable($tempName, $tableName);
    }

    private function introspectTable(string $name): Table
    {
        $stmt = $this->pdo->query("PRAGMA table_info(\"{$name}\")");
        $cols = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $columns = [];
        foreach ($cols as $col) {
            $columns[] = new Column(
                $col['name'],
                $col['type'],
                $col['notnull'] == 0,
                $col['dflt_value'],
                $col['pk'] == 1,
                // SQLite doesn't easily show auto_increment in table_info, 
                // but usually PK Integer is AI.
                $col['pk'] == 1 && strtolower($col['type']) === 'integer'
            );
        }
        
        return new Table($name, $columns);
    }

    protected function getColumnSql(Column $column): string
    {
        $type = $this->mapType($column->type);
        $nullable = $column->nullable ? 'NULL' : 'NOT NULL';
        $default = $column->default !== null ? "DEFAULT " . $this->quote($column->default) : '';
        $primaryKey = $column->primaryKey ? 'PRIMARY KEY' : '';
        $autoIncrement = ($column->primaryKey && $column->autoIncrement) ? 'AUTOINCREMENT' : '';

        return sprintf(
            "\"%s\" %s %s %s %s %s",
            $column->name,
            $type,
            $nullable,
            $default,
            $primaryKey,
            $autoIncrement
        );
    }

    protected function mapType(string $type): string
    {
        $type = strtolower($type);
        if ($type === 'int' || $type === 'integer') return 'INTEGER';
        if (str_contains($type, 'varchar')) return 'VARCHAR';
        
        return match ($type) {
            'string' => 'VARCHAR',
            'text' => 'TEXT',
            'boolean', 'bool' => 'BOOLEAN',
            'datetime' => 'DATETIME',
            'decimal' => 'DECIMAL',
            default => strtoupper($type),
        };
    }

    protected function quote(mixed $value): string
    {
        if (is_string($value)) {
            return $this->pdo->quote($value);
        }
        return parent::quote($value);
    }

    public function alterUniqueTogether(string $tableName, array $oldUnique, array $newUnique): void
    {
        // SQLite doesn't support ALTER TABLE for unique constraints directly
        // We need to rebuild the table
        // For now, add to deferred SQL - actual implementation would require table rebuild
        if (!empty($oldUnique)) {
            $constraintName = 'uniq_' . $tableName . '_' . implode('_', $oldUnique);
            $this->deferredSql[] = "DROP INDEX IF EXISTS \"{$constraintName}\"";
        }

        if (!empty($newUnique)) {
            $columns = implode(', ', array_map(fn($c) => "\"$c\"", $newUnique));
            $constraintName = 'uniq_' . $tableName . '_' . implode('_', $newUnique);
            $this->deferredSql[] = "CREATE UNIQUE INDEX \"{$constraintName}\" ON \"{$tableName}\" ({$columns})";
        }
    }

    public function alterIndexTogether(string $tableName, array $oldIndex, array $newIndex): void
    {
        // SQLite doesn't have a direct ALTER for index_together
        // Add to deferred SQL
        if (!empty($oldIndex)) {
            $indexName = 'idx_' . $tableName . '_' . implode('_', $oldIndex);
            $this->deferredSql[] = "DROP INDEX IF EXISTS \"{$indexName}\"";
        }

        if (!empty($newIndex)) {
            $columns = implode(', ', array_map(fn($c) => "\"$c\"", $newIndex));
            $indexName = 'idx_' . $tableName . '_' . implode('_', $newIndex);
            $this->deferredSql[] = "CREATE INDEX \"{$indexName}\" ON \"{$tableName}\" ({$columns})";
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
        return false; // SQLite doesn't support table comments natively
    }

    public function setTableComment(string $tableName, ?string $comment): void
    {
        // SQLite doesn't support table comments - no-op
    }

    public function renameIndex(string $tableName, string $oldName, string $newName): void
    {
        // SQLite doesn't support RENAME INDEX directly
        // Add to deferred SQL for manual handling
        $this->deferredSql[] = "ALTER INDEX \"{$oldName}\" RENAME TO \"{$newName}\"";
    }
}
