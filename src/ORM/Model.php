<?php

namespace Nudelsalat\ORM;

use Nudelsalat\Schema\Constraint;
use Nudelsalat\Schema\Index;

/**
 * Model - Base class for all ORM models
 */
#[\AllowDynamicProperties]
abstract class Model
{
    public static Meta $meta;
    public static Manager $objects;
    public static array $options = [];

    /**
     * Define model fields.
     *
     * @return \Nudelsalat\Migrations\Fields\Field[]
     */
    abstract public static function fields(): array;

    public static function getTableName(): ?string
    {
        $meta = static::resolveMeta();

        if ($meta->dbTable) {
            return $meta->dbTable;
        }

        if ($meta->abstract) {
            return null;
        }

        return strtolower((new \ReflectionClass(static::class))->getShortName());
    }

    public static function getOptions(): array
    {
        return static::$options ?? [];
    }

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

    public static function getPkName(): ?string
    {
        foreach (static::fields() as $name => $field) {
            if ($field->primaryKey ?? false) {
                return $name;
            }
        }

        return 'id';
    }

    public function toArray(): array
    {
        $values = [];
        foreach (static::fields() as $name => $field) {
            $values[$name] = $this->$name ?? null;
        }
        return $values;
    }

    public static function usesAutoIncrement(): bool
    {
        foreach (static::fields() as $field) {
            if (($field->primaryKey ?? false) && ($field->autoIncrement ?? false)) {
                return true;
            }
        }
        return false;
    }

    public static function getIndexes(): array
    {
        $meta = static::resolveMeta();
        return $meta->indexes;
    }

    public static function getConstraints(): array
    {
        $meta = static::resolveMeta();
        return $meta->constraints;
    }

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

    public static function objects(): QuerySet
    {
        return static::getObjectManager()->objects();
    }

    public static function query(): QuerySet
    {
        return static::objects();
    }

    /**
     * Hydrate a model instance from a row array.
     */
    public static function hydrate(array $row): static
    {
        $obj = new static();
        foreach ($row as $k => $v) {
            $obj->$k = $v;
        }
        return $obj;
    }

    public static function all(): array
    {
        return static::getObjectManager()->all();
    }

    public static function get(int|string $pk): ?Model
    {
        return static::getObjectManager()->get($pk);
    }

    public static function filter(array $conditions): array
    {
        return static::getObjectManager()->filter($conditions);
    }

    public static function where(array $conditions): array
    {
        return static::filter($conditions);
    }

    public static function first(?array $conditions = null): ?Model
    {
        return static::getObjectManager()->first($conditions);
    }

    public static function create(array $data): ?Model
    {
        $result = static::getObjectManager()->create($data);

        if ($result === null) {
            return null;
        }

        if ($result instanceof Model) {
            return $result;
        }

        if (is_array($result)) {
            return static::hydrate($result);
        }

        return null;
    }

    public static function update(array $data, array $conditions): int
    {
        return static::getObjectManager()->update($data, $conditions);
    }

    /**
     * Delete records matching conditions.
     */
    public static function deleteWhere(array $conditions): int
    {
        return static::getObjectManager()->delete($conditions);
    }

    /**
     * Backward-compat for old static calls like Model::delete([...]).
     */
    public static function __callStatic(string $name, array $arguments): mixed
    {
        if ($name === 'delete') {
            $conditions = $arguments[0] ?? null;

            if (!is_array($conditions)) {
                throw new \InvalidArgumentException('Static delete() expects an array of conditions.');
            }

            return static::deleteWhere($conditions);
        }

        throw new \BadMethodCallException('Call to undefined static method ' . static::class . '::' . $name . '()');
    }

    /**
     * Save this instance. Updates if PK exists, creates otherwise.
     */
    public function save(): static
    {
        $pkName = static::getPkName() ?? 'id';
        $data = $this->toArray();

        if (!empty($this->$pkName ?? null)) {
            $pkValue = $this->$pkName;
            $updateData = $data;
            unset($updateData[$pkName]);

            static::update($updateData, [$pkName => $pkValue]);

            $fresh = static::get($pkValue);
            if ($fresh instanceof static) {
                foreach ($fresh->toArray() as $k => $v) {
                    $this->$k = $v;
                }
            }

            return $this;
        }

        $created = static::create($data);
        if ($created instanceof static) {
            foreach ($created->toArray() as $k => $v) {
                $this->$k = $v;
            }
        }

        return $this;
    }

    /**
     * Refresh this instance from the database.
     */
    public function refresh(): static
    {
        $pkName = static::getPkName() ?? 'id';
        $pkValue = $this->$pkName ?? null;

        if ($pkValue === null) {
            throw new \RuntimeException('Cannot refresh model without primary key.');
        }

        $fresh = static::get($pkValue);
        if (!$fresh instanceof static) {
            throw new \RuntimeException('Model no longer exists in database.');
        }

        foreach ($fresh->toArray() as $k => $v) {
            $this->$k = $v;
        }

        return $this;
    }

    /**
     * Delete this instance by primary key.
     */
    public function delete(): int
    {
        $pkName = static::getPkName() ?? 'id';
        $pkValue = $this->$pkName ?? null;

        if ($pkValue === null) {
            throw new \RuntimeException('Cannot delete model without primary key.');
        }

        return static::getObjectManager()->delete([$pkName => $pkValue]);
    }

    public static function count(): int
    {
        return static::getObjectManager()->count();
    }

    public static function exists(): bool
    {
        return static::getObjectManager()->exists();
    }

