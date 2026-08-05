<?php

declare(strict_types=1);

namespace Vitis\RestDB\Values;

final class ApiResponse
{
    /** @var array<mixed>|null */
    private ?array $decoded = null;

    /** @param array<string, array<int, string>> $headers */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {}

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Decoded JSON body; empty array when the body is not valid JSON.
     *
     * @return array<mixed>
     */
    public function json(): array
    {
        if ($this->decoded === null) {
            $decoded = json_decode($this->body, true);
            $this->decoded = is_array($decoded) ? $decoded : [];
        }

        return $this->decoded;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $values) {
            if (strcasecmp($key, $name) === 0) {
                return $values[0] ?? null;
            }
        }

        return null;
    }
}
