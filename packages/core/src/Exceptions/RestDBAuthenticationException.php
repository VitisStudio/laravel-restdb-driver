<?php

declare(strict_types=1);

namespace Vitis\RestDB\Exceptions;

use RuntimeException;
use Vitis\RestDB\Contracts\RestDBException;

final class RestDBAuthenticationException extends RuntimeException implements RestDBException
{
    public static function unauthorized(string $connection): self
    {
        return new self(
            "Connection [{$connection}] was rejected with HTTP 401. "
            ."Check the credentials under connections.{$connection}.auth.",
        );
    }
}
