<?php

declare(strict_types=1);

namespace Vitis\RestDB\Values;

final class WriteResult
{
    /** @param array<string, mixed> $attributes server-side resource state after the write */
    public function __construct(
        public readonly int $affected,
        public readonly string|int|null $id = null,
        public readonly array $attributes = [],
    ) {}
}
