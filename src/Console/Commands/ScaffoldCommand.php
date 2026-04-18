<?php

namespace Nudelsalat\Console\Commands;

use Nudelsalat\Schema\Introspector;
use Nudelsalat\Migrations\Operations\RunSQL;
use Nudelsalat\Migrations\Migration;

class ScaffoldCommand extends BaseCommand
{
    private ?string $schema = null;
    private ?string $outputDir = null;
    private bool $dryRun = false;

    public function execute(array $args): void
    {
        $databaseAlias = $this->getOptionValue($args, '--database') ?: $this->bootstrap->getConfig()->getDefaultDatabaseAlias();
        $this->schema = $this->getOptionValue($args, '--schema');
        $this->outputDir = $this->getOptionValue($args, '--output');
        $this->dryRun = $this->hasFlag($args, '--dry-run');
        
        $pdo = $this->bootstrap->getPdo($databaseAlias);
        $introspector = new Introspector($pdo, $this->schema);
        
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        
        if ($driver !== 'pgsql') {
            $this->error("Scaffold command currently only supports PostgreSQL. Driver: {$driver}");
            return;
        }

        $this->info("Scaffolding database objects" . ($this->schema ? " from schema '{$this->schema}'" : " from all schemas") . "...");

        $schemas = $this->schema ? [$this->schema] : $introspector->getSchemas();
        
        $baseTime = time();
        $phaseIndex = 0;
        
        foreach ($schemas as $schema) {
            $this->info("\n=== Schema: {$schema} ===");
            $schemaIntrospector = new Introspector($pdo, $schema);
            
            // Phase 1: Schema creation
            $this->generateSchemaMigration($schema, $baseTime + $phaseIndex++);
            
            // Phase 2: Tables
            $tables = $schemaIntrospector->getTables();
            foreach ($tables as $table) {
                if ($table->name === 'nudelsalat_migrations') continue;
                $this->generateTableMigration($table, $baseTime + $phaseIndex++);
            }
            
            // Phase 3: Foreign Keys
            foreach ($tables as $table) {
                $fks = $table->getForeignKeys();
                foreach ($fks as $fk) {
                    $this->generateForeignKeyMigration($table->name, $fk, $baseTime + $phaseIndex++);
                }
            }
            
            // Phase 4: Functions
            $functions = $schemaIntrospector->getFunctions();
            foreach ($functions as $fn) {
                $this->generateFunctionMigration($fn, $baseTime + $phaseIndex++);
            }
            
            // Phase 5: Views with dependency ordering
            $views = $schemaIntrospector->getViews();
            $sortedViews = $this->computeViewOrder($views);
            foreach ($sortedViews as $view) {
                $this->generateViewMigration($view, $baseTime + $phaseIndex++);
            }
            
            // Phase 6: Materialized Views
            $matviews = $schemaIntrospector->getMaterializedViews();
            foreach ($matviews as $mv) {
                $this->generateMaterializedViewMigration($mv, $baseTime + $phaseIndex++);
            }
            
            // Phase 7: Triggers
            $triggers = $schemaIntrospector->getTriggers();
            foreach ($triggers as $trigger) {
                $this->generateTriggerMigration($trigger, $baseTime + $phaseIndex++);
            }
            
            // Phase 8: Indexes (non-constraint)
            foreach ($tables as $table) {
                $indexes = $table->getIndexes();
                foreach ($indexes as $idx) {
                    $this->generateIndexMigration($table->name, $idx, $baseTime + $phaseIndex++);
                }
            }
            
            // Phase 9: Constraints (unique, check, exclusion)
            foreach ($tables as $table) {
                $constraints = $table->getConstraints();
                foreach ($constraints as $con) {
                    if ($con->type === 'unique' || $con->type === 'check') {
                        $this->generateConstraintMigration($table->name, $con, $baseTime + $phaseIndex++);
                    }
                }
            }
        }
        
        $this->info("\nScaffold complete! Generated migrations in: " . ($this->outputDir ?? "memory (use --output to save)"));
    }

