<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Article;
use Vitis\RestDB\Capabilities\UnsupportedCapabilityException;

/**
 * The capability matrix is the executable spec of the north star: for every
 * gated capability, the builder throws when it is absent and proceeds when it
 * is present. Writes are gated now and implemented in v0.3 — their "present"
 * case asserts only that no capability exception fires.
 */
$eq = ['filter' => ['operators' => ['eq']]];

dataset('capability matrix', [
    'select' => [
        'select',
        [],
        ['select' => true],
        fn () => Article::query()->get(),
    ],
    'select.columns' => [
        'select.columns',
        ['select' => true],
        ['select' => true, 'select.columns' => true],
        fn () => Article::query()->select(['title'])->get(),
    ],
    'filter operator eq' => [
        'eq',
        ['select' => true, 'filter' => ['operators' => []]],
        ['select' => true, 'filter' => ['operators' => ['eq']]],
        fn () => Article::query()->where('status', 'open')->get(),
    ],
    'filter operator gte' => [
        'gte',
        ['select' => true, 'filter' => ['operators' => ['eq']]],
        ['select' => true, 'filter' => ['operators' => ['gte']]],
        fn () => Article::query()->where('rating', '>=', 4)->get(),
    ],
    // Note: whereIn on the primary key is identity targeting and bypasses
    // operator capabilities — the matrix must probe a non-key column.
    'filter operator in' => [
        'in',
        ['select' => true, 'filter' => ['operators' => ['eq']]],
        ['select' => true, 'filter' => ['operators' => ['in']]],
        fn () => Article::query()->whereIn('status', ['open', 'closed'])->get(),
    ],
    'filter operator not-in' => [
        'not-in',
        ['select' => true, 'filter' => ['operators' => ['in']]],
        ['select' => true, 'filter' => ['operators' => ['not-in']]],
        fn () => Article::query()->whereNotIn('id', [3])->get(),
    ],
    'filter operator null' => [
        'null',
        ['select' => true, 'filter' => ['operators' => ['eq']]],
        ['select' => true, 'filter' => ['operators' => ['null']]],
        fn () => Article::query()->whereNull('deleted_at')->get(),
    ],
    'filter operator not-null' => [
        'not-null',
        ['select' => true, 'filter' => ['operators' => ['null']]],
        ['select' => true, 'filter' => ['operators' => ['not-null']]],
        fn () => Article::query()->whereNotNull('published_at')->get(),
    ],
    'filter operator between' => [
        'between',
        ['select' => true, 'filter' => ['operators' => ['eq']]],
        ['select' => true, 'filter' => ['operators' => ['between']]],
        fn () => Article::query()->whereBetween('rating', [1, 5])->get(),
    ],
    'filter operator like' => [
        'like',
        ['select' => true, 'filter' => ['operators' => ['eq']]],
        ['select' => true, 'filter' => ['operators' => ['like']]],
        fn () => Article::query()->where('title', 'like', '%laravel%')->get(),
    ],
    'filter.or' => [
        'filter.or',
        ['select' => true, 'filter' => ['operators' => ['eq']]],
        ['select' => true, 'filter' => ['operators' => ['eq']], 'filter.or' => true],
        fn () => Article::query()->where('a', 1)->orWhere('b', 2)->get(),
    ],
    'filter.nested' => [
        'filter.nested',
        ['select' => true, 'filter' => ['operators' => ['eq']], 'filter.or' => true],
        ['select' => true, 'filter' => ['operators' => ['eq']], 'filter.or' => true, 'filter.nested' => true],
        fn () => Article::query()->where(fn ($q) => $q->where('a', 1)->orWhere('b', 2))->get(),
    ],
    'sort' => [
        'sort',
        ['select' => true],
        ['select' => true, 'sort' => true],
        fn () => Article::query()->orderBy('created_at')->get(),
    ],
    'sort.multi' => [
        'sort.multi',
        ['select' => true, 'sort' => true],
        ['select' => true, 'sort' => true, 'sort.multi' => true],
        fn () => Article::query()->orderByDesc('created_at')->orderBy('title')->get(),
    ],
    'page.limit' => [
        'page.limit',
        ['select' => true],
        ['select' => true, 'page.limit' => true],
        fn () => Article::query()->limit(5)->get(),
    ],
    'page.offset' => [
        'page.offset',
        ['select' => true, 'page.limit' => true],
        ['select' => true, 'page.limit' => true, 'page.offset' => true],
        fn () => Article::query()->offset(10)->get(),
    ],
    'aggregate.count' => [
        'aggregate.count',
        ['select' => true],
        ['select' => true, 'aggregate.count' => true],
        fn () => Article::query()->count(),
    ],
    'aggregate.exists' => [
        'aggregate.exists',
        ['select' => true],
        ['select' => true, 'aggregate.exists' => true],
        fn () => Article::query()->exists(),
    ],
    'write.insert' => [
        'write.insert',
        ['select' => true],
        ['select' => true, 'write.insert' => true],
        fn () => Article::query()->toBase()->insert(['title' => 'x']),
    ],
    'write.update' => [
        'write.update',
        ['select' => true],
        ['select' => true, 'write.update' => true],
        fn () => Article::query()->toBase()->where('id', 1)->update(['title' => 'x']),
    ],
    'write.delete' => [
        'write.delete',
        ['select' => true],
        ['select' => true, 'write.delete' => true],
        fn () => Article::query()->toBase()->delete(1),
    ],
]);

it('throws when the capability is absent', function (string $needle, array $without, array $with, Closure $act) {
    $this->defineApiConnection($without);
    Http::fake(['*' => Http::response(['data' => [], 'count' => 0])]);

    try {
        $act();
        $this->fail('Expected UnsupportedCapabilityException for ['.$needle.'], none thrown.');
    } catch (UnsupportedCapabilityException $e) {
        expect($e->getMessage())
            ->toContain($needle)
            ->toContain('testapi')
            ->and($e->connection)->toBe('testapi')
            ->and($e->capability)->toBe($needle);
    }

    Http::assertNothingSent();
})->with('capability matrix');

it('proceeds when the capability is present', function (string $needle, array $without, array $with, Closure $act) {
    $this->defineApiConnection($with);
    Http::fake(['*' => Http::response(['data' => [], 'count' => 0])]);

    try {
        $act();
    } catch (UnsupportedCapabilityException $e) {
        $this->fail("Capability [{$needle}] was declared but still threw: {$e->getMessage()}");
    }

    expect(true)->toBeTrue();
})->with('capability matrix');

it('names the model, method, and fix in the exception', function () {
    $this->defineApiConnection(['select' => true]);

    try {
        Article::query()->limit(25)->get();
        $this->fail('Expected UnsupportedCapabilityException.');
    } catch (UnsupportedCapabilityException $e) {
        expect($e->getMessage())
            ->toContain('testapi')
            ->toContain('page.limit')
            ->toContain(Article::class)
            ->toContain('limit()')
            ->toContain('connections.testapi.capabilities')
            ->and($e->model)->toBe(Article::class)
            ->and($e->builderMethod)->toBe('limit');
    }
});
