<?php

namespace Nudelsalat\Migrations;

use Nudelsalat\Schema\Table;

/**
 * The StateRegistry provides access to "frozen" models based on a specific ProjectState.
 * This is crucial for data migrations to remain functional even if models are deleted.
 * 
 * This class provides equivalent access to historical model state for data migrations.
 */
class StateRegistry
{
    private ProjectState $state;
    private \PDO $pdo;

    public function __construct(ProjectState $state, \PDO $pdo)
    {
        $this->state = $state;
        $this->pdo = $pdo;
    }

    /**
     * Get a historical model by name.
     * Supports formats: 'ModelName', 'app.ModelName', or lookup by db_table.
     */
    public function getModel(string $modelName, ?string $lookupName = null): HistoricalModel
    {
        $table = $this->resolveTable($modelName, $lookupName);
        if (!$table) {
            $displayName = $lookupName === null ? $modelName : ($modelName . '.' . $lookupName);
            throw new \Exception("Model '{$displayName}' not found in the current migration state.");
        }

        return new HistoricalModel($table, $this->pdo, $this);
    }

    /**
     * Check if a model exists in the current state.
     */
    public function hasModel(string $modelName, ?string $lookupName = null): bool
    {
        return $this->resolveTable($modelName, $lookupName) !== null;
    }

    /**
     * Get all models, optionally filtered by app label.
     * Similar to the migration system's apps.get_models() and apps.get_app_models().
     *
     * @return HistoricalModel[]
     */
    public function getModels(?string $appLabel = null): array
    {
        $models = [];

        foreach ($this->state->getTables() as $table) {
            if ($appLabel !== null && (($table->options['app_label'] ?? null) !== $appLabel)) {
                continue;
            }

            $models[] = new HistoricalModel($table, $this->pdo, $this);
        }

        return $models;
    }

    /**
     * Get all app labels that have models in this state.
     *
     * @return string[]
     */
    public function getAppLabels(): array
    {
        $labels = [];
        foreach ($this->state->getTables() as $table) {
            $label = $table->options['app_label'] ?? null;
            if ($label !== null && !in_array($label, $labels, true)) {
                $labels[] = $label;
            }
        }
        return $labels;
    }

    /**
     * Get model options (Meta) for a specific model.
     */
    public function getModelOptions(string $modelName, ?string $lookupName = null): ?array
    {
        $table = $this->resolveTable($modelName, $lookupName);
        return $table?->options;
    }

    /**
     * Get the list of field names for a model.
     */
    public function getFieldNames(string $modelName, ?string $lookupName = null): array
    {
        $table = $this->resolveTable($modelName, $lookupName);
        if (!$table) {
            return [];
        }
        return array_keys($table->getColumns());
    }

    /**
     * Get the ProjectState this registry is based on.
     */
    public function getProjectState(): ProjectState
    {
        return $this->state;
    }

    /**
     * Get the PDO connection.
     */
    public function getConnection(): \PDO
    {
        return $this->pdo;
    }

    /**
     * Get all models that reference this model (reverse relations).
     *
     * @return HistoricalModel[]
     */
    public function getModelsWithRelationTo(string $modelName): array
    {
        $referencingModels = [];
        
        foreach ($this->state->getTables() as $table) {
            foreach ($table->getForeignKeys() as $fk) {
                if (strtolower($fk->toTable) === strtolower($modelName)) {
                    $referencingModels[] = new HistoricalModel($table, $this->pdo, $this);
                    break;
                }
            }
        }
        
        return $referencingModels;
    }

    /**
     * Get the actual table from the state.
     */
    public function getTable(string $name): ?Table
    {
        return $this->state->getTable($name);
    }

    private function resolveTable(string $modelName, ?string $lookupName = null): ?Table
    {
        if ($lookupName !== null) {
            return $this->findTableByAppAndModel($modelName, $lookupName);
        }

        if (str_contains($modelName, '.')) {
            [$appLabel, $name] = explode('.', $modelName, 2);
            return $this->findTableByAppAndModel($appLabel, $name);
        }

        $direct = $this->state->getTable($modelName);
        if ($direct !== null) {
            return $direct;
        }

        $matched = [];
        foreach ($this->state->getTables() as $table) {
            if ($this->matchesModelName($table, $modelName)) {
                $matched[] = $table;
            }
        }

        return count($matched) === 1 ? $matched[0] : null;
    }

