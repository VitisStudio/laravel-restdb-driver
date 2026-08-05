<?php

declare(strict_types=1);

namespace Vitis\RestDB\Auth;

use Illuminate\Http\Client\PendingRequest;
use Vitis\RestDB\Contracts\Authenticator;

final class BearerAuthenticator implements Authenticator
{
    public function __construct(private readonly string $token) {}

    public function authenticate(PendingRequest $request): PendingRequest
    {
        return $request->withToken($this->token);
    }
}
