<?php

declare(strict_types=1);

namespace Vitis\RestDB\Exceptions;

use RuntimeException;
use Vitis\RestDB\Contracts\RestDBException;

/**
 * Thrown when builder state cannot be translated to a REST request. A dropped
 * where is a data-exposure bug — anything unmappable throws, never drops.
 */
final class UnsupportedQueryException extends RuntimeException implements RestDBException
{
    public static function whereType(string $type): self
    {
        return new self(
            "Where type [{$type}] cannot be translated to a REST request by the restdb driver. "
            .'Nothing is ever silently dropped — remove the clause or express it with a supported where.',
        );
    }

    public static function sqlOperator(string $operator): self
    {
        return new self(
            "Operator [{$operator}] cannot be translated to a REST filter by the restdb driver.",
        );
    }

    public static function subquery(string $method): self
    {
        return new self(
            "Subqueries are not supported by the restdb driver (used in {$method}()).",
        );
    }

    public static function rawExpression(string $context): self
    {
        return new self(
            "Raw expressions (DB::raw) are not supported by the restdb driver (found in {$context}).",
        );
    }

    public static function notBetween(): self
    {
        return new self('whereNotBetween() is not supported by the restdb driver.');
    }

    public static function aliasedTable(string $from): self
    {
        return new self(
            "Table aliases are not supported by the restdb driver (got [{$from}]).",
        );
    }

    public static function aggregate(string $function): self
    {
        return new self(
            "Aggregate [{$function}] has no generic REST mapping and is not supported by the restdb driver.",
        );
    }
}
