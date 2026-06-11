<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;
use Tests\Fixtures\FakeCompiler;
use Tests\Fixtures\FakeParser;
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

    /**
     * (Re)define the 'testapi' generic connection with the given capability
     * config and purge any cached instance so the next query rebuilds it.
     *
     * @param  array<string, mixed>  $capabilities
     * @param  array<string, mixed>  $overrides
     */
    public function defineApiConnection(array $capabilities = [], array $overrides = []): void
    {
        config()->set('database.connections.testapi', array_replace([
            'driver' => 'restdb',
            'adapter' => 'generic',
            'base_url' => 'https://api.test',
            'compiler' => FakeCompiler::class,
            'parser' => FakeParser::class,
            'capabilities' => $capabilities,
        ], $overrides));

        $this->app['db']->purge('testapi');
    }

    /** Broad capability set for tests that exercise behavior, not gating. */
    public function defineOpenApiConnection(array $overrides = []): void
    {
        $this->defineApiConnection([
            'select' => true,
            'select.columns' => true,
            'filter' => ['operators' => ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'in', 'not-in', 'null', 'not-null', 'between', 'like']],
            'filter.nested' => true,
            'filter.or' => true,
            'sort' => true,
            'sort.multi' => true,
            'page.limit' => true,
            'page.offset' => true,
            'aggregate.count' => true,
            'aggregate.exists' => true,
        ], $overrides);
    }
}
