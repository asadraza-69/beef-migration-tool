<?php

namespace Nudelsalat\ORM;

use Nudelsalat\Schema\Column;
use Nudelsalat\Schema\Constraint;
use Nudelsalat\Schema\Index;
use Nudelsalat\ORM\Signal;

/**
 * Model - Base class for all ORM models
 * 
 * Provides:
 * - Automatic table name resolution
 * - Field definitions via static fields() method
 * - Meta class for model configuration
 * - Manager for CRUD operations via objects property
 * - Support for abstract and proxy models
 * 
 * Usage:
 * ```php
 * class User extends Model
 * {
 *     public static function fields(): array
 *     {
 *         return [
 *             'id' => new AutoField(),
 *             'name' => new StringField(100),
 *         ];
 *     }
 * }
 * 
 * // CRUD operations
 * User::all();           // Get all records
 * User::get(1);          // Get by ID
 * User::create([...]);  // Create new record
 * User::filter([...]);  // Filter records
 * ```
 */
abstract class Model
{
    /** @var Meta Model configuration */
    public static Meta $meta;
    
    /** @var Manager Default manager for CRUD operations */
    public static Manager $objects;
    
    /**
     * Define model fields.
     * 
     * Each field should be an instance of a Field class:
     * - IntField, AutoField, BigAutoField, SmallAutoField
     * - StringField, TextField, SlugField, EmailField, URLField
     * - BooleanField
     * - DateField, DateTimeField, TimeField
     * - DecimalField, FloatField
     * - UUIDField
     * - BinaryField, JSONField
     * - ForeignKey, ManyToManyField
     * 
     * @return \Nudelsalat\Migrations\Fields\Field[]
     */
    abstract public static function fields(): array;

    /**
     * Get the database table name.
     * 
     * Uses:
     * 1. Meta::$dbTable if set
     * 2. Model class short name lowercased (User -> user)
     * 3. Returns null for abstract models
     * 
     * @return string|null The table name
     */
    public static function getTableName(): ?string
    {
        $meta = static::resolveMeta();
        if ($meta->dbTable) {
            return $meta->dbTable;
        }
        if ($meta->abstract) {
            return null;
        }
        // Default to lowercase short name
        return strtolower((new \ReflectionClass(static::class))->getShortName());
    }

    /**
     * Get model options (alias for backward compatibility).
     * 
     * @deprecated Use resolveMeta() instead
     * @return array
     */
    public static function getOptions(): array
    {
        return static::$options ?? [];
    }

    /**
     * Resolve and return the Meta class.
     * 
     * Creates a new Meta instance if one doesn't exist.
     * Reads from model's $options array for backward compatibility.
     * 
     * @return Meta The model's Meta configuration
     */
    public static function resolveMeta(): Meta
    {
        if (!isset(static::$meta)) {
            $options = static::$options ?? [];
            
            static::$meta = new Meta(
                dbTable: $options['db_table'] ?? null,
                dbTableComment: $options['db_table_comment'] ?? null,
                ordering: $options['ordering'] ?? null,
                uniqueTogether: $options['unique_together'] ?? null,
                permissions: $options['permissions'] ?? null,
                getLatestBy: $options['get_latest_by'] ?? null,
                orderWithRespectTo: $options['order_with_respect_to'] ?? null,
                appLabel: $options['app_label'] ?? null,
                dbTablespace: $options['db_tablespace'] ?? null,
                abstract: $options['abstract'] ?? false,
                managed: $options['managed'] ?? true,
                proxy: $options['proxy'] ?? false,
                swappable: $options['swappable'] ?? null,
                baseManagerName: $options['base_manager_name'] ?? null,
                defaultManagerName: $options['default_manager_name'] ?? null,
                indexes: $options['indexes'] ?? [],
                constraints: $options['constraints'] ?? [],
                verboseName: $options['verbose_name'] ?? null,
                verboseNamePlural: $options['verbose_name_plural'] ?? null,
                defaultPermissions: $options['default_permissions'] ?? null,
                selectOnSave: $options['select_on_save'] ?? null,
                defaultRelatedName: $options['default_related_name'] ?? null,
                requiredDbFeatures: $options['required_db_features'] ?? null,
                requiredDbVendor: $options['required_db_vendor'] ?? null,
            );
        }
        return static::$meta;
    }

