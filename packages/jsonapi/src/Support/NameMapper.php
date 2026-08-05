<?php

declare(strict_types=1);

namespace Vitis\RestDB\JsonApi\Support;

/**
 * Attribute/relationship name mapping between Eloquent's snake_case and the
 * API's member-name style. JSON:API v1.1 recommends camelCase (the default
 * here); v1.0-era servers often use kebab-case. 'none' passes names through.
 */
final class NameMapper
{
    public function __construct(private readonly string $style = 'camel') {}

    /** snake_case -> API style. */
    public function toApi(string $name): string
    {
        return match ($this->style) {
            'kebab' => str_replace('_', '-', $name),
            'camel' => lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $name)))),
            default => $name,
        };
    }

    /** API style -> snake_case. */
    public function toModel(string $name): string
    {
        return match ($this->style) {
            'kebab' => str_replace('-', '_', $name),
            'camel' => strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name)),
            default => $name,
        };
    }
}
