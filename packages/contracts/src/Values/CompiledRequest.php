<?php

declare(strict_types=1);

namespace Vitis\RestDB\Values;

final class CompiledRequest
{
    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly ?array $body = null,
        public readonly array $headers = [],
    ) {}

    /** @param array<string, mixed> $query */
    public function withQuery(array $query): self
    {
        return new self($this->method, $this->path, array_replace($this->query, $query), $this->body, $this->headers);
    }

    public function withoutQueryParam(string ...$keys): self
    {
        $query = $this->query;

        foreach ($keys as $key) {
            unset($query[$key]);
        }

        return new self($this->method, $this->path, $query, $this->body, $this->headers);
    }

    /** Human-readable request line for query logging: "GET /articles?filter[status]=open". */
    public function requestLine(): string
    {
        $query = $this->query === [] ? '' : '?'.urldecode(http_build_query($this->query));

        return strtoupper($this->method).' '.$this->path.$query;
    }
}
