<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Article;
use Vitis\RestDB\Capabilities\UnsupportedCapabilityException;
use Vitis\RestDB\Exceptions\ApiValidationException;
use Vitis\RestDB\Exceptions\UnsupportedQueryException;

beforeEach(function () {
    $this->defineOpenApiConnection([
        'capabilities' => [
            'select' => true,
            'filter' => ['operators' => ['eq', 'in']],
            'page.limit' => true,
            'write.insert' => true,
            'write.update' => true,
            'write.delete' => true,
        ],
    ]);
});

function fetchArticle(array $attributes = ['id' => 1, 'title' => 'Original']): Article
{
    // Hydrate exactly as a query would — exists = true, originals synced —
    // without burning an HTTP fake on the fetch.
    $article = (new Article)->newFromBuilder($attributes);
    \assert($article instanceof Article);

    return $article;
}

it('creates via POST and re-fills the model from the response', function () {
    Http::fake(['*' => Http::response(['data' => [
        'id' => 7, 'title' => 'Hello', 'slug' => 'hello-from-server',
    ]], 201)]);

    $article = new Article(['title' => 'Hello']);

    expect($article->save())->toBeTrue()
        ->and($article->id)->toBe(7)
        ->and($article->slug)->toBe('hello-from-server')
        ->and($article->wasRecentlyCreated)->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://api.test/articles'
        && $request->data() === ['title' => 'Hello']);
});

it('saves nothing when the model is clean', function () {
    $article = fetchArticle();

    Http::fake();

    expect($article->save())->toBeTrue();
    Http::assertNothingSent();
});

it('PATCHes dirty attributes only and accepts server mutations', function () {
    $article = fetchArticle(['id' => 1, 'title' => 'Original', 'status' => 'draft']);

    Http::fake(['*' => Http::response(['data' => [
        'id' => 1, 'title' => 'Updated (moderated)', 'status' => 'draft',
    ]])]);

    $article->title = 'Updated';

    expect($article->save())->toBeTrue()
        ->and($article->title)->toBe('Updated (moderated)');

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && $request->url() === 'https://api.test/articles/1'
        && $request->data() === ['title' => 'Updated']);
});

it('DELETEs a single resource by id', function () {
    $article = fetchArticle();

    Http::fake(['*' => Http::response(null, 204)]);

    expect($article->delete())->toBeTrue()
        ->and($article->exists)->toBeFalse();

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.test/articles/1');
});

it('treats deleting an already-missing resource as zero affected', function () {
    Http::fake(['*' => Http::response(null, 404)]);

    expect(Article::query()->toBase()->delete(99))->toBe(0);
});

it('refuses mass updates with arbitrary wheres', function () {
    expect(fn () => Article::query()->where('status', 'draft')->update(['status' => 'live']))
        ->toThrow(UnsupportedQueryException::class, 'single resource');
});

it('refuses updates with no target at all', function () {
    expect(fn () => Article::query()->toBase()->update(['status' => 'live']))
        ->toThrow(UnsupportedQueryException::class, 'single resource');
});

it('refuses multi-row inserts', function () {
    expect(fn () => Article::query()->toBase()->insert([
        ['title' => 'a'], ['title' => 'b'],
    ]))->toThrow(UnsupportedQueryException::class, 'one resource per request');
});

it('maps a 422 to field-keyed validation errors', function () {
    Http::fake(['*' => Http::response([
        'message' => 'Unprocessable',
        'errors' => ['title' => ['The title field is required.']],
    ], 422)]);

    $article = new Article(['title' => '']);

    try {
        $article->save();
        $this->fail('Expected ApiValidationException.');
    } catch (ApiValidationException $e) {
        expect($e->errors())->toHaveKey('title')
            ->and($e->errors()['title'][0])->toBe('The title field is required.');
    }
});

it('exposes the created id through insertGetId', function () {
    Http::fake(['*' => Http::response(['data' => ['id' => 41, 'title' => 'x']], 201)]);

    $id = Article::query()->toBase()->insertGetId(['title' => 'x']);

    expect($id)->toBe(41);
});

it('lets identity wheres through on a filterless connection', function () {
    // find() runs through first() → limit(1), so page.limit is the only
    // non-write capability this connection needs — no filter operators.
    $this->defineApiConnection([
        'select' => true,
        'page.limit' => true,
        'write.delete' => true,
        'write.update' => true,
    ]);

    Http::fake(['*' => Http::response(['data' => [['id' => 5, 'title' => 'T']]])]);

    // find() by primary key needs no filter operators…
    $article = Article::query()->find(5);
    expect($article)->not->toBeNull();

    // …and neither do single-resource writes.
    Http::fake(['*' => Http::response(null, 204)]);
    expect(Article::query()->toBase()->delete(5))->toBe(1);

    // Non-key columns still gate.
    expect(fn () => Article::query()->where('title', 'T')->get())
        ->toThrow(UnsupportedCapabilityException::class);
});
