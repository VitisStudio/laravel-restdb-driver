<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\JsonApi\Post;
use Vitis\RestDB\Capabilities\UnsupportedCapabilityException;

beforeEach(function () {
    $this->defineJsonApiConnection();
});

function emptyDoc(): array
{
    return ['data' => []];
}

it('compiles find() to the resource URL with JSON:API headers', function () {
    Http::fake(['*' => Http::response(['data' => [
        'type' => 'posts', 'id' => '42', 'attributes' => ['title' => 'Hello'],
    ]])]);

    $post = Post::query()->find(42);

    expect($post?->id)->toBe('42')
        ->and($post?->title)->toBe('Hello');

    // find() rides first()/limit(1), so the paginator may append page[size]=1.
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://jsonapi.test/posts/42')
        && $request->hasHeader('Accept', 'application/vnd.api+json'));
});

it('compiles filters, multi-sort, fields, and page size onto the wire', function () {
    Http::fake(['*' => Http::response(emptyDoc())]);

    Post::query()
        ->where('status', 'open')
        ->whereIn('id', [1, 2, 3])
        ->orderByDesc('created_at')
        ->orderBy('title')
        ->select(['title', 'view_count'])
        ->limit(25)
        ->get();

    Http::assertSent(function (Request $request) {
        $url = urldecode($request->url());

        return str_starts_with($url, 'https://jsonapi.test/posts')
            && str_contains($url, 'filter[status]=open')
            && str_contains($url, 'filter[id]=1,2,3')
            && str_contains($url, 'sort=-createdAt,title')
            && str_contains($url, 'fields[posts]=title,viewCount')
            && str_contains($url, 'page[size]=25');
    });
});

it('compiles operator filters through the nested-operator dialect', function () {
    $this->defineJsonApiConnection(['filter_dialect' => 'nested-operator']);
    Http::fake(['*' => Http::response(emptyDoc())]);

    Post::query()
        ->where('age', '>=', 18)
        ->whereNull('deleted_at')
        ->get();

    Http::assertSent(function (Request $request) {
        $url = urldecode($request->url());

        return str_contains($url, 'filter[age][gte]=18')
            && str_contains($url, 'filter[deletedAt][null]=1');
    });
});

it('grants exactly the dialect-supported operators', function () {
    Http::fake(['*' => Http::response(emptyDoc())]);

    // comma-list supports eq + in…
    Post::query()->where('status', 'open')->get();

    // …and nothing else.
    expect(fn () => Post::query()->where('age', '>=', 18)->get())
        ->toThrow(UnsupportedCapabilityException::class, 'gte');
});

it('maps attribute names kebab-style when configured', function () {
    $this->defineJsonApiConnection(['name_mapping' => 'kebab']);
    Http::fake(['*' => Http::response(['data' => [[
        'type' => 'posts', 'id' => '1', 'attributes' => ['view-count' => 9],
    ]]])]);

    $post = Post::query()->orderByDesc('view_count')->get()->first();

    expect($post?->view_count)->toBe(9);
    Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), 'sort=-view-count'));
});

it('drains pages by following links.next verbatim', function () {
    Http::fake(['*' => Http::sequence()
        ->push([
            'data' => [['type' => 'posts', 'id' => '1', 'attributes' => ['title' => 'A']]],
            'links' => ['next' => 'https://jsonapi.test/posts?page[number]=2&page[size]=10'],
        ])
        ->push([
            'data' => [['type' => 'posts', 'id' => '2', 'attributes' => ['title' => 'B']]],
            'links' => ['next' => null],
        ]),
    ]);

    $posts = Post::query()->get();

    expect($posts->pluck('title')->all())->toBe(['A', 'B']);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => ! str_contains($request->url(), 'page%5Bnumber%5D=2')
        || str_contains(urldecode($request->url()), 'page[number]=2'));
});

it('exposes to-one linkage as a foreign key column', function () {
    Http::fake(['*' => Http::response(['data' => [[
        'type' => 'posts', 'id' => '1',
        'attributes' => ['title' => 'A'],
        'relationships' => ['author' => ['data' => ['type' => 'people', 'id' => '9']]],
    ]]])]);

    $post = Post::query()->get()->first();

    expect($post?->author_id)->toBe('9');
});
