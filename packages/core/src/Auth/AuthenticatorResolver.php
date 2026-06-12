<?php

declare(strict_types=1);

namespace Vitis\RestDB\Auth;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Container\Container;
use Vitis\RestDB\Contracts\Authenticator;
use Vitis\RestDB\Exceptions\InvalidConfigurationException;
use Vitis\RestDB\Values\ConnectionConfig;

/**
 * Maps string drivers to authenticators, with class-string passthrough for
 * fully custom implementations (container-resolved, so they can have their own
 * dependencies). Instances are cached per connection name for the process
 * lifetime — instance state, never static state.
 */
final class AuthenticatorResolver
{
    /** @var array<string, Authenticator> */
    private array $resolved = [];

    public function __construct(private readonly Container $container) {}

    /** @param array<string, class-string> $registry from config('restdb.auth_drivers') */
    public function resolve(ConnectionConfig $config, array $registry = []): Authenticator
    {
        return $this->resolved[$config->name] ??= $this->build($config, $registry);
    }

    /** @param array<string, class-string> $registry */
    private function build(ConnectionConfig $config, array $registry): Authenticator
    {
        $auth = $config->auth();
        $driver = $auth['driver'] ?? 'none';

        if (! is_string($driver)) {
            throw InvalidConfigurationException::missing('auth.driver', $config->name);
        }

        return match ($driver) {
            'none' => new NoneAuthenticator,
            'bearer' => new BearerAuthenticator($this->requireString($auth, 'token', $config->name)),
            'basic' => new BasicAuthenticator(
                $this->requireString($auth, 'username', $config->name),
                $this->requireString($auth, 'password', $config->name),
            ),
            'api_key' => ApiKeyAuthenticator::fromConfig($auth, $config->name),
            'oauth2_client_credentials' => $this->clientCredentials($auth, $config),
            'oauth2_refresh_token' => $this->refreshToken($auth, $config),
            default => $this->custom($driver, $registry, $config),
        };
    }

    /** @param array<string, mixed> $auth */
    private function refreshToken(array $auth, ConnectionConfig $config): RefreshTokenAuthenticator
    {
        $store = $auth['cache_store'] ?? null;
        $skew = $auth['expiry_skew'] ?? 60;

        return new RefreshTokenAuthenticator(
            $this->container->make(Factory::class),
            $this->container->make(\Illuminate\Http\Client\Factory::class),
            $config->name,
            $this->requireString($auth, 'token_url', $config->name),
            $this->requireString($auth, 'client_id', $config->name),
            $this->requireString($auth, 'client_secret', $config->name),
            $this->requireString($auth, 'refresh_token', $config->name),
            is_string($store) ? $store : null,
            is_int($skew) ? $skew : 60,
        );
    }

    /** @param array<string, mixed> $auth */
    private function clientCredentials(array $auth, ConnectionConfig $config): ClientCredentialsAuthenticator
    {
        $scopes = [];

        foreach (is_array($auth['scopes'] ?? null) ? $auth['scopes'] : [] as $scope) {
            if (is_string($scope)) {
                $scopes[] = $scope;
            }
        }

        $store = $auth['cache_store'] ?? null;
        $skew = $auth['expiry_skew'] ?? 60;

        return new ClientCredentialsAuthenticator(
            $this->container->make(Factory::class),
            $this->container->make(\Illuminate\Http\Client\Factory::class),
            $config->name,
            $this->requireString($auth, 'token_url', $config->name),
            $this->requireString($auth, 'client_id', $config->name),
            $this->requireString($auth, 'client_secret', $config->name),
            $scopes,
            is_string($store) ? $store : null,
            is_int($skew) ? $skew : 60,
        );
    }

    /** @param array<string, class-string> $registry */
    private function custom(string $driver, array $registry, ConnectionConfig $config): Authenticator
    {
        $class = $registry[$driver] ?? (class_exists($driver) ? $driver : null);

        if ($class === null) {
            throw InvalidConfigurationException::unknownAuthDriver($driver, $config->name);
        }

        $instance = $this->container->make($class, ['config' => $config]);

        if (! $instance instanceof Authenticator) {
            throw InvalidConfigurationException::invalidClass('auth.driver', $class, Authenticator::class, $config->name);
        }

        return $instance;
    }

    /** @param array<string, mixed> $auth */
    private function requireString(array $auth, string $key, string $connection): string
    {
        $value = $auth[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw InvalidConfigurationException::missing("auth.{$key}", $connection);
        }

        return $value;
    }
}
