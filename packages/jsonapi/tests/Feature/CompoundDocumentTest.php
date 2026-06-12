<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\JsonApi\Person;
use Tests\Fixtures\JsonApi\Post;

beforeEach(function () {
    $this->defineJsonApiConnection();
});

function compoundDoc(): array
{
    return [
        'data' => [
            [
                'type' => 'posts', 'id' => '1',
                'attributes' => ['title' => 'First'],
                'relationships' => [
                    'author' => ['data' => ['type' => 'people', 'id' => '9']],
                    'comments' => ['data' => [
                        ['type' => 'comments', 'id' => '101'],
                        ['type' => 'comments', 'id' => '102'],
                    ]],
                ],
            ],
            [
                'type' => 'posts', 'id' => '2',
                'attributes' => ['title' => 'Second'],
                'relationships' => [
                    'author' => ['data' => null],
                    'comments' => ['data' => []],
                ],
            ],
        ],
        'included' => [
            ['type' => 'people', 'id' => '9', 'attributes' => ['name' => 'Ada']],
            [
                'type' => 'comments', 'id' => '101',
                'attributes' => ['body' => 'Nice'],
                'relationships' => ['author' => ['data' => ['type' => 'people', 'id' => '9']]],
            ],
            [
                'type' => 'comments', 'id' => '102',
                'attributes' => ['body' => 'Sharp'],
                'relationships' => ['author' => ['data' => ['type' => 'people', 'id' => '9']]],
            ],
        ],
    ];
}

it('hydrates with() from one compound document — zero extra HTTP', function () {
    Http::fake(['*' => Http::response(compoundDoc())]);

    $posts = Post::query()->with(['author', 'comments'])->get();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), 'include=author,comments'));

    expect($posts)->toHaveCount(2)
        ->and($posts[0]->relationLoaded('author'))->toBeTrue()
        ->and($posts[0]->author->name)->toBe('Ada')
        ->and($posts[0]->comments->pluck('body')->all())->toBe(['Nice', 'Sharp'])
        ->and($posts[1]->author)->toBeNull()
        ->and($posts[1]->comments)->toBeEmpty();
});

it('hydrates nested includes recursively', function () {
    Http::fake(['*' => Http::response(compoundDoc())]);

    $posts = Post::query()->with('comments.author')->get();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), 'include=comments.author'));

    expect($posts[0]->comments[0]->relationLoaded('author'))->toBeTrue()
        ->and($posts[0]->comments[0]->author->name)->toBe('Ada');
});

it('falls back to a query for constrained eager loads', function () {
    Http::fake([
        'jsonapi.test/posts*' => Http::response([
            'data' => [[
                'type' => 'posts', 'id' => '1',
                'attributes' => ['title' => 'First'],
                'relationships' => ['author' => ['data' => ['type' => 'people', 'id' => '9']]],
            ]],
        ]),
        'jsonapi.test/people*' => Http::response([
            'data' => [['type' => 'people', 'id' => '9', 'attributes' => ['name' => 'Ada']]],
        ]),
    ]);

    $posts = Post::query()->with(['author' => fn ($q) => $q->select(['name'])])->get();

    // The constraint cannot ride include= — a real second query ran.
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => ! str_contains($request->url(), 'posts')
        || ! str_contains(urldecode($request->url()), 'include='));

    expect($posts[0]->author?->name)->toBe('Ada');
});

it('falls back when linkage is missing from the response', function () {
    Http::fake([
        'jsonapi.test/posts*' => Http::response([
            'data' => [['type' => 'posts', 'id' => '1', 'attributes' => ['title' => 'First', 'author_id' => '9']]],
        ]),
        'jsonapi.test/people*' => Http::response([
            'data' => [['type' => 'people', 'id' => '9', 'attributes' => ['name' => 'Ada']]],
        ]),
    ]);

    $posts = Post::query()->with('author')->get();

    Http::assertSentCount(2);
    expect($posts[0]->author?->name)->toBe('Ada');
});

it('skips include= when the capability is disabled', function () {
    $this->defineJsonApiConnection(['capabilities' => ['select.include' => false]]);

    Http::fake([
        'jsonapi.test/posts*' => Http::response([
            'data' => [[
                'type' => 'posts', 'id' => '1',
                'attributes' => ['title' => 'First'],
                'relationships' => ['author' => ['data' => ['type' => 'people', 'id' => '9']]],
            ]],
        ]),
        'jsonapi.test/people*' => Http::response([
            'data' => [['type' => 'people', 'id' => '9', 'attributes' => ['name' => 'Ada']]],
        ]),
    ]);

    $posts = Post::query()->with('author')->get();

    Http::assertSent(fn (Request $request) => ! str_contains(urldecode($request->url()), 'include='));
    expect($posts[0]->author?->name)->toBe('Ada');
});

it('keeps people queries working standalone', function () {
    Http::fake(['*' => Http::response(['data' => [
        ['type' => 'people', 'id' => '9', 'attributes' => ['name' => 'Ada']],
    ]])]);

    expect(Person::query()->get()->first()?->name)->toBe('Ada');
});
