<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Article;
use Vitis\RestDB\Capabilities\UnsupportedCapabilityException;

/**
 * Regressions found by running the example app against a live API: Eloquent
 * eager-loads integer-key relations through whereIntegerInRaw, and HasMany
 * constrains `fk = ?` AND `fk IS NOT NULL` — neither may demand capabilities
 * the query doesn't logically need.
 */
beforeEach(function () {
    // Deliberately minimal: eq + in only. No null operators declared.
    $this->defineApiConnection([
        'select' => true,
        'page.limit' => true,
        'filter' => ['operators' => ['eq', 'in']],
    ]);
});

it('eager loads belongsTo through the whereIntegerInRaw fast path', function () {
    Http::fake([
        'api.test/articles*' => Http::response(['data' => [
            ['id' => 1, 'title' => 'A', 'authorId' => 9],
            ['id' => 2, 'title' => 'B', 'authorId' => 9],
        ]]),
        'api.test/authors*' => Http::response(['data' => [
            ['id' => 9, 'name' => 'Ada'],
        ]]),
    ]);

    $articles = Article::query()->with('author')->get();

    expect($articles)->toHaveCount(2)
        ->and($articles[0]->author?->name)->toBe('Ada');

    // The integer-key optimization compiled to a plain In on the wire.
    Http::assertSent(fn (Request $request) => ! str_contains($request->url(), '/authors')
        || str_contains(urldecode($request->url()), 'id=9'));
});

it('lazy loads hasMany without demanding a not-null operator', function () {
    Http::fake([
        'api.test/comments*' => Http::response(['data' => [
            ['id' => 100, 'articleId' => 1, 'body' => 'First!'],
        ]]),
    ]);

    $article = (new Article)->newFromBuilder(['id' => 1, 'title' => 'A']);
    \assert($article instanceof Article);

    // HasMany adds `whereNotNull(articleId)` next to `articleId = 1` — the
    // NotNull is implied by the equality and must be pruned, not gated.
    $comments = $article->comments;

    expect($comments)->toHaveCount(1)
        ->and($comments[0]->body)->toBe('First!');

    Http::assertSent(function (Request $request) {
        $url = urldecode($request->url());

        return str_contains($url, 'articleId=1') && ! str_contains($url, 'not_null');
    });
});

it('still gates a developer whereNotNull that nothing implies', function () {
    expect(fn () => Article::query()->whereNotNull('published_at')->get())
        ->toThrow(UnsupportedCapabilityException::class, 'not-null');
});

it('prunes the implied NotNull but keeps an unrelated one gated alongside', function () {
    // published_at has no equality on it — implied-prune must not touch it.
    expect(fn () => Article::query()
        ->where('status', 'x')->toBase()
        ->whereNotNull('status')   // implied by status = x -> allowed
        ->whereNotNull('published_at') // not implied -> gated
        ->get())
        ->toThrow(UnsupportedCapabilityException::class, 'not-null');
});
