<?php

declare(strict_types=1);

namespace Vitis\RestDB\Contracts;

use Illuminate\Http\Client\PendingRequest;

interface Authenticator
{
    public function authenticate(PendingRequest $request): PendingRequest;
}
