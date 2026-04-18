<?php

namespace Nudelsalat\ORM;

/**
 * Q - Object for building complex queries
 * 
 * Supports AND, OR, NOT operations for filtering.
 * 
 * Usage:
 * ```php
 * User::filter(Q(['status' => 'active']) & Q(['role' => 'admin']));
 * User::filter(Q(['status' => 'active']) | Q(['is_verified' => true]));
 * ```
 */
class Q
{
    public array $conditions;
    public string $connector = 'AND';

    public function __construct(array $conditions = [], string $connector = 'AND')
    {
        $this->conditions = $conditions;
        $this->connector = $connector;
    }
}

function Q_AND(array $conditions = []): Q
{
    return new Q($conditions, 'AND');
}

function Q_OR(array $conditions = []): Q
{
    return new Q($conditions, 'OR');
}

/**
 * F - Expression for field references
 * 
 * Used for updating field values relative to their current values.
 * 
 * Usage:
 * ```php
 * User::update(['counter' => F('counter') + 1], ['id' => 1]);
 * ```
 */
class F
{
    public string $field;

    public function __construct(string $field)
    {
        $this->field = $field;
    }

    public function __toString(): string
    {
        return $this->field;
    }
}

/**
 * Aggregation functions
 * 
 * Usage:
 * ```php
 * User::aggregate([Sum('age'), Avg('price')]);
 * ```
 */
class Aggregation
{
    public string $function;
    public string $field;
    public ?string $alias;

    public function __construct(string $function, string $field, ?string $alias = null)
    {
        $this->function = $function;
        $this->field = $field;
        $this->alias = $alias ?? strtolower($function) . '_' . $field;
    }

    public function getSql(): string
    {
        $field = $this->field === '*' ? $this->field : '"' . $this->field . '"';
        return "{$this->function}({$field}) AS {$this->alias}";
    }
}

function Sum(string $field, ?string $alias = null): Aggregation
{
    return new Aggregation('SUM', $field, $alias);
}

function Avg(string $field, ?string $alias = null): Aggregation
{
    return new Aggregation('AVG', $field, $alias);
}

function Min(string $field, ?string $alias = null): Aggregation
{
    return new Aggregation('MIN', $field, $alias);
}

function Max(string $field, ?string $alias = null): Aggregation
{
    return new Aggregation('MAX', $field, $alias);
}

function Count(string $field, ?string $alias = null): Aggregation
{
    return new Aggregation('COUNT', $field, $alias ?? 'count');
}

/**
 * Paginator - Pagination for query results
 * 
 * Usage:
 * ```php
 * $paginator = new Paginator(User::all(), 10);
 * $page = $paginator->getPage(1);
 * ```
 */
class Paginator
{
    public array $items;
    public int $perPage;
    public int $currentPage;

    public function __construct(array $items, int $perPage = 10, int $currentPage = 1)
    {
        $this->items = $items;
        $this->perPage = $perPage;
        $this->currentPage = $currentPage;
    }

    public function getTotalPages(): int
    {
        return (int) ceil(count($this->items) / $this->perPage);
    }

    public function getPage(int $pageNumber): array
    {
        $offset = ($pageNumber - 1) * $this->perPage;
        return array_slice($this->items, $offset, $this->perPage);
    }

    public function hasNextPage(): bool
    {
        return $this->currentPage < $this->getTotalPages();
    }

    public function hasPreviousPage(): bool
    {
        return $this->currentPage > 1;
    }

    public function getTotalCount(): int
    {
        return sizeof($this->items);
    }
}

/**
 * Atomic - Transaction context manager
 * 
 * Usage:
 * ```php
 * atomic($pdo, function() {
 *     User::create([...]);
 *     Order::create([...]);
 * });
 * ```
 */
function atomic(\PDO $pdo, callable $callback): mixed
{
    $pdo->beginTransaction();
    try {
        $result = $callback();
        $pdo->commit();
        return $result;
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}