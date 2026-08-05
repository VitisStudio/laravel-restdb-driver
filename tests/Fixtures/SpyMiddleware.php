<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Psr\Http\Message\RequestInterface;

/**
 * A Guzzle handler-stack middleware for tests: records that it ran (with its
 * label, in call order) and stamps an identifying header on the outgoing
 * request so assertions can prove it reached the wire. Subclasses set $label so
 * two distinct class-strings can verify ordering.
 */
class SpyMiddleware
{
    /** @var list<string> labels in the order middleware were invoked */
    public static array $calls = [];

    protected string $label = 'spy';

    public static function reset(): void
    {
        self::$calls = [];
    }

    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            self::$calls[] = $this->label;

            return $handler($request->withHeader('X-Spy', $this->label), $options);
        };
    }
}
