<?php

declare(strict_types=1);

namespace Vitis\RestDB\Values;

final class SelectIntent
{
    /**
     * @param  list<string>|null  $columns  null = all columns
     * @param  list<Order>  $orders
     * @param  list<string>  $includes
     */
    public function __construct(
        public readonly string $resource,
        public readonly ?array $columns = null,
        public readonly FilterGroup $filters = new FilterGroup,
        public readonly array $orders = [],
        public readonly ?PageRequest $page = null,
        public readonly array $includes = [],
        public readonly ?string $aggregate = null,
    ) {}

    /** Provably matches nothing (e.g. whereIn over an empty list) — no HTTP should be issued. */
    public function provablyEmpty(): bool
    {
        return $this->filters->provablyEmpty();
    }
}
