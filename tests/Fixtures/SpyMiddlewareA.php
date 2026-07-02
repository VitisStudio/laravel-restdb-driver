<?php

declare(strict_types=1);

namespace Tests\Fixtures;

final class SpyMiddlewareA extends SpyMiddleware
{
    protected string $label = 'a';
}
