<?php

namespace Nudelsalat\Schema;

class Introspector
{
    private \PDO $pdo;
    private ?string $schemaFilter = null;

    public function __construct(\PDO $pdo, ?string $schema = null)
    {
        $this->pdo = $pdo;
        $this->schemaFilter = $schema;
    }

    public function setSchema(?string $schema): void
    {
        $this->schemaFilter = $schema;
    }

    public function getSchema(): ?string
    {
        return $this->schemaFilter;
    }

    /** @return Table[] */
    public function getTables(): array
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $tables = [];

        if ($driver === 'sqlite') {
            $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
            $tableNames = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        } elseif ($driver === 'pgsql') {
            $schemaFilter = $this->schemaFilter ?? 'public';
            $stmt = $this->pdo->prepare("SELECT schemaname, tablename FROM pg_catalog.pg_tables WHERE schemaname = :schema AND schemaname NOT IN ('pg_catalog', 'information_schema')");
            $stmt->execute(['schema' => $schemaFilter]);
            $tableNames = array_map(
                static fn(array $row): string => $row['schemaname'] . '.' . $row['tablename'],
                $stmt->fetchAll(\PDO::FETCH_ASSOC)
            );
            
            if (empty($tableNames) && $schemaFilter === 'public') {
                $stmt = $this->pdo->query("SELECT schemaname, tablename FROM pg_catalog.pg_tables WHERE schemaname NOT IN ('pg_catalog', 'information_schema')");
                $tableNames = array_map(
                    static fn(array $row): string => $row['schemaname'] . '.' . $row['tablename'],
                    $stmt->fetchAll(\PDO::FETCH_ASSOC)
                );
            }
        } else {
            // MySQL - no schema filtering needed
            $stmt = $this->pdo->query("SHOW TABLES");
            $tableNames = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        }

        foreach ($tableNames as $name) {
            $tables[] = $this->getTable($name);
        }

