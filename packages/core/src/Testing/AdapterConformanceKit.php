<?php

declare(strict_types=1);

namespace Vitis\RestDB\Testing;

use Throwable;
use Vitis\RestDB\Capabilities\Operator;
use Vitis\RestDB\Contracts\Adapter;
use Vitis\RestDB\Values\ApiResponse;
use Vitis\RestDB\Values\CompiledRequest;
use Vitis\RestDB\Values\Condition;
use Vitis\RestDB\Values\ConnectionConfig;
use Vitis\RestDB\Values\DeleteIntent;
use Vitis\RestDB\Values\EmptyResult;
use Vitis\RestDB\Values\FilterGroup;
use Vitis\RestDB\Values\InsertIntent;
use Vitis\RestDB\Values\PageInfo;
use Vitis\RestDB\Values\SelectIntent;
use Vitis\RestDB\Values\UpdateIntent;

/**
 * What it means to be a valid adapter, as executable checks. Third-party
 * adapters run this in their own suites:
 *
 *     $violations = AdapterConformanceKit::check($adapter, $config);
 *     expect($violations)->toBe([]);
 *
 * Checks are pure — no HTTP, no container. Pass fixture bodies matching your
 * API's envelope when the defaults ({"data": ...}) don't apply.
 */
final class AdapterConformanceKit
{
    /**
     * @param  array{collection?: string, resource?: string, error?: string}  $fixtures  raw response bodies
     * @return list<string> human-readable violations; empty = conformant
     */
    public static function check(
        Adapter $adapter,
        ConnectionConfig $config,
        string $resource = 'articles',
        array $fixtures = [],
    ): array {
        $violations = [];

        $collectionBody = $fixtures['collection'] ?? '{"data": []}';
        $resourceBody = $fixtures['resource'] ?? '{"data": {"type": "articles", "id": "1", "attributes": {}}}';

        $compiler = $adapter->compiler($config);
        $parser = $adapter->parser($config);
        $paginator = $adapter->paginator($config);

        // 1. A provably empty query must compile to EmptyResult — zero HTTP, ever.
        $empty = new SelectIntent($resource, filters: new FilterGroup([
            new Condition('id', Operator::In, []),
        ]));

        try {
            if (! $compiler->compileSelect($empty) instanceof EmptyResult) {
                $violations[] = 'compileSelect() must return EmptyResult for a provably empty query (whereIn []).';
            }
        } catch (Throwable $e) {
            $violations[] = "compileSelect() threw on a provably empty query: {$e->getMessage()}";
        }

        // 2. A plain select compiles to a usable request.
        $select = new SelectIntent($resource);
        $compiled = $compiler->compileSelect($select);

        if (! $compiled instanceof CompiledRequest || $compiled->method === '' || $compiled->path === '') {
            $violations[] = 'compileSelect() must return a CompiledRequest with a method and path.';

            return $violations; // everything below needs the request
        }

        // 3. Writes compile against a single-id target.
        $target = new FilterGroup([new Condition('id', Operator::Eq, '1')]);

        foreach ([
            'compileInsert' => fn () => $compiler->compileInsert(new InsertIntent($resource, [['title' => 'x']])),
            'compileUpdate' => fn () => $compiler->compileUpdate(new UpdateIntent($resource, ['title' => 'x'], $target)),
            'compileDelete' => fn () => $compiler->compileDelete(new DeleteIntent($resource, $target)),
        ] as $method => $compile) {
            try {
                $compile();
            } catch (Throwable $e) {
                $violations[] = "{$method}() threw for a single-resource intent: {$e->getMessage()}";
            }
        }

        // 4. A non-error body must not parse as errors.
        if ($parser->errors(new ApiResponse(200, [], $collectionBody)) !== null) {
            $violations[] = 'errors() must return null when the body is not an error document.';
        }

        // 5. An empty collection parses to zero rows, never to garbage rows.
        $page = $parser->rows(new ApiResponse(200, [], $collectionBody), $select);

        if ($page->rows !== []) {
            $violations[] = 'rows() must return zero rows for an empty collection document.';
        }

        // 6. A successful write reports at least one affected row.
        $write = $parser->writeResult(
            new ApiResponse(200, [], $resourceBody),
            new UpdateIntent($resource, ['title' => 'x'], $target),
        );

        if ($write->affected < 1) {
            $violations[] = 'writeResult() must report affected >= 1 for a 2xx response.';
        }

        // 7. Pagination: page application is non-destructive, and a has-more=false
        //    page info must terminate the drain.
        if ($paginator->applyPage($compiled, null)->method !== $compiled->method) {
            $violations[] = 'applyPage() must preserve the request method.';
        }

        $info = $paginator->pageInfo(new ApiResponse(200, [], $collectionBody), $page);

        if ($paginator->nextRequest($compiled, new PageInfo(hasMore: false)) !== null) {
            $violations[] = 'nextRequest() must return null when hasMore is false — drains must terminate.';
        }

        if ($info->hasMore && $paginator->nextRequest($compiled, $info) === null) {
            $violations[] = 'pageInfo() signalled hasMore but nextRequest() cannot continue.';
        }

        return $violations;
    }
}
