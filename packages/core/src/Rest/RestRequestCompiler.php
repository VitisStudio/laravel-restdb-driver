<?php

declare(strict_types=1);

namespace Vitis\RestDB\Rest;

use Vitis\RestDB\Capabilities\Operator;
use Vitis\RestDB\Contracts\RequestCompiler;
use Vitis\RestDB\Contracts\ResolvesEndpoints;
use Vitis\RestDB\Exceptions\InvalidConfigurationException;
use Vitis\RestDB\Exceptions\UnsupportedQueryException;
use Vitis\RestDB\Values\CompiledRequest;
use Vitis\RestDB\Values\Condition;
use Vitis\RestDB\Values\ConnectionConfig;
use Vitis\RestDB\Values\DeleteIntent;
use Vitis\RestDB\Values\EmptyResult;
use Vitis\RestDB\Values\FilterGroup;
use Vitis\RestDB\Values\InsertIntent;
use Vitis\RestDB\Values\SelectIntent;
use Vitis\RestDB\Values\UpdateIntent;

/**
 * The generic adapter's default compiler: intent -> flat query-string wire
 * formats, shaped entirely by connection config (usually via a preset).
 *
 *   filters.style      'suffix' (age_gte=18), 'bracket' (age[gte]=18), or
 *                      'plain' (equality only)
 *   filters.wrapper    optional wrapping param: 'filter' -> filter[age][gte]
 *   filters.separator  suffix separator, default '_'
 *   filters.in         'comma' (id_in=1,2) or 'single' (collapse one-value
 *                      whereIn to equality, throw on more)
 *   filters.like       'contains' (strip SQL % wildcards) or 'raw'
 *   sort.param         default 'sort'
 *   sort.direction_param  set (e.g. '_order') for sort=title&_order=desc;
 *                      unset for prefix style sort=-title
 *   fields.param / include.param  optional column/include list params
 *   writes.update_method  'patch' (default) or 'put'
 *   writes.wrap        optional body envelope key for writes
 *   id_key             primary key for identity URLs, default 'id'
 *
 * Flat styles cannot express nested groups or OR — those throw, never drop.
 */
final class RestRequestCompiler implements RequestCompiler
{
    private readonly string $style;

    private readonly ?string $wrapper;

    private readonly string $separator;

    private readonly string $inMode;

    private readonly string $likeMode;

    private readonly string $idKey;

    private readonly string $sortParam;

    private readonly ?string $sortDirectionParam;

    private readonly ?string $fieldsParam;

    private readonly ?string $includeParam;

    private readonly string $updateMethod;

    private readonly ?string $writeWrap;

    public function __construct(
        private readonly ResolvesEndpoints $endpoints,
        ConnectionConfig $config,
    ) {
        $this->style = self::oneOf($config, 'filters.style', ['suffix', 'bracket', 'plain'], 'suffix');
        $this->wrapper = self::stringOrNull($config, 'filters.wrapper');
        $this->separator = self::stringOrNull($config, 'filters.separator') ?? '_';
        $this->inMode = self::oneOf($config, 'filters.in', ['comma', 'single'], 'comma');
        $this->likeMode = self::oneOf($config, 'filters.like', ['contains', 'raw'], 'contains');
        $this->idKey = self::stringOrNull($config, 'id_key') ?? 'id';
        $this->sortParam = self::stringOrNull($config, 'sort.param') ?? 'sort';
        $this->sortDirectionParam = self::stringOrNull($config, 'sort.direction_param');
        $this->fieldsParam = self::stringOrNull($config, 'fields.param');
        $this->includeParam = self::stringOrNull($config, 'include.param');
        $this->updateMethod = strtoupper(self::oneOf($config, 'writes.update_method', ['patch', 'put', 'PATCH', 'PUT'], 'patch'));
        $this->writeWrap = self::stringOrNull($config, 'writes.wrap');
    }

