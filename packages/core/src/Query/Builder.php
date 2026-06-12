<?php

declare(strict_types=1);

namespace Vitis\RestDB\Query;

use BadMethodCallException;
use Closure;
use Illuminate\Support\LazyCollection;
use LogicException;
use Vitis\RestDB\Capabilities\Capability;
use Vitis\RestDB\Capabilities\CapabilityGate;
use Vitis\RestDB\Capabilities\Operator;
use Vitis\RestDB\Connection\RestConnection;
use Vitis\RestDB\Exceptions\UnsupportedQueryException;
use Vitis\RestDB\Values\CompiledRequest;
use Vitis\RestDB\Values\SelectIntent;

/**
 * The gated query builder. Mutators check the capability gate before touching
 * state (phase 1 — the exception fires at the developer's own line); the
 * IntentFactory re-validates everything at compile time (phase 2). SQL-only
 * surface always throws, mongodb-driver style.
 *
 * @phpstan-consistent-constructor
 */
class Builder extends \Illuminate\Database\Query\Builder
{
    /** Model class for exception messages, set by the Eloquent builder. */
    protected ?string $modelContext = null;

    /** True once select()/addSelect() was called explicitly by the developer. */
    protected bool $explicitColumns = false;

    public function setModelContext(?string $model): void
    {
        $this->modelContext = $model;
    }

    public function getModelContext(): ?string
    {
        return $this->modelContext;
    }

    public function explicitColumns(): bool
    {
        return $this->explicitColumns;
    }

    public function newQuery()
    {
        $query = new static($this->connection, $this->grammar, $this->processor);
        $query->modelContext = $this->modelContext;

        return $query;
    }

    /*
    |--------------------------------------------------------------------------
    | Gated mutators — phase 1
    |--------------------------------------------------------------------------
    */

    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        // Nested closures and key-value arrays both become Nested where groups;
        // they are gated late by the IntentFactory, which can flatten pure-AND
        // groups instead of demanding filter.nested for flat conditions.
        if (is_array($column) || ($column instanceof Closure && $operator === null && $value === null)) {
            return parent::where($column, $operator, $value, $boolean);
        }

        if ($this->isQueryable($column)) {
            throw UnsupportedQueryException::subquery('where');
        }

        // Mirror the base builder's normalization so the gate inspects exactly
        // what the parent will store: 2-arg shorthand means '='; an
        // unrecognized operator string is treated as the value of an '='.
        if (func_num_args() === 2) {
            [$value, $operator] = [$operator, '='];
        } elseif (is_string($operator) && $this->invalidOperator($operator)) {
            [$value, $operator] = [$operator, '='];
        }

        $operator = is_string($operator) ? $operator : '=';

        if ($this->isQueryable($value)) {
            throw UnsupportedQueryException::subquery('where');
        }

        // Null value falls through to whereNull()/whereNotNull(), gated there.
        if ($value !== null) {
            $mapped = Operator::fromSqlOperator($operator)
                ?? throw UnsupportedQueryException::sqlOperator($operator);

            $this->gate()->ensureOperator($mapped, $this->isOr($boolean) ? 'orWhere' : 'where', $this->modelContext);
        }

