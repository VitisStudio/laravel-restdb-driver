<?php

declare(strict_types=1);

namespace Vitis\RestDB\Auth;

use Illuminate\Http\Client\PendingRequest;
use Vitis\RestDB\Contracts\Authenticator;

/** Null object — explicit, so there are no `if ($auth)` checks anywhere. */
final class NoneAuthenticator implements Authenticator
{
    public function authenticate(PendingRequest $request): PendingRequest
    {
        return $request;
    }
}
