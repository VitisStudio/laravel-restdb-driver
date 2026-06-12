<?php

declare(strict_types=1);

namespace Vitis\RestDB;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Vitis\RestDB\Adapters\AdapterRegistry;
use Vitis\RestDB\Auth\AuthenticatorResolver;
use Vitis\RestDB\Connection\RestConnection;
use Vitis\RestDB\Exceptions\InvalidConfigurationException;
use Vitis\RestDB\Http\HttpOptions;
use Vitis\RestDB\Http\Transport;
use Vitis\RestDB\Rest\Presets;
use Vitis\RestDB\Values\ConnectionConfig;

/**
 * The `db` extension target: a resolved connection array becomes a fully wired
 * RestConnection. Bypasses Laravel's ConnectionFactory entirely, so no PDO
 * resolver is ever constructed.
 */
final class RestConnectionFactory
{
    public function __construct(
        private readonly AdapterRegistry $adapters,
        private readonly AuthenticatorResolver $authenticators,
        private readonly HttpFactory $http,
        private readonly Repository $appConfig,
    ) {}

    /** @param array<string, mixed> $config */
    public function make(array $config, string $name): RestConnection
    {
        // ConnectionFactory normally seeds these; we bypass it entirely.
        $config['name'] ??= $name;
        $config['prefix'] ??= '';

        $config = $this->applyPreset($config, $name);
        $config = $this->mergePackageDefaults($config);
        $connectionConfig = new ConnectionConfig($name, $config);

        if ($connectionConfig->baseUrl() === '') {
            throw InvalidConfigurationException::missing('base_url', $name);
        }

        $adapterName = $config['adapter'] ?? null;

        if (! is_string($adapterName)) {
            throw InvalidConfigurationException::missing('adapter', $name);
        }

        $adapter = $this->adapters->get($adapterName);
        $paginator = $adapter->paginator($connectionConfig);

        // Effective capabilities, lowest to highest precedence: adapter
        // baseline -> paginator contributions -> discovered manifest (advisory,
        // additive only) -> declared connection config (additive and
        // subtractive — always wins). Model-level narrowing happens at query
        // time and may only drop, never grant.
        $capabilities = $adapter->capabilities($connectionConfig)
            ->with(...$paginator->provides());

        $capabilities = $capabilities->applyConfig(
            Commands\DiscoverCommand::manifestCapabilities($this->appConfig, $name),
        );

        $capabilities = $capabilities->applyConfig(
            ConnectionConfig::stringKeyed($connectionConfig->get('capabilities')),
        );

        $transport = new Transport(
            $this->http,
            $connectionConfig,
            $this->authenticators->resolve($connectionConfig, $this->authDriverRegistry()),
            HttpOptions::fromConfig(ConnectionConfig::stringKeyed($config['http'] ?? null)),
        );

        return new RestConnection(
            $connectionConfig,
            $adapter->compiler($connectionConfig),
            $adapter->parser($connectionConfig),
            $paginator,
            $capabilities,
            $transport,
            $config,
        );
    }

    /** @return array<string, class-string> */
    private function authDriverRegistry(): array
    {
        $registry = [];

        foreach (ConnectionConfig::stringKeyed($this->appConfig->get('restdb.auth_drivers', [])) as $name => $class) {
            if (is_string($class) && class_exists($class)) {
                $registry[$name] = $class;
            }
        }

        return $registry;
    }

    /**
     * Expand a named wire-format preset into the connection array. Declared
     * connection keys always win; user presets in config('restdb.presets')
     * win over built-ins of the same name.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function applyPreset(array $config, string $name): array
    {
        $preset = $config['preset'] ?? null;

        if ($preset === null) {
            return $config;
        }

        if (! is_string($preset)) {
            throw InvalidConfigurationException::missing('preset (a preset name string)', $name);
        }

        $userPresets = ConnectionConfig::stringKeyed($this->appConfig->get('restdb.presets'));
        $definition = $userPresets[$preset] ?? Presets::builtIn()[$preset] ?? null;

        if (! is_array($definition)) {
            throw InvalidConfigurationException::unknownPreset(
                $preset,
                $name,
                [...array_keys(Presets::builtIn()), ...array_keys($userPresets)],
            );
        }

        /** @var array<string, mixed> $definition */
        return Presets::merge($definition, $config);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function mergePackageDefaults(array $config): array
    {
        foreach (['guards', 'http'] as $section) {
            $defaults = $this->appConfig->get("restdb.{$section}", []);
            $declared = $config[$section] ?? [];

            $config[$section] = array_replace(
                is_array($defaults) ? $defaults : [],
                is_array($declared) ? $declared : [],
            );
        }

        return $config;
    }
}