        return parent::where($column, $operator, $value, $boolean);
    }

    public function whereIn($column, $values, $boolean = 'and', $not = false)
    {
        if ($this->isQueryable($values)) {
            throw UnsupportedQueryException::subquery($not ? 'whereNotIn' : 'whereIn');
        }

        $method = ($this->isOr($boolean) ? 'orWhere' : 'where').($not ? 'NotIn' : 'In');
        $this->gate()->ensureOperator($not ? Operator::NotIn : Operator::In, $method, $this->modelContext);

        return parent::whereIn($column, $values, $boolean, $not);
    }

    public function whereNull($columns, $boolean = 'and', $not = false)
    {
        $method = ($this->isOr($boolean) ? 'orWhere' : 'where').($not ? 'NotNull' : 'Null');
        $this->gate()->ensureOperator($not ? Operator::NotNull : Operator::Null, $method, $this->modelContext);

        return parent::whereNull($columns, $boolean, $not);
    }

    /** @param iterable<mixed> $values */
    public function whereBetween($column, iterable $values, $boolean = 'and', $not = false)
    {
        if ($not) {
            throw UnsupportedQueryException::notBetween();
        }

        $this->gate()->ensureOperator(Operator::Between, 'whereBetween', $this->modelContext);

        return parent::whereBetween($column, $values, $boolean, $not);
    }

    public function orderBy($column, $direction = 'asc')
    {
        if ($this->isQueryable($column)) {
            throw UnsupportedQueryException::subquery('orderBy');
        }

        $this->gate()->ensure(Capability::Sort, 'orderBy', $this->modelContext);

        if (count($this->orders ?? []) >= 1) {
            $this->gate()->ensure(Capability::MultiSort, 'orderBy', $this->modelContext);
        }

        return parent::orderBy($column, $direction);
    }

    public function limit($value)
    {
        $this->gate()->ensure(Capability::Limit, 'limit', $this->modelContext);

        return parent::limit($value);
    }

    public function offset($value)
    {
        $this->gate()->ensure(Capability::Offset, 'offset', $this->modelContext);

        return parent::offset($value);
    }

    public function select($columns = ['*'])
    {
        $columns = is_array($columns) ? $columns : func_get_args();

        if ($columns !== ['*']) {
            $this->gate()->ensure(Capability::Columns, 'select', $this->modelContext);
            $this->explicitColumns = true;
        }

        return parent::select($columns);
    }

    public function addSelect($column)
    {
        $this->gate()->ensure(Capability::Columns, 'addSelect', $this->modelContext);
        $this->explicitColumns = true;

        return parent::addSelect(is_array($column) ? $column : func_get_args());
    }

    /*
    |--------------------------------------------------------------------------
    | Terminals
    |--------------------------------------------------------------------------
    */

    public function toIntent(?int $forcedLimit = null): SelectIntent
    {
        return IntentFactory::select($this, $forcedLimit);
    }

    /** The REST equivalent of toSql(). */
    public function toRequest(): CompiledRequest
    {
        $compiled = $this->restConnection()->compile($this->toIntent());

        if ($compiled instanceof CompiledRequest) {
            return $compiled;
        }

        throw new LogicException('This query provably matches nothing (e.g. whereIn over an empty list) — no request is compiled.');
    }

    /** @return list<array<string, mixed>> */
    protected function runSelect()
    {
        return $this->restConnection()->select($this->toIntent(), [], ! $this->useWritePdo);
    }

    /** @return LazyCollection<int, \stdClass> */
    public function cursor()
    {
        return new LazyCollection(function () {
            yield from $this->restConnection()->cursor($this->toIntent(), [], ! $this->useWritePdo);
        });
    }

    public function exists()
    {
        $this->gate()->ensure(Capability::Exists, 'exists', $this->modelContext);

        return $this->restConnection()->select($this->toIntent(forcedLimit: 1), [], true) !== [];
    }

    public function aggregate($function, $columns = ['*'])
    {
        if ($function !== 'count') {
            throw UnsupportedQueryException::aggregate($function);
        }

        $this->gate()->ensure(Capability::Count, 'count', $this->modelContext);

        return parent::aggregate($function, $columns);
    }

    protected function runPaginationCountQuery($columns = ['*'])
    {
        throw new BadMethodCallException(
            'paginate() requires meta totals (page.total) and lands in v0.5. Use simplePaginate(), lazy(), or get().',
        );
    }

    /** @param array<mixed> $values */
    public function insert(array $values)
    {
        $this->gate()->ensure(Capability::Insert, 'insert', $this->modelContext);

        throw new LogicException('The restdb write path ships in v0.3.');
    }

    /** @param array<mixed> $values */
    public function insertGetId(array $values, $sequence = null)
    {
        $this->gate()->ensure(Capability::Insert, 'insertGetId', $this->modelContext);

        throw new LogicException('The restdb write path ships in v0.3.');
    }

    /** @param array<mixed> $values */
    public function update(array $values)
    {
        $this->gate()->ensure(Capability::Update, 'update', $this->modelContext);

        throw new LogicException('The restdb write path ships in v0.3.');
    }

    public function delete($id = null)
    {
        $this->gate()->ensure(Capability::Delete, 'delete', $this->modelContext);

        throw new LogicException('The restdb write path ships in v0.3.');
    }

    public function toSql(): never
    {
        throw new BadMethodCallException(
            'toSql() is not supported by the restdb driver — there is no SQL. Use toRequest() instead.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | SQL-only surface — always throws
    |--------------------------------------------------------------------------
    */

    /** @param mixed ...$distinct */
    public function distinct(...$distinct): never
    {
        $this->unsupported('distinct');
    }

    /** @param array<mixed> $bindings */
    public function selectRaw($expression, array $bindings = []): never
    {
        $this->unsupported('selectRaw');
    }

    public function selectSub($query, $as): never
    {
        $this->unsupported('selectSub');
    }

    public function fromRaw($expression, $bindings = []): never
    {
        $this->unsupported('fromRaw');
    }

    public function fromSub($query, $as): never
    {
        $this->unsupported('fromSub');
    }

    public function join($table, $first, $operator = null, $second = null, $type = 'inner', $where = false): never
    {
        $this->unsupported('join');
    }

    public function joinWhere($table, $first, $operator, $second, $type = 'inner'): never
    {
        $this->unsupported('joinWhere');
    }

    public function joinSub($query, $as, $first, $operator = null, $second = null, $type = 'inner', $where = false): never
    {
        $this->unsupported('joinSub');
    }

    public function leftJoin($table, $first, $operator = null, $second = null): never
    {
        $this->unsupported('leftJoin');
    }

    public function rightJoin($table, $first, $operator = null, $second = null): never
    {
        $this->unsupported('rightJoin');
    }

    public function crossJoin($table, $first = null, $operator = null, $second = null): never
    {
        $this->unsupported('crossJoin');
    }

    public function union($query, $all = false): never
    {
        $this->unsupported('union');
    }

    public function unionAll($query): never
    {
        $this->unsupported('unionAll');
    }

    /** @param mixed ...$groups */
    public function groupBy(...$groups): never
    {
        $this->unsupported('groupBy');
    }

    /** @param array<mixed> $bindings */
    public function groupByRaw($sql, array $bindings = []): never
    {
        $this->unsupported('groupByRaw');
    }

    public function having($column, $operator = null, $value = null, $boolean = 'and'): never
    {
        $this->unsupported('having');
    }

    /** @param array<mixed> $bindings */
    public function havingRaw($sql, array $bindings = [], $boolean = 'and'): never
    {
        $this->unsupported('havingRaw');
    }

    /** @param iterable<mixed> $values */
    public function havingBetween($column, iterable $values, $boolean = 'and', $not = false): never
    {
        $this->unsupported('havingBetween');
    }

    public function whereColumn($first, $operator = null, $second = null, $boolean = 'and'): never
    {
        $this->unsupported('whereColumn');
    }

    public function whereExists($callback, $boolean = 'and', $not = false): never
    {
        $this->unsupported('whereExists');
    }

    public function whereRaw($sql, $bindings = [], $boolean = 'and'): never
    {
        $this->unsupported('whereRaw');
    }

    /** @param array<mixed> $bindings */
    public function orderByRaw($sql, $bindings = []): never
    {
        $this->unsupported('orderByRaw');
    }

    public function inRandomOrder($seed = ''): never
    {
        $this->unsupported('inRandomOrder');
    }

    /** @param array<mixed> $options */
    public function whereFullText($columns, $value, array $options = [], $boolean = 'and'): never
    {
        $this->unsupported('whereFullText');
    }

    public function whereJsonContains($column, $value, $boolean = 'and', $not = false): never
    {
        $this->unsupported('whereJsonContains');
    }

    public function whereJsonLength($column, $operator, $value = null, $boolean = 'and'): never
    {
        $this->unsupported('whereJsonLength');
    }

    public function whereDate($column, $operator, $value = null, $boolean = 'and'): never
    {
        $this->unsupported('whereDate');
    }

    public function whereTime($column, $operator, $value = null, $boolean = 'and'): never
    {
        $this->unsupported('whereTime');
    }

    public function whereDay($column, $operator, $value = null, $boolean = 'and'): never
    {
        $this->unsupported('whereDay');
    }

    public function whereMonth($column, $operator, $value = null, $boolean = 'and'): never
    {
        $this->unsupported('whereMonth');
    }

    public function whereYear($column, $operator, $value = null, $boolean = 'and'): never
    {
        $this->unsupported('whereYear');
    }

    public function whereIntegerInRaw($column, $values, $boolean = 'and', $not = false): never
    {
        $this->unsupported('whereIntegerInRaw');
    }

    public function whereIntegerNotInRaw($column, $values, $boolean = 'and'): never
    {
        $this->unsupported('whereIntegerNotInRaw');
    }

    public function whereAll($columns, $operator = null, $value = null, $boolean = 'and'): never
    {
        $this->unsupported('whereAll');
    }

    public function whereAny($columns, $operator = null, $value = null, $boolean = 'and'): never
    {
        $this->unsupported('whereAny');
    }

    public function whereNone($columns, $operator = null, $value = null, $boolean = 'and'): never
    {
        $this->unsupported('whereNone');
    }

    public function lock($value = true): never
    {
        $this->unsupported('lock');
    }

    public function lockForUpdate(): never
    {
        $this->unsupported('lockForUpdate');
    }

    public function sharedLock(): never
    {
        $this->unsupported('sharedLock');
    }

    /**
     * @param  array<mixed>  $values
     * @param  mixed  $uniqueBy
     * @param  mixed  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): never
    {
        $this->unsupported('upsert');
    }

    /** @param array<mixed> $values */
    public function insertOrIgnore(array $values): never
    {
        $this->unsupported('insertOrIgnore');
    }

    /** @param array<mixed> $columns */
    public function insertUsing(array $columns, $query): never
    {
        $this->unsupported('insertUsing');
    }

    /** @param array<mixed> $values */
    public function updateFrom(array $values): never
    {
        $this->unsupported('updateFrom');
    }

    public function truncate(): never
    {
        $this->unsupported('truncate');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    protected function gate(): CapabilityGate
    {
        return $this->restConnection()->gate();
    }

    protected function restConnection(): RestConnection
    {
        $connection = $this->connection;

        if (! $connection instanceof RestConnection) {
            throw new LogicException('Vitis\RestDB\Query\Builder requires a RestConnection.');
        }

        return $connection;
    }

    protected function isOr(string $boolean): bool
    {
        return str_contains(strtolower($boolean), 'or');
    }

    protected function unsupported(string $method): never
    {
        throw new BadMethodCallException("{$method} is not supported by the restdb driver.");
    }
}
