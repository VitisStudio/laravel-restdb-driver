<?php

declare(strict_types=1);

namespace Vitis\RestDB\JsonApi\Pagination;

use Vitis\RestDB\Capabilities\Capability;
use Vitis\RestDB\Values\CompiledRequest;
use Vitis\RestDB\Values\PageRequest;

/** Opaque page[cursor] (+ page[size]). */
final class CursorPaginator extends AbstractJsonApiPaginator
{
    protected function strategyCapabilities(): array
    {
        return [Capability::Cursor];
    }

    public function applyPage(CompiledRequest $request, ?PageRequest $page): CompiledRequest
    {
        $params = [];
        $size = $page !== null && $page->limit !== null ? $page->limit : $this->defaultSize;

        if ($size !== null) {
            $params['page[size]'] = (string) $size;
        }

        if ($page !== null && $page->cursor !== null) {
            $params['page[cursor]'] = $page->cursor;
        }

        return $params === [] ? $request : $request->withQuery($params);
    }
}
