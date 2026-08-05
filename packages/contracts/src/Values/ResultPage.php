<?php

declare(strict_types=1);

namespace Vitis\RestDB\Values;

final class ResultPage
{
    /**
     * @param  list<array<string, mixed>>  $rows  flat attribute rows
     * @param  array<string, mixed>  $meta  pagination-relevant metadata
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $meta = [],
    ) {}
}
