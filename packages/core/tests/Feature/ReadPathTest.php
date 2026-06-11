<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Article;
use Vitis\RestDB\Exceptions\ApiResponseException;
use Vitis\RestDB\Exceptions\RestDBAuthenticationException;
use Vitis\RestDB\Exceptions\UnsupportedQueryException;

beforeEach(function () {
    $this->defineOpenApiConnection();
});

it('hydrates models from the data envelope', function () {
    Http::fake(['*' => Http::response(['data' => [
        ['id' => 1, 'title' => 'First'],
        ['id' => 2, 'title' => 'Second'],
    ]])]);

    $articles = Article::query()->get();

    expect($articles)->toHaveCount(2)
        ->and($articles->first())->toBeInstanceOf(Article::class)
        ->and($articles->first()->title)->toBe('First');
});

it('compiles filters, sort, and limit onto the wire', function () {
    Http::fake(['*' => Http::response(['data' => []])]);

    Article::query()
        ->where('status', 'open')
        ->where('rating', '>=', 4)
        ->orderByDesc('created_at')
        ->limit(25)
        ->get();

    Http::assertSent(function (Request $request) {
        $url = $request->url();

        return str_starts_with($url, 'https://api.test/articles')
            && str_contains($url, 'status=open')
            && str_contains($url, 'rating_gte=4')
            && str_contains($url, 'sort=-created_at')
            && str_contains($url, 'limit=25');
    });
});

it('issues zero HTTP requests for a provably empty whereIn', function () {
    Http::fake();

    $result = Article::query()->whereIn('id', [])->get();

    expect($result)->toBeEmpty();
    Http::assertNothingSent();
});

it('throws BadMethodCallException for SQL-only surface', function () {
    expect(fn () => Article::query()->groupBy('status')->get())
        ->toThrow(BadMethodCallException::class, 'groupBy is not supported by the restdb driver');

    expect(fn () => Article::query()->toBase()->whereRaw('1=1'))
        ->toThrow(BadMethodCallException::class, 'whereRaw is not supported by the restdb driver');

    expect(fn () => Article::query()->toBase()->toSql())
        ->toThrow(BadMethodCallException::class, 'toRequest()');
});

it('throws on unmappable operators instead of dropping them', function () {
    expect(fn () => Article::query()->where('title', 'regexp', '^a')->get())
        ->toThrow(UnsupportedQueryException::class, 'regexp');
});

it('throws on raw expressions anywhere in the query', function () {
    expect(fn () => Article::query()->where('status', DB::raw('open'))->get())
        ->toThrow(UnsupportedQueryException::class);
});

it('returns null from find() on a 404 and throws from findOrFail()', function () {
    Http::fake(['*' => Http::response(null, 404)]);

    expect(Article::query()->find(42))->toBeNull();

    expect(fn () => Article::query()->findOrFail(42))
        ->toThrow(ModelNotFoundException::class);
});

it('maps non-2xx responses to ApiResponseException with details', function () {
    Http::fake(['*' => Http::response(['message' => 'server exploded'], 500)]);

    try {
        Article::query()->get();
        $this->fail('Expected ApiResponseException.');
    } catch (ApiResponseException $e) {
        expect($e->getMessage())
            ->toContain('testapi')
            ->toContain('500')
            ->toContain('server exploded')
            ->and($e->status)->toBe(500);
    }
});

it('maps a 401 to RestDBAuthenticationException', function () {
    Http::fake(['*' => Http::response(null, 401)]);

    expect(fn () => Article::query()->get())
        ->toThrow(RestDBAuthenticationException::class, 'testapi');
});

it('redacts Authorization in response headers carried by exceptions', function () {
    Http::fake(['*' => Http::response('boom', 500, ['Authorization' => 'Bearer leaked', 'X-Trace' => 'abc'])]);

    try {
        Article::query()->get();
        $this->fail('Expected ApiResponseException.');
    } catch (ApiResponseException $e) {
        expect($e->responseHeaders['Authorization'])->toBe(['[redacted]'])
            ->and($e->responseHeaders['X-Trace'])->toBe(['abc']);
    }
});

it('emulates count() through the adapter', function () {
    Http::fake(['*' => Http::response(['count' => 7])]);

    expect(Article::query()->where('status', 'open')->count())->toBe(7);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'count=1'));
});

it('answers exists() with a single limit-1 probe', function () {
    Http::fake(['*' => Http::response(['data' => [['id' => 1]]])]);

    expect(Article::query()->exists())->toBeTrue();
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'limit=1'));
});

it('answers exists() false on an empty result', function () {
    Http::fake(['*' => Http::response(['data' => []])]);

    expect(Article::query()->exists())->toBeFalse();
});

it('serves first() and value() through limit 1', function () {
    Http::fake(['*' => Http::response(['data' => [['id' => 1, 'title' => 'Hello']]])]);

    expect(Article::query()->first()?->title)->toBe('Hello')
        ->and(Article::query()->value('title'))->toBe('Hello');
});

it('plucks client-side without demanding select.columns', function () {
    $this->defineApiConnection(['select' => true, 'page.limit' => true]);
    Http::fake(['*' => Http::response(['data' => [
        ['id' => 1, 'title' => 'A'], ['id' => 2, 'title' => 'B'],
    ]])]);

    $titles = Article::query()->pluck('title');

    expect($titles->all())->toBe(['A', 'B']);

    // The projection was omitted from the wire — not silently sent and dropped.
    Http::assertSent(fn (Request $request) => ! str_contains($request->url(), 'fields='));
});

it('sends sparse fieldsets when select.columns is declared', function () {
    Http::fake(['*' => Http::response(['data' => []])]);

    Article::query()->select(['title', 'body'])->get();

    Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), 'fields=title,body'));
});

it('strips qualified columns and rewrites qualified equality to In', function () {
    Http::fake(['*' => Http::response(['data' => []])]);

    Article::query()->toBase()->from('articles')->where('articles.id', 5)->get();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'id=5')
        && ! str_contains($request->url(), 'articles.id'));
});

it('fires QueryExecuted with the request line and real timing', function () {
    Http::fake(['*' => Http::response(['data' => []])]);

    $captured = [];
    DB::listen(function ($event) use (&$captured) {
        $captured[] = $event;
    });

    Article::query()->where('status', 'open')->get();

    expect($captured)->toHaveCount(1)
        ->and($captured[0]->sql)->toBe('GET /articles?status=open')
        ->and($captured[0]->time)->toBeFloat();
});

it('blocks transactions and the schema builder loudly', function () {
    $connection = Article::query()->getConnection();

    expect(fn () => $connection->transaction(fn () => null))
        ->toThrow(LogicException::class, 'transactions');

    expect(fn () => $connection->getSchemaBuilder())
        ->toThrow(LogicException::class, 'schema');
});

it('flattens key-value array wheres without demanding filter.nested', function () {
    $this->defineApiConnection(['select' => true, 'filter' => ['operators' => ['eq']]]);
    Http::fake(['*' => Http::response(['data' => []])]);

    Article::query()->where(['status' => 'open', 'type' => 'news'])->get();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'status=open')
        && str_contains($request->url(), 'type=news'));
});