    public function compileSelect(SelectIntent $intent): CompiledRequest|EmptyResult
    {
        if ($intent->provablyEmpty()) {
            return new EmptyResult;
        }

        $query = [];

        if ($intent->columns !== null && $intent->columns !== []) {
            $query[$this->requireParam($this->fieldsParam, 'fields.param', 'column selection')] = implode(',', $intent->columns);
        }

        if ($intent->includes !== []) {
            $query[$this->requireParam($this->includeParam, 'include.param', 'include lists')] = implode(',', $intent->includes);
        }

        // A lone primary-key equality is the resource URL: GET /posts/1.
        $identity = $this->identityTarget($intent->filters);

        if ($identity !== null && $intent->aggregate === null) {
            return new CompiledRequest('GET', $this->endpoints->resource($intent->resource, $identity), $query);
        }

        $query = [...$query, ...$this->filters($intent->filters)];

        if ($intent->orders !== []) {
            if ($this->sortDirectionParam !== null) {
                $query[$this->sortParam] = implode(',', array_map(fn ($order) => $order->column, $intent->orders));
                $query[$this->sortDirectionParam] = implode(',', array_map(fn ($order) => $order->descending() ? 'desc' : 'asc', $intent->orders));
            } else {
                $query[$this->sortParam] = implode(',', array_map(
                    fn ($order) => ($order->descending() ? '-' : '').$order->column,
                    $intent->orders,
                ));
            }
        }

        return new CompiledRequest('GET', $this->endpoints->collection($intent->resource), $query);
    }

    public function compileInsert(InsertIntent $intent): CompiledRequest
    {
        return new CompiledRequest(
            'POST',
            $this->endpoints->collection($intent->resource),
            [],
            $this->body($intent->rows[0] ?? []),
        );
    }

    public function compileUpdate(UpdateIntent $intent): CompiledRequest
    {
        return new CompiledRequest(
            $this->updateMethod,
            $this->endpoints->resource($intent->resource, $this->requireIdentity($intent->filters, 'update')),
            [],
            $this->body($intent->attributes),
        );
    }

    public function compileDelete(DeleteIntent $intent): CompiledRequest
    {
        return new CompiledRequest(
            'DELETE',
            $this->endpoints->resource($intent->resource, $this->requireIdentity($intent->filters, 'delete')),
        );
    }

    /** @return array<string, string> */
    private function filters(FilterGroup $filters): array
    {
        $query = [];

        foreach ($filters->items as $condition) {
            if (! $condition instanceof Condition) {
                throw UnsupportedQueryException::whereType(
                    "nested filter group (the '{$this->style}' filter style is flat — "
                    .'set a custom compiler class on the connection to support grouping)',
                );
            }

            if (str_contains(strtolower($condition->boolean), 'or')) {
                throw UnsupportedQueryException::whereType(
                    "OR condition (the '{$this->style}' filter style has no OR syntax — "
                    .'set a custom compiler class on the connection to support it)',
                );
            }

            foreach ($this->compileCondition($condition) as $key => $value) {
                if (array_key_exists($key, $query)) {
                    throw UnsupportedQueryException::whereType(
                        "conflicting conditions (both compile to the query parameter [{$key}])",
                    );
                }

                $query[$key] = $value;
            }
        }

        return $query;
    }

    /** @return array<string, string> */
    private function compileCondition(Condition $condition): array
    {
        if ($condition->operator === Operator::In) {
            return $this->compileIn($condition);
        }

        if ($condition->operator === Operator::Between && $this->style === 'suffix') {
            // No native between in suffix style — decompose to gte + lte.
            $bounds = is_array($condition->value) ? array_values($condition->value) : [];

            return [
                $this->key($condition->column, Operator::Gte) => self::scalar($bounds[0] ?? null),
                $this->key($condition->column, Operator::Lte) => self::scalar($bounds[1] ?? null),
            ];
        }

        $value = match (true) {
            $condition->operator === Operator::Null,
            $condition->operator === Operator::NotNull => '1',
            $condition->operator === Operator::Like => $this->likeValue($condition->value),
            is_array($condition->value) => implode(',', array_map(self::scalar(...), $condition->value)),
            default => self::scalar($condition->value),
        };

        return [$this->key($condition->column, $condition->operator) => $value];
    }

