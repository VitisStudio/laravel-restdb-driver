<?php

declare(strict_types=1);

namespace Vitis\RestDB\Exceptions;

use RuntimeException;
use Vitis\RestDB\Contracts\RestDBException;

final class ResultTruncationException extends RuntimeException implements RestDBException
{
    public static function maxPages(string $connection, int $maxPages): self
    {
        return new self(
            "Connection [{$connection}] hit the max_pages guard ({$maxPages} pages) while draining an unbounded result set. "
            .'Results are never silently truncated. Use lazy()/cursor() to stream, add a limit(), '
            ."or raise connections.{$connection}.guards.max_pages.",
        );
    }
}
