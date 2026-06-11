<?php

declare(strict_types=1);

namespace Vitis\RestDB\Eloquent;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent-level REST semantics live here (whereHas decomposition and
 * pagination guards arrive in v0.5). Model context for capability exceptions
 * is attached by InteractsWithRestApi::newEloquentBuilder().
 *
 * @template TModel of Model
 *
 * @extends EloquentBuilder<TModel>
 */
class Builder extends EloquentBuilder {}
