<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Article;
use Vitis\RestDB\Exceptions\RestDBAuthenticationException;

function refreshConnection($testCase): void
{
    $testCase->defineOpenApiConnection([
        'auth' => [
            'driver' => 'oauth2_refresh_token',
            'token_url' => 'https://auth.test/oauth/token',
            'client_id' => 'client-1',
            'client_secret' => 'secret-1',
            'refresh_token' => 'initial-refresh',
        ],
    ]);
}

beforeEach(function () {
    config()->set('cache.default', 'array');
    refreshConnection($this);
});

it('exchanges the refresh token for an access token', function () {
    Http::fake([
        'auth.test/*' => Http::response([
            'access_token' => 'acc-1', 'expires_in' => 3600, 'refresh_token' => 'rotated-refresh',
        ]),
        'api.test/*' => Http::response(['data' => []]),
    ]);

    Article::query()->get();

    Http::assertSent(function (Request $request) {
        if (! str_starts_with($request->url(), 'https://auth.test')) {
            return $request->header('Authorization') === ['Bearer acc-1'];
        }

        return str_contains($request->body(), 'grant_type=refresh_token')
            && str_contains($request->body(), 'refresh_token=initial-refresh');
    });
});

it('persists rotated refresh tokens for the next grant', function () {
    Http::fake([
        'auth.test/*' => Http::sequence()
            ->push(['access_token' => 'acc-1', 'expires_in' => 3600, 'refresh_token' => 'rotated-refresh'])
            ->push(['access_token' => 'acc-2', 'expires_in' => 3600]),
        'api.test/*' => Http::sequence()
            ->push(['data' => []])
            ->push(['message' => 'expired'], 401)
            ->push(['data' => []]),
    ]);

    Article::query()->get();   // grant #1 with initial-refresh; rotation stored
    Article::query()->get();   // 401 → invalidate → grant #2 must use the rotated token

    $grants = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $request) => str_starts_with($request->url(), 'https://auth.test'))
        ->values();

    expect($grants)->toHaveCount(2)
        ->and($grants[1]->body())->toContain('refresh_token=rotated-refresh');
});

it('maps invalid_grant to a re-consent exception without retrying', function () {
    Http::fake([
        'auth.test/*' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    expect(fn () => Article::query()->get())
        ->toThrow(RestDBAuthenticationException::class, 'invalid_grant');

    $grants = collect(Http::recorded())
        ->filter(fn (array $pair) => str_starts_with($pair[0]->url(), 'https://auth.test'))
        ->count();

    expect($grants)->toBe(1);
});
