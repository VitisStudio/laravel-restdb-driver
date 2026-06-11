<?php

declare(strict_types=1);

namespace Vitis\RestDB\Values;

final class InsertIntent
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(
        public readonly string $resource,
        public readonly array $rows,
    ) {}
}
