<?php

declare(strict_types=1);

namespace Vitis\RestDB\Http;

final class HttpOptions
{
    /**
     * @param  list<class-string>  $middleware  Guzzle handler-stack middleware
     *                                          class names, applied in order
     */
    public function __construct(
        public readonly int $timeout = 10,
        public readonly int $connectTimeout = 2,
        public readonly int $retryTimes = 1,
        public readonly int $retrySleep = 100,
        public readonly array $middleware = [],
    ) {}

    /** @param array<string, mixed> $config the connection's (already merged) http array */
    public static function fromConfig(array $config): self
    {
        $retry = is_array($config['retry'] ?? null) ? $config['retry'] : [];

        return new self(
            timeout: self::positiveInt($config['timeout'] ?? null, 10),
            connectTimeout: self::positiveInt($config['connect_timeout'] ?? null, 2),
            retryTimes: self::positiveInt($retry['times'] ?? null, 1),
            retrySleep: self::positiveInt($retry['sleep'] ?? null, 100),
            middleware: self::classStrings($config['middleware'] ?? null),
        );
    }

    /**
     * Accept only non-empty strings as middleware class names; anything else
     * (non-array, non-string entries) is ignored. Container resolution and the
     * "is it actually callable" check happen at wiring time in the factory.
     *
     * @return list<class-string>
     */
    private static function classStrings(mixed $value): array
    {
        $result = [];

        foreach (is_array($value) ? $value : [] as $entry) {
            if (is_string($entry) && $entry !== '') {
                /** @var class-string $entry */
                $result[] = $entry;
            }
        }

        return $result;
    }

    private static function positiveInt(mixed $value, int $default): int
    {
        return is_int($value) && $value > 0 ? $value : $default;
    }
}
