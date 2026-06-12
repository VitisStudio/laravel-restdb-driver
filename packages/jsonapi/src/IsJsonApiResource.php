<?php

declare(strict_types=1);

namespace Vitis\RestDB\JsonApi;

use Illuminate\Database\Eloquent\Model;
use Vitis\RestDB\Eloquent\InteractsWithRestApi;

/**
 * Composes the core trait with JSON:API defaults: string keys,
 * non-incrementing ids, the compound-document-aware Eloquent builder, and the
 * resource type derived from the table name by convention.
 *
 * @phpstan-require-extends Model
 */
trait IsJsonApiResource
{
    use InteractsWithRestApi;

    public function initializeIsJsonApiResource(): void
    {
        // JSON:API ids are strings assigned by the server.
        $this->incrementing = false;
        $this->keyType = 'string';
    }

    public function newEloquentBuilder($query)
    {
        $this->configureRestQueryBuilder($query);

        return new Eloquent\Builder($query);
    }
}
