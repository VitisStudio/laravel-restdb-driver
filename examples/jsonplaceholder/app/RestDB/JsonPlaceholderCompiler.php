<?php

declare(strict_types=1);

namespace App\RestDB;

use Vitis\RestDB\Capabilities\Operator;
use Vitis\RestDB\Contracts\RequestCompiler;
use Vitis\RestDB\Contracts\ResolvesEndpoints;
use Vitis\RestDB\Exceptions\UnsupportedQueryException;
use Vitis\RestDB\Values\CompiledRequest;
use Vitis\RestDB\Values\Condition;
use Vitis\RestDB\Values\DeleteIntent;
use Vitis\RestDB\Values\EmptyResult;
use Vitis\RestDB\Values\FilterGroup;
use Vitis\RestDB\Values\InsertIntent;
use Vitis\RestDB\Values\SelectIntent;
use Vitis\RestDB\Values\UpdateIntent;

/**
 * Intent -> json-server wire format: equality is `field=value`, operators use
 * json-server's suffixes (`field_gte`, `field_ne`, `field_like`), sorting is
 * `_sort`/`_order`. json-server cannot express IN over multiple values or
 * nested boolean groups — those throw instead of guessing.
 */
final class JsonPlaceholderCompiler implements RequestCompiler
{
    public function __construct(private readonly ResolvesEndpoints $endpoints) {}

    public function compileSelect(SelectIntent $intent): CompiledRequest|EmptyResult
    {
        if ($intent->provablyEmpty()) {
            return new EmptyResult;
        }

        // A lone primary-key equality is the resource URL: GET /posts/1.
        $identity = $this->identityTarget($intent->filters);

        if ($identity !== null && $intent->aggregate === null) {
            return new CompiledRequest('GET', $this->endpoints->resource($intent->resource, $identity));
        }

        $query = $this->filters($intent->filters);

        if ($intent->orders !== []) {
            $query['_sort'] = implode(',', array_map(fn ($order) => $order->column, $intent->orders));
            $query['_order'] = implode(',', array_map(fn ($order) => $order->descending() ? 'desc' : 'asc', $intent->orders));
        }

        return new CompiledRequest('GET', $this->endpoints->collection($intent->resource), $query);
    }

    public function compileInsert(InsertIntent $intent): CompiledRequest
    {
        return new CompiledRequest('POST', $this->endpoints->collection($intent->resource), [], $intent->rows[0] ?? []);
    }

    public function compileUpdate(UpdateIntent $intent): CompiledRequest
    {
        return new CompiledRequest(
            'PATCH',
            $this->endpoints->resource($intent->resource, $this->requireIdentity($intent->filters, 'update')),
            [],
            $intent->attributes,
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
                throw UnsupportedQueryException::whereType('nested filter group (json-server filters are flat)');
            }

            [$key, $value] = match ($condition->operator) {
                Operator::Eq => [$condition->column, $condition->value],
                Operator::In => [$condition->column, $this->singleValue($condition)],
                Operator::Ne => ["{$condition->column}_ne", $condition->value],
                Operator::Gte => ["{$condition->column}_gte", $condition->value],
                Operator::Lte => ["{$condition->column}_lte", $condition->value],
                Operator::Like => ["{$condition->column}_like", trim((string) $condition->value, '%')],
                default => throw UnsupportedQueryException::sqlOperator($condition->operator->value),
            };

            $query[$key] = is_scalar($value) ? (string) $value : throw UnsupportedQueryException::rawExpression('a filter value');
        }

        return $query;
    }

    /**
     * json-server has no IN syntax PHP query arrays can express — a
     * single-value IN collapses to equality, anything wider fails loudly.
     */
    private function singleValue(Condition $condition): mixed
    {
        $values = is_array($condition->value) ? $condition->value : [$condition->value];

        if (count($values) !== 1) {
            throw new UnsupportedQueryException(
                'JSONPlaceholder cannot express whereIn over '.count($values).' values — '
                .'json-server has no IN syntax. Query per value, or filter client-side from a broader fetch.',
            );
        }

        return $values[0];
    }

    private function identityTarget(FilterGroup $filters): string|int|null
    {
        if (count($filters->items) !== 1) {
            return null;
        }

        $condition = $filters->items[0];

        if (! $condition instanceof Condition || $condition->column !== 'id') {
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
}
