<?php

declare(strict_types=1);

namespace Vitis\RestDB\Values;

final class ConnectionConfig
{
    /** @param array<string, mixed> $config the fully merged connection array */
    public function __construct(
        public readonly string $name,
        private readonly array $config,
    ) {}

    /** Dot-notation access into the connection array. */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->config;

        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function baseUrl(): string
    {
        $url = $this->get('base_url');

        return is_string($url) ? $url : '';
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        $headers = $this->get('headers');
        $result = [];

        foreach (is_array($headers) ? $headers : [] as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $result[$name] = $value;
            }
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function auth(): array
    {
        return self::stringKeyed($this->get('auth'));
    }

    /** @return array<string, mixed> */
    public function guards(): array
    {
        return self::stringKeyed($this->get('guards'));
    }

    /** @return array<string, mixed> */
    public static function stringKeyed(mixed $value): array
    {
        $result = [];

        foreach (is_array($value) ? $value : [] as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->config;
    }
}
