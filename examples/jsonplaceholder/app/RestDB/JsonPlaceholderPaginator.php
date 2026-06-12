<?php

declare(strict_types=1);

namespace App\RestDB;

use Vitis\RestDB\Capabilities\Capability;
use Vitis\RestDB\Contracts\Paginator;
use Vitis\RestDB\Values\ApiResponse;
use Vitis\RestDB\Values\CompiledRequest;
use Vitis\RestDB\Values\PageInfo;
use Vitis\RestDB\Values\PageRequest;
use Vitis\RestDB\Values\ResultPage;

/**
 * json-server pagination: _page/_limit (1-based pages), _start/_limit
 * (offsets), and an X-Total-Count header on paginated responses. The header
 * is what powers one-request paginate() and count() — this paginator
 * contributes page.total to the connection's capabilities because of it.
 */
final class JsonPlaceholderPaginator implements Paginator
{
    public function provides(): array
    {
        return [Capability::Limit, Capability::PageNumber, Capability::Offset, Capability::TotalCount];
    }

    public function applyPage(CompiledRequest $request, ?PageRequest $page): CompiledRequest
    {
        if ($page === null) {
            return $request;
        }

        $params = [];

        if ($page->limit !== null) {
            $params['_limit'] = (string) $page->limit;
        }

        if ($page->page !== null) {
            $params['_page'] = (string) $page->page;
        } elseif ($page->offset !== null) {
            $params['_start'] = (string) $page->offset;
        }

        return $request->withQuery($params);
    }

    public function pageInfo(ApiResponse $response, ResultPage $page): PageInfo
    {
        $total = $response->header('X-Total-Count');

        return new PageInfo(
            hasMore: $total !== null, // nextRequest() does the real math
            total: is_numeric($total) ? (int) $total : null,
        );
    }

    public function nextRequest(CompiledRequest $current, PageInfo $info): ?CompiledRequest
    {
        $limit = (int) ($current->query['_limit'] ?? 0);

        if ($info->total === null || $limit < 1) {
            return null; // unpaginated request — json-server returned everything
        }

        if (isset($current->query['_page'])) {
            $page = (int) $current->query['_page'];

            return $page * $limit < $info->total
                ? $current->withQuery(['_page' => (string) ($page + 1)])
                : null;
        }

        $start = (int) ($current->query['_start'] ?? 0);

        return $start + $limit < $info->total
            ? $current->withQuery(['_start' => (string) ($start + $limit)])
            : null;
    }
}
