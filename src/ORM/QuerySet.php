<?php

namespace Nudelsalat\ORM;

/**
 * QuerySet - Chainable query builder for models
 * 
 * Provides a fluent interface for building queries:
 * ```php
 * $users = User::objects()->filter(['status' => 'active'])->orderBy('-created_at')->limit(10);
 * ```
 */
class QuerySet
{
    private Model|string $model;
    private \PDO $pdo;
    private array $conditions = [];
    private array $orConditions = [];
    private ?string $orderBy = null;
    private ?int $limit = null;
    private ?int $offset = null;
    private array $selectRelated = [];
    private array $prefetchRelated = [];
    // Hydration flag: hydrate results into Model instances by default
    private bool $hydrate = true;

    public function __construct(Model|string $model, \PDO $pdo, array $conditions = [])
    {
        $this->model = $model;
        $this->pdo = $pdo;
        $this->conditions = $conditions;
    }

    /** Enable or disable hydration of results into Model instances. */
    public function hydrate(bool $flag): self
    {
        $this->hydrate = $flag;
        return $this;
    }

    /**
     * Alias: Django-style order_by (wrapper around existing orderBy).
     * This keeps a familiar API while preserving existing behavior.
     */
    public function order_by(string $field): self
    {
        return $this->orderBy($field);
    }

    /**
     * Get results as plain arrays (values of each model) instead of hydrated models.
     *
     * @return array
     */
    public function values(): array
    {
        $rows = $this->execute();
        $out = [];
        foreach ($rows as $row) {
            if ($row instanceof \Nudelsalat\ORM\Model) {
                $out[] = $row->toArray();
            } elseif (is_array($row)) {
                $out[] = $row;
            } else {
                $out[] = ['value' => $row];
            }
        }
        return $out;
    }

    /**
     * Select related objects (single query with JOIN).
     * 
     * @param string ...$fields Field names to select
     * @return self
     */
    public function select_related(string ...$fields): self
    {
        $this->selectRelated = array_merge($this->selectRelated, $fields);
        return $this;
    }

    /**
     * Prefetch related objects (separate queries).
     * 
     * @param string ...$fields Field names to prefetch
     * @return self
     */
    public function prefetch_related(string ...$fields): self
    {
        $this->prefetchRelated = array_merge($this->prefetchRelated, $fields);
        return $this;
    }

    /**
     * Add filter conditions (AND).
     * 
     * @param array $conditions
     * @return self
     */
    public function filter(array $conditions): self
    {
        $this->conditions = array_merge($this->conditions, $conditions);
        return $this;
    }

    /**
     * Add OR condition.
     * 
     * @param array $conditions
     * @return self
     */
    public function filterOr(array $conditions): self
    {
        $this->orConditions[] = $conditions;
        return $this;
    }

    /**
     * Order by field.
     * 
     * @param string $field Field name (prefix with - for DESC)
     * @return self
     */
    public function orderBy(string $field): self
    {
        $this->orderBy = $field;
        return $this;
    }

    /**
     * Limit results.
     * 
     * @param int $limit
     * @return self
     */
    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Offset results.
     * 
     * @param int $offset
     * @return self
     */
    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * Execute the query and return results.
     * 
     * @return array
     */
    public function execute(): array
    {
        $table = is_string($this->model) ? $this->model::getTableName() : $this->model::getTableName();
        
        if ($table === null) {
            throw new \RuntimeException('Cannot query abstract model');
        }
        
        $results = $this->fetchResults($table);
        
        if (!empty($this->prefetchRelated)) {
            $results = $this->applyPrefetch($results);
        }
        
        if ($this->orderBy) {
            $results = $this->applyOrderBy($results);
        }
        
        if ($this->offset) {
            $results = array_slice($results, $this->offset);
        }
        
        if ($this->limit) {
            $results = array_slice($results, 0, $this->limit);
        }
        
        // Hydration step: convert rows to Model instances when required
        if ($this->hydrate) {
            $hydrated = [];
            $cls = is_string($this->model) ? $this->model : (is_object($this->model) ? get_class($this->model) : null);
            foreach ($results as $row) {
                if ($row instanceof \Nudelsalat\ORM\Model) {
                    $hydrated[] = $row;
                    continue;
                }
                if (is_null($cls)) {
                    $hydrated[] = $row;
                    continue;
                }
                $obj = new $cls();
                foreach ($row as $k => $v) {
                    $obj->$k = $v;
                }
                $hydrated[] = $obj;
            }
            $results = $hydrated;
        }
        return $results;
    }

