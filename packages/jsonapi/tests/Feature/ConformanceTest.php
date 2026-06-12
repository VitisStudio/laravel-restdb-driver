<?php

declare(strict_types=1);

use Tests\Fixtures\FakeCompiler;
use Tests\Fixtures\FakePaginator;
use Tests\Fixtures\FakeParser;
use Vitis\RestDB\Adapters\GenericAdapter;
use Vitis\RestDB\JsonApi\JsonApiAdapter;
use Vitis\RestDB\Testing\AdapterConformanceKit;
use Vitis\RestDB\Values\ConnectionConfig;

it('passes the conformance kit: json-api adapter, both dialects and every paginator', function (string $dialect, string $strategy) {
    $config = new ConnectionConfig('kit', [
        'base_url' => 'https://kit.test',
        'filter_dialect' => $dialect,
        'pagination' => ['strategy' => $strategy, 'size' => 10],
    ]);

    $violations = AdapterConformanceKit::check(new JsonApiAdapter(app()), $config, 'articles');

    expect($violations)->toBe([]);
})->with(['comma-list', 'nested-operator'])->with(['page-number', 'offset', 'cursor']);

it('passes the conformance kit: generic adapter with the fixture strategies', function () {
    $config = new ConnectionConfig('kit', [
        'base_url' => 'https://kit.test',
        'compiler' => FakeCompiler::class,
        'parser' => FakeParser::class,
        'paginator' => FakePaginator::class,
    ]);

    $violations = AdapterConformanceKit::check(new GenericAdapter(app()), $config, 'articles');

    expect($violations)->toBe([]);
});
