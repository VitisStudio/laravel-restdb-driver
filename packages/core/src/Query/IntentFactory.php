<?php

declare(strict_types=1);

namespace Vitis\RestDB\Query;

use Illuminate\Contracts\Database\Query\Expression;
use Vitis\RestDB\Capabilities\Capability;
use Vitis\RestDB\Capabilities\CapabilityGate;
use Vitis\RestDB\Capabilities\Operator;
use Vitis\RestDB\Connection\RestConnection;
use Vitis\RestDB\Exceptions\UnsupportedQueryException;
use Vitis\RestDB\Values\Condition;
use Vitis\RestDB\Values\DeleteIntent;
use Vitis\RestDB\Values\FilterGroup;
use Vitis\RestDB\Values\InsertIntent;
use Vitis\RestDB\Values\Order;
use Vitis\RestDB\Values\PageRequest;
use Vitis\RestDB\Values\SelectIntent;
use Vitis\RestDB\Values\UpdateIntent;

/**
 * Normalizes builder state into an immutable SelectIntent. This is phase 2 of
 * capability enforcement: the where-type whitelist re-validates everything,
 * catching wheres injected by relations, global scopes, packages, or future
 * framework where-types. A dropped where is a data-exposure bug — anything
 * outside the whitelist throws.
 */
final class IntentFactory
{
    /** Where types this driver knows how to translate. Anything else throws. */
    private const WHERE_TYPES = ['Basic', 'In', 'NotIn', 'Null', 'NotNull', 'between', 'Nested'];

    public static function select(Builder $builder, ?int $forcedLimit = null): SelectIntent
    {
        $connection = $builder->getConnection();
        \assert($connection instanceof RestConnection);

        $gate = $connection->gate();
        $model = $builder->getModelContext();

        $gate->ensure(Capability::Select, 'get', $model);

        $resource = self::resource($builder);
        $filters = self::mapWheres($builder->wheres, $gate, $model, $builder->keyName(), gateOperators: true);

        if ($filters->hasNestedGroups()) {
            $gate->ensure(Capability::FilterNested, 'where', $model);
        }

        if ($filters->hasOrBoolean()) {
            $gate->ensure(Capability::FilterOr, 'orWhere', $model);
        }

        if ($builder->includes !== []) {
            $gate->ensure(Capability::Include, 'with', $model);
        }

        return new SelectIntent(
            resource: $resource,
            columns: self::columns($builder, $gate, $model),
            filters: $filters,
            orders: self::orders($builder, $gate, $model),
            page: self::page($builder, $gate, $model, $forcedLimit),
            includes: $builder->includes,
            aggregate: self::aggregate($builder, $gate, $model),
        );
    }

    /** @param array<mixed> $values */
    public static function insert(Builder $builder, array $values): InsertIntent
    {
        // Normalize Eloquent's two shapes: one row (assoc) or a list of rows.
        $rows = array_is_list($values) ? $values : [$values];

        if (count($rows) > 1) {
            throw UnsupportedQueryException::batchInsert();
        }

        $normalized = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw UnsupportedQueryException::whereType('insert row');
            }

