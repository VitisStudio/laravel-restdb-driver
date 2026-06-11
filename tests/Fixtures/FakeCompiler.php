<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Vitis\RestDB\Contracts\RequestCompiler;
use Vitis\RestDB\Contracts\ResolvesEndpoints;
use Vitis\RestDB\Values\CompiledRequest;
use Vitis\RestDB\Values\Condition;
use Vitis\RestDB\Values\DeleteIntent;
use Vitis\RestDB\Values\EmptyResult;
use Vitis\RestDB\Values\FilterGroup;
use Vitis\RestDB\Values\InsertIntent;
use Vitis\RestDB\Values\SelectIntent;
use Vitis\RestDB\Values\UpdateIntent;

/**
 * Minimal query-string compiler for tests: conditions become flat params
 * (status=open, age_gte=18, id=1,2), sort joins with commas, fields lists
 * columns. Just enough surface to assert what reached the wire.
 */
final class FakeCompiler implements RequestCompiler
{
    public function __construct(private readonly ResolvesEndpoints $endpoints) {}

    public function compileSelect(SelectIntent $intent): CompiledRequest|EmptyResult
    {
        $query = $this->filters($intent->filters);

        if ($intent->orders !== []) {
            $query['sort'] = implode(',', array_map(
                fn ($order) => ($order->descending() ? '-' : '').$order->column,
                $intent->orders,
            ));
        }

        if ($intent->columns !== null) {
            $query['fields'] = implode(',', $intent->columns);
        }

        if ($intent->aggregate === 'count') {
            $query['count'] = '1';
        }

        if ($intent->page?->limit !== null) {
            $query['limit'] = (string) $intent->page->limit;
        }

        if ($intent->page?->offset !== null) {
            $query['offset'] = (string) $intent->page->offset;
        }

        return new CompiledRequest('GET', $this->endpoints->collection($intent->resource), $query);
    }

    /** @return array<string, string> */
    private function filters(FilterGroup $filters): array
    {
        $query = [];

        foreach ($filters->allConditions() as $condition) {
            $query[$this->key($condition)] = $this->value($condition);
        }

        return $query;
    }

    private function key(Condition $condition): string
    {
        $suffix = match ($condition->operator->value) {
            'eq', 'in' => '',
            default => '_'.str_replace('-', '_', $condition->operator->value),
        };

        return $condition->column.$suffix;
    }

    private function value(Condition $condition): string
    {
        if (is_array($condition->value)) {
            return implode(',', array_map(strval(...), $condition->value));
        }

        return $condition->value === null ? '' : (string) $condition->value;
    }

    public function compileInsert(InsertIntent $intent): CompiledRequest
    {
        throw new \LogicException('Writes are not part of the read-core fixture.');
    }

    public function compileUpdate(UpdateIntent $intent): CompiledRequest
    {
        throw new \LogicException('Writes are not part of the read-core fixture.');
    }

    public function compileDelete(DeleteIntent $intent): CompiledRequest
    {
        throw new \LogicException('Writes are not part of the read-core fixture.');
    }
}
