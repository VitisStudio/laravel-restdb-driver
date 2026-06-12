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
 * OAuth2 refresh-token grant. The current refresh token starts from config and
 * is persisted in the cache — rotated refresh tokens are stored atomically
 * under the same lock that guards the access-token fetch. `invalid_grant`
 * means the refresh token is dead (re-consent required): dedicated exception,
 * no retry. No static state, ever.
 */
final class RefreshTokenAuthenticator implements RefreshableAuthenticator
{
    public function __construct(
        private readonly CacheFactory $cache,
        private readonly HttpFactory $http,
        private readonly string $connection,
        private readonly string $tokenUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $initialRefreshToken,
        private readonly ?string $cacheStore = null,
        private readonly int $expirySkew = 60,
    ) {}

    public function authenticate(PendingRequest $request): PendingRequest
    {
        return $request->withToken($this->accessToken());
    }

    public function invalidate(): void
    {
        // Drop only the access token; the (possibly rotated) refresh token
        // must survive — it is the credential.
        $this->store()->forget($this->accessKey());
    }

    private function accessToken(): string
    {
        $store = $this->store();

        $cached = $store->get($this->accessKey());

        if (is_string($cached)) {
            return $cached;
        }

        $fetch = function () use ($store): string {
            $cached = $store->get($this->accessKey());

            if (is_string($cached)) {
                return $cached;
            }

            return $this->refreshGrant($store);
        };

        $backingStore = $store->getStore();

        if ($backingStore instanceof LockProvider) {
            /** @var string */
            return $backingStore->lock($this->accessKey().':lock', 10)->block(10, $fetch);
        }

        return $fetch();
    }

    private function refreshGrant(Repository $store): string
    {
        $refreshToken = $store->get($this->refreshKey());
        $refreshToken = is_string($refreshToken) ? $refreshToken : $this->initialRefreshToken;

        $response = $this->http->asForm()->post($this->tokenUrl, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);

        if ($response->json('error') === 'invalid_grant') {
            throw RestDBAuthenticationException::invalidGrant($this->connection);
        }

        if ($response->failed()) {
            throw RestDBAuthenticationException::tokenEndpointFailed($this->connection, $response->status());
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw RestDBAuthenticationException::invalidTokenResponse($this->connection);
        }

        $expiresIn = $response->json('expires_in');
        $expiresIn = is_numeric($expiresIn) ? (int) $expiresIn : 3600;

        $store->put($this->accessKey(), $token, max($expiresIn - $this->expirySkew, 10));

        // Rotation: persist the new refresh token inside the same lock.
        $rotated = $response->json('refresh_token');

        if (is_string($rotated) && $rotated !== '') {
            $store->forever($this->refreshKey(), $rotated);
        }

        return $token;
    }

    private function store(): Repository
    {
        return $this->cache->store($this->cacheStore);
    }

    private function accessKey(): string
    {
        return "restdb:token:{$this->connection}:".$this->hash();
    }

    private function refreshKey(): string
    {
        return "restdb:refresh:{$this->connection}:".$this->hash();
    }

    private function hash(): string
    {
        return sha1($this->tokenUrl.'|'.$this->clientId.'|refresh');
    }
}
