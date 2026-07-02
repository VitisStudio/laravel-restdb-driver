<?php

declare(strict_types=1);

namespace Tests\Fixtures;

final class SpyMiddlewareB extends SpyMiddleware
{
    protected string $label = 'b';
}
