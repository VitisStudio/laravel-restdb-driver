<?php

declare(strict_types=1);

namespace Vitis\RestDB\Contracts;

/** Opt-in capability for token-based authenticators — drives the 401 retry-once. */
interface RefreshableAuthenticator extends Authenticator
{
    /** Drop the cached credential; the next authenticate() fetches fresh. */
    public function invalidate(): void;
}
