<?php

declare(strict_types=1);

namespace Vitis\RestDB\Adapters;

use Illuminate\Contracts\Container\Container;
use Vitis\RestDB\Contracts\Adapter;
use Vitis\RestDB\Exceptions\InvalidConfigurationException;

/**
 * name => Adapter. Seeded from config('restdb.adapters'); third-party packages
 * add entries via RestDB::registerAdapter() in their service providers.
 */
final class AdapterRegistry
{
    /** @var array<string, class-string<Adapter>|Adapter> */
    private array $adapters = [];

    /** @var array<string, Adapter> */
    private array $resolved = [];

    public function __construct(private readonly Container $container) {}

    /** @param class-string<Adapter>|Adapter $adapter */
    public function registerAdapter(string $name, string|Adapter $adapter): void
    {
        $this->adapters[$name] = $adapter;
        unset($this->resolved[$name]);
    }

    public function get(string $name): Adapter
    {
        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        $adapter = $this->adapters[$name] ?? throw InvalidConfigurationException::unknownAdapter($name);

        if (is_string($adapter)) {
            $instance = $this->container->make($adapter);

            if (! $instance instanceof Adapter) {
                throw InvalidConfigurationException::invalidClass('adapter', $adapter, Adapter::class, $name);
            }

            $adapter = $instance;
        }

        return $this->resolved[$name] = $adapter;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->adapters);
    }
}
