<?php

declare(strict_types=1);

namespace Vitis\RestDB\JsonApi\Pagination;

use Vitis\RestDB\Capabilities\Capability;
use Vitis\RestDB\Contracts\Paginator;
use Vitis\RestDB\Values\ApiResponse;
use Vitis\RestDB\Values\CompiledRequest;
use Vitis\RestDB\Values\PageInfo;
use Vitis\RestDB\Values\ResultPage;

/**
 * Shared JSON:API pagination behavior. links.next is the portable has-more
 * signal and is followed verbatim; totals exist only when a meta_total path
 * is configured — the spec does not define them.
 */
abstract class AbstractJsonApiPaginator implements Paginator
{
    public function __construct(
        protected readonly ?int $defaultSize = null,
        protected readonly ?string $metaTotalPath = null,
    ) {}

    /** @return list<Capability> */
    abstract protected function strategyCapabilities(): array;

    public function provides(): array
    {
        $capabilities = [Capability::Limit, ...$this->strategyCapabilities()];

        if ($this->metaTotalPath !== null) {
            $capabilities[] = Capability::TotalCount;
        }

        return $capabilities;
    }

    public function pageInfo(ApiResponse $response, ResultPage $page): PageInfo
    {
        $json = $response->json();

        $links = $json['links'] ?? null;
        $next = is_array($links) ? ($links['next'] ?? null) : null;
        $next = is_string($next) && $next !== '' ? $next : null;

        return new PageInfo(
            hasMore: $next !== null,
            total: $this->total($json),
            nextUrl: $next,
        );
    }

    public function nextRequest(CompiledRequest $current, PageInfo $info): ?CompiledRequest
    {
        if ($info->nextUrl === null) {
            return null;
        }

        // Followed verbatim — the server owns the shape of its next link.
        return new CompiledRequest('GET', $info->nextUrl, [], null, $current->headers);
    }

    /** @param array<mixed> $json */
    private function total(array $json): ?int
    {
        if ($this->metaTotalPath === null) {
            return null;
        }

        $value = $json;

        foreach (explode('.', $this->metaTotalPath) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
