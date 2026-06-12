<?php

declare(strict_types=1);

use Vitis\RestDB\Capabilities\Operator;
use Vitis\RestDB\Endpoints\ConventionEndpointResolver;
use Vitis\RestDB\Exceptions\UnsupportedQueryException;
use Vitis\RestDB\Rest\RestRequestCompiler;
use Vitis\RestDB\Values\CompiledRequest;
use Vitis\RestDB\Values\Condition;
use Vitis\RestDB\Values\ConnectionConfig;
use Vitis\RestDB\Values\DeleteIntent;
use Vitis\RestDB\Values\EmptyResult;
use Vitis\RestDB\Values\FilterGroup;
use Vitis\RestDB\Values\InsertIntent;
use Vitis\RestDB\Values\Order;
use Vitis\RestDB\Values\SelectIntent;
use Vitis\RestDB\Values\UpdateIntent;

function restCompiler(array $config = []): RestRequestCompiler
{
    return new RestRequestCompiler(
        new ConventionEndpointResolver,
        new ConnectionConfig('test', $config),
    );
}

function selectWhere(Condition ...$conditions): SelectIntent
{
    return new SelectIntent('articles', filters: new FilterGroup(array_values($conditions)));
}

it('compiles suffix-style filters by default', function () {
    $request = restCompiler()->compileSelect(selectWhere(
        new Condition('status', Operator::Eq, 'open'),
        new Condition('rating', Operator::Gte, 4),
        new Condition('views', Operator::Ne, 0),
    ));

    expect($request)->toBeInstanceOf(CompiledRequest::class)
        ->and($request->query)->toBe(['status' => 'open', 'rating_gte' => '4', 'views_ne' => '0']);
});

it('compiles bracket-style filters', function () {
    $request = restCompiler(['filters' => ['style' => 'bracket']])->compileSelect(selectWhere(
        new Condition('status', Operator::Eq, 'open'),
        new Condition('rating', Operator::Gte, 4),
        new Condition('deleted_at', Operator::Null, null),
    ));

    expect($request->query)->toBe(['status' => 'open', 'rating[gte]' => '4', 'deleted_at[null]' => '1']);
});

it('wraps filters in a configured wrapper param', function () {
    $plain = restCompiler(['filters' => ['style' => 'plain', 'wrapper' => 'filter']]);
    $bracket = restCompiler(['filters' => ['style' => 'bracket', 'wrapper' => 'filter']]);

    expect($plain->compileSelect(selectWhere(new Condition('status', Operator::Eq, 'open')))->query)
        ->toBe(['filter[status]' => 'open'])
        ->and($bracket->compileSelect(selectWhere(new Condition('rating', Operator::Gte, 4)))->query)
        ->toBe(['filter[rating][gte]' => '4']);
});

it('rejects non-equality operators in plain style', function () {
    restCompiler(['filters' => ['style' => 'plain']])
        ->compileSelect(selectWhere(new Condition('rating', Operator::Gte, 4)));
})->throws(UnsupportedQueryException::class, 'gte');

it('strips SQL wildcards from like values in contains mode, keeps them raw otherwise', function () {
    $contains = restCompiler()->compileSelect(selectWhere(new Condition('title', Operator::Like, '%qui%')));
    $raw = restCompiler(['filters' => ['like' => 'raw']])
        ->compileSelect(selectWhere(new Condition('title', Operator::Like, '%qui%')));

    expect($contains->query)->toBe(['title_like' => 'qui'])
        ->and($raw->query)->toBe(['title_like' => '%qui%']);
});

it('compiles whereIn to a comma list by default and collapses single-value whereIn in single mode', function () {
    $comma = restCompiler()->compileSelect(selectWhere(new Condition('userId', Operator::In, [1, 2, 3])));
    $single = restCompiler(['filters' => ['in' => 'single']])
        ->compileSelect(selectWhere(new Condition('userId', Operator::In, [7]), new Condition('x', Operator::Eq, 'y')));

    expect($comma->query)->toBe(['userId_in' => '1,2,3'])
        ->and($single->query)->toBe(['userId' => '7', 'x' => 'y']);
});

it('throws on multi-value whereIn in single mode with a config hint', function () {
    restCompiler(['filters' => ['in' => 'single']])
        ->compileSelect(selectWhere(new Condition('userId', Operator::In, [1, 2, 3]), new Condition('x', Operator::Eq, 'y')));
})->throws(UnsupportedQueryException::class, 'filters.in');

it('decomposes between into gte and lte in suffix style', function () {
    $request = restCompiler()->compileSelect(selectWhere(new Condition('rating', Operator::Between, [2, 5])));

    expect($request->query)->toBe(['rating_gte' => '2', 'rating_lte' => '5']);
});

