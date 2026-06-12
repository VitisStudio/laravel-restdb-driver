<?php

declare(strict_types=1);

namespace Vitis\RestDB\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Vitis\RestDB\Connection\RestConnection;

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
        $this->configureRestQueryBuilder($query);

        return new Builder($query);
    }

    /**
     * The base query builder carries the model class so capability exceptions
     * can name the model at the developer's line, and the key name so identity
     * wheres bypass filter capabilities. Adapter traits reuse this hook.
     */
    protected function configureRestQueryBuilder(mixed $query): void
    {
        if ($query instanceof \Vitis\RestDB\Query\Builder) {
            $query->setModelContext(static::class);
            $query->setKeyName($this->getKeyName());
        }
    }

    protected function performInsert(\Illuminate\Database\Eloquent\Builder $query)
    {
        $saved = parent::performInsert($query);

        if ($saved) {
            $this->fillFromWriteResult();
        }

        return $saved;
    }

    protected function performUpdate(\Illuminate\Database\Eloquent\Builder $query)
    {
        $saved = parent::performUpdate($query);

        if ($saved) {
            $this->fillFromWriteResult();
        }

        return $saved;
    }

    /**
     * The server may mutate what was sent (defaults, computed fields,
     * server-assigned ids) — its resource state wins after every write.
     */
    protected function fillFromWriteResult(): void
    {
        $connection = $this->getConnection();

        if (! $connection instanceof RestConnection) {
            return;
        }

        $attributes = $connection->lastWriteResult()?->attributes ?? [];

        if ($attributes !== []) {
            $this->forceFill($attributes);
        }
    }
}
