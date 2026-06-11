<?php

declare(strict_types=1);

namespace Vitis\RestDB\Capabilities;

final class CapabilityGate
{
    public function __construct(
        private readonly CapabilitySet $capabilities,
        private readonly string $connection,
    ) {}

    public function allows(Capability $capability): bool
    {
        return $this->capabilities->has($capability);
    }

    public function allowsOperator(Operator $operator): bool
    {
        return $this->capabilities->has(Capability::Filter)
            && $this->capabilities->hasOperator($operator);
    }

    /** @throws UnsupportedCapabilityException */
    public function ensure(Capability $capability, string $method, ?string $model = null): void
    {
        if (! $this->allows($capability)) {
            throw UnsupportedCapabilityException::capability($this->connection, $capability, $method, $model);
        }
    }

    /** @throws UnsupportedCapabilityException */
    public function ensureOperator(Operator $operator, string $method, ?string $model = null): void
    {
        $this->ensure(Capability::Filter, $method, $model);

        if (! $this->capabilities->hasOperator($operator)) {
            throw UnsupportedCapabilityException::operator($this->connection, $operator, $method, $model);
        }
    }
}
