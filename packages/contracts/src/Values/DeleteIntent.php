<?php

declare(strict_types=1);

namespace Vitis\RestDB\Values;

final class DeleteIntent
{
    public function __construct(
        public readonly string $resource,
        public readonly FilterGroup $filters = new FilterGroup,
    ) {}
}