    public static function bulkCreate(array $records, bool $batch = false): array
    {
        $rows = static::getObjectManager()->bulkCreate($records, $batch);
        $results = [];

        foreach ($rows as $row) {
            if ($row instanceof self) {
                $results[] = $row;
                continue;
            }

            if (is_array($row)) {
                $results[] = static::hydrate($row);
            }
        }

        return $results;
    }

    public static function bulkUpdate(array $records, array $fields): int
    {
        return static::getObjectManager()->bulkUpdate($records, $fields);
    }

    public static function aggregate(array $aggs): array
    {
        return static::getObjectManager()->aggregate($aggs);
    }

    public static function raw(string $sql, array $params = []): array
    {
        $rows = static::getObjectManager()->raw($sql, $params);
        $results = [];

        foreach ($rows as $row) {
            $results[] = static::hydrate($row);
        }

        return $results;
    }

    public static function getOrCreate(array $lookup, array $defaults = []): ?Model
    {
        $existing = static::filter($lookup);

        if (!empty($existing)) {
            return $existing[0];
        }

        return static::create(array_merge($lookup, $defaults));
    }

    public static function updateOrCreate(array $lookup, array $updateValues): ?Model
    {
        $existing = static::filter($lookup);

        if (!empty($existing)) {
            static::update($updateValues, $lookup);
            $pkName = static::getPkName();
            $pkVal = $existing[0]->$pkName ?? null;
            return static::get($pkVal);
        }

        return static::create(array_merge($lookup, $updateValues));
    }

    public static function paginate(array $items, int $perPage = 10, int $page = 1): Paginator
    {
        return new Paginator($items, $perPage, $page);
    }

    public static function transaction(callable $callback): mixed
    {
        $pdo = \Nudelsalat\Bootstrap::getInstance()->getPdo();
        $started = false;

        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $started = true;
            }

            $result = $callback();

            if ($started && $pdo->inTransaction()) {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function isAbstract(): bool
    {
        $meta = static::resolveMeta();
        return $meta->abstract;
    }

    public static function isProxy(): bool
    {
        $meta = static::resolveMeta();
        return $meta->proxy;
    }

    public static function getProxyFor(): ?string
    {
        return static::$options['proxy_for'] ?? null;
    }

    public static function isChildModel(): bool
    {
        return isset(static::$options['parent_link']);
    }

    public static function getParentLink(): ?string
    {
        return static::$options['parent_link'] ?? null;
    }

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
        return array_merge($errors, $customErrors);
    }

    protected static function cleanFields(array $data): array
    {
        return [];
    }

    protected static function clean(array $data): array
    {
        return static::validate($data);
    }
}

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

class Manager
{
    private Model $model;
    private ?string $connection = null;

    public function __construct(Model|string $model, ?string $connection = null)
    {
        if (is_string($model)) {
            $model = new $model();
        }

        $this->model = $model;
        $this->connection = $connection;
    }

    public function objects(): QuerySet
    {
        return new QuerySet($this->model, $this->getConnection());
    }

    public function all(): array
    {
        $table = $this->model::getTableName();
        if ($table === null) {
            throw new \RuntimeException('Cannot query abstract model: ' . static::class);
        }

        $pdo = $this->getConnection();
        $stmt = $pdo->query("SELECT * FROM {$table}");
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        return array_map(fn($row) => $this->model::hydrate($row), $rows);
    }

    public function first(?array $conditions = null): ?Model
    {
        if ($conditions === null || empty($conditions)) {
            $items = $this->all();
            return $items[0] ?? null;
        }

        $items = $this->filter($conditions);
        return $items[0] ?? null;
    }

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

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(fn($row) => $this->model::hydrate($row), $rows);
    }

    public function get(int|string $pk): ?Model
    {
        $table = $this->model::getTableName();
        $pkName = $this->model::getPkName() ?? 'id';
        $pdo = $this->getConnection();

        $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE {$pkName} = ?");
        $stmt->execute([$pk]);

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if ($result) {
            return $this->model::hydrate($result);
        }
        
        return null;
    }

    public function create(array $data): Model
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
                return $this->model::hydrate($result);
            }
        } catch (\PDOException $e) {
            // fallback below
        }

        $sql = sprintf(
            "INSERT INTO %s (%s) VALUES (%s)",
            $table,
            implode(', ', $fields),
            implode(', ', $placeholders)
        );

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($data));

        $result = $this->get((int) $pdo->lastInsertId());
        Signal::send('post_save', [$this->model, $result, false]);

        return $result;
    }

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

    public function count(): int
    {
        $table = $this->model::getTableName();
        $pdo = $this->getConnection();

        $stmt = $pdo->query("SELECT COUNT(*) FROM {$table}");
        return (int) $stmt->fetchColumn();
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

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

    public function bulkUpdate(array $records, array $fields): int
    {
        if (empty($records) || empty($fields)) {
            return 0;
        }

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

    private function getConnection(): \PDO
    {
        $bootstrap = \Nudelsalat\Bootstrap::getInstance();
        return $bootstrap->getPdo();
    }
}

class CheckConstraint
{
    public function __construct(
        public string $name,
        public string $condition,
        public ?string $message = null,
    ) {}
}

class UniqueConstraint
{
    public function __construct(
        public string $name,
        public array $fields,
        public ?string $condition = null,
        public ?string $message = null,
    ) {}
}

abstract class AbstractModel extends Model
{
    public static function getTableName(): ?string
    {
        return null;
    }

    public static function isAbstract(): bool
    {
        return true;
    }
}

abstract class ProxyModel extends Model
{
    public static function getTableName(): ?string
    {
        $proxyFor = static::getProxyFor();
        if ($proxyFor && class_exists($proxyFor)) {
            return $proxyFor::getTableName();
        }

        return parent::getTableName();
    }

    public static function isProxy(): bool
    {
        return true;
    }
}