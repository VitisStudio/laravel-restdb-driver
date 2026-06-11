<?php

declare(strict_types=1);

namespace Vitis\RestDB\Values;

final class PageRequest
{
    public function __construct(
        public readonly ?int $limit = null,
        public readonly ?int $offset = null,
        public readonly ?int $page = null,
        public readonly ?string $cursor = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->limit === null
            && $this->offset === null
            && $this->page === null
            && $this->cursor === null;
    }
}
