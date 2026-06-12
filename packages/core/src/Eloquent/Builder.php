<?php

declare(strict_types=1);

namespace Vitis\RestDB\Eloquent;

use BadMethodCallException;
use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\Paginator as PagePaginator;
use Vitis\RestDB\Capabilities\Capability;
use Vitis\RestDB\Connection\RestConnection;
use Vitis\RestDB\Exceptions\UnsupportedQueryException;
use Vitis\RestDB\Query\Builder as QueryBuilder;

/**
 * Eloquent-level REST semantics: one-request paginate() over meta totals and
 * whereHas() decomposed into key queries. Model context for capability
 * exceptions is attached by InteractsWithRestApi::newEloquentBuilder().
 *
 * @template TModel of Model
 *
 * @extends EloquentBuilder<TModel>
 */
class Builder extends EloquentBuilder
{
    /** @param list<string> $includes */
    public function withIncludes(array $includes): static
    {
        if ($this->query instanceof QueryBuilder) {
            $this->query->includes = $includes;
        }

        return $this;
    }

    public function connectionAllows(Capability $capability): bool
    {
        $connection = $this->getQuery()->getConnection();

        return $connection instanceof RestConnection
            && $connection->capabilities()->has($capability);
    }

    /**
     * ONE request: the page query carries the page parameters and the total is
     * read from the same response's pagination metadata — never Laravel's
     * usual count-query + page-query pair.
     *
     * @param  int|null  $perPage
     * @param  array<int, string>|string  $columns
     * @param  string  $pageName
     * @param  int|null  $page
     * @param  Closure|int|null  $total  caller-supplied total (Filament precomputes one) — used only when the response carries none
     */
    public function paginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null, $total = null)
    {
        $connection = $this->getQuery()->getConnection();
        $query = $this->getQuery();

        $columns = is_array($columns) ? $columns : [$columns];

        if (! $connection instanceof RestConnection || ! $query instanceof QueryBuilder) {
            return parent::paginate($perPage, $columns, $pageName, $page, $total);
        }

        if (! $this->connectionAllows(Capability::TotalCount)) {
            throw new BadMethodCallException(
                'paginate() needs totals the API does not declare (page.total — configure pagination.meta_total). '
                .'Use simplePaginate() or cursorPaginate() instead.',
            );
        }

        $page = (int) ($page ?: PagePaginator::resolveCurrentPage($pageName));
        $perPage = (int) ($perPage ?: $this->getModel()->getPerPage());

        $query->limit($perPage);

        if ($this->connectionAllows(Capability::PageNumber)) {
            $query->pageNumber = $page;
        } elseif ($this->connectionAllows(Capability::Offset)) {
            $query->offset(($page - 1) * $perPage);
        } else {
            throw new BadMethodCallException(
                'paginate() needs page.number or page.offset; this connection paginates by cursor — use cursorPaginate().',
            );
        }

        $results = $this->get($columns);

        $provided = $total instanceof Closure ? $total() : $total;
        $total = $connection->lastPageInfo()->total ?? (is_int($provided) ? $provided : null);

        if ($total === null) {
            throw new BadMethodCallException(
                'The response carried no total — check the pagination.meta_total path against the API\'s payload.',
            );
        }

        return $this->paginator($results, $total, $perPage, $page, [
            'path' => PagePaginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    /**
     * Cross-request decomposition: run the relation query, pluck its keys,
     * constrain the outer query with whereIn. Two non-transactional round
     * trips — approximate by design, capped by guards.where_has_max_keys.
     *
     * @param  Relation<Model, Model, mixed>|string  $relation
     * @param  string  $operator
     * @param  int  $count
     * @param  string  $boolean
     * @param  (Closure(EloquentBuilder<Model>): mixed)|null  $callback
     */
    public function has($relation, $operator = '>=', $count = 1, $boolean = 'and', ?Closure $callback = null)
    {
        if (! $this->getQuery()->getConnection() instanceof RestConnection) {
            return parent::has($relation, $operator, $count, $boolean, $callback);
        }

        if (! is_string($relation) || str_contains($relation, '.') || $boolean !== 'and' || $operator !== '>=' || $count !== 1) {
            throw new BadMethodCallException(
                'Only whereHas(relation, callback) with default semantics is supported by the restdb driver '
                .'(no orWhereHas, no nested dots, no counted has()).',
            );
        }

        $model = $this->getModel();
        $relationInstance = Relation::noConstraints(static fn () => $model->{$relation}());

        [$outerKey, $innerKey] = match (true) {
            $relationInstance instanceof BelongsTo => [$relationInstance->getForeignKeyName(), $relationInstance->getOwnerKeyName()],
            $relationInstance instanceof HasOneOrMany => [$relationInstance->getLocalKeyName(), $relationInstance->getForeignKeyName()],
            default => throw new BadMethodCallException(
                'whereHas() on the restdb driver supports belongsTo, hasOne, and hasMany relations.',
            ),
        };

        $inner = $relationInstance->getRelated()->newQuery();

        if ($callback !== null) {
            $callback($inner);
        }

        $keys = $inner->getQuery()->pluck($innerKey)->filter(fn ($key) => $key !== null)->unique()->values();

        $max = $this->whereHasMaxKeys();

        if ($keys->count() > $max) {
            throw UnsupportedQueryException::whereHasKeyCap($relation, $max);
        }

        $this->whereIn($outerKey, $keys->all());

        return $this;
    }

    public function withCount($relations)
    {
        // Model::newQueryWithoutScopes() calls withCount([]) on every query.
        if ($relations === [] || $relations === null || $relations === '') {
            return $this;
        }

        throw new BadMethodCallException(
            'withCount() has no generic REST mapping and is not supported by the restdb driver.',
        );
    }

    private function whereHasMaxKeys(): int
    {
        $connection = $this->getQuery()->getConnection();

        if ($connection instanceof RestConnection) {
            $max = $connection->connectionConfig()->get('guards.where_has_max_keys', 500);

            if (is_int($max) && $max > 0) {
                return $max;
            }
        }

        return 500;
    }
}
