<?php

declare(strict_types=1);

namespace Vitis\RestDB\Contracts;

use Vitis\RestDB\Capabilities\CapabilitySet;
use Vitis\RestDB\Values\ConnectionConfig;

/**
 * An adapter is a factory of strategies for one API style. GenericAdapter
 * (user-supplied compiler/parser via config) and JsonApiAdapter are both
 * implementations of this single concept — there is no second code path.
 */
interface Adapter
{
    /** 'generic' | 'json-api' | … */
    public function name(): string;

    public function compiler(ConnectionConfig $config): RequestCompiler;

    public function parser(ConnectionConfig $config): ResponseParser;

    public function paginator(ConnectionConfig $config): Paginator;

    public function endpoints(ConnectionConfig $config): ResolvesEndpoints;

    /** The adapter's capability baseline — only what the API style guarantees. */
    public function capabilities(ConnectionConfig $config): CapabilitySet;

    /** Null = this adapter has no spec discovery. */
    public function specParser(): ?SpecParser;
}
