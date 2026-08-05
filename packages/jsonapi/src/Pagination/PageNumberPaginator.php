<?php

declare(strict_types=1);

namespace Vitis\RestDB\JsonApi\Pagination;

use Vitis\RestDB\Capabilities\Capability;
use Vitis\RestDB\Exceptions\UnsupportedQueryException;
use Vitis\RestDB\Values\CompiledRequest;
use Vitis\RestDB\Values\PageRequest;

/** page[number] / page[size]. */
final class PageNumberPaginator extends AbstractJsonApiPaginator
{
    protected function strategyCapabilities(): array
    {
        return [Capability::PageNumber];
    }

    public function applyPage(CompiledRequest $request, ?PageRequest $page): CompiledRequest
    {
        if ($page?->offset !== null) {
            throw UnsupportedQueryException::whereType('offset on a page-number strategy');
        }

        $params = [];
        $size = $page->limit ?? $this->defaultSize;

        if ($size !== null) {
            // Size never travels alone — strict servers (hatchify) reject
            // page[size] without page[number], and an explicit first page
            // means the same thing everywhere.
            $params['page[size]'] = (string) $size;
            $params['page[number]'] = (string) ($page->page ?? 1);
        } elseif ($page?->page !== null) {
            $params['page[number]'] = (string) $page->page;
        }

        return $params === [] ? $request : $request->withQuery($params);
    }

    protected function totalBasedNext(CompiledRequest $current, int $total): ?CompiledRequest
    {
        $size = self::intParam($current, 'page[size]');

        if ($size === null) {
            return null; // unpaginated — the server returned everything
        }

        $page = self::intParam($current, 'page[number]') ?? 1;

        return $page * $size < $total
            ? $current->withQuery(['page[number]' => (string) ($page + 1)])
            : null;
    }
}