    /**
     * Fetch base results from database.
     * 
     * @param string $table
     * @return array
     */
    private function fetchResults(string $table): array
    {
        $where = [];
        $values = [];
        
        foreach ($this->conditions as $field => $value) {
            if (is_array($value)) {
                $placeholders = implode(',', array_fill(0, count($value), '?'));
                $where[] = "{$field} IN ({$placeholders})";
                $values = array_merge($values, $value);
            } else {
                $where[] = "{$field} = ?";
                $values[] = $value;
            }
        }
        
        if (!empty($this->orConditions)) {
            $orParts = [];
            foreach ($this->orConditions as $orCond) {
                $orFields = [];
                foreach ($orCond as $field => $value) {
                    $orFields[] = "{$field} = ?";
                    $values[] = $value;
                }
                $orParts[] = '(' . implode(' OR ', $orFields) . ')';
            }
            $where[] = '(' . implode(' OR ', $orParts) . ')';
        }
        
        $sql = "SELECT * FROM {$table}";
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Apply prefetch_related - separate queries for related objects.
     * 
     * @param array $results
     * @return array
     */
    private function applyPrefetch(array $results): array
    {
        if (empty($results) || empty($this->prefetchRelated)) {
            return $results;
        }
        
        $pkName = is_string($this->model) 
            ? $this->model::getPkName() ?? 'id' 
            : $this->model::getPkName() ?? 'id';
        
        $pks = array_column($results, $pkName);
        
        foreach ($this->prefetchRelated as $field) {
            $relatedField = $field . '_id';
            $relatedResults = [];
            
            foreach ($results as $result) {
                if (isset($result[$relatedField])) {
                    $relatedResults[$result[$pkName]] = $result[$relatedField];
                }
            }
            
            foreach ($results as &$result) {
                $result[$field] = $relatedResults[$result[$pkName]] ?? [];
            }
        }
        
        return $results;
    }

    /**
     * Get first result or null.
     * 
     * @return array|null
     */
    public function first(): ?Model
    {
        $results = $this->limit(1)->execute();
        return $results[0] ?? null;
    }

    /**
     * Count all matching records.
     * 
     * @return int
     */
    public function count(): int
    {
        return count($this->execute());
    }

    /**
     * Check if any records match.
     * 
     * @return bool
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Order results in PHP.
     * 
     * @param array $results
     * @return array
     */
    private function applyOrderBy(array $results): array
    {
        if (!$this->orderBy) {
            return $results;
        }

        $field = ltrim($this->orderBy, '-');
        $desc = str_starts_with($this->orderBy, '-');

        usort($results, function ($a, $b) use ($field, $desc) {
            $aVal = is_array($a) ? ($a[$field] ?? null) : (is_object($a) ? ($a->$field ?? null) : null);
            $bVal = is_array($b) ? ($b[$field] ?? null) : (is_object($b) ? ($b->$field ?? null) : null);

            if ($aVal === $bVal) {
                return 0;
            }

            $cmp = $aVal <=> $bVal;
            return $desc ? -$cmp : $cmp;
        });

        return $results;
    }

    /**
     * Get all results (alias for execute).
     * 
     * @return array
     */
    public function all(): array
    {
        return $this->execute();
    }

    /**
     * Pluck a single column value from hydrated results.
     *
     * @param string $column
     * @return array
     */
    public function pluck(string $column): array
    {
        $results = $this->execute();
        $values = [];
        foreach ($results as $row) {
            if (is_array($row)) {
                $values[] = $row[$column] ?? null;
            } elseif (is_object($row)) {
                $values[] = $row->$column ?? null;
            }
        }
        return array_values(array_filter($values, function ($v) { return $v !== null; }));
    }
}
