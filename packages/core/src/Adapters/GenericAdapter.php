<?php

declare(strict_types=1);

namespace Vitis\RestDB\Adapters;

use Illuminate\Contracts\Container\Container;
use Vitis\RestDB\Capabilities\CapabilitySet;
use Vitis\RestDB\Contracts\Adapter;
use Vitis\RestDB\Contracts\Paginator;
use Vitis\RestDB\Contracts\RequestCompiler;
use Vitis\RestDB\Contracts\ResolvesEndpoints;
use Vitis\RestDB\Contracts\ResponseParser;
use Vitis\RestDB\Contracts\SpecParser;
use Vitis\RestDB\Endpoints\ConventionEndpointResolver;
use Vitis\RestDB\Exceptions\InvalidConfigurationException;
use Vitis\RestDB\Rest\JsonResponseParser;
use Vitis\RestDB\Rest\QueryParamPaginator;
use Vitis\RestDB\Rest\RestRequestCompiler;
use Vitis\RestDB\Values\ConnectionConfig;

/**
 * The "talk to any REST API" layer. Config (usually a preset) shapes the
 * default compiler, parser, and paginator; a connection may swap any piece
 * for its own class when the API outgrows configuration. The capability
 * baseline is NONE — nothing works until declared (presets declare what
 * their named server framework honors).
 */
final class GenericAdapter implements Adapter
{
    public function __construct(private readonly Container $container) {}

    public function name(): string
    {
        return 'generic';
    }

    public function compiler(ConnectionConfig $config): RequestCompiler
    {
        if ($config->get('compiler') === null) {
            return new RestRequestCompiler($this->endpoints($config), $config);
        }

        return $this->strategy($config, 'compiler', RequestCompiler::class);
    }

    public function parser(ConnectionConfig $config): ResponseParser
    {
        if ($config->get('parser') === null) {
            return new JsonResponseParser($config);
        }

        return $this->strategy($config, 'parser', ResponseParser::class);
    }

    public function paginator(ConnectionConfig $config): Paginator
    {
        if ($config->get('paginator') !== null) {
            return $this->strategy($config, 'paginator', Paginator::class);
        }

        if (is_array($config->get('pagination'))) {
            return new QueryParamPaginator($config);
        }

        return new NullPaginator;
    }

    public function endpoints(ConnectionConfig $config): ResolvesEndpoints
    {
        $overrides = $config->get('endpoints');

        /** @var array<string, string> $overrides */
        $overrides = is_array($overrides) ? $overrides : [];

        return new ConventionEndpointResolver($overrides);
    }

    public function capabilities(ConnectionConfig $config): CapabilitySet
    {
        return CapabilitySet::none();
    }

    public function specParser(): ?SpecParser
    {
        return null;
    }

    /**
     * @template TInterface of object
     *
     * @param  class-string<TInterface>  $interface
     * @return TInterface
     */
    private function strategy(ConnectionConfig $config, string $key, string $interface): object
    {
        $class = $config->get($key);

        if (! is_string($class)) {
            throw InvalidConfigurationException::missing($key, $config->name);
        }

        $instance = $this->container->make($class, [
            'config' => $config,
            'endpoints' => $this->endpoints($config),
        ]);

        if (! $instance instanceof $interface) {
            throw InvalidConfigurationException::invalidClass($key, $class, $interface, $config->name);
        }

        return $instance;
    }
}
