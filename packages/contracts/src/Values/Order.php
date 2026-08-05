<?php

declare(strict_types=1);

namespace Vitis\RestDB\Values;

final class Order
{
    public function __construct(
        public readonly string $column,
        public readonly string $direction = 'asc',
    ) {}

    public function descending(): bool
    {
        return strtolower($this->direction) === 'desc';
    }
}
