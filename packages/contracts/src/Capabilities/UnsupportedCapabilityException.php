<?php

declare(strict_types=1);

namespace Vitis\RestDB\Capabilities;

use RuntimeException;
use Vitis\RestDB\Contracts\RestDBException;

final class UnsupportedCapabilityException extends RuntimeException implements RestDBException
{
    public function __construct(
        string $message,
        public readonly string $connection,
        public readonly string $capability,
        public readonly string $builderMethod,
        public readonly ?string $model = null,
    ) {
        parent::__construct($message);
    }

    public static function capability(string $connection, Capability $capability, string $method, ?string $model = null): self
    {
        $usedIn = $model === null ? "used in {$method}()" : "used in {$method}() on {$model}";

        return new self(
            "Connection [{$connection}] does not support [{$capability->value}] ({$usedIn}). "
            ."Hint: if the API actually supports it, add '{$capability->value}' under "
            ."connections.{$connection}.capabilities — or remove the {$method}() call.",
            $connection,
            $capability->value,
            $method,
            $model,
        );
    }

    public static function operator(string $connection, Operator $operator, string $method, ?string $model = null): self
    {
        $usedIn = $model === null ? "used in {$method}()" : "used in {$method}() on {$model}";

        return new self(
            "Connection [{$connection}] does not support the filter operator [{$operator->value}] ({$usedIn}). "
            ."Hint: if the API actually supports it, add '{$operator->value}' to "
            ."connections.{$connection}.capabilities.filter.operators — or change the {$method}() call.",
            $connection,
            $operator->value,
            $method,
            $model,
        );
    }
}
