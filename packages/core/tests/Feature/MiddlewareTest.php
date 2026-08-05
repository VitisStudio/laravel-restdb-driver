<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Article;
use Tests\Fixtures\NotCallableMiddleware;
use Tests\Fixtures\SpyMiddleware;
use Tests\Fixtures\SpyMiddlewareA;
use Tests\Fixtures\SpyMiddlewareB;
use Vitis\RestDB\Exceptions\InvalidConfigurationException;

beforeEach(function () {
    SpyMiddleware::reset();
});

it('applies user-registered http.middleware to every request', function () {
    $this->defineOpenApiConnection(['http' => ['middleware' => [SpyMiddlewareA::class]]]);
    Http::fake(['*' => Http::response(['data' => []])]);

    Article::query()->get();

    // The middleware ran and reached the wire — Http::fake still intercepts.
    expect(SpyMiddleware::$calls)->toBe(['a']);
    Http::assertSent(fn (Request $request) => $request->hasHeader('X-Spy', 'a'));
});

it('runs middleware in registration order', function () {
    $this->defineOpenApiConnection(['http' => ['middleware' => [SpyMiddlewareA::class, SpyMiddlewareB::class]]]);
    Http::fake(['*' => Http::response(['data' => []])]);

    Article::query()->get();

    expect(SpyMiddleware::$calls)->toBe(['a', 'b']);
});

it('needs no middleware config — the list is empty by default', function () {
    $this->defineOpenApiConnection();
    Http::fake(['*' => Http::response(['data' => []])]);

    Article::query()->get();

    expect(SpyMiddleware::$calls)->toBe([]);
    Http::assertSentCount(1);
});

it('rejects a middleware class that is not callable', function () {
    $this->defineOpenApiConnection(['http' => ['middleware' => [NotCallableMiddleware::class]]]);

    expect(fn () => Article::query()->get())
        ->toThrow(InvalidConfigurationException::class, 'http.middleware');
});
