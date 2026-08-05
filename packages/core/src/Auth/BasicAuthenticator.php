<?php

declare(strict_types=1);

namespace Vitis\RestDB\Auth;

use Illuminate\Http\Client\PendingRequest;
use Vitis\RestDB\Contracts\Authenticator;

final class BasicAuthenticator implements Authenticator
{
    public function __construct(
        private readonly string $username,
        private readonly string $password,
    ) {}

    public function authenticate(PendingRequest $request): PendingRequest
    {
        return $request->withBasicAuth($this->username, $this->password);
    }
}
