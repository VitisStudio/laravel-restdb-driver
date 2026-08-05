<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Article;
use Tests\Fixtures\FakeCompiler;
use Tests\Fixtures\FakeParser;
use Vitis\RestDB\Exceptions\RestDBAuthenticationException;

function oauthConnection($testCase, array $authOverrides = []): void
{
    $testCase->defineOpenApiConnection([
        'auth' => array_replace([
            'driver' => 'oauth2_client_credentials',
            'token_url' => 'https://auth.test/oauth/token',
            'client_id' => 'client-1',
            'client_secret' => 'secret-1',
            'scopes' => ['read'],
        ], $authOverrides),
    ]);
}

function tokenResponse(string $token, int $expiresIn = 3600): array
{
    return ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => $expiresIn];
}

beforeEach(function () {
    config()->set('cache.default', 'array');
});

it('fetches the token once and reuses it across queries', function () {
    oauthConnection($this);

    Http::fake([
        'auth.test/*' => Http::response(tokenResponse('token-1')),
        'api.test/*' => Http::response(['data' => []]),
    ]);

    Article::query()->get();
    Article::query()->get();

    Http::assertSentCount(3); // one token fetch + two API calls

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.test')
        ? $request->header('Authorization') === ['Bearer token-1']
        : true);
});

it('sends the token request bare — no authenticator recursion, form-encoded grant', function () {
    oauthConnection($this);

    Http::fake([
        'auth.test/*' => Http::response(tokenResponse('token-1')),
        'api.test/*' => Http::response(['data' => []]),
    ]);

    Article::query()->get();

    Http::assertSent(function (Request $request) {
        if (! str_starts_with($request->url(), 'https://auth.test')) {
            return true;
        }

        return $request->header('Authorization') === []
            && str_contains($request->body(), 'grant_type=client_credentials')
            && str_contains($request->body(), 'scope=read');
    });
});

it('applies the expiry skew to the cache TTL', function () {
    oauthConnection($this, ['expiry_skew' => 60]);

    Http::fake([
        'auth.test/*' => Http::sequence()
            ->push(tokenResponse('token-1', expiresIn: 120))
            ->push(tokenResponse('token-2', expiresIn: 120)),
        'api.test/*' => Http::response(['data' => []]),
    ]);

    Article::query()->get();

    // 70s < 120s expires_in but > the skewed 60s TTL — must refetch.
    $this->travel(70)->seconds();

    Article::query()->get();

    $tokenFetches = collect(Http::recorded())
        ->filter(fn (array $pair) => str_starts_with($pair[0]->url(), 'https://auth.test'))
        ->count();

    expect($tokenFetches)->toBe(2);
});

it('shares the cached token instead of stampeding the endpoint', function () {
    oauthConnection($this);

    Http::fake([
        'auth.test/*' => Http::response(tokenResponse('token-1')),
        'api.test/*' => Http::response(['data' => []]),
    ]);

    // Two freshly built connections (purged instances) — the second resolves
    // the token from cache, not the endpoint.
    Article::query()->get();
    oauthConnection($this);
    Article::query()->get();

    $tokenFetches = collect(Http::recorded())
        ->filter(fn (array $pair) => str_starts_with($pair[0]->url(), 'https://auth.test'))
        ->count();

    expect($tokenFetches)->toBe(1);
});

it('refreshes and retries exactly once on a 401', function () {
    oauthConnection($this);

    Http::fake([
        'auth.test/*' => Http::sequence()
            ->push(tokenResponse('stale-token'))
            ->push(tokenResponse('fresh-token')),
        'api.test/*' => Http::sequence()
            ->push(['message' => 'expired'], 401)
            ->push(['data' => [['id' => 1]]]),
    ]);

    $articles = Article::query()->get();

    expect($articles)->toHaveCount(1);

    $apiRequests = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $request) => str_starts_with($request->url(), 'https://api.test'))
        ->values();

    expect($apiRequests)->toHaveCount(2)
        ->and($apiRequests[0]->header('Authorization'))->toBe(['Bearer stale-token'])
        ->and($apiRequests[1]->header('Authorization'))->toBe(['Bearer fresh-token']);
});

it('surfaces a second 401 instead of looping', function () {
    oauthConnection($this);

    Http::fake([
        'auth.test/*' => Http::response(tokenResponse('rejected-token')),
        'api.test/*' => Http::response(['message' => 'nope'], 401),
    ]);

    expect(fn () => Article::query()->get())
        ->toThrow(RestDBAuthenticationException::class);

    $apiRequests = collect(Http::recorded())
        ->filter(fn (array $pair) => str_starts_with($pair[0]->url(), 'https://api.test'))
        ->count();

    expect($apiRequests)->toBe(2); // original + exactly one retry
});

it('keeps two connections token-isolated — no cross-talk, no static state', function () {
    config()->set('database.connections.othertapi', [
        'driver' => 'restdb',
        'adapter' => 'generic',
        'base_url' => 'https://other.test',
        'compiler' => FakeCompiler::class,
        'parser' => FakeParser::class,
        'capabilities' => ['select' => true],
        'auth' => [
            'driver' => 'oauth2_client_credentials',
            'token_url' => 'https://auth-b.test/oauth/token',
            'client_id' => 'client-b',
            'client_secret' => 'secret-b',
        ],
    ]);
    oauthConnection($this);

    Http::fake([
        'auth.test/*' => Http::response(tokenResponse('token-a')),
        'auth-b.test/*' => Http::response(tokenResponse('token-b')),
        'api.test/*' => Http::response(['data' => []]),
        'other.test/*' => Http::response(['data' => []]),
    ]);

    Article::query()->get();
    Article::query()->getModel()->setConnection('othertapi')->newQuery()->get();

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.test')
        ? $request->header('Authorization') === ['Bearer token-a']
        : true);

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://other.test')
        ? $request->header('Authorization') === ['Bearer token-b']
        : true);
});

it('maps token endpoint failures to a dedicated exception', function () {
    oauthConnection($this);

    Http::fake(['auth.test/*' => Http::response('down', 503)]);

    expect(fn () => Article::query()->get())
        ->toThrow(RestDBAuthenticationException::class, 'token endpoint returned HTTP 503');
});
