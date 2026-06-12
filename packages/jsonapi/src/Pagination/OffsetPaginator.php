<?php

declare(strict_types=1);

namespace Vitis\RestDB\JsonApi\Pagination;

use Vitis\RestDB\Capabilities\Capability;
use Vitis\RestDB\Values\CompiledRequest;
use Vitis\RestDB\Values\PageRequest;

/** page[offset] / page[limit]. */
final class OffsetPaginator extends AbstractJsonApiPaginator
{
    protected function strategyCapabilities(): array
    {
        return [Capability::Offset];
    }

    public function applyPage(CompiledRequest $request, ?PageRequest $page): CompiledRequest
    {
        $limit = $page !== null && $page->limit !== null ? $page->limit : $this->defaultSize;
        $params = [];

        if ($limit !== null) {
            $params['page[limit]'] = (string) $limit;
        }

        if ($page !== null && $page->offset !== null) {
            $params['page[offset]'] = (string) $page->offset;
        }

        return $params === [] ? $request : $request->withQuery($params);
    }
}
