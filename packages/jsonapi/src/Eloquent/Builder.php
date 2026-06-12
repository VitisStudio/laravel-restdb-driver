<?php

declare(strict_types=1);

namespace Vitis\RestDB\JsonApi\Eloquent;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionFunction;
use SplObjectStorage;
use Vitis\RestDB\Capabilities\Capability;
use Vitis\RestDB\Connection\RestConnection;
use Vitis\RestDB\Eloquent\Builder as CoreBuilder;
use Vitis\RestDB\JsonApi\JsonApiResponseParser;
use Vitis\RestDB\Values\ConnectionConfig;

/**
 * Eager loading over compound documents: unconstrained with() relations ride
 * the include= parameter and hydrate from the response's included resources
 * via the identity map — zero extra HTTP. Constrained relations (closures)
 * cannot be expressed as include= and fall back to standard eager-load
 * queries; nothing is silently dropped either way.
 *
 * @template TModel of Model
 *
 * @extends CoreBuilder<TModel>
 */
class Builder extends CoreBuilder
{
    /** @var SplObjectStorage<Model, array<string, mixed>>|null per-model relationship linkage */
    private ?SplObjectStorage $linkage = null;

    public function getModels($columns = ['*'])
    {
        $this->withIncludes($this->connectionAllows(Capability::Include) ? $this->includablePaths() : []);

        $columns = is_array($columns) ? $columns : [$columns];

        // The base implementation hydrates through $this->model->hydrate(),
        // which forwards to a *fresh* builder — linkage must land on this one.
        return array_values($this->hydrate(
            $this->query->get($columns)->all(),
        )->all());
    }

    /**
     * Mirrors the base hydrate() while peeling relationship linkage off each
     * row and keying it to the created model.
     *
     * @param  array<int, mixed>  $items
     * @return Collection<int, TModel>
     */
    public function hydrate(array $items)
    {
        /** @var SplObjectStorage<Model, array<string, mixed>> $linkage */
        $linkage = new SplObjectStorage;
        $this->linkage = $linkage;

        $instance = $this->newModelInstance();
        $items = array_values($items);
        $models = [];

        foreach ($items as $item) {
            $row = ConnectionConfig::stringKeyed((array) $item);
            $rowLinkage = ConnectionConfig::stringKeyed($row[JsonApiResponseParser::RELATIONSHIPS_KEY] ?? null);
            unset($row[JsonApiResponseParser::RELATIONSHIPS_KEY]);

            $model = $instance->newFromBuilder($row);

            if (count($items) > 1) {
                $model->preventsLazyLoading = Model::preventsLazyLoading();
            }

            $linkage[$model] = $rowLinkage;
            $models[] = $model;
        }

        return $instance->newCollection($models);
    }

    public function eagerLoadRelations(array $models)
    {
        $parser = $this->jsonApiParser();

        if ($parser === null || $models === []) {
            return parent::eagerLoadRelations($models);
        }

        [$tree, $fallback] = $this->eagerLoadTree();

        $unhydrated = $this->hydrateTree($models, $tree, $parser);

        $remaining = [...$fallback, ...$unhydrated];

        if ($remaining !== []) {
            $original = $this->eagerLoad;
            $this->eagerLoad = array_intersect_key($original, array_flip($remaining));
            $models = parent::eagerLoadRelations($models);
            $this->eagerLoad = $original;
        }

        return $models;
    }

    /*
    |--------------------------------------------------------------------------
    | Hydration from the identity map
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<int, Model>  $models
     * @param  array<string, mixed>  $tree  name => children subtree
     * @return list<string> dotted paths that could not hydrate and need queries
     */
    private function hydrateTree(array $models, array $tree, JsonApiResponseParser $parser, string $prefix = ''): array
    {
        $failed = [];

        foreach ($tree as $name => $children) {
            $children = ConnectionConfig::stringKeyed($children);
            $path = $prefix === '' ? $name : "{$prefix}.{$name}";
            $related = $this->hydrateRelation($models, $name, $parser);

            if ($related === null) {
                $failed[] = $path;

                foreach ($this->collectDescendants($children, $path) as $descendant) {
                    $failed[] = $descendant;
                }

                continue;
            }

            if ($children !== []) {
                $failed = [...$failed, ...$this->hydrateTree($related, $children, $parser, $path)];
            }
        }

        return $failed;
    }

    /**
     * Hydrate one relation on every model from included resources. Returns the
     * related models (for nested hydration), or null when any model lacks
     * linkage or any linked resource is absent — then the whole relation falls
     * back to a standard eager-load query. All or nothing; never partial.
     *
     * @param  array<int, Model>  $models
     * @return list<Model>|null
     */
    private function hydrateRelation(array $models, string $name, JsonApiResponseParser $parser): ?array
    {
        $first = $models[array_key_first($models)] ?? null;

        if ($first === null) {
            return [];
        }

        $relation = $this->relationFor($first, $name);

        if ($relation === null) {
            return null;
        }

        $prototype = $relation->getRelated();
        $resolved = [];
        $allRelated = [];

        foreach ($models as $model) {
            if ($this->linkage === null || ! $this->linkage->offsetExists($model)) {
                return null; // hydrated outside this builder — must query instead
            }

            $linkage = $this->linkage[$model];

            if (! array_key_exists($name, $linkage)) {
                return null; // server sent no linkage — must query instead
            }

            $data = $linkage[$name];

            if ($data === null) {
                $resolved[] = [$model, null];
            } elseif (is_array($data) && isset($data['type'])) {
                $instance = $this->modelFromIdentityMap($prototype, $data, $parser);

                if ($instance === null) {
                    return null;
                }

                $resolved[] = [$model, $instance];
                $allRelated[] = $instance;
            } elseif (is_array($data)) {
                $instances = [];

                foreach ($data as $identifier) {
                    $instance = is_array($identifier)
                        ? $this->modelFromIdentityMap($prototype, $identifier, $parser)
                        : null;

                    if ($instance === null) {
                        return null;
                    }

                    $instances[] = $instance;
                }

                $resolved[] = [$model, $prototype->newCollection($instances)];
                $allRelated = [...$allRelated, ...$instances];
            } else {
                return null;
            }
        }

        foreach ($resolved as [$model, $value]) {
            $model->setRelation($name, $value);
        }

        return $allRelated;
    }

