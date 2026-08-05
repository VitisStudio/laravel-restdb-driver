<?php

declare(strict_types=1);

namespace Vitis\RestDB\Values;

use Generator;
use Vitis\RestDB\Capabilities\Operator;

final class FilterGroup
{
    /** @param list<Condition|FilterGroup> $items */
    public function __construct(
        public readonly array $items = [],
        public readonly string $boolean = 'and',
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /** Every condition in this group and all nested groups. @return Generator<Condition> */
    public function allConditions(): Generator
    {
        foreach ($this->items as $item) {
            if ($item instanceof Condition) {
                yield $item;
            } else {
                yield from $item->allConditions();
            }
        }
    }

    public function hasNestedGroups(): bool
    {
        foreach ($this->items as $item) {
            if ($item instanceof self) {
                return true;
            }
        }

        return false;
    }

    public function hasOrBoolean(): bool
    {
        foreach ($this->items as $item) {
            if (str_contains(strtolower($item->boolean), 'or')) {
                return true;
            }

            if ($item instanceof self && $item->hasOrBoolean()) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when this group provably matches nothing: an AND-level In condition
     * with an empty value list. Lets the connection skip HTTP entirely.
     */
    public function provablyEmpty(): bool
    {
        foreach ($this->items as $item) {
            if (
                $item instanceof Condition
                && $item->operator === Operator::In
                && $item->boolean === 'and'
                && $item->value === []
            ) {
                return true;
            }
        }

        return false;
    }
}
