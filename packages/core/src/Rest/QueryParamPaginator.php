<?php

declare(strict_types=1);

namespace Vitis\RestDB\Rest;

use Vitis\RestDB\Capabilities\Capability;
use Vitis\RestDB\Contracts\Paginator;
use Vitis\RestDB\Exceptions\UnsupportedQueryException;
use Vitis\RestDB\Values\ApiResponse;
use Vitis\RestDB\Values\CompiledRequest;
use Vitis\RestDB\Values\ConnectionConfig;
use Vitis\RestDB\Values\PageInfo;
use Vitis\RestDB\Values\PageRequest;
use Vitis\RestDB\Values\ResultPage;

/**
 * The generic adapter's default paginator: plain query parameters, named in
 * config. The parameters you name are the capabilities you get — this class
 * contributes page.* to the connection's set from config alone.
 *
 *   pagination.params.limit   e.g. '_limit', 'per_page'
 *   pagination.params.page    1-based page number, e.g. '_page'
 *   pagination.params.offset  e.g. '_start', 'offset'
 *   pagination.total_header   total-count response header, e.g. 'X-Total-Count'
 *   pagination.total_path     dot path to a body total, e.g. 'meta.total'
 *
 * A total source powers one-request paginate() and count(). Without one the
 * drain advances page by page until a short or empty page; the guards
 * max_pages cap still bounds the loop.
 */
final class QueryParamPaginator implements Paginator
{
    private readonly ?string $limitParam;

    private readonly ?string $pageParam;

    private readonly ?string $offsetParam;

    private readonly ?string $totalHeader;

    private readonly ?string $totalPath;

    public function __construct(ConnectionConfig $config)
    {
        $this->limitParam = self::param($config, 'pagination.params.limit');
        $this->pageParam = self::param($config, 'pagination.params.page');
        $this->offsetParam = self::param($config, 'pagination.params.offset');
        $this->totalHeader = self::param($config, 'pagination.total_header');
        $this->totalPath = self::param($config, 'pagination.total_path');
    }

    public function provides(): array
    {
        $capabilities = [];

        if ($this->limitParam !== null) {
            $capabilities[] = Capability::Limit;
        }

        if ($this->pageParam !== null) {
            $capabilities[] = Capability::PageNumber;
        }

        if ($this->offsetParam !== null) {
            $capabilities[] = Capability::Offset;
        }

        if ($this->totalHeader !== null || $this->totalPath !== null) {
            $capabilities[] = Capability::TotalCount;
        }

        return $capabilities;
    }

    public function applyPage(CompiledRequest $request, ?PageRequest $page): CompiledRequest
    {
        if ($page === null) {
            return $request;
        }

        if ($page->cursor !== null) {
            throw UnsupportedQueryException::whereType(
                'cursor pagination (configure a custom paginator class on the connection)',
            );
        }

        $params = [];

        if ($page->limit !== null) {
            $params[$this->require($this->limitParam, 'pagination.params.limit', 'a limit')] = (string) $page->limit;
        }

        if ($page->page !== null) {
            $params[$this->require($this->pageParam, 'pagination.params.page', 'a page number')] = (string) $page->page;
        } elseif ($page->offset !== null) {
            $params[$this->require($this->offsetParam, 'pagination.params.offset', 'an offset')] = (string) $page->offset;
        }

        return $request->withQuery($params);
    }

    public function pageInfo(ApiResponse $response, ResultPage $page): PageInfo
    {
        $total = $this->total($response);

        return new PageInfo(
            // With a total, nextRequest() does the real math; without one the
            // drain probes forward until a page comes back empty.
            hasMore: $total !== null || $page->rows !== [],
            total: $total,
        );
    }

    public function nextRequest(CompiledRequest $current, PageInfo $info): ?CompiledRequest
    {
        if (! $info->hasMore) {
            return null;
        }

        $limit = self::intParam($current, $this->limitParam);

        if ($limit < 1) {
            return null; // unpaginated request — the server returned everything
        }

        if ($this->pageParam !== null && isset($current->query[$this->pageParam])) {
            $page = self::intParam($current, $this->pageParam);

            return $info->total === null || $page * $limit < $info->total
                ? $current->withQuery([$this->pageParam => (string) ($page + 1)])
                : null;
        }

        if ($this->offsetParam === null) {
            return null;
        }

        $start = self::intParam($current, $this->offsetParam);

        return $info->total === null || $start + $limit < $info->total
            ? $current->withQuery([$this->offsetParam => (string) ($start + $limit)])
            : null;
    }

    private function total(ApiResponse $response): ?int
    {
        if ($this->totalHeader !== null) {
            $total = $response->header($this->totalHeader);

            if (is_numeric($total)) {
                return (int) $total;
            }
        }

        if ($this->totalPath !== null) {
            $value = $response->json();

            foreach (explode('.', $this->totalPath) as $segment) {
                if (! is_array($value) || ! array_key_exists($segment, $value)) {
                    return null;
                }

                $value = $value[$segment];
            }

            return is_numeric($value) ? (int) $value : null;
        }

        return null;
    }

    private function require(?string $param, string $key, string $feature): string
    {
        return $param ?? throw new UnsupportedQueryException(
            ucfirst($feature).' was requested but this connection has no query parameter for it. '
            ."Set {$key} if the API supports it, or use a pagination style the connection declares.",
        );
    }

    private static function intParam(CompiledRequest $request, ?string $param): int
    {
        $value = $param === null ? null : ($request->query[$param] ?? null);

        return is_numeric($value) ? (int) $value : 0;
    }

    private static function param(ConnectionConfig $config, string $key): ?string
    {
        $value = $config->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
