<?php

declare(strict_types=1);

namespace Vitis\RestDB\Adapters;

use Vitis\RestDB\Contracts\Paginator;
use Vitis\RestDB\Values\ApiResponse;
use Vitis\RestDB\Values\CompiledRequest;
use Vitis\RestDB\Values\PageInfo;
use Vitis\RestDB\Values\PageRequest;
use Vitis\RestDB\Values\ResultPage;

/**
 * One page, ever. The default for generic connections that declare no
 * pagination strategy: nothing is added to the wire, and there is never a next
 * page. Client-side limits are still honored by the drain loop.
 */
final class NullPaginator implements Paginator
{
    public function provides(): array
    {
        return [];
    }

    public function applyPage(CompiledRequest $request, ?PageRequest $page): CompiledRequest
    {
        return $request;
    }

    public function pageInfo(ApiResponse $response, ResultPage $page): PageInfo
    {
        return new PageInfo(hasMore: false);
    }

    public function nextRequest(CompiledRequest $current, PageInfo $info): ?CompiledRequest
    {
        return null;
    }
}
