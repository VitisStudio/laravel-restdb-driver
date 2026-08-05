<?php

declare(strict_types=1);

namespace Vitis\RestDB\JsonApi;

use Vitis\RestDB\Capabilities\Operator;
use Vitis\RestDB\Contracts\FilterDialect;
use Vitis\RestDB\Contracts\RequestCompiler;
use Vitis\RestDB\Contracts\ResolvesEndpoints;
use Vitis\RestDB\Exceptions\UnsupportedQueryException;
use Vitis\RestDB\JsonApi\Support\NameMapper;
use Vitis\RestDB\Values\CompiledRequest;
use Vitis\RestDB\Values\Condition;
use Vitis\RestDB\Values\DeleteIntent;
use Vitis\RestDB\Values\EmptyResult;
use Vitis\RestDB\Values\FilterGroup;
use Vitis\RestDB\Values\InsertIntent;
use Vitis\RestDB\Values\SelectIntent;
use Vitis\RestDB\Values\UpdateIntent;

final class JsonApiRequestCompiler implements RequestCompiler
{
    private const MEDIA_TYPE = 'application/vnd.api+json';

    /** @param array<string, string> $resourceTypes resource (table) => type member override */
    public function __construct(
        private readonly ResolvesEndpoints $endpoints,
        private readonly FilterDialect $dialect,
        private readonly NameMapper $names,
        private readonly array $resourceTypes = [],
    ) {}

    public function compileSelect(SelectIntent $intent): CompiledRequest|EmptyResult
    {
        if ($intent->provablyEmpty()) {
            return new EmptyResult;
        }

        $query = [];

        // A lone primary-key equality compiles to the resource URL — identity,
        // not filtering: GET /articles/42.
        $identity = $intent->aggregate === null ? $this->identityTarget($intent->filters) : null;

        $path = $identity !== null
            ? $this->endpoints->resource($this->resourcePath($intent->resource), $identity)
            : $this->endpoints->collection($this->resourcePath($intent->resource));

        if ($identity === null) {
            $query = $this->dialect->compile($intent->filters);
        }

        if ($intent->orders !== []) {
            $query['sort'] = implode(',', array_map(
                fn ($order) => ($order->descending() ? '-' : '').$this->names->toApi($order->column),
                $intent->orders,
            ));
        }

        if ($intent->columns !== null) {
            $type = $this->resourceType($intent->resource);
            $query["fields[{$type}]"] = implode(',', array_map($this->names->toApi(...), $intent->columns));
        }

        if ($intent->includes !== []) {
            $query['include'] = implode(',', array_map(
                fn (string $path) => implode('.', array_map($this->names->toApi(...), explode('.', $path))),
                $intent->includes,
            ));
        }

        return new CompiledRequest('GET', $path, $query, null, ['Accept' => self::MEDIA_TYPE]);
    }

    public function compileInsert(InsertIntent $intent): CompiledRequest
    {
        $attributes = $intent->rows[0] ?? [];
        $id = $attributes['id'] ?? null;
        unset($attributes['id']);

        $data = ['type' => $this->resourceType($intent->resource)];

        if (is_string($id) && $id !== '') {
            $data['id'] = $id; // client-generated id (write.client-ids)
        }

        $data['attributes'] = $this->mapAttributes($attributes);

        return new CompiledRequest(
            'POST',
            $this->endpoints->collection($this->resourcePath($intent->resource)),
            [],
            ['data' => $data],
            ['Accept' => self::MEDIA_TYPE, 'Content-Type' => self::MEDIA_TYPE],
        );
    }

    public function compileUpdate(UpdateIntent $intent): CompiledRequest
    {
        $id = $this->requireIdentity($intent->filters, 'update');
        $attributes = $intent->attributes;
        unset($attributes['id']);

        return new CompiledRequest(
            'PATCH',
            $this->endpoints->resource($this->resourcePath($intent->resource), $id),
            [],
            ['data' => [
                'type' => $this->resourceType($intent->resource),
                'id' => (string) $id,
                'attributes' => $this->mapAttributes($attributes),
            ]],
            ['Accept' => self::MEDIA_TYPE, 'Content-Type' => self::MEDIA_TYPE],
        );
    }

    public function compileDelete(DeleteIntent $intent): CompiledRequest
    {
        return new CompiledRequest(
            'DELETE',
            $this->endpoints->resource($this->resourcePath($intent->resource), $this->requireIdentity($intent->filters, 'delete')),
            [],
            null,
            ['Accept' => self::MEDIA_TYPE],
        );
    }

    /**
     * The resource type member: an explicit resource_types override (servers
     * like hatchify demand their schema name, e.g. 'authors' => 'Author'),
     * else the snake table name mapped to the API's style.
     */
    private function resourceType(string $resource): string
    {
        return $this->resourceTypes[$resource] ?? $this->names->toApi($resource);
    }

    /** URL segment for the resource — same mapping by convention. */
    private function resourcePath(string $resource): string
    {
        return $this->names->toApi($resource);
    }

    /** A filter group that is exactly one id equality, or null. */
    private function identityTarget(FilterGroup $filters): string|int|null
    {
        if (count($filters->items) !== 1) {
            return null;
        }

        $condition = $filters->items[0];

        if (! $condition instanceof Condition || $condition->column !== 'id' || $condition->boolean !== 'and') {
            return null;
        }

        $value = $condition->value;

        if ($condition->operator === Operator::In && is_array($value) && count($value) === 1) {
            $value = $value[0];
        } elseif ($condition->operator !== Operator::Eq) {
            return null;
        }

        return is_string($value) || is_int($value) ? $value : null;
    }

    private function requireIdentity(FilterGroup $filters, string $method): string|int
    {
        return $this->identityTarget($filters) ?? throw UnsupportedQueryException::massWrite($method);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function mapAttributes(array $attributes): array
    {
        $mapped = [];

        foreach ($attributes as $name => $value) {
            $mapped[$this->names->toApi($name)] = $value;
        }

        return $mapped;
    }
}