    /** @param array<mixed> $identifier resource identifier {type, id} */
    private function modelFromIdentityMap(Model $prototype, array $identifier, JsonApiResponseParser $parser): ?Model
    {
        $type = $identifier['type'] ?? null;
        $id = $identifier['id'] ?? null;

        if (! is_string($type) || (! is_string($id) && ! is_int($id))) {
            return null;
        }

        $resource = $parser->lookup($type, $id);

        if ($resource === null || ! isset($resource['attributes'])) {
            return null; // linkage only — the resource was not included
        }

        $row = $parser->flattenResource($resource);
        $linkage = ConnectionConfig::stringKeyed($row[JsonApiResponseParser::RELATIONSHIPS_KEY] ?? null);
        unset($row[JsonApiResponseParser::RELATIONSHIPS_KEY]);

        $instance = $prototype->newFromBuilder($row, $prototype->getConnectionName());
        $this->linkage ??= new SplObjectStorage;
        $this->linkage[$instance] = $linkage;

        return $instance;
    }

    /** @return Relation<Model, Model, mixed>|null */
    private function relationFor(Model $model, string $name): ?Relation
    {
        if (! method_exists($model, $name)) {
            return null;
        }

        // Without noConstraints, relation constructors push gated wheres
        // (e.g. HasMany's whereNotNull on the foreign key) onto a query that
        // will never run.
        $relation = Relation::noConstraints(static fn () => $model->{$name}());

        return $relation instanceof Relation ? $relation : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Eager-load bookkeeping
    |--------------------------------------------------------------------------
    */

    /**
     * Dotted paths safe to express as include= (no user constraints anywhere).
     *
     * @return list<string>
     */
    private function includablePaths(): array
    {
        [$tree, $fallback] = $this->eagerLoadTree();
        unset($fallback);

        return $this->leafPaths($tree, '');
    }

    /**
     * @param  array<string, mixed>  $tree
     * @return list<string>
     */
    private function leafPaths(array $tree, string $prefix): array
    {
        $paths = [];

        foreach ($tree as $name => $children) {
            $children = ConnectionConfig::stringKeyed($children);
            $path = $prefix === '' ? $name : "{$prefix}.{$name}";

            if ($children === []) {
                $paths[] = $path;
            } else {
                $paths = [...$paths, ...$this->leafPaths($children, $path)];
            }
        }

        return $paths;
    }

    /**
     * Split eagerLoad into an include-able tree and a fallback list. A user
     * constraint anywhere on a path forces that whole path to query normally.
     *
     * @return array{array<string, mixed>, list<string>}
     */
    private function eagerLoadTree(): array
    {
        $constrained = [];

        foreach ($this->eagerLoad as $name => $constraint) {
            if ($constraint instanceof Closure && ! $this->isDefaultConstraint($constraint)) {
                $constrained[] = (string) $name;
            }
        }

        $tree = [];
        $fallback = [];

        foreach (array_keys($this->eagerLoad) as $name) {
            $name = (string) $name;
            $isConstrained = array_filter($constrained, fn (string $c) => $name === $c || str_starts_with($name, "{$c}.") || str_starts_with($c, "{$name}."));

            if ($isConstrained !== []) {
                $fallback[] = $name;

                continue;
            }

            $tree = $this->insertPath($tree, explode('.', $name));
        }

        return [$tree, $fallback];
    }

    /**
     * @param  array<string, mixed>  $tree
     * @param  list<string>  $segments
     * @return array<string, mixed>
     */
    private function insertPath(array $tree, array $segments): array
    {
        $head = array_shift($segments);

        if ($head === null) {
            return $tree;
        }

        $child = ConnectionConfig::stringKeyed($tree[$head] ?? null);
        $tree[$head] = $segments === [] ? $child : $this->insertPath($child, $segments);

        return $tree;
    }

    /**
     * @param  array<string, mixed>  $children
     * @return list<string>
     */
    private function collectDescendants(array $children, string $prefix): array
    {
        $paths = [];

        foreach ($children as $name => $grandchildren) {
            $path = "{$prefix}.{$name}";
            $paths[] = $path;
            $paths = [...$paths, ...$this->collectDescendants(ConnectionConfig::stringKeyed($grandchildren), $path)];
        }

        return $paths;
    }

    /** with('author') registers a default no-op closure; user closures live outside the framework. */
    private function isDefaultConstraint(Closure $constraint): bool
    {
        $file = (new ReflectionFunction($constraint))->getFileName();

        return $file === false || str_contains($file, '/laravel/framework/');
    }

    private function jsonApiParser(): ?JsonApiResponseParser
    {
        $connection = $this->getQuery()->getConnection();

        if (! $connection instanceof RestConnection) {
            return null;
        }

        $parser = $connection->parser();

        return $parser instanceof JsonApiResponseParser ? $parser : null;
    }
}
