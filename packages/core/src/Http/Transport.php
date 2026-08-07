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

    /** Connection failures retried this send(), gated by retry.times. */
    private int $connectionFailures = 0;

    /** Throttled (429/503) responses retried this send(), gated by retry.throttle.times. */
    private int $throttled = 0;

    /** Attempts the non-throttle causes may consume: max(retry.times, 2 if refreshable). */
    private int $baseAttempts = 1;

    /**
     * @param  list<callable>  $middleware  resolved Guzzle handler-stack
     *                                      middleware, applied in order (the
     *                                      factory turns config class-strings
     *                                      into instances)
     */
    public function __construct(
        private readonly Factory $http,
        private readonly ConnectionConfig $config,
        private readonly Authenticator $authenticator,
        private readonly HttpOptions $options,
        private readonly array $middleware = [],
    ) {}

    public function send(CompiledRequest $request): ApiResponse
    {
        $this->refreshed = false;
        $this->connectionFailures = 0;
        $this->throttled = 0;

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

        // User-registered Guzzle handler-stack middleware (caching, rate
        // limiting, logging — none of it owned by this driver). Applied in
        // registration order, before auth and retry wrap the request.
        foreach ($this->middleware as $middleware) {
            $pending = $pending->withMiddleware($middleware);
        }

        $refreshable = $this->authenticator instanceof RefreshableAuthenticator;
        $this->baseAttempts = max($this->options->retryTimes, $refreshable ? 2 : 1);

        // Throttle retries get their own budget on top, so a burst of 429s
        // cannot eat the attempt a token refresh needs (or vice versa). Each
        // cause is gated by its own counter in shouldRetry(); this total only
        // has to be large enough that Laravel's cap never truncates a budget.
        $attempts = $this->baseAttempts + $this->options->throttleTimes;

        if ($attempts > 1) {
            $pending = $pending->retry(
                $attempts,
                fn (int $attempt, mixed $exception): int => $this->sleepFor($exception),
                fn (mixed $exception, PendingRequest $request): bool => $this->shouldRetry($exception, $request),
                throw: false,
            );
        }

        return $pending;
    }

    /**
     * Connection failures retry per config. A throttled response (429, or 503
     * from an overloaded origin) retries on its own budget — safe even for
     * non-idempotent calls, since both mean the origin refused the request
     * before processing. A 401 with a refreshable authenticator retries
     * exactly once with a fresh credential, same reasoning. A second 401
     * surfaces; never loop.
     */
    private function shouldRetry(mixed $exception, PendingRequest $request): bool
    {
        if ($exception instanceof ConnectionException) {
            return ++$this->connectionFailures < $this->baseAttempts;
        }

        if (
            $exception instanceof RequestException
            && in_array($exception->response->status(), [429, 503], true)
        ) {
            return ++$this->throttled <= $this->options->throttleTimes;
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

    /**
     * Milliseconds to wait before the retry shouldRetry() just approved.
     *
     * Throttled responses honor Retry-After when the origin sends one, and
     * fall back to exponential backoff from the configured sleep floor when it
     * doesn't — either way capped at retry.throttle.max_wait so a hostile
     * header cannot park a worker. Every other retryable failure keeps the
     * flat configured sleep.
     */
    private function sleepFor(mixed $exception): int
    {
        if (
            ! $exception instanceof RequestException
            || ! in_array($exception->response->status(), [429, 503], true)
        ) {
            return $this->options->retrySleep;
        }

        $cap = $this->options->throttleMaxWait * 1000;
        $retryAfter = $this->retryAfterMs($exception->response);

        if ($retryAfter !== null) {
            return min($retryAfter, $cap);
        }

        // shouldRetry() has already counted this response, so the first
        // throttle backs off at the floor, then 2x, 4x, ...
        return min($this->options->retrySleep * (2 ** max($this->throttled - 1, 0)), $cap);
    }

    /**
     * The Retry-After header as milliseconds: delta-seconds or an HTTP-date,
     * per RFC 9110. Null when absent or unparseable, negative dates clamp to
     * zero.
     */
    private function retryAfterMs(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        if ($header === '') {
            return null;
        }

        if (is_numeric($header)) {
            return max((int) ceil((float) $header * 1000), 0);
        }

        $timestamp = strtotime($header);

        return $timestamp === false ? null : max(($timestamp - time()) * 1000, 0);
    }
}
