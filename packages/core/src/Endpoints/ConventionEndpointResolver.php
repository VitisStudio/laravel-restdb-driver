<?php

declare(strict_types=1);

namespace Vitis\RestDB\Endpoints;

use Vitis\RestDB\Contracts\ResolvesEndpoints;

/** $table -> '/{table}' by convention, with explicit per-resource overrides from config. */
final class ConventionEndpointResolver implements ResolvesEndpoints
{
    /** @param array<string, string> $overrides resource => path */
    public function __construct(private readonly array $overrides = []) {}

    public function collection(string $resource): string
    {
        $path = $this->overrides[$resource] ?? '/'.$resource;

        return '/'.ltrim($path, '/');
    }

    public function resource(string $resource, string|int $id): string
    {
        return $this->collection($resource).'/'.rawurlencode((string) $id);
    }
}
