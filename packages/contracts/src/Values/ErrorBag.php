<?php

declare(strict_types=1);

namespace Vitis\RestDB\Values;

final class ErrorBag
{
    /**
     * @param  array<string, list<string>>  $fieldMessages  validation messages keyed by field
     * @param  list<string>  $general  errors not tied to a field
     */
    public function __construct(
        public readonly array $fieldMessages = [],
        public readonly array $general = [],
    ) {}

    public function any(): bool
    {
        return $this->fieldMessages !== [] || $this->general !== [];
    }

    public function summary(): string
    {
        $parts = $this->general;

        foreach ($this->fieldMessages as $field => $messages) {
            $parts[] = $field.': '.implode('; ', $messages);
        }

        return implode(' | ', $parts);
    }
}
