<?php

declare(strict_types=1);

namespace Vitis\RestDB\JsonApi\Dialects;

use Vitis\RestDB\Capabilities\Operator;
use Vitis\RestDB\Contracts\FilterDialect;
use Vitis\RestDB\Exceptions\UnsupportedQueryException;
use Vitis\RestDB\JsonApi\Support\NameMapper;
use Vitis\RestDB\Values\Condition;
use Vitis\RestDB\Values\FilterGroup;

/**
 * The spatie/laravel-query-builder convention: filter[status]=open,
 * filter[id]=1,2,3. Equality and In only — what it supports is exactly what
 * it contributes to the connection's capability set.
 */
final class CommaListDialect implements FilterDialect
{
    public function __construct(private readonly NameMapper $names) {}

    public function supports(Operator $operator): bool
    {
        return in_array($operator, [Operator::Eq, Operator::In], true);
    }

    public function compile(FilterGroup $filters): array
    {
        $params = [];

        foreach ($filters->items as $item) {
            if (! $item instanceof Condition) {
                throw UnsupportedQueryException::whereType('nested filter group (the comma-list dialect is flat)');
            }

            $column = $this->names->toApi($item->column);
            $value = $item->value;

            $params["filter[{$column}]"] = is_array($value)
                ? implode(',', array_map(self::scalar(...), $value))
                : self::scalar($value);
        }

        return $params;
    }

    private static function scalar(mixed $value): string
    {
        return is_scalar($value)
            ? (string) $value
            : throw UnsupportedQueryException::rawExpression('a filter value');
    }
}
