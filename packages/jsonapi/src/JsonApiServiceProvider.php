<?php

declare(strict_types=1);

namespace Vitis\RestDB\JsonApi;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class JsonApiServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('restdb-jsonapi');
    }
}
