<?php

declare(strict_types=1);

namespace Vitis\RestDB\Auth;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Vitis\RestDB\Contracts\RefreshableAuthenticator;
use Vitis\RestDB\Exceptions\RestDBAuthenticationException;

/**
 * OAuth2 client-credentials grant. The token is cached for
 * expires_in − expiry_skew (floor 10s) under a key that hashes the inputs, so
 * changed credentials or scopes auto-invalidate. Concurrent fetches are
 * lock-guarded with a double-checked read — workers never stampede the token
 * endpoint at expiry. The token request goes out on the bare HTTP factory,
 * never through the authenticator pipeline. No static state, ever.
 */
final class ClientCredentialsAuthenticator implements RefreshableAuthenticator
{
    /** @param list<string> $scopes */
    public function __construct(
        private readonly CacheFactory $cache,
        private readonly HttpFactory $http,
        private readonly string $connection,
        private readonly string $tokenUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly array $scopes = [],
        private readonly ?string $cacheStore = null,
        private readonly int $expirySkew = 60,
    ) {}

    public function authenticate(PendingRequest $request): PendingRequest
    {
        return $request->withToken($this->token());
    }

    public function invalidate(): void
    {
        $this->store()->forget($this->cacheKey());
    }

    private function token(): string
    {
        $store = $this->store();
        $key = $this->cacheKey();

        $cached = $store->get($key);

        if (is_string($cached)) {
            return $cached;
        }

        $fetch = function () use ($store, $key): string {
            $cached = $store->get($key); // double-checked read inside the lock

            if (is_string($cached)) {
                return $cached;
            }

            [$token, $ttl] = $this->fetchToken();
            $store->put($key, $token, $ttl);

            return $token;
        };

        $backingStore = $store->getStore();

        if ($backingStore instanceof LockProvider) {
            /** @var string */
            return $backingStore->lock($key.':lock', 10)->block(10, $fetch);
        }

        return $fetch();
    }

    /** @return array{string, int} token + ttl seconds */
    private function fetchToken(): array
    {
        $payload = [
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ];

        if ($this->scopes !== []) {
            $payload['scope'] = implode(' ', $this->scopes);
        }

        // Bare factory: no base URL, no connection headers, no authenticator —
        // recursion through the pipeline is structurally impossible.
        $response = $this->http->asForm()->post($this->tokenUrl, $payload);

        if ($response->failed()) {
            throw RestDBAuthenticationException::tokenEndpointFailed($this->connection, $response->status());
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw RestDBAuthenticationException::invalidTokenResponse($this->connection);
        }

        $expiresIn = $response->json('expires_in');
        $expiresIn = is_numeric($expiresIn) ? (int) $expiresIn : 3600;

        return [$token, max($expiresIn - $this->expirySkew, 10)];
    }

    private function store(): Repository
    {
        return $this->cache->store($this->cacheStore);
    }

    private function cacheKey(): string
    {
        $hash = sha1($this->tokenUrl.'|'.$this->clientId.'|'.implode(' ', $this->scopes));

        return "restdb:token:{$this->connection}:{$hash}";
    }
}
