<?php

declare(strict_types=1);

namespace Vitis\RestDB\Exceptions;

use RuntimeException;
use Vitis\RestDB\Contracts\RestDBException;
use Vitis\RestDB\Values\ApiResponse;
use Vitis\RestDB\Values\CompiledRequest;

/**
 * The API refused the request as busy — HTTP 429, or 503 from an overloaded
 * origin — and the transport's retry budget ran out. Distinct from
 * ApiResponseException so callers can tell "the origin is busy, back off and
 * reschedule" apart from a real failure.
 */
final class RestDBThrottledException extends RuntimeException implements RestDBException
{
    /** @param int|null $retryAfter seconds the origin asked us to wait, when it said */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public static function exhausted(string $connection, CompiledRequest $request, ApiResponse $response): self
    {
        $retryAfter = $response->header('Retry-After');
        $retryAfter = is_numeric($retryAfter) ? (int) ceil((float) $retryAfter) : null;

        return new self(
            "Connection [{$connection}]: {$request->requestLine()} was throttled (HTTP {$response->status}) and retries were exhausted."
            .($retryAfter === null ? '' : " The API asked for a {$retryAfter}s wait."),
            $response->status,
            $retryAfter,
        );
    }
}
