<?php

declare(strict_types=1);

namespace Vitis\RestDB\Contracts;

use Vitis\RestDB\Capabilities\Capability;
use Vitis\RestDB\Values\ApiResponse;
use Vitis\RestDB\Values\CompiledRequest;
use Vitis\RestDB\Values\PageInfo;
use Vitis\RestDB\Values\PageRequest;
use Vitis\RestDB\Values\ResultPage;

interface Paginator
{
    /**
     * Capabilities this pagination strategy contributes to the connection set
     * (e.g. [Capability::Limit, Capability::PageNumber, Capability::TotalCount]).
     *
     * @return list<Capability>
     */
    public function provides(): array;

    public function applyPage(CompiledRequest $request, ?PageRequest $page): CompiledRequest;

    /** hasMore / total / cursor extracted from one response. */
    public function pageInfo(ApiResponse $response, ResultPage $page): PageInfo;

    /** Null = drained. */
    public function nextRequest(CompiledRequest $current, PageInfo $info): ?CompiledRequest;
}
