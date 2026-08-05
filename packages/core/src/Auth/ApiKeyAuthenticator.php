<?php

declare(strict_types=1);

namespace Vitis\RestDB\Auth;

use Illuminate\Http\Client\PendingRequest;
use Vitis\RestDB\Contracts\Authenticator;
use Vitis\RestDB\Exceptions\InvalidConfigurationException;

final class ApiKeyAuthenticator implements Authenticator
{
    public function __construct(
        private readonly string $in,
        private readonly string $name,
        private readonly string $key,
    ) {}

    /** @param array<string, mixed> $auth */
    public static function fromConfig(array $auth, string $connection): self
    {
        $in = $auth['in'] ?? 'header';
        $name = $auth['name'] ?? null;
        $key = $auth['key'] ?? null;

        if (! is_string($name) || $name === '' || ! is_string($key)) {
            throw InvalidConfigurationException::missing('auth.name / auth.key', $connection);
        }

        if (! in_array($in, ['header', 'query'], true)) {
            throw InvalidConfigurationException::missing("auth.in ('header' or 'query')", $connection);
        }

        return new self($in, $name, $key);
    }

    public function authenticate(PendingRequest $request): PendingRequest
    {
        return $this->in === 'header'
            ? $request->withHeaders([$this->name => $this->key])
            : $request->withQueryParameters([$this->name => $this->key]);
    }
}
