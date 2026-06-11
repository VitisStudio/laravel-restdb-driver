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
use Vitis\RestDB\Values\ConnectionConfig;

/**
 * The "talk to any REST API" layer: the user hand-crafts a RequestCompiler and
 * ResponseParser per API and declares its capabilities in config. The baseline
 * is NONE — nothing works until declared.
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
        return $this->strategy($config, 'compiler', RequestCompiler::class);
    }

    public function parser(ConnectionConfig $config): ResponseParser
    {
        return $this->strategy($config, 'parser', ResponseParser::class);
    }

    public function paginator(ConnectionConfig $config): Paginator
    {
        if ($config->get('paginator') === null) {
            return new NullPaginator;
        }

        return $this->strategy($config, 'paginator', Paginator::class);
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
