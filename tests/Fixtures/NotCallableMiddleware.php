<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * Deliberately NOT invokable — used to prove http.middleware rejects a class
 * that does not resolve to a callable with a clear configuration error.
 */
final class NotCallableMiddleware
{
    public function nope(): void {}
}