        return $tables;
    }

    public function getTable(string $name): Table
    {
        $table = new Table($name);
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $stmt = $this->pdo->query('PRAGMA table_info(' . $this->quoteSqliteIdentifier($name) . ')');
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                // pk, name, type, notnull, dflt_value
                $table->addColumn(new Column(
                    $row['name'],
                    $this->mapSqlTypeToNudelsalat($row['type']),
                    $row['notnull'] == 0,
                    $row['dflt_value'],
                    $row['pk'] == 1,
                    $row['pk'] == 1 // Usually autoinc if PK in sqlite
                ));
            }
        } elseif ($driver === 'pgsql') {
            [$schema, $tableName] = $this->splitQualifiedName($name);
            $sql = "SELECT column_name, data_type, is_nullable, column_default 
                    FROM information_schema.columns WHERE table_schema = :schema AND table_name = :table";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['schema' => $schema, 'table' => $tableName]);
            
            $primaryKeyColumns = $this->getPostgresPrimaryKeyColumns($schema, $tableName);
            
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $columnDefault = $row['column_default'];
                $sequenceName = null;
                $autoIncrement = false;
                
                if ($columnDefault !== null && preg_match("/^nextval\('([^']+)'::regclass\)$/", $columnDefault, $matches)) {
                    $sequenceName = $matches[1];
                    $autoIncrement = true;
                }
                
                $isPrimaryKey = in_array($row['column_name'], $primaryKeyColumns, true);
                
                $table->addColumn(new Column(
                    $row['column_name'],
                    $this->mapSqlTypeToNudelsalat($row['data_type']),
                    $row['is_nullable'] === 'YES',
                    $columnDefault,
                    $isPrimaryKey,
                    $autoIncrement,
                    $sequenceName ? ['sequenceName' => $sequenceName] : []
                ));
            }
            $pkName = $this->getPostgresPrimaryKeyConstraintName($schema, $tableName);
            if ($pkName) {
                $table->setPrimaryKeyName($pkName);
            }
            $this->addPostgresIndexes($table, $schema, $tableName);
            $this->addPostgresConstraints($table, $schema, $tableName);
            $this->addPostgresForeignKeys($table, $schema, $tableName);
            $this->markPostgresPrimaryKeyColumns($table, $schema, $tableName);
        } else {
            // MySQL
            $stmt = $this->pdo->query("DESCRIBE `$name` ");
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                // Field, Type, Null, Key, Default, Extra
                $table->addColumn(new Column(
                    $row['Field'],
                    $this->mapSqlTypeToNudelsalat($row['Type']),
                    $row['Null'] === 'YES',
                    $row['Default'],
                    $row['Key'] === 'PRI',
                    str_contains($row['Extra'], 'auto_increment')
                ));
            }
            $this->addMySqlIndexes($table, $name);
            $this->addMySqlConstraints($table, $name);
            $this->addMySqlForeignKeys($table, $name);
        }

        if ($driver === 'sqlite') {
            $this->addSqliteIndexes($table, $name);
            $this->addSqliteForeignKeys($table, $name);
        }

        return $table;
    }

    private function addSqliteIndexes(Table $table, string $tableName): void
    {
        $stmt = $this->pdo->query('PRAGMA index_list(' . $this->quoteSqliteIdentifier($tableName) . ')');
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (($row['origin'] ?? '') === 'pk') {
                continue;
            }

            $indexInfo = $this->pdo->query('PRAGMA index_info(' . $this->quoteSqliteIdentifier($row['name']) . ')');
            $columns = array_map(static fn(array $info): string => $info['name'], $indexInfo->fetchAll(\PDO::FETCH_ASSOC));

            if (($row['unique'] ?? 0) && ($row['origin'] ?? '') === 'u') {
                $table->addConstraint(new Constraint($row['name'], 'unique', $columns));
                continue;
            }

            $table->addIndex(new Index($row['name'], $columns, (bool) ($row['unique'] ?? 0)));
        }
    }

    private function addSqliteForeignKeys(Table $table, string $tableName): void
    {
        $stmt = $this->pdo->query('PRAGMA foreign_key_list(' . $this->quoteSqliteIdentifier($tableName) . ')');
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $table->addForeignKey(new ForeignKeyConstraint(
                'fk_' . $tableName . '_' . $row['from'],
                $row['from'],
                $row['table'],
                $row['to'],
                $row['on_delete'] ?? 'CASCADE'
            ));
        }
    }

    private function getPostgresPrimaryKeyColumns(string $schema, string $tableName): array
    {
        $sql = "SELECT kcu.column_name
                FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage kcu
                    ON tc.constraint_name = kcu.constraint_name
                    AND tc.table_schema = kcu.table_schema
                    AND tc.table_name = kcu.table_name
                WHERE tc.table_schema = :schema
                  AND tc.table_name = :table
                  AND tc.constraint_type = 'PRIMARY KEY'
                ORDER BY kcu.ordinal_position";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['schema' => $schema, 'table' => $tableName]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function markPostgresPrimaryKeyColumns(Table $table, string $schema, string $tableName): void
    {
        $primaryKeys = $this->getPostgresPrimaryKeyColumns($schema, $tableName);
        foreach ($primaryKeys as $pkColumn) {
            $column = $table->getColumn($pkColumn);
            if ($column !== null) {
                $column->primaryKey = true;
                $table->setColumnPrimaryKey($pkColumn);
            }
        }
    }

    private function getPostgresPrimaryKeyConstraintName(string $schema, string $tableName): ?string
    {
        $sql = "SELECT tc.constraint_name
                FROM information_schema.table_constraints tc
                WHERE tc.table_schema = :schema
                  AND tc.table_name = :table
                  AND tc.constraint_type = 'PRIMARY KEY'
                LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['schema' => $schema, 'table' => $tableName]);
        return $stmt->fetchColumn() ?: null;
    }

    private function addPostgresIndexes(Table $table, string $schema, string $tableName): void
    {
        $sql = 'SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = :schema AND tablename = :table';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['schema' => $schema, 'table' => $tableName]);

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (str_contains($row['indexdef'], 'PRIMARY KEY')) {
                continue;
            }

            $columns = $this->extractIndexedColumns($row['indexdef']);
            $table->addIndex(new Index($row['indexname'], $columns, str_contains($row['indexdef'], 'UNIQUE INDEX')));
        }
    }

    private function addPostgresConstraints(Table $table, string $schema, string $tableName): void
    {
        $sql = "SELECT tc.constraint_name, tc.constraint_type, kcu.column_name, cc.check_clause
                FROM information_schema.table_constraints tc
                LEFT JOIN information_schema.key_column_usage kcu
                    ON tc.constraint_name = kcu.constraint_name
                    AND tc.table_schema = kcu.table_schema
                    AND tc.table_name = kcu.table_name
                LEFT JOIN information_schema.check_constraints cc
                    ON tc.constraint_name = cc.constraint_name
                WHERE tc.table_schema = :schema AND tc.table_name = :table
                  AND tc.constraint_type IN ('UNIQUE', 'CHECK')
                ORDER BY tc.constraint_name, kcu.ordinal_position";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['schema' => $schema, 'table' => $tableName]);

        $grouped = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $name = $row['constraint_name'];
            $grouped[$name]['type'] = strtolower($row['constraint_type']);
            $grouped[$name]['check'] = $row['check_clause'] ?? null;
            if (!empty($row['column_name'])) {
                $grouped[$name]['columns'][] = $row['column_name'];
            }
        }

        foreach ($grouped as $name => $constraint) {
            $table->addConstraint(new Constraint(
                $name,
                $constraint['type'],
                $constraint['columns'] ?? [],
                $constraint['check'] ?? null
            ));
        }
    }

    private function addPostgresForeignKeys(Table $table, string $schema, string $tableName): void
    {
        $sql = "SELECT
                    tc.constraint_name,
                    kcu.column_name,
                    ccu.table_schema AS foreign_table_schema,
                    ccu.table_name AS foreign_table_name,
                    ccu.column_name AS foreign_column_name,
                    rc.delete_rule
                FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage kcu
                    ON tc.constraint_name = kcu.constraint_name
                    AND tc.table_schema = kcu.table_schema
                JOIN information_schema.constraint_column_usage ccu
                    ON tc.constraint_name = ccu.constraint_name
                    AND tc.table_schema = ccu.table_schema
                JOIN information_schema.referential_constraints rc
                    ON tc.constraint_name = rc.constraint_name
                    AND tc.table_schema = rc.constraint_schema
                WHERE tc.constraint_type = 'FOREIGN KEY'
                  AND tc.table_schema = :schema
                  AND tc.table_name = :table";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['schema' => $schema, 'table' => $tableName]);

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $toTable = $row['foreign_table_schema'] === 'public'
                ? $row['foreign_table_name']
                : $row['foreign_table_schema'] . '.' . $row['foreign_table_name'];

            $table->addForeignKey(new ForeignKeyConstraint(
                $row['constraint_name'],
                $row['column_name'],
                $toTable,
                $row['foreign_column_name'],
                $row['delete_rule'] ?? 'CASCADE'
            ));
        }
    }

    private function addMySqlIndexes(Table $table, string $tableName): void
    {
        $stmt = $this->pdo->query("SHOW INDEX FROM `{$tableName}`");
        $grouped = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (($row['Key_name'] ?? '') === 'PRIMARY') {
                continue;
            }

            $grouped[$row['Key_name']]['unique'] = ((int) ($row['Non_unique'] ?? 1)) === 0;
            $grouped[$row['Key_name']]['columns'][(int) ($row['Seq_in_index'] ?? 1)] = $row['Column_name'];
        }

        foreach ($grouped as $name => $index) {
            ksort($index['columns']);
            $table->addIndex(new Index($name, array_values($index['columns']), $index['unique']));
        }
    }

    private function addMySqlConstraints(Table $table, string $tableName): void
    {
        $sql = "SELECT tc.CONSTRAINT_NAME, tc.CONSTRAINT_TYPE, kcu.COLUMN_NAME
                FROM information_schema.TABLE_CONSTRAINTS tc
                LEFT JOIN information_schema.KEY_COLUMN_USAGE kcu
                    ON tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                    AND tc.TABLE_SCHEMA = kcu.TABLE_SCHEMA
                    AND tc.TABLE_NAME = kcu.TABLE_NAME
                WHERE tc.TABLE_SCHEMA = DATABASE()
                  AND tc.TABLE_NAME = :table
                  AND tc.CONSTRAINT_TYPE = 'UNIQUE'
                ORDER BY tc.CONSTRAINT_NAME, kcu.ORDINAL_POSITION";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['table' => $tableName]);

        $grouped = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $grouped[$row['CONSTRAINT_NAME']]['type'] = 'unique';
            if (!empty($row['COLUMN_NAME'])) {
                $grouped[$row['CONSTRAINT_NAME']]['columns'][] = $row['COLUMN_NAME'];
            }
        }

        foreach ($grouped as $name => $constraint) {
            $table->addConstraint(new Constraint($name, $constraint['type'], $constraint['columns'] ?? []));
        }
    }

    private function addMySqlForeignKeys(Table $table, string $tableName): void
    {
        $sql = "SELECT
                    kcu.CONSTRAINT_NAME,
                    kcu.COLUMN_NAME,
                    kcu.REFERENCED_TABLE_NAME,
                    kcu.REFERENCED_COLUMN_NAME,
                    rc.DELETE_RULE
                FROM information_schema.KEY_COLUMN_USAGE kcu
                JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                    ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                    AND kcu.TABLE_SCHEMA = rc.CONSTRAINT_SCHEMA
                WHERE kcu.TABLE_SCHEMA = DATABASE()
                  AND kcu.TABLE_NAME = :table
                  AND kcu.REFERENCED_TABLE_NAME IS NOT NULL";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['table' => $tableName]);

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $table->addForeignKey(new ForeignKeyConstraint(
                $row['CONSTRAINT_NAME'],
                $row['COLUMN_NAME'],
                $row['REFERENCED_TABLE_NAME'],
                $row['REFERENCED_COLUMN_NAME'],
                $row['DELETE_RULE'] ?? 'CASCADE'
            ));
        }
    }

    /** @return string[] */
    private function extractIndexedColumns(string $definition): array
    {
        if (preg_match('/\((.*)\)/', $definition, $matches) !== 1) {
            return [];
        }

        return array_values(array_filter(array_map(
            static function (string $column): string {
                $column = trim($column);
                $column = preg_replace('/\s+(ASC|DESC)$/i', '', $column) ?? $column;
                return trim($column, '" ');
            },
            explode(',', $matches[1])
        )));
    }

    private function splitQualifiedName(string $name): array
    {
        if (str_contains($name, '.')) {
            return explode('.', $name, 2);
        }

        return ['public', $name];
    }

    private function quoteSqliteIdentifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    private function mapSqlTypeToNudelsalat(string $type): string
    {
        $type = strtolower($type);
        if (str_contains($type, 'int')) return 'int';
        if (str_contains($type, 'char') || str_contains($type, 'text')) return 'string';
        if (str_contains($type, 'bool') || $type === 'tinyint(1)') return 'bool';
        if (str_contains($type, 'decimal') || str_contains($type, 'float') || str_contains($type, 'double')) return 'decimal';
        if (str_contains($type, 'date') || str_contains($type, 'time')) return 'datetime';
        return 'string';
    }

    /** @return string[] Get all non-system schemas */
    public function getSchemas(): array
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        
        if ($driver !== 'pgsql') {
            return ['public'];
        }

        $stmt = $this->pdo->query("SELECT schema_name FROM information_schema.schemata WHERE schema_name NOT IN ('pg_catalog', 'information_schema') ORDER BY schema_name");
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** @return FunctionDefinition[] Get all functions in schema */
    public function getFunctions(): array
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        
        if ($driver !== 'pgsql') {
            return [];
        }

        $schema = $this->schemaFilter ?? 'public';
        
        $sql = "SELECT
                    p.oid,
                    p.proname AS function_name,
                    pg_get_functiondef(p.oid) AS definition,
                    pg_get_function_identity_arguments(p.oid) AS identity_arguments,
                    n.nspname AS schema_name
                FROM pg_proc p
                JOIN pg_namespace n ON p.pronamespace = n.oid
                WHERE n.nspname = :schema
                ORDER BY p.proname";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['schema' => $schema]);
        
        $functions = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $functions[] = new FunctionDefinition(
                $row['function_name'],
                $row['definition'] ?? '',
                $row['identity_arguments'] ?? '',
                $row['schema_name']
            );
        }
        
        return $functions;
    }

    /** @return ViewDefinition[] Get all views in schema */
    public function getViews(): array
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        
        if ($driver !== 'pgsql') {
            return [];
        }

        $schema = $this->schemaFilter ?? 'public';
        
        $sql = "SELECT table_name, view_definition
                FROM information_schema.views
                WHERE table_schema = :schema
                ORDER BY table_name";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['schema' => $schema]);
        
        $views = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $views[] = new ViewDefinition(
                $row['table_name'],
                $row['view_definition'] ?? '',
                false,
                $schema
            );
        }
        
        return $views;
    }

    /** @return ViewDefinition[] Get all materialized views in schema */
    public function getMaterializedViews(): array
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        
        if ($driver !== 'pgsql') {
            return [];
        }

        $schema = $this->schemaFilter ?? 'public';
        
        $sql = "SELECT matviewname, definition
                FROM pg_matviews
                WHERE schemaname = :schema
                ORDER BY matviewname";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['schema' => $schema]);
        
        $views = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $views[] = new ViewDefinition(
                $row['matviewname'],
                $row['definition'] ?? '',
                true,
                $schema
            );
        }
        
        return $views;
    }

    /** @return TriggerDefinition[] Get all triggers in schema */
    public function getTriggers(): array
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        
        if ($driver !== 'pgsql') {
            return [];
        }

        $schema = $this->schemaFilter ?? 'public';
        
        $sql = "SELECT trigger_name, action_statement, event_object_table
                FROM information_schema.triggers
                WHERE trigger_schema = :schema
                ORDER BY trigger_name";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['schema' => $schema]);
        
        $triggers = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $triggers[] = new TriggerDefinition(
                $row['trigger_name'],
                $row['action_statement'] ?? '',
                $row['event_object_table'],
                $schema
            );
        }
        
        return $triggers;
    }
}
