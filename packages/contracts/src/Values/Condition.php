<?php

declare(strict_types=1);

namespace Vitis\RestDB\Values;

use Vitis\RestDB\Capabilities\Operator;

final class Condition
{
    public function __construct(
        public readonly string $column,
        public readonly Operator $operator,
        public readonly mixed $value,
        public readonly string $boolean = 'and',
    ) {}
}