    private function generateSchemaMigration(string $schema, int $timestamp): void
    {
        $className = 'CreateSchema' . $this->classify($schema);
        $migration = $this->buildMigration($className, [
            new RunSQL(
                "CREATE SCHEMA IF NOT EXISTS {$schema}",
                "DROP SCHEMA IF EXISTS {$schema} CASCADE"
            ),
        ], $timestamp);
        
        $this->outputMigration($migration, "create_schema_{$schema}.php");
    }

    private function generateTableMigration(\Nudelsalat\Schema\Table $table, int $timestamp): void
    {
        $tableName = $table->name;
        $quotedTableName = $this->quoteIdentifier($tableName);
        
        $colDefs = [];
        foreach ($table->getColumns() as $col) {
            $quotedColName = $this->quoteIdentifier($col->name);
            $colType = $this->mapTypeToSql($col->type, $col);
            
            $isSerialPk = $col->primaryKey && $col->default !== null && stripos((string)$col->default, 'nextval(') !== false;
            if ($isSerialPk) {
                $def = "{$quotedColName} SERIAL";
            } elseif ($col->primaryKey && $col->autoIncrement) {
                if (str_contains(strtolower($colType), 'bigint')) {
                    $def = "{$quotedColName} BIGSERIAL";
                } else {
                    $def = "{$quotedColName} SERIAL";
                }
            } else {
                $def = "{$quotedColName} {$colType}";
                if (!$col->nullable && !$col->primaryKey) {
                    $def .= ' NOT NULL';
                }
                if ($col->default !== null) {
                    $def .= ' DEFAULT ' . $col->default;
                }
            }
            
            $colDefs[] = $def;
        }
        
        $pkColumns = array_filter($table->getColumns(), fn($c) => $c->primaryKey);
        if (!empty($pkColumns)) {
            $pkCols = implode('", "', array_map(fn($c) => $c->name, $pkColumns));
            $pkName = $table->getPrimaryKeyName();
            if ($pkName) {
                $colDefs[] = "CONSTRAINT {$pkName} PRIMARY KEY (\"{$pkCols}\")";
            } else {
                $colDefs[] = "PRIMARY KEY (\"{$pkCols}\")";
            }
        }
        
        $createSql = "CREATE TABLE IF NOT EXISTS {$quotedTableName} (\n    " . implode(",\n    ", $colDefs) . "\n)";
        
        $className = 'CreateTable' . $this->classify($tableName);
        $migration = $this->buildMigration($className, [
            new RunSQL($createSql, "DROP TABLE IF EXISTS {$quotedTableName} CASCADE"),
        ], $timestamp);
        
        $this->outputMigration($migration, "create_table_{$tableName}.php");
    }

    private function generateForeignKeyMigration(string $tableName, \Nudelsalat\Schema\ForeignKeyConstraint $fk, int $timestamp): void
    {
        $quotedFkName = $this->quoteIdentifier($fk->name);
        $quotedColumn = $this->quoteIdentifier($fk->column);
        $quotedRefTable = $this->quoteIdentifier($fk->referencedTable);
        $quotedRefColumn = $this->quoteIdentifier($fk->referencedColumn);
        $quotedTableName = $this->quoteIdentifier($tableName);
        $onDelete = $fk->onDelete ?? 'CASCADE';
        
        $addSql = "ALTER TABLE {$quotedTableName} ADD CONSTRAINT {$quotedFkName} FOREIGN KEY ({$quotedColumn}) REFERENCES {$quotedRefTable}({$quotedRefColumn}) ON DELETE {$onDelete}";
        $dropSql = "ALTER TABLE {$quotedTableName} DROP CONSTRAINT IF EXISTS {$quotedFkName}";
        
        $className = 'AddFk' . $this->classify($tableName);
        $migration = $this->buildMigration($className, [
            new RunSQL($addSql, $dropSql),
        ], $timestamp);
        
        $this->outputMigration($migration, "add_fk_{$tableName}_{$fk->column}.php");
    }

