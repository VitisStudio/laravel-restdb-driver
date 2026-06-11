<?php

declare(strict_types=1);

namespace Vitis\RestDB\Contracts;

interface ResolvesEndpoints
{
    /** Path for the resource collection: 'articles' -> '/articles'. */
    public function collection(string $resource): string;

    /** Path for a single resource: ('articles', 42) -> '/articles/42'. */
    public function resource(string $resource, string|int $id): string;
}
