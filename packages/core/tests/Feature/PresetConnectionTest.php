<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Article;
use Vitis\RestDB\Adapters\GenericAdapter;
use Vitis\RestDB\Capabilities\UnsupportedCapabilityException;
use Vitis\RestDB\Exceptions\InvalidConfigurationException;
use Vitis\RestDB\Exceptions\UnsupportedQueryException;
use Vitis\RestDB\Rest\Presets;
use Vitis\RestDB\Testing\AdapterConformanceKit;
use Vitis\RestDB\Values\ConnectionConfig;

/** A config-only connection — no compiler/parser/paginator classes anywhere. */
function definePresetConnection(array $overrides = []): void
{
    config()->set('database.connections.testapi', array_replace([
        'driver' => 'restdb',
        'adapter' => 'generic',
        'base_url' => 'https://api.test',
        'preset' => 'json-server',
    ], $overrides));

    app('db')->purge('testapi');
}

it('drives a json-server API from the preset alone — zero custom classes', function () {
    definePresetConnection();
    Http::fake(['*' => Http::response([['id' => 5, 'title' => 'Five']])]);

    $articles = Article::query()
        ->where('authorId', 1)
        ->where('id', '>=', 5)
        ->where('title', 'like', '%qui%')
        ->orderByDesc('title')
        ->orderBy('id')
        ->get();

    expect($articles)->toHaveCount(1)
        ->and($articles->first()->title)->toBe('Five');

    Http::assertSent(function (Request $request) {
        $url = urldecode($request->url());

        return str_starts_with($url, 'https://api.test/articles')
            && str_contains($url, 'authorId=1')
            && str_contains($url, 'id_gte=5')
            && str_contains($url, 'title_like=qui')
            && str_contains($url, '_sort=title,id')
            && str_contains($url, '_order=desc,asc');
    });
});

it('compiles find() to the resource URL and paginates in one request off the total header', function () {
    definePresetConnection();
    Http::fake([
        'https://api.test/articles/7*' => Http::response(['id' => 7, 'title' => 'Seven']),
        'https://api.test/articles?*' => Http::response(
            [['id' => 11], ['id' => 12]],
            200,
            ['X-Total-Count' => '57'],
        ),
    ]);

    expect(Article::query()->find(7)?->title)->toBe('Seven');

    $page = Article::query()->paginate(perPage: 2, page: 6);

    expect($page->total())->toBe(57)
        ->and($page->lastPage())->toBe(29)
        ->and($page->items())->toHaveCount(2);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.test/articles/7')
        || (str_contains($request->url(), '_page=6') && str_contains($request->url(), '_limit=2')));
});

it('gates queries by the capabilities the preset declares', function () {
    definePresetConnection();
    Http::fake(['*' => Http::response([])]);

    // gt is NOT in the json-server preset (only gte/lte) — the gate throws
    // before any HTTP, pointing at the connection's capability config.
    expect(fn () => Article::query()->where('id', '>', 5)->get())
        ->toThrow(UnsupportedCapabilityException::class, 'connections.testapi.capabilities')
        ->and(fn () => Article::query()->select(['title'])->get())
        ->toThrow(UnsupportedCapabilityException::class, 'select.columns');

    Http::assertNothingSent();
});

it('collapses single-value whereIn and refuses wider ones, as json-server requires', function () {
    definePresetConnection();
    Http::fake(['*' => Http::response([])]);

    Article::query()->whereIn('authorId', [3])->get();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'authorId=3'));

    expect(fn () => Article::query()->whereIn('authorId', [1, 2, 3])->get())
        ->toThrow(UnsupportedQueryException::class, 'filters.in');
});

it('lets connection config override any preset key', function () {
    definePresetConnection([
        'pagination' => ['total_header' => 'X-Count'],
        'capabilities' => ['write.delete' => false],
    ]);
    Http::fake(['*' => Http::response([['id' => 1]], 200, ['X-Count' => '9'])]);

    expect(Article::query()->paginate(perPage: 1)->total())->toBe(9)
        ->and(fn () => Article::query()->where('id', 1)->delete())
        ->toThrow(UnsupportedCapabilityException::class, 'write.delete');
});

it('resolves user-defined presets from restdb.presets, which win over built-ins', function () {
    config()->set('restdb.presets', [
        'corp-api' => [
            'filters' => ['style' => 'bracket', 'wrapper' => 'filter'],
            'response' => ['data' => 'data'],
            'capabilities' => ['select' => true, 'filter' => ['operators' => ['eq', 'gte']]],
        ],
        'json-server' => [
            'capabilities' => ['select' => true, 'filter' => ['operators' => ['eq']]],
        ],
    ]);

    definePresetConnection(['preset' => 'corp-api']);
    Http::fake(['*' => Http::response(['data' => [['id' => 1, 'title' => 'Enveloped']]])]);

    $articles = Article::query()->where('rating', '>=', 4)->get();

    expect($articles->first()->title)->toBe('Enveloped');
    Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), 'filter[rating][gte]=4'));

    // The user's json-server preset shadows the built-in: gte is gone.
    definePresetConnection();
    expect(fn () => Article::query()->where('id', '>=', 5)->get())
        ->toThrow(UnsupportedCapabilityException::class);
});

it('rejects unknown presets with the available names', function () {
    definePresetConnection(['preset' => 'typo-server']);

    expect(fn () => Article::query()->get())
        ->toThrow(InvalidConfigurationException::class, 'json-server');
});

it('passes the adapter conformance kit on preset config alone', function () {
    $config = new ConnectionConfig('testapi', Presets::merge(
        Presets::builtIn()['json-server'],
        ['base_url' => 'https://api.test'],
    ));

    $violations = AdapterConformanceKit::check(
        app(GenericAdapter::class),
        $config,
        fixtures: ['collection' => '[]', 'resource' => '{"id": 1, "title": "x"}'],
    );

    expect($violations)->toBe([]);
});
