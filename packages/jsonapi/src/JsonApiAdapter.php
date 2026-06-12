<?php

declare(strict_types=1);

namespace Vitis\RestDB\JsonApi;

use Illuminate\Contracts\Container\Container;
use Vitis\RestDB\Capabilities\Capability;
use Vitis\RestDB\Capabilities\CapabilitySet;
use Vitis\RestDB\Capabilities\Operator;
use Vitis\RestDB\Contracts\Adapter;
use Vitis\RestDB\Contracts\FilterDialect;
use Vitis\RestDB\Contracts\Paginator;
use Vitis\RestDB\Contracts\RequestCompiler;
use Vitis\RestDB\Contracts\ResolvesEndpoints;
use Vitis\RestDB\Contracts\ResponseParser;
use Vitis\RestDB\Contracts\SpecParser;
use Vitis\RestDB\Endpoints\ConventionEndpointResolver;
use Vitis\RestDB\Exceptions\InvalidConfigurationException;
use Vitis\RestDB\JsonApi\Dialects\CommaListDialect;
use Vitis\RestDB\JsonApi\Dialects\NestedOperatorDialect;
use Vitis\RestDB\JsonApi\Pagination\CursorPaginator;
use Vitis\RestDB\JsonApi\Pagination\OffsetPaginator;
use Vitis\RestDB\JsonApi\Pagination\PageNumberPaginator;
use Vitis\RestDB\JsonApi\Support\NameMapper;
use Vitis\RestDB\Values\ConnectionConfig;

/**
 * A complete, preconfigured implementation of the core contracts for JSON:API
 * v1.1 — one adapter, zero new architecture. Every piece implements a core
 * contract; if JSON:API ever needed a private core hook, the contracts would
 * be wrong.
 */
final class JsonApiAdapter implements Adapter
{
    public function __construct(private readonly Container $container) {}

    public function name(): string
    {
        return 'json-api';
    }

    public function compiler(ConnectionConfig $config): RequestCompiler
    {
        return new JsonApiRequestCompiler(
            $this->endpoints($config),
            $this->dialect($config),
            $this->names($config),
        );
    }

    public function parser(ConnectionConfig $config): ResponseParser
    {
        return new JsonApiResponseParser($this->names($config));
    }

    public function paginator(ConnectionConfig $config): Paginator
    {
        $strategy = $config->get('pagination.strategy', 'page-number');
        $size = $config->get('pagination.size');
        $size = is_int($size) && $size > 0 ? $size : null;
        $metaTotal = $config->get('pagination.meta_total');
        $metaTotal = is_string($metaTotal) && $metaTotal !== '' ? $metaTotal : null;

        return match ($strategy) {
            'page-number' => new PageNumberPaginator($size, $metaTotal),
            'offset' => new OffsetPaginator($size, $metaTotal),
            'cursor' => new CursorPaginator($size, $metaTotal),
            default => throw InvalidConfigurationException::missing(
                "pagination.strategy ('page-number', 'offset', or 'cursor')",
                $config->name,
            ),
        };
    }

    public function endpoints(ConnectionConfig $config): ResolvesEndpoints
    {
        $overrides = $config->get('endpoints');

        /** @var array<string, string> $overrides */
        $overrides = is_array($overrides) ? $overrides : [];

        return new ConventionEndpointResolver($overrides);
    }

    /**
     * Honest defaults over optimistic defaults: only what the spec guarantees.
     * Filter operators come from the dialect's supports(); totals, counts, and
     * page capabilities come from the paginator and declared config.
     */
    public function capabilities(ConnectionConfig $config): CapabilitySet
    {
        $dialect = $this->dialect($config);

        $operators = array_filter(Operator::cases(), $dialect->supports(...));

        return CapabilitySet::of(
            Capability::Select,
            Capability::Columns,
            Capability::Include,
            Capability::Filter,
            Capability::Sort,
            Capability::MultiSort,
            Capability::Insert,
            Capability::Update,
            Capability::Delete,
        )->withOperators(...$operators);
    }

    public function specParser(): SpecParser
    {
        return new JsonApiSpecParser;
    }

    private function dialect(ConnectionConfig $config): FilterDialect
    {
        $dialect = $config->get('filter_dialect', 'comma-list');

        if (is_string($dialect) && class_exists($dialect)) {
            $instance = $this->container->make($dialect, ['config' => $config, 'names' => $this->names($config)]);

            return $instance instanceof FilterDialect
                ? $instance
                : throw InvalidConfigurationException::invalidClass('filter_dialect', $dialect, FilterDialect::class, $config->name);
        }

        return match ($dialect) {
            'comma-list' => new CommaListDialect($this->names($config)),
            'nested-operator' => new NestedOperatorDialect($this->names($config)),
            default => throw InvalidConfigurationException::missing(
                "filter_dialect ('comma-list', 'nested-operator', or a FilterDialect class)",
                $config->name,
            ),
        };
    }

    private function names(ConnectionConfig $config): NameMapper
    {
        $style = $config->get('name_mapping', 'camel');

        return new NameMapper(is_string($style) ? $style : 'camel');
    }
}
