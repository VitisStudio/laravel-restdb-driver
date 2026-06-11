<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;
use Vitis\RestDB\JsonApi\JsonApiServiceProvider;
use Vitis\RestDB\RestDBServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [
            RestDBServiceProvider::class,
            JsonApiServiceProvider::class,
        ];
    }
}
