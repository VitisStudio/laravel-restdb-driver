<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Article;
use Tests\Fixtures\FakeCompiler;
use Tests\Fixtures\FakeParser;
use Vitis\RestDB\Capabilities\UnsupportedCapabilityException;

/**
 * Octane-shaped: one long-lived process, many connections — zero cross-talk.
 * All per-connection state (capabilities, gate, parser, last write/page info)
 * is instance state on the connection object.
 */
it('keeps two connections fully isolated in one process', function () {
    $this->defineApiConnection(['select' => true, 'filter' => ['operators' => ['eq']]]);

    config()->set('database.connections.secondapi', [
        'driver' => 'restdb',
        'adapter' => 'generic',
        'base_url' => 'https://second.test',
        'compiler' => FakeCompiler::class,
        'parser' => FakeParser::class,
        'capabilities' => ['select' => true], // no filter operators at all
    ]);

    $first = app('db')->connection('testapi');
    $second = app('db')->connection('secondapi');

    expect($first)->not->toBe($second)
        ->and($first->capabilities()->operators())->not->toBe($second->capabilities()->operators());

    Http::fake([
        'api.test/*' => Http::response(['data' => [['id' => 1, 'title' => 'A']]]),
        'second.test/*' => Http::response(['data' => [['id' => 2, 'title' => 'B']]]),
    ]);

    // The first connection filters; the second must still refuse the same query.
    expect(Article::query()->where('status', 'open')->get()->first()?->title)->toBe('A');

    $onSecond = (new Article)->setConnection('secondapi');

    expect(fn () => $onSecond->newQuery()->where('status', 'open')->get())
        ->toThrow(UnsupportedCapabilityException::class);

    // And plain reads on the second connection hit its own base URL.
    expect($onSecond->newQuery()->get()->first()?->title)->toBe('B');
});

it('purges cleanly — a rebuilt connection carries no stale instance state', function () {
    $this->defineOpenApiConnection();
    Http::fake(['*' => Http::response(['data' => [['id' => 1]]])]);

    Article::query()->get();
    $before = app('db')->connection('testapi');
    expect($before->lastPageInfo())->not->toBeNull();

    $this->defineApiConnection(['select' => true]); // purge + redefine

    $after = app('db')->connection('testapi');

    expect($after)->not->toBe($before)
        ->and($after->lastPageInfo())->toBeNull()
        ->and($after->lastWriteResult())->toBeNull();
});
