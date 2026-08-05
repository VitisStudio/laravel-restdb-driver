<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\JsonApi\Post;
use Vitis\RestDB\Capabilities\UnsupportedCapabilityException;

// Behaviors a real link-less, dollar-operator JSON:API server (hatchify)
// forced: the dollar dialect, resource_types overrides, and total-based
// pagination math when the server never sends a links object.

it('compiles operator filters through the dollar-operator dialect', function () {
    $this->defineJsonApiConnection(['filter_dialect' => 'dollar-operator']);
    Http::fake(['*' => Http::response(['data' => []])]);

    Post::query()
        ->where('status', 'open')
        ->where('rating', '>=', 4)
        ->whereIn('id', [1, 2])
        ->whereNotIn('author_id', [9])
        ->where('title', 'like', '%cook%')
        ->get();

    Http::assertSent(function (Request $request) {
        $url = urldecode($request->url());

        return str_contains($url, 'filter[status]=open')
            && str_contains($url, 'filter[rating][$gte]=4')
            && str_contains($url, 'filter[id][$in]=1,2')
            && str_contains($url, 'filter[authorId][$nin]=9')
            && str_contains($url, 'filter[title][$like]=%cook%');
    });
});

it('grants exactly the dollar-dialect operators — null checks gate out', function () {
    $this->defineJsonApiConnection(['filter_dialect' => 'dollar-operator']);
    Http::fake(['*' => Http::response(['data' => []])]);

    expect(fn () => Post::query()->whereNull('deleted_at')->get())
        ->toThrow(UnsupportedCapabilityException::class)
        ->and(fn () => Post::query()->whereBetween('rating', [1, 3])->get())
        ->toThrow(UnsupportedCapabilityException::class);

    Http::assertNothingSent();
});

it('writes the resource_types override as the type member', function () {
    $this->defineJsonApiConnection(['resource_types' => ['posts' => 'Post']]);
    Http::fake(['*' => Http::response(['data' => [
        'type' => 'Post', 'id' => '1', 'attributes' => ['title' => 'x'],
    ]], 201)]);

    $post = new Post(['title' => 'x']);
    $post->save();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->data()['data']['type'] === 'Post');
});

it('drains every page off the meta total when the server sends no links', function () {
    $this->defineJsonApiConnection([
        'pagination' => ['strategy' => 'page-number', 'size' => 2, 'meta_total' => 'meta.unpaginatedCount'],
    ]);

    $doc = fn (array $rows) => [
        'meta' => ['unpaginatedCount' => 5],
        'data' => array_map(fn ($i) => ['type' => 'posts', 'id' => (string) $i, 'attributes' => []], $rows),
    ];

    Http::fake(['*' => Http::sequence()
        ->push($doc([1, 2]))
        ->push($doc([3, 4]))
        ->push($doc([5]))]);

    expect(Post::query()->get())->toHaveCount(5);

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), 'page[number]=3'));
});

it('stops the link-less drain exactly at the total', function () {
    $this->defineJsonApiConnection([
        'pagination' => ['strategy' => 'page-number', 'size' => 2, 'meta_total' => 'meta.total'],
    ]);

    $doc = fn (array $ids) => [
        'meta' => ['total' => 4],
        'data' => array_map(fn ($i) => ['type' => 'posts', 'id' => (string) $i, 'attributes' => []], $ids),
    ];

    Http::fake(['*' => Http::sequence()->push($doc([1, 2]))->push($doc([3, 4]))]);

    expect(Post::query()->get())->toHaveCount(4);

    // Two pages of two: 2 * page[size] reaches the total — no third probe.
    Http::assertSentCount(2);
});
