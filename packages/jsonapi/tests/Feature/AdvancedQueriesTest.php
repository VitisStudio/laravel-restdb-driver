<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\JsonApi\Comment;
use Tests\Fixtures\JsonApi\Post;
use Vitis\RestDB\Exceptions\UnsupportedQueryException;

function totalsConnection($testCase, array $overrides = []): void
{
    $testCase->defineJsonApiConnection(array_replace([
        'pagination' => ['strategy' => 'page-number', 'size' => 10, 'meta_total' => 'meta.page.total'],
        'capabilities' => ['aggregate.count' => true],
    ], $overrides));
}

function postsPage(int $count, int $total, int $startId = 1): array
{
    return [
        'data' => array_map(fn (int $i) => [
            'type' => 'posts', 'id' => (string) ($startId + $i), 'attributes' => ['title' => 'P'.($startId + $i)],
        ], range(0, $count - 1)),
        'meta' => ['page' => ['total' => $total]],
    ];
}

it('paginate() issues exactly one request and reads the meta total', function () {
    totalsConnection($this);
    Http::fake(['*' => Http::response(postsPage(2, 57))]);

    $page = Post::query()->paginate(2, page: 3);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        $url = urldecode($request->url());

        return str_contains($url, 'page[number]=3') && str_contains($url, 'page[size]=2');
    });

    expect($page->total())->toBe(57)
        ->and($page->currentPage())->toBe(3)
        ->and($page->items())->toHaveCount(2)
        ->and($page->lastPage())->toBe(29);
});

it('paginate() works on offset strategies too', function () {
    totalsConnection($this, ['pagination' => ['strategy' => 'offset', 'size' => 10, 'meta_total' => 'meta.page.total']]);
    Http::fake(['*' => Http::response(postsPage(2, 41))]);

    $page = Post::query()->paginate(2, page: 3);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        $url = urldecode($request->url());

        return str_contains($url, 'page[offset]=4') && str_contains($url, 'page[limit]=2');
    });

    expect($page->total())->toBe(41);
});

it('paginate() without totals throws with a simplePaginate hint', function () {
    $this->defineJsonApiConnection(); // no meta_total -> no page.total capability

    expect(fn () => Post::query()->paginate(10))
        ->toThrow(BadMethodCallException::class, 'simplePaginate');
});

it('count() reads the meta total from a page[size]=1 probe', function () {
    totalsConnection($this);
    Http::fake(['*' => Http::response(postsPage(1, 123))]);

    expect(Post::query()->where('status', 'open')->count())->toBe(123);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        $url = urldecode($request->url());

        return str_contains($url, 'page[size]=1') && str_contains($url, 'filter[status]=open');
    });
});

it('whereHas decomposes into two key-constrained requests', function () {
    totalsConnection($this);
    Http::fake([
        'jsonapi.test/comments*' => Http::response(['data' => [
            ['type' => 'comments', 'id' => '1', 'attributes' => ['post_id' => '11', 'flagged' => true]],
            ['type' => 'comments', 'id' => '2', 'attributes' => ['post_id' => '12', 'flagged' => true]],
            ['type' => 'comments', 'id' => '3', 'attributes' => ['post_id' => '11', 'flagged' => true]],
        ]]),
        'jsonapi.test/posts*' => Http::response(postsPage(2, 2, 11)),
    ]);

    $posts = Post::query()->whereHas('comments', fn ($q) => $q->where('flagged', 'true'))->get();

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => ! str_contains($request->url(), '/comments')
        || str_contains(urldecode($request->url()), 'filter[flagged]=true'));
    Http::assertSent(fn (Request $request) => ! str_contains($request->url(), '/posts')
        || str_contains(urldecode($request->url()), 'filter[id]=11,12'));

    expect($posts)->toHaveCount(2);
});

it('whereHas throws above the key cap instead of truncating', function () {
    totalsConnection($this, ['guards' => ['where_has_max_keys' => 2]]);
    Http::fake(['jsonapi.test/comments*' => Http::response(['data' => [
        ['type' => 'comments', 'id' => '1', 'attributes' => ['post_id' => '1']],
        ['type' => 'comments', 'id' => '2', 'attributes' => ['post_id' => '2']],
        ['type' => 'comments', 'id' => '3', 'attributes' => ['post_id' => '3']],
    ]])]);

    expect(fn () => Post::query()->whereHas('comments')->get())
        ->toThrow(UnsupportedQueryException::class, 'where_has_max_keys');
});

it('whereHas with zero matches short-circuits to zero HTTP for the outer query', function () {
    totalsConnection($this);
    Http::fake(['jsonapi.test/comments*' => Http::response(['data' => []])]);

    $posts = Post::query()->whereHas('comments')->get();

    Http::assertSentCount(1); // only the inner relation query
    expect($posts)->toBeEmpty();
});

it('refuses orWhereHas and counted has() loudly', function () {
    totalsConnection($this);

    expect(fn () => Post::query()->orWhereHas('comments'))
        ->toThrow(BadMethodCallException::class);

    expect(fn () => Post::query()->has('comments', '>=', 2)->get())
        ->toThrow(BadMethodCallException::class);

    expect(fn () => Post::query()->withCount('comments')->get())
        ->toThrow(BadMethodCallException::class, 'withCount');
});

it('cursorPaginate compiles cursor conditions into dialect filters', function () {
    $this->defineJsonApiConnection([
        'filter_dialect' => 'nested-operator',
        'capabilities' => ['filter.nested' => true, 'filter.or' => true],
    ]);

    Http::fake(['*' => Http::response(['data' => [
        ['type' => 'comments', 'id' => '1', 'attributes' => ['body' => 'a']],
        ['type' => 'comments', 'id' => '2', 'attributes' => ['body' => 'b']],
        ['type' => 'comments', 'id' => '3', 'attributes' => ['body' => 'c']],
    ]])]);

    $page = Comment::query()->orderBy('id')->cursorPaginate(2);

    expect($page->items())->toHaveCount(2)
        ->and($page->hasMorePages())->toBeTrue()
        ->and($page->nextCursor())->not->toBeNull();

    // Page two carries the cursor as a comparison filter.
    Http::fake(['*' => Http::response(['data' => []])]);
    Comment::query()->orderBy('id')->cursorPaginate(2, cursor: $page->nextCursor());

    Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), 'filter[id][gt]=2'));
});