    private function findTableByAppAndModel(string $appLabel, string $modelName): ?Table
    {
        foreach ($this->state->getTables() as $table) {
            if (($table->options['app_label'] ?? null) !== $appLabel) {
                continue;
            }

            if ($this->matchesModelName($table, $modelName)) {
                return $table;
            }
        }

        return null;
    }

    private function matchesModelName(Table $table, string $modelName): bool
    {
        $modelName = strtolower($modelName);
        $candidates = [
            strtolower($table->name),
            strtolower($table->options['model_name'] ?? $table->name),
            strtolower($table->options['db_table'] ?? $table->name),
        ];

        return in_array($modelName, $candidates, true);
    }
}

/**
 * A lightweight proxy for interacting with database data during migrations.
 * 
 * This class provides an ORM-like interface for data access in migrations.
 * It allows data migrations to work even after models are modified or deleted.
 */
class HistoricalModel
{
    private Table $table;
    private \PDO $pdo;
    private StateRegistry $registry;

    public function __construct(Table $table, \PDO $pdo, StateRegistry $registry)
    {
        $this->table = $table;
        $this->pdo = $pdo;
        $this->registry = $registry;
    }

    /**
     * Get all records (equivalent to Model.objects.all()).
     */
    public function all(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM {$this->quoteIdentifier($this->table->name, true)}");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get the first record matching the where conditions.
     */
    public function first(array $where = []): ?array
    {
        $rows = $this->where($where, 1);
        return $rows[0] ?? null;
    }

    /**
     * Find a record by a specific column value (usually id).
     */
    public function find(mixed $value, string $column = 'id'): ?array
    {
        return $this->first([$column => $value]);
    }

    /**
     * Get records matching conditions with optional limit.
     */
    public function where(array $where, ?int $limit = null): array
    {
        $sql = "SELECT * FROM {$this->quoteIdentifier($this->table->name, true)}";
        $params = [];

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', array_map(
                fn($key) => $this->quoteIdentifier((string) $key) . ' = ?',
                array_keys($where)
            ));
            $params = array_values($where);
        }

        if ($limit !== null) {
            $sql .= ' LIMIT ' . $limit;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Create a new record (equivalent to Model.objects.create()).
     */
    public function create(array $data): void
    {
        $this->insert($data);
    }

    /**
     * Delete records matching conditions.
     */
    public function delete(array $where): void
    {
        $whereSql = implode(' AND ', array_map(fn($k) => $this->quoteIdentifier((string) $k) . ' = ?', array_keys($where)));
        $sql = "DELETE FROM {$this->quoteIdentifier($this->table->name, true)} WHERE {$whereSql}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($where));
    }

    /**
     * Count records matching conditions.
     */
    public function count(array $where = []): int
    {
        $sql = "SELECT COUNT(*) FROM {$this->quoteIdentifier($this->table->name, true)}";
        $params = [];

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', array_map(
                fn($key) => $this->quoteIdentifier((string) $key) . ' = ?',
                array_keys($where)
            ));
            $params = array_values($where);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Check if any records exist matching conditions.
     */
    public function exists(array $where = []): bool
    {
        return $this->count($where) > 0;
    }

    /**
     * Get a single value for a column.
     */
    public function value(string $column, array $where = []): mixed
    {
        $row = $this->first($where);
        return $row[$column] ?? null;
    }

    /**
     * Get all values for a specific column.
     *
     * @return array
     */
    public function values(string $column): array
    {
        $sql = "SELECT {$this->quoteIdentifier($column)} FROM {$this->quoteIdentifier($this->table->name, true)}";
        $stmt = $this->pdo->query($sql);
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return array_column($results, $column);
    }

    /**
     * Get records ordered by a column.
     */
    public function orderBy(string $column, string $direction = 'ASC', ?int $limit = null): array
    {
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $sql = "SELECT * FROM {$this->quoteIdentifier($this->table->name, true)} ORDER BY {$this->quoteIdentifier($column)} {$direction}";
        if ($limit !== null) {
            $sql .= ' LIMIT ' . $limit;
        }

        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get the table name for this model.
     */
    public function getTableName(): string
    {
        return $this->table->name;
    }

    /**
     * Get the model name from options, falling back to table name.
     */
    public function getModelName(): string
    {
        return $this->table->options['model_name'] ?? $this->table->name;
    }

    /**
     * Get the app label for this model.
     */
    public function getAppLabel(): string
    {
        return $this->table->options['app_label'] ?? '';
    }

    /**
     * Get all field names for this model.
     *
     * @return string[]
     */
    public function getFieldNames(): array
    {
        return array_keys($this->table->getColumns());
    }

    /**
     * Get all columns (fields) for this model.
     *
     * @return \Nudelsalat\Schema\Column[]
     */
    public function getFields(): array
    {
        return $this->table->getColumns();
    }

    /**
     * Get a specific field (column) by name.
     */
    public function getField(string $name): ?\Nudelsalat\Schema\Column
    {
        return $this->table->getColumns()[$name] ?? null;
    }

    /**
     * Get all foreign keys for this model.
     *
     * @return \Nudelsalat\Schema\ForeignKeyConstraint[]
     */
    public function getForeignKeys(): array
    {
        return $this->table->getForeignKeys();
    }

    /**
     * Get all indexes for this model.
     *
     * @return \Nudelsalat\Schema\Index[]
     */
    public function getIndexes(): array
    {
        return $this->table->getIndexes();
    }

    /**
     * Get all constraints for this model.
     *
     * @return \Nudelsalat\Schema\Constraint[]
     */
    public function getConstraints(): array
    {
        return $this->table->getConstraints();
    }

    /**
     * Get model options (Meta).
     */
    public function getOptions(): array
    {
        return $this->table->options;
    }

    /**
     * Get a related model instance by following a foreign key.
     * Similar to model.related_object access.
     */
    public function related(string $column, array $row): ?array
    {
        $foreignKey = $this->table->getForeignKeys()["fk_{$this->table->name}_{$column}"] ?? null;
        if ($foreignKey === null) {
            foreach ($this->table->getForeignKeys() as $candidate) {
                if ($candidate->column === $column) {
                    $foreignKey = $candidate;
                    break;
                }
            }
        }

        if ($foreignKey === null) {
            return null;
        }

        $value = $row[$column] ?? null;
        if ($value === null) {
            return null;
        }

        return $this->registry->getModel($foreignKey->toTable)->find($value, $foreignKey->toColumn);
    }

    /**
     * Get all related objects (records in other tables that reference this model).
     * Similar to model_set accessor.
     *
     * @return array<string, array> Keys are relation names, values are arrays of records
     */
    public function getRelatedObjects(array $row): array
    {
        $related = [];
        
        foreach ($this->table->getForeignKeys() as $fkName => $fk) {
            $value = $row[$fk->column] ?? null;
            if ($value !== null) {
                // This model has a FK to another model
            }
        }
        
        // Find models that reference this model
        $referencingModels = $this->registry->getModelsWithRelationTo($this->table->name);
        
        foreach ($referencingModels as $model) {
            $column = null;
            foreach ($model->getForeignKeys() as $fk) {
                if (strtolower($fk->toTable) === strtolower($this->table->name)) {
                    $column = $fk->column;
                    break;
                }
            }
            
            if ($column && isset($row['id'])) {
                $related[$model->getModelName()] = $model->where([$column => $row['id']]);
            }
        }
        
        return $related;
    }

    /**
     * Insert a new record.
     */
    public function insert(array $data): void
    {
        $cols = implode(', ', array_map(fn($k) => $this->quoteIdentifier((string) $k), array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        
        $sql = "INSERT INTO {$this->quoteIdentifier($this->table->name, true)} ($cols) VALUES ($placeholders)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($data));
    }

    /**
     * Update records matching conditions.
     */
    public function update(array $data, array $where): void
    {
        $set = implode(', ', array_map(fn($k) => $this->quoteIdentifier((string) $k) . ' = ?', array_keys($data)));
        $whereSql = implode(' AND ', array_map(fn($k) => $this->quoteIdentifier((string) $k) . ' = ?', array_keys($where)));
        
        $sql = "UPDATE {$this->quoteIdentifier($this->table->name, true)} SET $set WHERE $whereSql";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge(array_values($data), array_values($where)));
    }

    /**
     * Execute a raw SQL query and return results.
     *
     * @return array
     */
    public function raw(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get the primary key column name for this model.
     */
    public function getPkColumnName(): string
    {
        foreach ($this->table->getColumns() as $name => $column) {
            if ($column->primaryKey) {
                return $name;
            }
        }
        return 'id'; // Default fallback
    }

    private function quoteIdentifier(string $name, bool $allowQualified = false): string
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($allowQualified && str_contains($name, '.')) {
            $parts = explode('.', $name);
            return implode('.', array_map(fn(string $part): string => $this->quoteIdentifier($part, false), $parts));
        }

        return match ($driver) {
            'pgsql', 'sqlite' => '"' . str_replace('"', '""', $name) . '"',
            default => '`' . str_replace('`', '``', $name) . '`',
        };
    }
}