            $normalized[] = self::attributes($row, 'insert()');
        }

        return new InsertIntent(self::resource($builder), $normalized);
    }

    /** @param array<mixed> $values */
    public static function update(Builder $builder, array $values): UpdateIntent
    {
        return new UpdateIntent(
            self::resource($builder),
            self::attributes($values, 'update()'),
            self::writeTarget($builder, 'update'),
        );
    }

    public static function delete(Builder $builder): DeleteIntent
    {
        return new DeleteIntent(
            self::resource($builder),
            self::writeTarget($builder, 'delete'),
        );
    }

    /**
     * Writes target exactly one resource by primary key — anything else is a
     * mass write, which REST cannot express transactionally. Operator
     * capabilities do not apply: this is identity, not filtering.
     */
    private static function writeTarget(Builder $builder, string $method): FilterGroup
    {
        $connection = $builder->getConnection();
        \assert($connection instanceof RestConnection);

        $filters = self::mapWheres($builder->wheres, $connection->gate(), $builder->getModelContext(), $builder->keyName(), gateOperators: false);
        $items = $filters->items;

        $single = count($items) === 1
            && $items[0] instanceof Condition
            && $items[0]->column === $builder->keyName()
            && $items[0]->boolean === 'and'
            && ($items[0]->operator === Operator::Eq
                || ($items[0]->operator === Operator::In && is_array($items[0]->value) && count($items[0]->value) === 1));

        if (! $single) {
            throw UnsupportedQueryException::massWrite($method);
        }

        return $filters;
    }

    /**
     * @param  array<mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function attributes(array $attributes, string $context): array
    {
        $result = [];

        foreach ($attributes as $key => $value) {
            if (! is_string($key)) {
                throw UnsupportedQueryException::rawExpression($context.' (non-string attribute key)');
            }

            if ($value instanceof Expression) {
                throw UnsupportedQueryException::rawExpression($context);
            }

            // Strip qualified attribute names the same way wheres are stripped.
            $result[str_contains($key, '.') ? substr((string) strrchr($key, '.'), 1) : $key] = $value;
        }

        return $result;
    }

    private static function resource(Builder $builder): string
    {
        $from = $builder->from;

        if (! is_string($from)) {
            throw UnsupportedQueryException::rawExpression('from');
        }

        if (preg_match('/\s/', $from) === 1) {
            throw UnsupportedQueryException::aliasedTable($from);
        }

        return $from;
    }

    /** @param array<mixed> $wheres */
    private static function mapWheres(array $wheres, CapabilityGate $gate, ?string $model, string $keyName, bool $gateOperators, int $depth = 0): FilterGroup
    {
        $items = [];

        foreach ($wheres as $where) {
            if (! is_array($where)) {
                throw UnsupportedQueryException::whereType(get_debug_type($where));
            }

            $type = is_string($where['type'] ?? null) ? $where['type'] : 'unknown';

            if (! in_array($type, self::WHERE_TYPES, true)) {
                throw UnsupportedQueryException::whereType($type);
            }

            $boolean = is_string($where['boolean'] ?? null) ? $where['boolean'] : 'and';

            if ($type === 'Nested') {
                $query = $where['query'] ?? null;

                if (! $query instanceof \Illuminate\Database\Query\Builder) {
                    throw UnsupportedQueryException::whereType('Nested');
                }

                $group = self::mapWheres($query->wheres, $gate, $model, $keyName, $gateOperators, $depth + 1);

                // A pure-AND nested group under an AND boolean is flattenable —
                // key-value array wheres should not demand filter.nested.
                if ($boolean === 'and' && ! $group->hasOrBoolean() && ! $group->hasNestedGroups()) {
                    $items = [...$items, ...$group->items];
                } else {
                    $items[] = new FilterGroup($group->items, $boolean);
                }

                continue;
            }

            $items[] = self::condition($type, $where, $boolean);
        }

        // Prune before gating: an implied NotNull must never demand an operator.
        $items = self::pruneImpliedNotNull($items);

        if ($gateOperators) {
            foreach ($items as $item) {
                if (! $item instanceof Condition) {
                    continue;
                }

                // Top-level AND primary-key equality is identity targeting,
                // not a filter — exempt (mirrors the eager gate).
                $identity = $depth === 0
                    && $item->boolean === 'and'
                    && $item->column === $keyName
                    && in_array($item->operator, [Operator::Eq, Operator::In], true);

                if (! $identity) {
                    $gate->ensureOperator($item->operator, 'where', $model);
                }
            }
        }

        return new FilterGroup($items);
    }

    /**
     * Eloquent relations constrain `fk = ?` AND `fk IS NOT NULL` — the
     * NotNull is logically implied by any equality/In on the same column with
     * non-null values, so dropping it changes nothing. This is the one prune
     * that is provably lossless; it spares connections from declaring a
     * not-null operator just to lazy-load relations.
     *
     * @param  list<Condition|FilterGroup>  $items
     * @return list<Condition|FilterGroup>
     */
    private static function pruneImpliedNotNull(array $items): array
    {
        $implied = [];

        foreach ($items as $item) {
            if (! $item instanceof Condition || $item->boolean !== 'and') {
                continue;
            }

            $values = is_array($item->value) ? $item->value : [$item->value];

            if (
                in_array($item->operator, [Operator::Eq, Operator::In], true)
                && $values !== []
                && ! in_array(null, $values, true)
            ) {
                $implied[$item->column] = true;
            }
        }

        return array_values(array_filter(
            $items,
            fn ($item) => ! ($item instanceof Condition
                && $item->operator === Operator::NotNull
                && $item->boolean === 'and'
                && isset($implied[$item->column])),
        ));
    }

    /** @param array<mixed> $where */
    private static function condition(string $type, array $where, string $boolean): Condition
    {
        $column = self::column($where['column'] ?? null);
        $qualified = str_contains($column, '.');

        if ($qualified) {
            $column = substr((string) strrchr($column, '.'), 1);
        }

        [$operator, $value] = match ($type) {
            'Basic' => self::basic($where),
            'In' => [Operator::In, self::scalarList($where['values'] ?? [], 'whereIn')],
            'NotIn' => [Operator::NotIn, self::scalarList($where['values'] ?? [], 'whereNotIn')],
            'Null' => [Operator::Null, null],
            'NotNull' => [Operator::NotNull, null],
            'between' => self::between($where),
            default => throw UnsupportedQueryException::whereType($type),
        };

        // Qualified equality wheres are how lazy relation loading arrives
        // (belongsTo/hasMany constraints) — rewrite to In so they batch.
        if ($qualified && $operator === Operator::Eq) {
            $operator = Operator::In;
            $value = [$value];
        }

        return new Condition($column, $operator, $value, $boolean);
    }

    /**
     * @param  array<mixed>  $where
     * @return array{Operator, mixed}
     */
    private static function basic(array $where): array
    {
        $sqlOperator = is_string($where['operator'] ?? null) ? $where['operator'] : '=';
        $operator = Operator::fromSqlOperator($sqlOperator)
            ?? throw UnsupportedQueryException::sqlOperator($sqlOperator);

        $value = $where['value'] ?? null;

        if ($value instanceof Expression) {
            throw UnsupportedQueryException::rawExpression('a where value');
        }

        return [$operator, $value];
    }

    /**
     * @param  array<mixed>  $where
     * @return array{Operator, mixed}
     */
    private static function between(array $where): array
    {
        if (($where['not'] ?? false) === true) {
            throw UnsupportedQueryException::notBetween();
        }

        return [Operator::Between, self::scalarList($where['values'] ?? [], 'whereBetween')];
    }

    /** @return list<mixed> */
    private static function scalarList(mixed $values, string $context): array
    {
        if (! is_array($values)) {
            throw UnsupportedQueryException::whereType($context);
        }

        foreach ($values as $value) {
            if ($value instanceof Expression) {
                throw UnsupportedQueryException::rawExpression($context);
            }
        }

        return array_values($values);
    }

    private static function column(mixed $column): string
    {
        if ($column instanceof Expression || ! is_string($column)) {
            throw UnsupportedQueryException::rawExpression('a where column');
        }

        return $column;
    }

    /** @return list<string>|null */
    private static function columns(Builder $builder, CapabilityGate $gate, ?string $model): ?array
    {
        $columns = $builder->columns;

        if ($columns === null || $columns === ['*']) {
            return null;
        }

        $names = [];

        foreach ($columns as $column) {
            if (! is_string($column)) {
                throw UnsupportedQueryException::rawExpression('select()');
            }

            if (preg_match('/\s/', $column) === 1) {
                throw UnsupportedQueryException::rawExpression("select() column [{$column}]");
            }

            $names[] = $column;
        }

        // Explicit select() was eager-gated; internal projections (pluck, value)
        // are only sent when the API can honor them. Omitting the projection is
        // safe — the API returns a superset and the caller plucks client-side.
        if (! $gate->allows(Capability::Columns)) {
            if ($builder->explicitColumns()) {
                $gate->ensure(Capability::Columns, 'select', $model);
            }

            return null;
        }

        return $names;
    }

    /** @return list<Order> */
    private static function orders(Builder $builder, CapabilityGate $gate, ?string $model): array
    {
        $orders = [];

        foreach ($builder->orders ?? [] as $order) {
            if (! is_array($order) || ! isset($order['column'])) {
                throw UnsupportedQueryException::whereType('Raw order');
            }

            $column = $order['column'];

            if ($column instanceof Expression || ! is_string($column)) {
                throw UnsupportedQueryException::rawExpression('orderBy()');
            }

            $direction = $order['direction'] ?? 'asc';

            $orders[] = new Order($column, is_string($direction) ? $direction : 'asc');
        }

        if ($orders !== []) {
            $gate->ensure(Capability::Sort, 'orderBy', $model);
        }

        if (count($orders) > 1) {
            $gate->ensure(Capability::MultiSort, 'orderBy', $model);
        }

        return $orders;
    }

    private static function page(Builder $builder, CapabilityGate $gate, ?string $model, ?int $forcedLimit): ?PageRequest
    {
        $limit = $builder->limit;
        $offset = $builder->offset;
        $page = $builder->pageNumber;

        if ($limit !== null) {
            $gate->ensure(Capability::Limit, 'limit', $model);
        }

        if ($offset !== null) {
            $gate->ensure(Capability::Offset, 'offset', $model);
        }

        if ($page !== null) {
            $gate->ensure(Capability::PageNumber, 'paginate', $model);
        }

        // An internal probe limit (exists()) is not the developer's limit() —
        // it is applied without a gate; the drain loop trims client-side.
        if ($forcedLimit !== null) {
            $limit = $limit === null ? $forcedLimit : min($limit, $forcedLimit);
        }

        if ($limit === null && $offset === null && $page === null) {
            return null;
        }

        return new PageRequest(limit: $limit, offset: $offset, page: $page);
    }

    private static function aggregate(Builder $builder, CapabilityGate $gate, ?string $model): ?string
    {
        $aggregate = $builder->aggregate;

        if ($aggregate === null) {
            return null;
        }

        $function = $aggregate['function'];

        if ($function !== 'count') {
            throw UnsupportedQueryException::aggregate($function);
        }

        $gate->ensure(Capability::Count, 'count', $model);

        return $function;
    }
}
