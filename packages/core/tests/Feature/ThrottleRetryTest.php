<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Fixtures\Article;
use Vitis\RestDB\Exceptions\RestDBThrottledException;

beforeEach(function () {
    Sleep::fake();
    $this->defineOpenApiConnection();
});

it('retries a throttled read and succeeds', function () {
    Http::fake(['api.test/*' => Http::sequence()
        ->push(['message' => 'too many requests'], 429)
        ->push(['data' => [['id' => 1, 'title' => 'First']]]),
    ]);

    $articles = Article::query()->get();

    expect($articles)->toHaveCount(1)
        ->and($articles->first()->title)->toBe('First');

    Http::assertSentCount(2);
});

it('retries an overloaded origin (503) as a throttle', function () {
    Http::fake(['api.test/*' => Http::sequence()
        ->push('busy', 503)
        ->push(['data' => []]),
    ]);

    Article::query()->get();

    Http::assertSentCount(2);
});

it('honors Retry-After delta-seconds for the wait', function () {
    Http::fake(['api.test/*' => Http::sequence()
        ->push('slow down', 429, ['Retry-After' => '2'])
        ->push(['data' => []]),
    ]);

    Article::query()->get();

    Sleep::assertSequence([Sleep::usleep(2_000_000)]);
});

it('caps a hostile Retry-After at the configured max wait', function () {
    $this->defineOpenApiConnection([
        'http' => ['retry' => ['throttle' => ['max_wait' => 1]]],
    ]);

    Http::fake(['api.test/*' => Http::sequence()
        ->push('come back tomorrow', 429, ['Retry-After' => '86400'])
        ->push(['data' => []]),
    ]);

    Article::query()->get();

    Sleep::assertSequence([Sleep::usleep(1_000_000)]);
});

it('backs off exponentially when no Retry-After is sent', function () {
    $this->defineOpenApiConnection([
        'http' => ['retry' => ['sleep' => 100]],
    ]);

    Http::fake(['api.test/*' => Http::sequence()
        ->push('busy', 429)
        ->push('busy', 429)
        ->push('busy', 429)
        ->push(['data' => []]),
    ]);

    Article::query()->get();

    Sleep::assertSequence([
        Sleep::usleep(100_000),
        Sleep::usleep(200_000),
        Sleep::usleep(400_000),
    ]);
});

it('surfaces a throttled exception once the budget is exhausted', function () {
    $this->defineOpenApiConnection([
        'http' => ['retry' => ['throttle' => ['times' => 2]]],
    ]);

    Http::fake(['api.test/*' => Http::response('nope', 429, ['Retry-After' => '30'])]);

    try {
        Article::query()->get();
        $this->fail('Expected a RestDBThrottledException.');
    } catch (RestDBThrottledException $e) {
        expect($e->status)->toBe(429)
            ->and($e->retryAfter)->toBe(30)
            ->and($e->getMessage())->toContain('throttled');
    }

    // Initial attempt plus the two budgeted retries — never more.
    Http::assertSentCount(3);
});

it('does not retry throttles when the budget is zero', function () {
    $this->defineOpenApiConnection([
        'http' => ['retry' => ['throttle' => ['times' => 0]]],
    ]);

    Http::fake(['api.test/*' => Http::response('nope', 429)]);

    expect(fn () => Article::query()->get())
        ->toThrow(RestDBThrottledException::class);

    Http::assertSentCount(1);
});

it('leaves non-throttle failures on the flat retry sleep', function () {
    $this->defineOpenApiConnection([
        'http' => ['retry' => ['times' => 2, 'sleep' => 150]],
    ]);

    Http::fake(['api.test/*' => Http::sequence()
        ->pushFailedConnection()
        ->push(['data' => []]),
    ]);

    Article::query()->get();

    Sleep::assertSequence([Sleep::usleep(150_000)]);
});
