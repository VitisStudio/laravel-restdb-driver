<?php

declare(strict_types=1);

namespace Vitis\RestDB\Contracts;

use Vitis\RestDB\Capabilities\Operator;
use Vitis\RestDB\Values\FilterGroup;

/** The spec-agnostic part of filtering — pluggable per connection. */
interface FilterDialect
{
    public function supports(Operator $operator): bool;

    /** @return array<string, mixed> query parameters */
    public function compile(FilterGroup $filters): array;
}
