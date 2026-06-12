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
 * Sequelize-style dollar operators, as served by hatchify and other
 * querystring-parser backends: filter[age][$gte]=18, filter[id][$in]=1,2,3,
 * filter[title][$like]=%cook%. Equality stays bare (filter[status]=open).
 * Like values pass through raw — these servers expect SQL wildcards.
 */
final class DollarOperatorDialect implements FilterDialect
{
    private const OPERATORS = [
        'ne' => '$ne',
        'gt' => '$gt',
        'gte' => '$gte',
        'lt' => '$lt',
        'lte' => '$lte',
        'in' => '$in',
        'not-in' => '$nin',
        'like' => '$like',
    ];

    public function __construct(private readonly NameMapper $names) {}

    public function supports(Operator $operator): bool
    {
        return $operator === Operator::Eq || isset(self::OPERATORS[$operator->value]);
    }

    public function compile(FilterGroup $filters): array
    {
        $params = [];

        foreach ($filters->items as $item) {
            if (! $item instanceof Condition) {
                throw UnsupportedQueryException::whereType('nested filter group (the dollar-operator dialect is flat)');
            }

            $column = $this->names->toApi($item->column);

            if ($item->operator === Operator::Eq) {
                $params["filter[{$column}]"] = self::value($item->value);

                continue;
            }

            $operator = self::OPERATORS[$item->operator->value]
                ?? throw UnsupportedQueryException::sqlOperator($item->operator->value);

            $params["filter[{$column}][{$operator}]"] = self::value($item->value);
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
