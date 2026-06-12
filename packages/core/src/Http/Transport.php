<?php

declare(strict_types=1);

namespace Vitis\RestDB\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Vitis\RestDB\Contracts\Authenticator;
use Vitis\RestDB\Contracts\RefreshableAuthenticator;
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
    /** One refresh per send(), tracked per request cycle — never a loop. */
    private bool $refreshed = false;

    public function __construct(
        private readonly Factory $http,
        private readonly ConnectionConfig $config,
        private readonly Authenticator $authenticator,
        private readonly HttpOptions $options,
    ) {}

    public function send(CompiledRequest $request): ApiResponse
    {
        $this->refreshed = false;

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

        $refreshable = $this->authenticator instanceof RefreshableAuthenticator;
        $attempts = max($this->options->retryTimes, $refreshable ? 2 : 1);

        if ($attempts > 1) {
            $pending = $pending->retry(
                $attempts,
                $this->options->retrySleep,
                fn (mixed $exception, PendingRequest $request): bool => $this->shouldRetry($exception, $request),
                throw: false,
            );
        }

        return $pending;
    }

    /**
     * Connection failures retry per config. A 401 with a refreshable
     * authenticator retries exactly once with a fresh credential — safe even
     * for non-idempotent calls, since a 401 means the origin rejected the
     * request before processing. A second 401 surfaces; never loop.
     */
    private function shouldRetry(mixed $exception, PendingRequest $request): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if (
            $this->authenticator instanceof RefreshableAuthenticator
            && $exception instanceof RequestException
            && $exception->response->status() === 401
            && ! $this->refreshed
        ) {
            $this->refreshed = true;
            $this->authenticator->invalidate();
            $this->authenticator->authenticate($request);

            return true;
        }

        return false;
    }
}
