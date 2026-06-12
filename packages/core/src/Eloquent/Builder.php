<?php

declare(strict_types=1);

namespace Vitis\RestDB\Eloquent;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Vitis\RestDB\Capabilities\Capability;
use Vitis\RestDB\Connection\RestConnection;
use Vitis\RestDB\Query\Builder as QueryBuilder;

/**
 * Eloquent-level REST semantics live here (whereHas decomposition and
 * pagination guards arrive in v0.5). Model context for capability exceptions
 * is attached by InteractsWithRestApi::newEloquentBuilder().
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
}
