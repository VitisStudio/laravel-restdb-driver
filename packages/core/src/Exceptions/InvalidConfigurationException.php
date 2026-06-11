<?php

declare(strict_types=1);

namespace Vitis\RestDB\Exceptions;

use RuntimeException;
use Vitis\RestDB\Contracts\RestDBException;

final class InvalidConfigurationException extends RuntimeException implements RestDBException
{
    public static function missing(string $key, string $connection): self
    {
        return new self(
            "Connection [{$connection}] is missing required config [{$key}]. "
            ."Add it under connections.{$connection} in config/database.php.",
        );
    }

    public static function invalidClass(string $key, string $class, string $interface, string $connection): self
    {
        return new self(
            "Connection [{$connection}]: [{$class}] configured as [{$key}] must implement {$interface}.",
        );
    }

    public static function unknownAdapter(string $adapter): self
    {
        return new self(
            "No adapter registered as [{$adapter}]. Register it in config/restdb.php "
            ."under 'adapters', or via RestDB::registerAdapter().",
        );
    }

    public static function unknownAuthDriver(string $driver, string $connection): self
    {
        return new self(
            "Connection [{$connection}]: unknown auth driver [{$driver}]. Use a built-in "
            .'(none, basic, bearer, api_key), a driver registered in config/restdb.php '
            ."under 'auth_drivers', or a class-string implementing Authenticator.",
        );
    }
}
