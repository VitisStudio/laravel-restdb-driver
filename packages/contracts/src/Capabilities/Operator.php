<?php

declare(strict_types=1);

namespace Vitis\RestDB\Capabilities;

enum Operator: string
{
    case Eq = 'eq';
    case Ne = 'ne';
    case Gt = 'gt';
    case Gte = 'gte';
    case Lt = 'lt';
    case Lte = 'lte';
    case In = 'in';
    case NotIn = 'not-in';
    case Null = 'null';
    case NotNull = 'not-null';
    case Between = 'between';
    case Like = 'like';

    /** Map a SQL-style builder operator string to an Operator, or null when unmappable. */
    public static function fromSqlOperator(string $operator): ?self
    {
        return match (strtolower($operator)) {
            '=' => self::Eq,
            '!=', '<>' => self::Ne,
            '>' => self::Gt,
            '>=' => self::Gte,
            '<' => self::Lt,
            '<=' => self::Lte,
            'like' => self::Like,
            default => null,
        };
    }
}
