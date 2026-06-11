<?php

declare(strict_types=1);

namespace Vitis\RestDB\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Vitis\RestDB\Contracts\Authenticator;
use Vitis\RestDB\Values\ApiResponse;
use Vitis\RestDB\Values\CompiledRequest;
use Vitis\RestDB\Values\ConnectionConfig;

/**
 * Pure HTTP: builds a PendingRequest from an *injected* factory (never the
 * facade — though Http::fake() still intercepts, since the facade resolves the
 * same singleton), authenticates it, sends, and wraps the response. Status
 * mapping is the connection's job — Transport returns every response.
 */
final class Transport
{
    public function __construct(
        private readonly Factory $http,
        private readonly ConnectionConfig $config,
        private readonly Authenticator $authenticator,
        private readonly HttpOptions $options,
    ) {}

    public function send(CompiledRequest $request): ApiResponse
    {
        $pending = $this->pending();

        if ($request->headers !== []) {
            $pending = $pending->withHeaders($request->headers);
        }

        $pending = $this->authenticator->authenticate($pending);

        $options = ['query' => $request->query];

        if ($request->body !== null) {
            $options['json'] = $request->body;
        }

        $response = $pending->send($request->method, ltrim($request->path, '/'), $options);

        return new ApiResponse($response->status(), $this->normalizeHeaders($response), $response->body());
    }

    /** @return array<string, array<int, string>> */
    private function normalizeHeaders(Response $response): array
    {
        $headers = [];

        foreach ($response->headers() as $name => $values) {
            if (is_string($name) && is_array($values)) {
                $headers[$name] = array_values(array_filter($values, is_string(...)));
            }
        }

        return $headers;
    }

    private function pending(): PendingRequest
    {
        $pending = $this->http
            ->baseUrl($this->config->baseUrl())
            ->acceptJson()
            ->withHeaders($this->config->headers())
            ->timeout($this->options->timeout)
            ->connectTimeout($this->options->connectTimeout);

        if ($this->options->retryTimes > 1) {
            // Retries cover transport failures only; HTTP status handling —
            // including the 401-refresh-retry-once — is layered separately.
            $pending = $pending->retry(
                $this->options->retryTimes,
                $this->options->retrySleep,
                static fn (mixed $exception): bool => $exception instanceof ConnectionException,
                throw: false,
            );
        }

        return $pending;
    }
}
