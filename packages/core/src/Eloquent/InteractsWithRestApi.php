<?php

declare(strict_types=1);

namespace Vitis\RestDB\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * The core model trait. Overrides exactly what a non-SQL backend needs — the
 * laravel-mongodb lessons: never qualify columns (no `articles.id` on the
 * wire), and hand out the RestDB Eloquent builder. newBaseQueryBuilder() needs
 * no override — RestConnection::query() already propagates the gated builder
 * to every model on the connection.
 *
 * @phpstan-require-extends Model
 */
trait InteractsWithRestApi
{
    public function qualifyColumn($column)
    {
        return $column;
    }

    public function qualifyColumns($columns)
    {
        return $columns;
    }

    public function getQualifiedKeyName()
    {
        return $this->getKeyName();
    }

    public function newEloquentBuilder($query)
    {
        // The base query builder carries the model class so capability
        // exceptions can name the model at the developer's line.
        if ($query instanceof \Vitis\RestDB\Query\Builder) {
            $query->setModelContext(static::class);
        }

        return new Builder($query);
    }
}
