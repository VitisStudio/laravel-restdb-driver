<?php

declare(strict_types=1);

use Illuminate\Database\Query\Builder as BaseBuilder;

/**
 * The known cost of the builder-subclass strategy, paid in CI instead of
 * production: when Laravel ships new public builder surface, this test fails
 * until the method is audited — gated, throw-listed, or confirmed to funnel
 * into an already-gated path. Methods reaching SQL compilation are caught by
 * the stub Grammar; unknown where types are caught by the IntentFactory
 * whitelist — but an audit beats a runtime exception.
 *
 * Audited against laravel/framework 12.x and 13.x.
 */
it('fails when Laravel ships builder surface we have not audited', function () {
    $audited = [
        '__call', '__construct', 'addBinding', 'addNestedHavingQuery', 'addNestedWhereQuery', 'addSelect',
        'addWhereExistsQuery', 'afterQuery', 'aggregate', 'applyAfterQueryCallbacks', 'applyBeforeQueryCallbacks',
        'average', 'avg', 'beforeQuery', 'castBinding', 'chunk', 'chunkById', 'chunkByIdDesc', 'chunkMap',
        'cleanBindings', 'clone', 'cloneWithout', 'cloneWithoutBindings', 'count', 'crossJoin', 'crossJoinSub',
        'cursor', 'cursorPaginate', 'dd', 'ddRawSql', 'decrement', 'decrementEach', 'delete', 'distinct',
        'doesntExist', 'doesntExistOr', 'dump', 'dumpRawSql', 'dynamicWhere', 'each', 'eachById', 'exists',
        'existsOr', 'explain', 'fetchUsing', 'find', 'findOr', 'first', 'firstOrFail', 'forNestedWhere', 'forPage',
        'forPageAfterId', 'forPageBeforeId', 'forceIndex', 'from', 'fromRaw', 'fromSub', 'get', 'getBindings',
        'getColumns', 'getConnection', 'getCountForPagination', 'getGrammar', 'getLimit', 'getOffset',
        'getProcessor', 'getRawBindings', 'groupBy', 'groupByRaw', 'groupLimit', 'having', 'havingBetween',
        'havingNested', 'havingNotBetween', 'havingNotNull', 'havingNull', 'havingRaw', 'ignoreIndex', 'implode',
        'inOrderOf', 'inRandomOrder', 'increment', 'incrementEach', 'insert', 'insertGetId', 'insertOrIgnore',
        'insertOrIgnoreReturning', 'insertOrIgnoreUsing', 'insertUsing', 'join', 'joinLateral', 'joinSub',
        'joinWhere', 'latest', 'lazy',
        'lazyById', 'lazyByIdDesc', 'leftJoin', 'leftJoinLateral', 'leftJoinSub', 'leftJoinWhere', 'limit',
        'lock', 'lockForUpdate', 'macroCall', 'max', 'mergeBindings', 'mergeWheres', 'min', 'newQuery',
        'numericAggregate', 'offset', 'oldest', 'orHaving', 'orHavingBetween', 'orHavingNotBetween',
        'orHavingNotNull', 'orHavingNull', 'orHavingRaw', 'orWhere', 'orWhereAfterToday', 'orWhereAll',
        'orWhereAny', 'orWhereBeforeToday', 'orWhereBetween', 'orWhereBetweenColumns', 'orWhereColumn',
        'orWhereDate', 'orWhereDay', 'orWhereExists', 'orWhereFullText', 'orWhereFuture', 'orWhereIn',
        'orWhereIntegerInRaw', 'orWhereIntegerNotInRaw', 'orWhereJsonContains', 'orWhereJsonContainsKey',
        'orWhereJsonDoesntContain', 'orWhereJsonDoesntContainKey', 'orWhereJsonDoesntOverlap',
        'orWhereJsonLength', 'orWhereJsonOverlaps', 'orWhereLike', 'orWhereMonth', 'orWhereNone', 'orWhereNot',
        'orWhereNotBetween', 'orWhereNotBetweenColumns', 'orWhereNotExists', 'orWhereNotIn', 'orWhereNotLike',
        'orWhereNotNull', 'orWhereNowOrFuture', 'orWhereNowOrPast', 'orWhereNull', 'orWhereNullSafeEquals',
        'orWherePast', 'orWhereRaw', 'orWhereRowValues', 'orWhereTime', 'orWhereToday', 'orWhereTodayOrAfter',
        'orWhereTodayOrBefore', 'orWhereValueBetween', 'orWhereValueNotBetween',
        'orWhereVectorDistanceLessThan', 'orWhereYear', 'orderBy', 'orderByDesc', 'orderByRaw',
        'orderByVectorDistance', 'orderedChunkById', 'paginate', 'pipe', 'pluck', 'prepareValueAndOperator',
        'raw', 'rawValue', 'reorder', 'reorderDesc', 'rightJoin', 'rightJoinSub', 'rightJoinWhere', 'select',
        'selectExpression', 'selectRaw', 'selectSub', 'selectVectorDistance', 'setBindings', 'sharedLock',
        'simplePaginate', 'skip', 'sole', 'soleValue', 'straightJoin', 'straightJoinSub', 'straightJoinWhere',
        'sum', 'take', 'tap', 'timeout', 'toRawSql', 'toSql',
        'truncate', 'union', 'unionAll', 'unless', 'update', 'updateFrom', 'updateOrInsert', 'upsert',
        'useIndex', 'useWritePdo', 'value', 'when', 'where', 'whereAfterToday', 'whereAll', 'whereAny',
        'whereBeforeToday', 'whereBetween', 'whereBetweenColumns', 'whereColumn', 'whereDate', 'whereDay',
        'whereExists', 'whereFullText', 'whereFuture', 'whereIn', 'whereIntegerInRaw', 'whereIntegerNotInRaw',
        'whereJsonContains', 'whereJsonContainsKey', 'whereJsonDoesntContain', 'whereJsonDoesntContainKey',
        'whereJsonDoesntOverlap', 'whereJsonLength', 'whereJsonOverlaps', 'whereLike', 'whereMonth',
        'whereNested', 'whereNone', 'whereNot', 'whereNotBetween', 'whereNotBetweenColumns', 'whereNotExists',
        'whereNotIn', 'whereNotLike', 'whereNotNull', 'whereNowOrFuture', 'whereNowOrPast', 'whereNull',
        'whereNullSafeEquals', 'wherePast', 'whereRaw', 'whereRowValues', 'whereTime', 'whereToday',
        'whereTodayOrAfter', 'whereTodayOrBefore', 'whereValueBetween', 'whereValueNotBetween',
        'whereVectorDistanceLessThan', 'whereVectorSimilarTo', 'whereYear',
    ];

    $reflection = new ReflectionClass(BaseBuilder::class);
    $actual = [];

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() === BaseBuilder::class && ! $method->isStatic()) {
            $actual[] = $method->getName();
        }
    }

    $unaudited = array_values(array_diff($actual, $audited));

    expect($unaudited)->toBe([], 'Laravel added public builder methods that need a gating audit: '
        .implode(', ', $unaudited)
        .'. Gate them, throw-list them in Vitis\RestDB\Query\Builder, or add them here after confirming they funnel into a gated path.');
});