it('throws when two conditions compile to the same query parameter', function () {
    restCompiler()->compileSelect(selectWhere(
        new Condition('status', Operator::Eq, 'open'),
        new Condition('status', Operator::Eq, 'closed'),
    ));
})->throws(UnsupportedQueryException::class, 'conflicting');

it('throws on nested groups and OR booleans — flat styles never drop clauses', function () {
    $nested = new SelectIntent('articles', filters: new FilterGroup([
        new Condition('a', Operator::Eq, 1),
        new FilterGroup([new Condition('b', Operator::Eq, 2)]),
    ]));

    expect(fn () => restCompiler()->compileSelect($nested))
        ->toThrow(UnsupportedQueryException::class, 'nested filter group')
        ->and(fn () => restCompiler()->compileSelect(selectWhere(
            new Condition('a', Operator::Eq, 1),
            new Condition('b', Operator::Eq, 2, 'or'),
        )))->toThrow(UnsupportedQueryException::class, 'OR condition');
});

it('returns EmptyResult for provably empty queries', function () {
    expect(restCompiler()->compileSelect(selectWhere(new Condition('id', Operator::In, []))))
        ->toBeInstanceOf(EmptyResult::class);
});

it('compiles a lone primary-key equality to the resource URL', function () {
    $byEq = restCompiler()->compileSelect(selectWhere(new Condition('id', Operator::Eq, 7)));
    $byIn = restCompiler()->compileSelect(selectWhere(new Condition('id', Operator::In, [7])));
    $custom = restCompiler(['id_key' => 'uuid'])
        ->compileSelect(selectWhere(new Condition('uuid', Operator::Eq, 'abc-123')));

    expect($byEq->path)->toBe('/articles/7')
        ->and($byIn->path)->toBe('/articles/7')
        ->and($custom->path)->toBe('/articles/abc-123');
});

it('compiles sort as a prefix list by default and as split params when configured', function () {
    $orders = [new Order('title', 'desc'), new Order('id')];

    $prefix = restCompiler()->compileSelect(new SelectIntent('articles', orders: $orders));
    $split = restCompiler(['sort' => ['param' => '_sort', 'direction_param' => '_order']])
        ->compileSelect(new SelectIntent('articles', orders: $orders));

    expect($prefix->query)->toBe(['sort' => '-title,id'])
        ->and($split->query)->toBe(['_sort' => 'title,id', '_order' => 'desc,asc']);
});

it('emits columns and includes only when params are configured, throws otherwise', function () {
    $configured = restCompiler(['fields' => ['param' => 'fields'], 'include' => ['param' => '_embed']])
        ->compileSelect(new SelectIntent('articles', columns: ['id', 'title'], includes: ['comments']));

    expect($configured->query)->toBe(['fields' => 'id,title', '_embed' => 'comments'])
        ->and(fn () => restCompiler()->compileSelect(new SelectIntent('articles', columns: ['id'])))
        ->toThrow(UnsupportedQueryException::class, 'fields.param')
        ->and(fn () => restCompiler()->compileSelect(new SelectIntent('articles', includes: ['comments'])))
        ->toThrow(UnsupportedQueryException::class, 'include.param');
});

it('compiles writes against the resource URL with the configured method and body wrap', function () {
    $target = new FilterGroup([new Condition('id', Operator::Eq, 7)]);

    $insert = restCompiler()->compileInsert(new InsertIntent('articles', [['title' => 'x']]));
    $patch = restCompiler()->compileUpdate(new UpdateIntent('articles', ['title' => 'y'], $target));
    $put = restCompiler(['writes' => ['update_method' => 'put', 'wrap' => 'article']])
        ->compileUpdate(new UpdateIntent('articles', ['title' => 'y'], $target));
    $delete = restCompiler()->compileDelete(new DeleteIntent('articles', $target));

    expect($insert)->toEqual(new CompiledRequest('POST', '/articles', [], ['title' => 'x']))
        ->and($patch)->toEqual(new CompiledRequest('PATCH', '/articles/7', [], ['title' => 'y']))
        ->and($put)->toEqual(new CompiledRequest('PUT', '/articles/7', [], ['article' => ['title' => 'y']]))
        ->and($delete)->toEqual(new CompiledRequest('DELETE', '/articles/7'));
});

it('refuses mass writes without a single-resource identity', function () {
    $broad = new FilterGroup([new Condition('status', Operator::Eq, 'open')]);

    expect(fn () => restCompiler()->compileUpdate(new UpdateIntent('articles', ['title' => 'y'], $broad)))
        ->toThrow(UnsupportedQueryException::class, 'update()')
        ->and(fn () => restCompiler()->compileDelete(new DeleteIntent('articles', $broad)))
        ->toThrow(UnsupportedQueryException::class, 'delete()');
});
