<?php

declare(strict_types=1);

namespace Vitis\RestDB\JsonApi;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Vitis\RestDB\Adapters\AdapterRegistry;

final class JsonApiServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('restdb-jsonapi')
            ->hasCommands([
                Commands\MakeModelsCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        // Core never references jsonapi classes — this package introduces
        // itself to the registry, the same way third-party adapters do.
        $this->callAfterResolving(AdapterRegistry::class, function (AdapterRegistry $registry): void {
            $registry->registerAdapter('json-api', JsonApiAdapter::class);
        });
    }
}
