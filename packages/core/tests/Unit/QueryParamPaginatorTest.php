<?php

declare(strict_types=1);

use Vitis\RestDB\Capabilities\Capability;
use Vitis\RestDB\Rest\QueryParamPaginator;
use Vitis\RestDB\Values\ApiResponse;
use Vitis\RestDB\Values\CompiledRequest;
use Vitis\RestDB\Values\ConnectionConfig;
use Vitis\RestDB\Values\PageInfo;
use Vitis\RestDB\Values\PageRequest;
use Vitis\RestDB\Values\ResultPage;

function paramPaginator(array $pagination): QueryParamPaginator
{
    return new QueryParamPaginator(new ConnectionConfig('test', ['pagination' => $pagination]));
}

function jsonServerPaginator(): QueryParamPaginator
{
    return paramPaginator([
        'params' => ['page' => '_page', 'limit' => '_limit', 'offset' => '_start'],
        'total_header' => 'X-Total-Count',
    ]);
}

it('contributes exactly the capabilities its config names', function () {
    expect(jsonServerPaginator()->provides())
        ->toBe([Capability::Limit, Capability::PageNumber, Capability::Offset, Capability::TotalCount])
        ->and(paramPaginator(['params' => ['limit' => 'per_page']])->provides())
        ->toBe([Capability::Limit])
        ->and(paramPaginator(['params' => ['offset' => 'skip'], 'total_path' => 'meta.total'])->provides())
        ->toBe([Capability::Offset, Capability::TotalCount]);
});

it('applies page, limit, and offset under their configured names', function () {
    $request = new CompiledRequest('GET', '/posts');

    expect(jsonServerPaginator()->applyPage($request, new PageRequest(limit: 5, page: 3))->query)
        ->toBe(['_limit' => '5', '_page' => '3'])
        ->and(jsonServerPaginator()->applyPage($request, new PageRequest(limit: 5, offset: 10))->query)
        ->toBe(['_limit' => '5', '_start' => '10'])
        ->and(jsonServerPaginator()->applyPage($request, null)->query)->toBe([]);
});

it('reads totals from the configured header or body path', function () {
    $rows = new ResultPage([['id' => 1]]);

    $fromHeader = jsonServerPaginator()
        ->pageInfo(new ApiResponse(200, ['X-Total-Count' => ['100']], '[]'), $rows);
    $fromBody = paramPaginator(['params' => ['page' => 'page', 'limit' => 'limit'], 'total_path' => 'meta.total'])
        ->pageInfo(new ApiResponse(200, [], '{"meta":{"total":42}}'), $rows);

    expect($fromHeader->total)->toBe(100)
        ->and($fromBody->total)->toBe(42);
});

it('advances page-number requests until the total is drained', function () {
    $paginator = jsonServerPaginator();
    $page3 = new CompiledRequest('GET', '/posts', ['_page' => '3', '_limit' => '5']);

    $next = $paginator->nextRequest($page3, new PageInfo(hasMore: true, total: 100));

    expect($next?->query['_page'])->toBe('4')
        ->and($paginator->nextRequest($page3, new PageInfo(hasMore: true, total: 15)))->toBeNull()
        ->and($paginator->nextRequest($page3, new PageInfo(hasMore: false)))->toBeNull();
});

it('advances offset requests by the limit', function () {
    $paginator = jsonServerPaginator();
    $request = new CompiledRequest('GET', '/posts', ['_start' => '10', '_limit' => '5']);

    expect($paginator->nextRequest($request, new PageInfo(hasMore: true, total: 100))?->query['_start'])->toBe('15')
        ->and($paginator->nextRequest($request, new PageInfo(hasMore: true, total: 15)))->toBeNull();
});

it('never advances an unpaginated request', function () {
    expect(jsonServerPaginator()->nextRequest(
        new CompiledRequest('GET', '/posts'),
        new PageInfo(hasMore: true, total: 100),
    ))->toBeNull();
});

it('probes forward on full pages when no total source exists, and stops on an empty page', function () {
    $paginator = paramPaginator(['params' => ['page' => 'page', 'limit' => 'limit']]);
    $request = new CompiledRequest('GET', '/posts', ['page' => '1', 'limit' => '2']);

    $fullPage = $paginator->pageInfo(new ApiResponse(200, [], '[]'), new ResultPage([['id' => 1], ['id' => 2]]));
    $emptyPage = $paginator->pageInfo(new ApiResponse(200, [], '[]'), new ResultPage([]));

    expect($fullPage->hasMore)->toBeTrue()
        ->and($paginator->nextRequest($request, $fullPage)?->query['page'])->toBe('2')
        ->and($emptyPage->hasMore)->toBeFalse()
        ->and($paginator->nextRequest($request, $emptyPage))->toBeNull();
});
