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
        if ($page === null) {
            return $this->defaultSize === null
                ? $request
                : $request->withQuery(['page[size]' => (string) $this->defaultSize]);
        }

        if ($page->offset !== null) {
            throw UnsupportedQueryException::whereType('offset on a page-number strategy');
        }

        $params = [];
        $size = $page->limit ?? $this->defaultSize;

        if ($size !== null) {
            $params['page[size]'] = (string) $size;
        }

        if ($page->page !== null) {
            $params['page[number]'] = (string) $page->page;
        }

        return $request->withQuery($params);
    }
}
