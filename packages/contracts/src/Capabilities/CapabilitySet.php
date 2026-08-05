<?php

declare(strict_types=1);

namespace Vitis\RestDB\Capabilities;

use InvalidArgumentException;

final class CapabilitySet
{
    /**
     * @param  array<string, true>  $capabilities  keyed by Capability value
     * @param  array<string, true>  $operators  keyed by Operator value
     */
    private function __construct(
        private readonly array $capabilities,
        private readonly array $operators,
    ) {}

    public static function none(): self
    {
        return new self([], []);
    }

    public static function of(Capability ...$capabilities): self
    {
        return self::none()->with(...$capabilities);
    }

    public function has(Capability $capability): bool
    {
        return isset($this->capabilities[$capability->value]);
    }

    public function hasOperator(Operator $operator): bool
    {
        return isset($this->operators[$operator->value]);
    }

    public function with(Capability ...$capabilities): self
    {
        $added = $this->capabilities;

        foreach ($capabilities as $capability) {
            $added[$capability->value] = true;
        }

        return new self($added, $this->operators);
    }

    public function without(Capability ...$capabilities): self
    {
        $remaining = $this->capabilities;

        foreach ($capabilities as $capability) {
            unset($remaining[$capability->value]);
        }

        return new self($remaining, $this->operators);
    }

    public function withOperators(Operator ...$operators): self
    {
        $added = $this->operators;

        foreach ($operators as $operator) {
            $added[$operator->value] = true;
        }

        return new self($this->capabilities, $added);
    }

    public function merge(self $other): self
    {
        return new self(
            $this->capabilities + $other->capabilities,
            $this->operators + $other->operators,
        );
    }

    /**
     * Apply a connection-config capabilities array — additive and subtractive.
     *
     * Format: ['select' => true, 'filter' => ['operators' => ['eq', 'in']],
     *          'page.total' => true, 'write.delete' => false]
     *
     * @param  array<string, mixed>  $config
     */
    public function applyConfig(array $config): self
    {
        $result = $this;

        foreach ($config as $key => $value) {
            $capability = Capability::tryFrom($key) ?? throw new InvalidArgumentException(
                "Unknown capability [{$key}] in connection config. Valid keys: "
                .implode(', ', array_column(Capability::cases(), 'value')).'.',
            );

            if ($capability === Capability::Filter && is_array($value)) {
                $result = $result->with(Capability::Filter);

                $operators = $value['operators'] ?? [];

                if (! is_array($operators)) {
                    throw new InvalidArgumentException('capabilities.filter.operators must be an array of operator names.');
                }

                foreach ($operators as $name) {
                    $operator = is_string($name) ? Operator::tryFrom($name) : null;

                    if ($operator === null) {
                        throw new InvalidArgumentException(
                            'Unknown filter operator ['.(is_string($name) ? $name : gettype($name)).'] in connection config. Valid operators: '
                            .implode(', ', array_column(Operator::cases(), 'value')).'.',
                        );
                    }

                    $result = $result->withOperators($operator);
                }

                continue;
            }

            if ($value === true) {
                $result = $result->with($capability);

                continue;
            }

            if ($value === false) {
                $result = $result->without($capability);

                if ($capability === Capability::Filter) {
                    $result = new self($result->capabilities, []);
                }

                continue;
            }

            throw new InvalidArgumentException(
                "Capability [{$key}] must be true, false, or — for 'filter' — an array with an 'operators' list.",
            );
        }

        return $result;
    }

    /** @return list<Capability> */
    public function all(): array
    {
        return array_map(Capability::from(...), array_keys($this->capabilities));
    }

    /** @return list<Operator> */
    public function operators(): array
    {
        return array_map(Operator::from(...), array_keys($this->operators));
    }
}
