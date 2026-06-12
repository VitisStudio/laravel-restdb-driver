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
 * Operator-suffixed filters: filter[age][gte]=18, filter[id][in]=1,2,3,
 * filter[deleted_at][null]=1. Equality stays bare (filter[status]=open).
 */
final class NestedOperatorDialect implements FilterDialect
{
    private const SUPPORTED = [
        Operator::Eq, Operator::Ne, Operator::Gt, Operator::Gte, Operator::Lt,
        Operator::Lte, Operator::In, Operator::NotIn, Operator::Like,
        Operator::Between, Operator::Null, Operator::NotNull,
    ];

    public function __construct(private readonly NameMapper $names) {}

    public function supports(Operator $operator): bool
    {
        return in_array($operator, self::SUPPORTED, true);
    }

    public function compile(FilterGroup $filters): array
    {
        $params = [];

        foreach ($filters->items as $item) {
            if (! $item instanceof Condition) {
                throw UnsupportedQueryException::whereType('nested filter group (the nested-operator dialect is flat)');
            }

            $column = $this->names->toApi($item->column);

            [$key, $value] = match ($item->operator) {
                Operator::Eq => ["filter[{$column}]", self::value($item->value)],
                Operator::Null => ["filter[{$column}][null]", '1'],
                Operator::NotNull => ["filter[{$column}][not-null]", '1'],
                default => ["filter[{$column}][{$item->operator->value}]", self::value($item->value)],
            };

            $params[$key] = $value;
        }

        return $params;
    }

    private static function value(mixed $value): string
    {
        if (is_array($value)) {
            return implode(',', array_map(self::value(...), $value));
        }

        return is_scalar($value)
            ? (string) $value
            : throw UnsupportedQueryException::rawExpression('a filter value');
    }
}
