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

    public static function tokenEndpointFailed(string $connection, int $status): self
    {
        return new self(
            "Connection [{$connection}]: the OAuth2 token endpoint returned HTTP {$status}. "
            ."Check auth.token_url, auth.client_id, and auth.client_secret under connections.{$connection}.",
        );
    }

    public static function invalidTokenResponse(string $connection): self
    {
        return new self(
            "Connection [{$connection}]: the OAuth2 token endpoint responded without an access_token.",
        );
    }
}