    /**
     * Get the primary key field name.
     * 
     * @return string The primary key field name
     */
    public static function getPkName(): ?string
    {
        foreach (static::fields() as $name => $field) {
            if ($field->primaryKey ?? false) {
                return $name;
            }
        }
        return 'id';
    }

    /**
     * Check if this model uses auto-incrementing primary key.
     * 
     * @return bool True if model uses auto-increment
     */
    public static function usesAutoIncrement(): bool
    {
        foreach (static::fields() as $field) {
            if (($field->primaryKey ?? false) && ($field->autoIncrement ?? false)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get all indexes defined in Meta.
     * 
     * @return Index[]
     */
    public static function getIndexes(): array
    {
        $meta = static::resolveMeta();
        return $meta->indexes;
    }

    /**
     * Get all constraints defined in Meta.
     * 
     * @return Constraint[]
     */
    public static function getConstraints(): array
    {
        $meta = static::resolveMeta();
        return $meta->constraints;
    }

    /**
     * Get the Manager instance for this model.
     * 
     * Creates a new Manager if one doesn't exist.
     * Supports custom managers via $options['manager_class'].
     * 
     * @return Manager
     */
    public static function getObjectManager(): Manager
    {
        if (!isset(static::$objects)) {
            $options = static::$options ?? [];
            $managerClass = $options['manager_class'] ?? Manager::class;
            
            static::$objects = new $managerClass(static::class);
        }
        
        if (!(static::$objects instanceof Manager)) {
            static::$objects = new Manager(static::class);
        }
        
        return static::$objects;
    }

    /**
     * Get a QuerySet for chainable queries.
     * 
     * @return QuerySet
     */
    public static function objects(): QuerySet
    {
        return static::getObjectManager()->objects();
    }

    // ============================================================
    // Static CRUD methods (proxy to Manager)
    // ============================================================

    /**
     * Get all records from the table.
     * 
     * @return array All records as associative arrays
     */
    public static function all(): array
    {
        return static::getObjectManager()->all();
    }

    /**
     * Get a single record by primary key.
     * 
     * @param int|string $pk The primary key value
     * @return array|null The record or null if not found
     */
    public static function get(int|string $pk): ?array
    {
        return static::getObjectManager()->get($pk);
    }

    /**
     * Filter records by conditions.
     * 
     * @param array $conditions Field => value pairs
     * @return array Matching records
     */
    public static function filter(array $conditions): array
    {
        return static::getObjectManager()->filter($conditions);
    }

    /**
     * Create a new record.
     * 
     * @param array $data Field => value pairs
     * @return array The created record
     */
    public static function create(array $data): array
    {
        return static::getObjectManager()->create($data);
    }

    /**
     * Update records matching conditions.
     * 
     * @param array $data Field => new value pairs
     * @param array $conditions Field => value pairs
     * @return int Number of affected rows
     */
    public static function update(array $data, array $conditions): int
    {
        return static::getObjectManager()->update($data, $conditions);
    }

    /**
     * Delete records matching conditions.
     * 
     * @param array $conditions Field => value pairs
     * @return int Number of deleted rows
     */
    public static function delete(array $conditions): int
    {
        return static::getObjectManager()->delete($conditions);
    }

    /**
     * Count total records.
     * 
     * @return int Total count
     */
    public static function count(): int
    {
        return static::getObjectManager()->count();
    }

    /**
     * Check if any records exist.
     * 
     * @return bool True if records exist
     */
    public static function exists(): bool
    {
        return static::getObjectManager()->exists();
    }

    /**
     * Bulk create multiple records.
     * 
     * @param array[] $records Array of field => value arrays
     * @param bool $batch Use batch insert if true
     * @return array[] The created records
     */
    public static function bulkCreate(array $records, bool $batch = false): array
    {
        return static::getObjectManager()->bulkCreate($records, $batch);
    }

    /**
     * Bulk update multiple records.
     * 
     * @param array[] $records Array of field => value arrays with PK
     * @param array $fields Fields to update
     * @return int Number of affected rows
     */
    public static function bulkUpdate(array $records, array $fields): int
    {
        return static::getObjectManager()->bulkUpdate($records, $fields);
    }

    /**
     * Aggregate functions (sum, avg, min, max, count).
     * 
     * @param Aggregation[] $aggs
     * @return array Result associative array
     */
    public static function aggregate(array $aggs): array
    {
        return static::getObjectManager()->aggregate($aggs);
    }

    /**
     * Execute raw SQL query.
     * 
     * @param string $sql
     * @param array $params
     * @return array
     */
    public static function raw(string $sql, array $params = []): array
    {
        return static::getObjectManager()->raw($sql, $params);
    }

    /**
     * Get or create a record.
     * 
     * @param array $lookup Lookup conditions
     * @param array $defaults Default values for creation
     * @return array The record (existing or newly created)
     */
    public static function getOrCreate(array $lookup, array $defaults = []): array
    {
        $existing = static::filter($lookup);
        
        if (!empty($existing)) {
            return $existing[0];
        }
        
        return static::create(array_merge($lookup, $defaults));
    }

    /**
     * Update or create a record.
     * 
     * @param array $lookup Lookup conditions
     * @param array $updateValues Values to update/create
     * @return array The record
     */
    public static function updateOrCreate(array $lookup, array $updateValues): array
    {
        $existing = static::filter($lookup);
        
        if (!empty($existing)) {
            static::update($updateValues, $lookup);
            return static::get($existing[0]['id']);
        }
        
        return static::create(array_merge($lookup, $updateValues));
    }

    /**
     * Paginate query results.
     * 
     * @param array $items
     * @param int $perPage
     * @param int $page
     * @return Paginator
     */
    public static function paginate(array $items, int $perPage = 10, int $page = 1): Paginator
    {
        return new Paginator($items, $perPage, $page);
    }

    /**
     * Check if this is an abstract model.
     * 
     * Abstract models don't have database tables but can be inherited.
     * 
     * @return bool
     */
    public static function isAbstract(): bool
    {
        $meta = static::resolveMeta();
        return $meta->abstract;
    }

    /**
     * Check if this is a proxy model.
     * 
     * Proxy models don't have their own table but mirror another model.
     * 
     * @return bool
     */
    public static function isProxy(): bool
    {
        $meta = static::resolveMeta();
        return $meta->proxy;
    }

    /**
     * Get the concrete model that this model proxies to.
     * 
     * Only applicable for proxy models.
     * 
     * @return string|null The parent model class
     */
    public static function getProxyFor(): ?string
    {
        return static::$options['proxy_for'] ?? null;
    }

    /**
     * Check if this is a child model (multi-table inheritance).
     * 
     * @return bool
     */
    public static function isChildModel(): bool
    {
        return isset(static::$options['parent_link']);
    }

    /**
     * Get the parent model class for multi-table inheritance.
     * 
     * @return string|null
     */
    public static function getParentLink(): ?string
    {
        return static::$options['parent_link'] ?? null;
    }

    /**
     * Validate the model instance.
     * 
     * Override in subclass to add custom validation.
     * 
     * @return array Array of validation errors (field => error message)
     */
    public static function validate(array $data, bool $skipAutoIncrement = false): array
    {
        $errors = [];
        
        $fields = static::fields();
        foreach ($fields as $name => $field) {
            if ($skipAutoIncrement && ($field->autoIncrement ?? false)) {
                continue;
            }
            
            $value = $data[$name] ?? null;
            
            $isNullable = $field->nullable ?? false;
            $hasDefault = $field->default !== null;
            $isAutoIncrement = $field->autoIncrement ?? false;
            
            if (!$isNullable && !$hasDefault && !$isAutoIncrement && $value === null) {
                $errors[$name] = "Field '{$name}' cannot be null";
            }
        }
        
        $customErrors = static::cleanFields($data);
        $errors = array_merge($errors, $customErrors);
        
        return $errors;
    }

    /**
     * Run custom field validation.
     * 
     * Override in subclass to add custom field validation.
     * 
     * @param array $data
     * @return array
     */
    protected static function cleanFields(array $data): array
    {
        return [];
    }

    /**
     * Run full model validation.
     * 
     * Override in subclass to add model-level validation.
     * 
     * @param array $data
     * @return array
     */
    protected static function clean(array $data): array
    {
        return static::validate($data);
    }
}

/**
 * Meta - Model-level configuration
 * 
 * Defines model metadata such as:
 * - Table configuration (name, comment, tablespace)
 * - Data ordering (ordering field)
 * - Constraints (unique_together, indexes, constraints)
 * - Model behavior (abstract, proxy, managed)
 * - Display options (verbose_name, verbose_name_plural)
 * 
 * @property string|null $dbTable Custom table name
 * @property string|null $dbTableComment Table comment
 * @property array|null $ordering Default ordering
 * @property array|null $uniqueTogether Unique field combinations
 * @property array|null $permissions Custom permissions
 * @property string|null $getLatestBy Field for get_latest()
 * @property string|null $orderWithRespectTo Field for ordering
 * @property string|null $appLabel Application label
 * @property string|null $dbTablespace Database tablespace
 * @property bool $abstract Is abstract model?
 * @property bool $managed Should migrations manage this table?
 * @property bool $proxy Is proxy model?
 * @property array $indexes Index definitions
 * @property array $constraints Constraint definitions
 * @property string|null $verboseName Human-readable name
 * @property string|null $verboseNamePlural Plural human-readable name
 */
class Meta
{
    public function __construct(
        public ?string $dbTable = null,
        public ?string $dbTableComment = null,
        public ?array $ordering = null,
        public ?array $uniqueTogether = null,
        public ?array $permissions = null,
        public ?string $getLatestBy = null,
        public ?string $orderWithRespectTo = null,
        public ?string $appLabel = null,
        public ?string $dbTablespace = null,
        public bool $abstract = false,
        public bool $managed = true,
        public bool $proxy = false,
        public ?string $swappable = null,
        public ?string $baseManagerName = null,
        public ?string $defaultManagerName = null,
        public array $indexes = [],
        public array $constraints = [],
        public ?string $verboseName = null,
        public ?string $verboseNamePlural = null,
        public ?array $defaultPermissions = null,
        public ?bool $selectOnSave = null,
        public ?string $defaultRelatedName = null,
        public ?array $requiredDbFeatures = null,
        public ?string $requiredDbVendor = null,
    ) {}
}

/**
 * Manager - Database query interface for models
 * 
 * Provides chainable CRUD operations:
 * - all()         - Get all records
 * - filter()      - Filter by conditions
 * - get()        - Get by primary key
 * - create()     - Insert new record
 * - update()      - Update records
 * - delete()     - Delete records
 * - count()      - Count records
 * - exists()     - Check if records exist
 * 
 * Usage:
 * ```php
 * $users = User::all();
 * $activeUsers = User::filter(['is_active' => true]);
 * $user = User::get(1);
 * $newUser = User::create(['name' => 'John']);
 * ```
 */
class Manager
{
    /** @var Model The model class this manager handles */
    private Model $model;
    
    /** @var string|null Optional database connection name */
    private ?string $connection = null;

    /**
     * Create a new Manager for the given model.
     * 
     * @param Model|string $model The model class or instance
     * @param string|null $connection Optional connection name
     */
    public function __construct(Model|string $model, ?string $connection = null)
    {
        if (is_string($model)) {
            $model = new $model();
        }
        $this->model = $model;
        $this->connection = $connection;
    }

    /**
     * Get a QuerySet for chainable queries.
     * 
     * @return QuerySet
     */
    public function objects(): QuerySet
    {
        return new QuerySet($this->model, $this->getConnection());
    }

    /**
     * Get all records from the table.
     * 
     * @return array All records as associative arrays
     */
    public function all(): array
    {
        $table = $this->model::getTableName();
        if ($table === null) {
            throw new \RuntimeException('Cannot query abstract model: ' . static::class);
        }
        $pdo = $this->getConnection();
        
        $stmt = $pdo->query("SELECT * FROM {$table}");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Filter records by conditions.
     * 
     * Supports both exact matches and IN queries:
     * ```php
     * $manager->filter(['status' => 'active']);
     * $manager->filter(['id' => [1, 2, 3]]);  // IN query
     * ```
     * 
     * @param array $conditions Field => value pairs
     * @return array Matching records
     */
    public function filter(array $conditions): array
    {
        $table = $this->model::getTableName();
        $pdo = $this->getConnection();
        
        if (empty($conditions)) {
            return $this->all();
        }
        
        $where = [];
        $values = [];
        
        foreach ($conditions as $field => $value) {
            if (is_array($value)) {
                $placeholders = implode(',', array_fill(0, count($value), '?'));
                $where[] = "{$field} IN ({$placeholders})";
                $values = array_merge($values, $value);
            } else {
                $where[] = "{$field} = ?";
                $values[] = $value;
            }
        }
        
        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where);
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get a record by primary key.
     * 
     * @param int|string $pk The primary key value
     * @return array|null The record or null if not found
     */
    public function get(int|string $pk): ?array
    {
        $table = $this->model::getTableName();
        $pkName = $this->model::getPkName() ?? 'id';
        $pdo = $this->getConnection();
        
        $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE {$pkName} = ?");
        $stmt->execute([$pk]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Create a new record.
     * 
     * @param array $data Field => value pairs
     * @return array The created record (with ID if auto-increment)
     */
    public function create(array $data): array
    {
        $table = $this->model::getTableName();
        $pdo = $this->getConnection();
        
        if (empty($data)) {
            throw new \InvalidArgumentException('Cannot create empty record');
        }
        
        Signal::send('pre_init', [$this->model, &$data]);
        
        $errors = $this->model::validate($data, true);
        if (!empty($errors)) {
            throw new \InvalidArgumentException('Validation failed: ' . json_encode($errors));
        }
        
        $fields = array_keys($data);
        $placeholders = array_fill(0, count($data), '?');
        
        Signal::send('pre_save', [$this->model, $data, false]);
        
        $sql = sprintf(
            "INSERT INTO %s (%s) VALUES (%s) RETURNING *",
            $table,
            implode(', ', $fields),
            implode(', ', $placeholders)
        );
        
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_values($data));
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($result) {
                Signal::send('post_save', [$this->model, $result, false]);
                return $result;
            }
        } catch (\PDOException $e) {
        }
        
        $sql = sprintf(
            "INSERT INTO %s (%s) VALUES (%s)",
            $table,
            implode(', ', $fields),
            implode(', ', $placeholders)
        );
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($data));
        
        $result = $this->get((int)$pdo->lastInsertId());
        Signal::send('post_save', [$this->model, $result, false]);
        
        return $result;
    }

    /**
     * Update records matching conditions.
     * 
     * @param array $data Field => new value pairs
     * @param array $conditions Field => value pairs
     * @return int Number of affected rows
     */
    public function update(array $data, array $conditions): int
    {
        $table = $this->model::getTableName();
        $pdo = $this->getConnection();
        
        if (empty($data)) {
            return 0;
        }
        
        $set = [];
        $values = [];
        
        foreach (array_keys($data) as $field) {
            $set[] = "{$field} = ?";
            $values[] = $data[$field];
        }
        
        if (!empty($conditions)) {
            $where = [];
            foreach ($conditions as $field => $value) {
                $where[] = "{$field} = ?";
                $values[] = $value;
            }
            $sql = sprintf(
                "UPDATE %s SET %s WHERE %s",
                $table,
                implode(', ', $set),
                implode(' AND ', $where)
            );
        } else {
            // No conditions = update all (dangerous!)
            $sql = sprintf(
                "UPDATE %s SET %s",
                $table,
                implode(', ', $set)
            );
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
        return $stmt->rowCount();
    }

    /**
     * Delete records matching conditions.
     * 
     * @param array $conditions Field => value pairs
     * @return int Number of deleted rows
     */
    public function delete(array $conditions): int
    {
        $table = $this->model::getTableName();
        $pdo = $this->getConnection();
        
        if (empty($conditions)) {
            return 0;
        }
        
        $where = [];
        $values = [];
        
        foreach ($conditions as $field => $value) {
            $where[] = "{$field} = ?";
            $values[] = $value;
        }
        
        Signal::send('pre_delete', [$this->model, $conditions]);
        
        $sql = sprintf(
            "DELETE FROM %s WHERE %s",
            $table,
            implode(' AND ', $where)
        );
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
        $count = $stmt->rowCount();
        
        Signal::send('post_delete', [$this->model, $conditions, $count]);
        
        return $count;
    }

    /**
     * Count total records.
     * 
     * @return int Total count
     */
    public function count(): int
    {
        $table = $this->model::getTableName();
        $pdo = $this->getConnection();
        
        $stmt = $pdo->query("SELECT COUNT(*) FROM {$table}");
        return (int)$stmt->fetchColumn();
    }

    /**
     * Check if any records exist.
     * 
     * @return bool True if at least one record exists
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Aggregate functions (sum, avg, min, max, count).
     * 
     * @param Aggregation[] $aggs
     * @return array
     */
    public function aggregate(array $aggs): array
    {
        $table = $this->model::getTableName();
        $pdo = $this->getConnection();
        
        $selectParts = [];
        foreach ($aggs as $agg) {
            $selectParts[] = $agg->getSql();
        }
        
        $sql = "SELECT " . implode(', ', $selectParts) . " FROM {$table}";
        
        $stmt = $pdo->query($sql);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * Execute raw SQL query.
     * 
     * @param string $sql
     * @param array $params
     * @return array
     */
    public function raw(string $sql, array $params = []): array
    {
        $pdo = $this->getConnection();
        
        if (empty($params)) {
            $stmt = $pdo->query($sql);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Bulk create multiple records.
     * 
     * @param array[] $records Array of field => value arrays
     * @param bool $batch Use batch insert
     * @return array[] The created records
     */
    public function bulkCreate(array $records, bool $batch = false): array
    {
        if (empty($records)) {
            return [];
        }

        $table = $this->model::getTableName();
        $pdo = $this->getConnection();
        
        $created = [];
        
        if ($batch) {
            $fields = array_keys($records[0]);
            $placeholders = implode(',', array_fill(0, count($fields), '?'));
            
            $sql = sprintf(
                "INSERT INTO %s (%s) VALUES (%s)",
                $table,
                implode(', ', $fields),
                $placeholders
            );
            
            $stmt = $pdo->prepare($sql);
            
            foreach ($records as $record) {
                $stmt->execute(array_values($record));
            }
        } else {
            foreach ($records as $record) {
                $created[] = $this->create($record);
            }
        }
        
        return $created;
    }

    /**
     * Bulk update multiple records.
     * 
     * @param array[] $records Array of field => value arrays with PK
     * @param array $fields Fields to update
     * @return int Number of affected rows
     */
    public function bulkUpdate(array $records, array $fields): int
    {
        if (empty($records) || empty($fields)) {
            return 0;
        }

        $table = $this->model::getTableName();
        $pdo = $this->getConnection();
        $pkName = $this->model::getPkName() ?? 'id';
        
        $total = 0;
        
        foreach ($records as $record) {
            if (!isset($record[$pkName])) {
                continue;
            }
            $pk = $record[$pkName];
            $updateData = array_intersect_key($record, array_flip($fields));
            
            $total += $this->update($updateData, [$pkName => $pk]);
        }
        
        return $total;
    }

    /**
     * Get the database connection.
     * 
     * @return \PDO The PDO connection
     */
    private function getConnection(): \PDO
    {
        // Get from Bootstrap
        $bootstrap = \Nudelsalat\Bootstrap::getInstance();
        return $bootstrap->getPdo();
    }
}

/**
 * CheckConstraint - SQL CHECK constraint
 * 
 * Defines a CHECK constraint on the model table.
 * 
 * @property string $name Constraint name
 * @property string $condition SQL condition expression
 * @property string|null $message Custom error message
 */
class CheckConstraint
{
    public function __construct(
        public string $name,
        public string $condition,
        public ?string $message = null,
    ) {}
}

/**
 * UniqueConstraint - UNIQUE constraint
 * 
 * Defines a UNIQUE constraint on specific fields.
 * 
 * @property string $name Constraint name
 * @property array $fields Field names that must be unique together
 * @property string|null $condition Optional SQL condition
 * @property string|null $message Custom error message
 */
class UniqueConstraint
{
    public function __construct(
        public string $name,
        public array $fields,
        public ?string $condition = null,
        public ?string $message = null,
    ) {}
}

/**
 * AbstractModel - Base class for abstract models
 * 
 * Abstract models don't have their own database table.
 * Instead, their fields are inherited by concrete child models.
 * 
 * Usage:
 * ```php
 * abstract class Person extends AbstractModel
 * {
 *     public static function fields(): array
 *     {
 *         return [
 *             'id' => new AutoField(),
 *             'name' => new StringField(100),
 *             'email' => new StringField(255),
 *         ];
 *     }
 * }
 * 
 * class User extends AbstractModel
 * {
 *     public static array $options = [
 *         'abstract' => true,
 *     ];
 *     
 *     public static function fields(): array
 *     {
 *         return [
 *             'id' => new AutoField(),
 *             'name' => new StringField(100),
 *             'email' => new StringField(255),
 *             'created_at' => new DateTimeField(),
 *         ];
 *     }
 * }
 * ```
 */
abstract class AbstractModel extends Model
{
    /**
     * Get the database table name.
     * 
     * Abstract models don't have tables - inherited models use their own.
     * 
     * @return string|null
     */
    public static function getTableName(): ?string
    {
        return null;
    }

    /**
     * Check if this is an abstract model.
     * 
     * @return bool
     */
    public static function isAbstract(): bool
    {
        return true;
    }
}

/**
 * ProxyModel - Base class for proxy models
 * 
 * Proxy models don't have their own database table.
 * They provide a different interface to a concrete parent model.
 * 
 * Usage:
 * ```php
 * class User extends Model
 * {
 *     public static function fields(): array
 *     {
 *         return [...];
 *     }
 * }
 * 
 * class ActiveUser extends ProxyModel
 * {
 *     public static array $options = [
 *         'proxy' => true,
 *         'proxy_for' => User::class,
 *     ];
 *     
 *     public static function fields(): array
 *     {
 *         return [
 *             // Inherits all fields from User
 *         ];
 *     }
 * }
 * ```
 */
abstract class ProxyModel extends Model
{
    /**
     * Get the database table name.
     * 
     * Proxy models use the parent model's table.
     * 
     * @return string|null
     */
    public static function getTableName(): ?string
    {
        $proxyFor = static::getProxyFor();
        if ($proxyFor && class_exists($proxyFor)) {
            return $proxyFor::getTableName();
        }
        return parent::getTableName();
    }

    /**
     * Check if this is a proxy model.
     * 
     * @return bool
     */
    public static function isProxy(): bool
    {
        return true;
    }
}