    /** @return array<string, string> */
    private function compileIn(Condition $condition): array
    {
        $values = is_array($condition->value) ? array_values($condition->value) : [$condition->value];

        if ($this->inMode === 'single') {
            if (count($values) !== 1) {
                throw new UnsupportedQueryException(
                    'whereIn over '.count($values)." values cannot be expressed — this connection's "
                    ."filters.in mode is 'single' (one value collapses to equality). Query per value, "
                    ."filter client-side from a broader fetch, or set filters.in to 'comma' if the API "
                    .'understands comma lists.',
                );
            }

            return [$this->key($condition->column, Operator::Eq) => self::scalar($values[0])];
        }

        return [$this->key($condition->column, Operator::In) => implode(',', array_map(self::scalar(...), $values))];
    }

    /** Operator -> query parameter name, by style and wrapper. */
    private function key(string $column, Operator $operator): string
    {
        $name = match ($this->style) {
            'plain' => $operator === Operator::Eq || $operator === Operator::In
                ? $column
                : throw UnsupportedQueryException::sqlOperator($operator->value),
            'suffix' => match ($operator) {
                Operator::Eq => $column,
                Operator::Ne, Operator::Gt, Operator::Gte, Operator::Lt, Operator::Lte,
                Operator::Like, Operator::In, Operator::NotIn => $column.$this->separator
                    .str_replace('-', $this->separator, $operator->value),
                default => throw UnsupportedQueryException::sqlOperator($operator->value),
            },
            default => $operator === Operator::Eq ? $column : "{$column}[{$operator->value}]",
        };

        if ($this->wrapper === null) {
            return $name;
        }

        // Wrap the column, keep the style's operator shape: filter[age][gte].
        $bracket = strpos($name, '[');

        return $bracket === false
            ? "{$this->wrapper}[{$name}]"
            : $this->wrapper.'['.substr($name, 0, $bracket).']'.substr($name, $bracket);
    }

    private function likeValue(mixed $value): string
    {
        $value = self::scalar($value);

        return $this->likeMode === 'contains' ? trim($value, '%') : $value;
    }

    private static function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : throw UnsupportedQueryException::rawExpression('a filter value');
    }

    private function identityTarget(FilterGroup $filters): string|int|null
    {
        if (count($filters->items) !== 1) {
            return null;
        }

        $condition = $filters->items[0];

        if (! $condition instanceof Condition || $condition->column !== $this->idKey) {
            return null;
        }

        $value = $condition->operator === Operator::In && is_array($condition->value) && count($condition->value) === 1
            ? $condition->value[0]
            : ($condition->operator === Operator::Eq ? $condition->value : null);

        return is_int($value) || is_string($value) ? $value : null;
    }

    private function requireIdentity(FilterGroup $filters, string $method): string|int
    {
        return $this->identityTarget($filters) ?? throw UnsupportedQueryException::massWrite($method);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function body(array $attributes): array
    {
        return $this->writeWrap === null ? $attributes : [$this->writeWrap => $attributes];
    }

    private function requireParam(?string $param, string $key, string $feature): string
    {
        return $param ?? throw new UnsupportedQueryException(
            ucfirst($feature).' has no query parameter configured on this connection. '
            ."Set {$key} (e.g. 'fields') if the API supports it, or remove the call.",
        );
    }

    /** @param list<string> $allowed */
    private static function oneOf(ConnectionConfig $config, string $key, array $allowed, string $default): string
    {
        $value = $config->get($key, $default);

        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new InvalidConfigurationException(
                "Connection [{$config->name}]: [{$key}] must be one of ['".implode("', '", array_unique(array_map(strtolower(...), $allowed)))
                ."'], got [".(is_scalar($value) ? (string) $value : gettype($value)).'].',
            );
        }

        return $value;
    }

    private static function stringOrNull(ConnectionConfig $config, string $key): ?string
    {
        $value = $config->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
