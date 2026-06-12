<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Article;
use Tests\Fixtures\FakePaginator;
use Vitis\RestDB\Exceptions\ResultTruncationException;

beforeEach(function () {
    $this->defineOpenApiConnection(['paginator' => FakePaginator::class]);
});

function pageResponse(array $rows, bool $hasMore = false, ?int $nextOffset = null): array
{
    return [
        'data' => $rows,
        'meta' => ['has_more' => $hasMore, 'next_offset' => $nextOffset],
    ];
}

it('drains pages until has-more is false', function () {
    Http::fake(['*' => Http::sequence()
        ->push(pageResponse([['id' => 1], ['id' => 2]], hasMore: true, nextOffset: 2))
        ->push(pageResponse([['id' => 3]], hasMore: false)),
    ]);

    $articles = Article::query()->get();

    expect($articles->pluck('id')->all())->toBe([1, 2, 3]);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'offset=2')
        || ! str_contains($request->url(), 'offset='));
});

it('stops draining once the limit is satisfied', function () {
    Http::fake(['*' => Http::response(pageResponse([['id' => 1], ['id' => 2]], hasMore: true, nextOffset: 2))]);

    $articles = Article::query()->limit(2)->get();

    expect($articles)->toHaveCount(2);
    Http::assertSentCount(1);
});

it('throws ResultTruncationException at the max_pages guard instead of truncating', function () {
    $this->defineOpenApiConnection([
        'paginator' => FakePaginator::class,
        'guards' => ['max_pages' => 3],
    ]);

    Http::fake(fn () => Http::response(pageResponse([['id' => 1]], hasMore: true, nextOffset: 1)));

    try {
        Article::query()->get();
        $this->fail('Expected ResultTruncationException.');
    } catch (ResultTruncationException $e) {
        expect($e->getMessage())
            ->toContain('max_pages')
            ->toContain('lazy()')
            ->toContain('testapi');
    }

    Http::assertSentCount(3);
});

it('streams pages through cursor() one page at a time', function () {
    Http::fake(['*' => Http::sequence()
        ->push(pageResponse([['id' => 1, 'title' => 'A'], ['id' => 2, 'title' => 'B']], hasMore: true, nextOffset: 2))
        ->push(pageResponse([['id' => 3, 'title' => 'C']], hasMore: false)),
    ]);

    $titles = [];

    foreach (Article::query()->cursor() as $article) {
        $titles[] = $article->title;

        if (count($titles) === 1) {
            // After the first row only one page has been fetched.
            Http::assertSentCount(1);
        }
    }

    expect($titles)->toBe(['A', 'B', 'C']);
    Http::assertSentCount(2);
});

it('honors limit while streaming', function () {
    Http::fake(['*' => Http::sequence()
        ->push(pageResponse([['id' => 1], ['id' => 2]], hasMore: true, nextOffset: 2))
        ->push(pageResponse([['id' => 3], ['id' => 4]], hasMore: false)),
    ]);

    $ids = Article::query()->limit(3)->cursor()->pluck('id')->all();

    expect($ids)->toBe([1, 2, 3]);
    Http::assertSentCount(2);
});

it('lazy() streams without loading everything', function () {
    Http::fake(['*' => Http::sequence()
        ->push(pageResponse([['id' => 1], ['id' => 2]], hasMore: true, nextOffset: 2))
        ->push(pageResponse([['id' => 3]], hasMore: false)),
    ]);

    $count = Article::query()->lazy(2)->count();

    expect($count)->toBe(3);
});

it('chunks sequentially through forPage', function () {
    Http::fake(['*' => Http::sequence()
        ->push(pageResponse([['id' => 1], ['id' => 2]]))
        ->push(pageResponse([['id' => 3]])),
    ]);

    $chunks = [];

    Article::query()->chunk(2, function ($articles) use (&$chunks) {
        $chunks[] = $articles->pluck('id')->all();
    });

    expect($chunks)->toBe([[1, 2], [3]]);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'limit=2'));
});

it('simplePaginate uses limit n+1 and reports has-more', function () {
    Http::fake(['*' => Http::response(pageResponse([['id' => 1], ['id' => 2], ['id' => 3]]))]);

    $page = Article::query()->simplePaginate(2);

    expect($page->hasMorePages())->toBeTrue()
        ->and($page->items())->toHaveCount(2);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'limit=3')
        && str_contains($request->url(), 'offset=0'));
});

it('blocks paginate() with a simplePaginate hint until v0.5', function () {
    expect(fn () => Article::query()->paginate(10))
        ->toThrow(BadMethodCallException::class, 'simplePaginate');
});
