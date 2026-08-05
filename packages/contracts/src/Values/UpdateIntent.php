<?php

declare(strict_types=1);

namespace Vitis\RestDB\Values;

final class UpdateIntent
{
    /** @param array<string, mixed> $attributes dirty attributes only */
    public function __construct(
        public readonly string $resource,
        public readonly array $attributes,
        public readonly FilterGroup $filters = new FilterGroup,
    ) {}
}
