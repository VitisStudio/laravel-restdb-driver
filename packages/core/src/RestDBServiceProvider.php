<?php

declare(strict_types=1);

namespace Vitis\RestDB;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class RestDBServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('restdb')
            ->hasConfigFile();
    }
}
