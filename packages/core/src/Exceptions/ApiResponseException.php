<?php

declare(strict_types=1);

namespace Vitis\RestDB\Exceptions;

use RuntimeException;
use Vitis\RestDB\Contracts\RestDBException;
use Vitis\RestDB\Values\ApiResponse;
use Vitis\RestDB\Values\CompiledRequest;
use Vitis\RestDB\Values\ErrorBag;

final class ApiResponseException extends RuntimeException implements RestDBException
{
    /** @param array<string, array<int, string>> $responseHeaders Authorization always redacted */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly string $body,
        public readonly array $responseHeaders,
        public readonly ?ErrorBag $errors = null,
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(
        string $connection,
        CompiledRequest $request,
        ApiResponse $response,
        ?ErrorBag $errors = null,
    ): self {
        $detail = $errors?->any() === true
            ? $errors->summary()
            : mb_substr($response->body, 0, 500);

        return new self(
            "Connection [{$connection}]: {$request->requestLine()} returned HTTP {$response->status}."
            .($detail === '' ? '' : " {$detail}"),
            $response->status,
            $response->body,
            self::redact($response->headers),
            $errors,
        );
    }

    /**
     * @param  array<string, array<int, string>>  $headers
     * @return array<string, array<int, string>>
     */
    private static function redact(array $headers): array
    {
        foreach ($headers as $name => $values) {
            if (in_array(strtolower($name), ['authorization', 'proxy-authorization', 'set-cookie'], true)) {
                $headers[$name] = ['[redacted]'];
            }
        }

        return $headers;
    }
}