    private function generateFunctionMigration(\Nudelsalat\Schema\FunctionDefinition $fn, int $timestamp): void
    {
        $className = 'CreateFunction' . $this->classify($fn->name);
        $migration = $this->buildMigration($className, [
            new RunSQL($fn->definition, $fn->getDropSql()),
        ], $timestamp);
        
        $this->outputMigration($migration, "create_function_{$fn->name}.php");
    }

    private function generateViewMigration(\Nudelsalat\Schema\ViewDefinition $view, int $timestamp): void
    {
        $def = $view->definition ?: 'SELECT 1 as dummy';
        
        $className = 'CreateView' . $this->classify($view->name);
        $migration = $this->buildMigration($className, [
            new RunSQL("CREATE OR REPLACE VIEW {$view->name} AS {$def}", $view->getDropSql()),
        ], $timestamp);
        
        $this->outputMigration($migration, "create_view_{$view->name}.php");
    }

    public function computeViewOrder(array $views): array
    {
        $byName = [];
        foreach ($views as $v) {
            $byName[$v->name] = $v;
        }

        $deps = [];
        foreach ($views as $v) {
            $deps[$v->name] = [];
            foreach ($views as $other) {
                if ($v->name === $other->name) continue;
                if (stripos($v->definition, $other->name) !== false) {
                    $deps[$v->name][] = $other->name;
                }
            }
        }

        $sorted = [];
        $visited = [];
        $visit = function(string $name) use (&$visit, &$sorted, &$visited, &$deps) {
            if (isset($visited[$name])) return;
            $visited[$name] = true;
            foreach ($deps[$name] as $dep) {
                $visit($dep);
            }
            $sorted[] = $name;
        };
        foreach (array_keys($byName) as $nm) {
            $visit($nm);
        }

        $result = [];
        foreach ($sorted as $name) {
            $result[] = $byName[$name];
        }
        return $result;
    }

    private function generateMaterializedViewMigration(\Nudelsalat\Schema\ViewDefinition $mv, int $timestamp): void
    {
        $def = rtrim($mv->definition ?: 'SELECT 1 as dummy', ';');
        
        $className = 'CreateMatView' . $this->classify($mv->name);
        $migration = $this->buildMigration($className, [
            new RunSQL("CREATE MATERIALIZED VIEW IF NOT EXISTS {$mv->name} AS {$def}", $mv->getDropSql()),
        ], $timestamp);
        
        $this->outputMigration($migration, "create_matview_{$mv->name}.php");
    }

    private function generateTriggerMigration(\Nudelsalat\Schema\TriggerDefinition $trigger, int $timestamp): void
    {
        $dropSql = $trigger->getDropSql();
        
        $className = 'CreateTrigger' . $this->classify($trigger->name);
        $migration = $this->buildMigration($className, [
            new RunSQL("{$dropSql}; {$trigger->definition}", $dropSql),
        ], $timestamp);
        
        $this->outputMigration($migration, "create_trigger_{$trigger->name}.php");
    }

    private function generateIndexMigration(string $tableName, \Nudelsalat\Schema\Index $index, int $timestamp): void
    {
        $cols = implode('", "', $index->columns);
        $unique = $index->unique ? 'UNIQUE ' : '';
        
        $className = 'CreateIndex' . $this->classify($index->name);
        $migration = $this->buildMigration($className, [
            new RunSQL(
                "CREATE {$unique}INDEX IF NOT EXISTS {$index->name} ON {$tableName} (\"{$cols}\")",
                "DROP INDEX IF EXISTS {$index->name}"
            ),
        ], $timestamp);
        
        $this->outputMigration($migration, "create_index_{$index->name}.php");
    }

