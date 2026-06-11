<?php

declare(strict_types=1);

namespace Vitis\RestDB\Contracts;

interface SpecParser
{
    /**
     * Parse an API spec document into a manifest array of capabilities and
     * per-resource filterable/sortable/includable fields. Build-time only —
     * runtime reads committed manifests, never the spec.
     *
     * @return array<string, mixed>
     */
    public function parse(string $specPath): array;
}
