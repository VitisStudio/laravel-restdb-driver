<?php

declare(strict_types=1);

namespace Vitis\RestDB;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Vitis\RestDB\Adapters\AdapterRegistry;
use Vitis\RestDB\Adapters\GenericAdapter;
use Vitis\RestDB\Values\ConnectionConfig;

final class RestDBServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('restdb')
            ->hasConfigFile();
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(AdapterRegistry::class, function (Application $app): AdapterRegistry {
            $registry = new AdapterRegistry($app);
            $registry->registerAdapter('generic', GenericAdapter::class);

            $configured = $app->make(Repository::class)->get('restdb.adapters', []);

            foreach (is_array($configured) ? $configured : [] as $name => $class) {
                if (is_string($name) && is_string($class) && is_subclass_of($class, Contracts\Adapter::class)) {
                    $registry->registerAdapter($name, $class);
                }
            }

            return $registry;
        });

        $this->app->singleton(Auth\AuthenticatorResolver::class);

        // laravel-mongodb's registration path: bypasses ConnectionFactory
        // entirely, so no PDO resolver is ever constructed and RestConnection's
        // constructor is free to take whatever collaborators it wants.
        $this->app->resolving('db', function (DatabaseManager $db): void {
            $db->extend('restdb', fn (array $config, string $name) => $this->app
                ->make(RestConnectionFactory::class)
                ->make(ConnectionConfig::stringKeyed($config), $name));
        });
    }
}
