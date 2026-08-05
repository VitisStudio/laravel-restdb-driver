<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Vitis\RestDB\Capabilities\Capability;
use Vitis\RestDB\Contracts\Paginator;
use Vitis\RestDB\Values\ApiResponse;
use Vitis\RestDB\Values\CompiledRequest;
use Vitis\RestDB\Values\PageInfo;
use Vitis\RestDB\Values\PageRequest;
use Vitis\RestDB\Values\ResultPage;

/**
 * Offset paginator for tests. Responses signal continuation via
 * meta.has_more + meta.next_offset; the next offset travels in
 * PageInfo::$cursor.
 */
final class FakePaginator implements Paginator
{
    public function provides(): array
    {
        return [Capability::Limit, Capability::Offset];
    }

    public function applyPage(CompiledRequest $request, ?PageRequest $page): CompiledRequest
    {
        if ($page === null) {
            return $request;
        }

        $params = [];

        if ($page->limit !== null) {
            $params['limit'] = (string) $page->limit;
        }

        if ($page->offset !== null) {
            $params['offset'] = (string) $page->offset;
        }

        return $request->withQuery($params);
    }

    public function pageInfo(ApiResponse $response, ResultPage $page): PageInfo
    {
        $meta = $page->meta;
        $hasMore = ($meta['has_more'] ?? false) === true;
        $next = $meta['next_offset'] ?? null;

        return new PageInfo(
            hasMore: $hasMore,
            cursor: is_numeric($next) ? (string) $next : null,
        );
    }

    public function nextRequest(CompiledRequest $current, PageInfo $info): ?CompiledRequest
    {
        if (! $info->hasMore || $info->cursor === null) {
            return null;
        }

        return $current->withQuery(['offset' => $info->cursor]);
    }
}