    private function generateConstraintMigration(string $tableName, \Nudelsalat\Schema\Constraint $constraint, int $timestamp): void
    {
        $quotedTableName = $this->quoteIdentifier($tableName);
        $quotedConstraintName = $this->quoteIdentifier($constraint->name);
        
        if ($constraint->type === 'unique') {
            $quotedColumns = implode('", "', array_map(fn($c) => $this->quoteIdentifier($c), $constraint->columns));
            $def = "UNIQUE (\"{$quotedColumns}\")";
        } else {
            $def = $this->quoteCheckSql($constraint->check ?? '');
        }
        
        $className = 'Add' . $this->classify($constraint->type) . $this->classify($constraint->name);
        $migration = $this->buildMigration($className, [
            new RunSQL(
                "ALTER TABLE {$quotedTableName} ADD CONSTRAINT {$quotedConstraintName} {$def}",
                "ALTER TABLE {$quotedTableName} DROP CONSTRAINT IF EXISTS {$quotedConstraintName}"
            ),
        ], $timestamp);
        
        $this->outputMigration($migration, "add_{$constraint->type}_{$constraint->name}.php");
    }

    private function buildMigration(string $className, array $operations, int $timestamp): string
    {
        $opsPhp = implode(",\n            ", array_map(fn($op) => $this->operationToPhp($op), $operations));
        
        return <<<PHP
<?php

use Nudelsalat\Migrations\Migration;
use Nudelsalat\Migrations\Operations\RunSQL;

return new class('{$timestamp}_scaffold') extends Migration {
    public function __construct(string \$name)
    {
        parent::__construct(\$name);
        
        \$this->atomic = false;
        
        \$this->operations = [
            {$opsPhp}
        ];
    }
};
PHP;
    }

    private function operationToPhp(RunSQL $op): string
    {
        $forward = addslashes($op->sql ?? '');
        $backward = addslashes($op->reverseSql ?? '');
        return "new RunSQL('{$forward}', '{$backward}')";
    }

    private function outputMigration(string $content, string $filename): void
    {
        if ($this->outputDir) {
            $path = rtrim($this->outputDir, '/') . '/' . $filename;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            file_put_contents($path, $content);
            echo "  Generated: {$filename}\n";
        } else {
            echo "--- {$filename} ---\n{$content}\n";
        }
    }

    private function mapTypeToSql(string $type, \Nudelsalat\Schema\Column $col): string
    {
        $type = strtolower($type);
        
        if (str_contains($type, 'int')) return 'INTEGER';
        if (str_contains($type, 'bool')) return 'BOOLEAN';
        if (str_contains($type, 'double')) return 'DOUBLE PRECISION';
        if (str_contains($type, 'decimal') || $type === 'numeric') return 'NUMERIC';
        if (str_contains($type, 'datetime') || str_contains($type, 'timestamp')) return 'TIMESTAMP';
        if (str_contains($type, 'json')) return 'JSONB';
        if (str_contains($type, 'text')) return 'TEXT';
        
        if (preg_match('/varchar\((\d+)\)/', $type, $m)) {
            return "VARCHAR({$m[1]})";
        }
        
        return 'VARCHAR(255)';
    }

    private function classify(string $name): string
    {
        $name = str_replace(['.', '_'], ' ', $name);
        $name = ucwords($name);
        return str_replace(' ', '', $name);
    }

    private function quoteIdentifier(string $name): string
    {
        if (is_numeric(substr($name, 0, 1))) {
            return '"' . $name . '"';
        }
        
        $reserved = ['user', 'group', 'order', 'table', 'index', 'select', 'from', 'where', 'desc', 'asc', 'limit', 'offset', 'join', 'left', 'right', 'inner', 'outer', 'on', 'and', 'or', 'not', 'null', 'true', 'false', 'default', 'primary', 'key', 'foreign', 'references', 'constraint', 'unique', 'check', 'type', 'value'];
        if (in_array(strtolower($name), $reserved, true)) {
            return '"' . $name . '"';
        }
        
        return '"' . $name . '"';
    }

    private function quoteCheckSql(string $checkSql): string
    {
        if (empty($checkSql)) {
            return $checkSql;
        }
        
        $pattern = '/(\s+)(\w+)(\s+IS)/i';
        return preg_replace_callback($pattern, function ($matches) {
            $space = $matches[1];
            $identifier = $matches[2];
            $rest = $matches[3];
            return $space . $this->quoteIdentifier($identifier) . $rest;
        }, $checkSql) ?? $checkSql;
    }
}