<?php

declare(strict_types=1);

namespace Vitis\RestDB\Values;

final class PageInfo
{
    public function __construct(
        public readonly bool $hasMore,
        public readonly ?int $total = null,
        public readonly ?string $cursor = null,
        public readonly ?string $nextUrl = null,
    ) {}
}
