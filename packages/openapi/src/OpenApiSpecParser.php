<?php

declare(strict_types=1);

namespace Vitis\RestDB\OpenApi;

use RuntimeException;
use Vitis\RestDB\Contracts\SpecParser;
use Vitis\RestDB\Values\ConnectionConfig;

/**
 * Reads a plain OpenAPI 3.0.3 document — no JSON:API envelope assumed — and
 * produces a committed, human-reviewable manifest: one resource per schema that
 * is actually read or written at a path (the request/response body of an
 * operation), with its attributes, $ref-derived relationships, and the
 * filter/sort/page query parameters its collection endpoint accepts.
 *
 * Everything is derived from the spec: a property that is a $ref to another
 * resource schema is a belongsTo; an array whose items are a $ref to another
 * resource schema is a hasMany; refs to non-resource schemas (value objects
 * like an address that no endpoint exposes) and inline objects stay attributes.
 * Build-time only — the runtime reads committed manifests, never the spec.
 */
final class OpenApiSpecParser implements SpecParser
{
    /** @var array<string, array<mixed>> the document's components.schemas */
    private array $schemas = [];

    /** @var list<string> path substrings whose operations are ignored */
    private array $excludePaths = [];

    /**
     * Ignore any path whose key contains one of these substrings. Useful to
     * drop RPC-style action endpoints whose request/response bodies would
     * otherwise register as resources (e.g. '/uploadImage', '/workflows/').
     *
     * @param  list<string>  $paths
     */
    public function excludePaths(array $paths): self
    {
        $this->excludePaths = array_values(array_filter($paths, static fn (string $p): bool => $p !== ''));

        return $this;
    }

    public function parse(string $specPath): array
    {
        if (! is_file($specPath)) {
            throw new RuntimeException("Spec file [{$specPath}] does not exist.");
        }

        $raw = (string) file_get_contents($specPath);
        $spec = json_decode($raw, true);

        if (! is_array($spec)) {
            throw new RuntimeException("Spec file [{$specPath}] is not valid JSON.");
        }

        $this->schemas = [];

        foreach (ConnectionConfig::stringKeyed(ConnectionConfig::stringKeyed($spec['components'] ?? null)['schemas'] ?? null) as $name => $schema) {
            if (is_array($schema)) {
                $this->schemas[$name] = $schema;
            }
        }

        $paths = ConnectionConfig::stringKeyed($spec['paths'] ?? null);

        // A schema is a "resource" (gets a model) only if some operation reads
        // or writes it. Endpoint path (last static segment) is its table name.
        $resourceSchemas = $this->resourceSchemas($paths);

        $resources = [];

        foreach ($resourceSchemas as $schemaName => $endpoint) {
            $resource = $this->resource($schemaName, $endpoint, $resourceSchemas);

            if ($resource !== null) {
                $resources[$resource['type']] = $resource;
            }
        }

        $capabilities = ['select' => true];

        $this->collectQueryCapabilities($paths, $resources, $capabilities);

        return [
            'generated_from' => basename($specPath),
            'capabilities' => $capabilities,
            'resources' => $resources,
        ];
    }

    /**
     * Map each resource schema name to the endpoint path segment that hosts it
     * (the collection path's last static segment → the table name). The first
     * endpoint that references a schema wins.
     *
     * @param  array<string, mixed>  $paths
     * @return array<string, string> schema name => endpoint segment
     */
    private function resourceSchemas(array $paths): array
    {
        $resources = [];

        foreach ($paths as $path => $operations) {
            if ($this->isExcluded($path)) {
                continue;
            }

            $segment = $this->endpointSegment($path);

            if ($segment === null) {
                continue;
            }

            foreach (ConnectionConfig::stringKeyed($operations) as $method => $operation) {
                if (! in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true) || ! is_array($operation)) {
                    continue;
                }

                foreach ($this->operationSchemaRefs($operation) as $schemaName) {
                    $resources[$schemaName] ??= $segment;
                }
            }
        }

        return $resources;
    }

    /** Whether the path matches one of the configured exclude substrings. */
    private function isExcluded(string $path): bool
    {
        foreach ($this->excludePaths as $needle) {
            if (str_contains($path, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The last non-parameter segment of a path — the collection name. Paths
     * that are only a template parameter (or empty) have no segment.
     */
    private function endpointSegment(string $path): ?string
    {
        foreach (array_reverse(array_filter(explode('/', $path), static fn (string $s): bool => $s !== '')) as $segment) {
            if (! str_starts_with($segment, '{')) {
                return $segment;
            }
        }

        return null;
    }

    /**
     * The resource schema names an operation reads or writes: every schema
     * reachable from its requestBody and response bodies, unwrapped through the
     * usual list/collection envelopes down to the item schema.
     *
     * @param  array<mixed>  $operation
     * @return list<string>
     */
    private function operationSchemaRefs(array $operation): array
    {
        $names = [];

        // Response keys are HTTP status codes; JSON decodes "200" to an int, so
        // they are numeric-keyed, not string-keyed. Iterate the raw map.
        $bodies = [$operation['requestBody'] ?? null];

        foreach (is_array($operation['responses'] ?? null) ? $operation['responses'] : [] as $response) {
            $bodies[] = $response;
        }

        foreach ($bodies as $body) {
            foreach (ConnectionConfig::stringKeyed(ConnectionConfig::stringKeyed($body)['content'] ?? null) as $media) {
                $schema = ConnectionConfig::stringKeyed(ConnectionConfig::stringKeyed($media)['schema'] ?? null);

                foreach ($this->unwrapToResource($schema) as $name) {
                    $names[] = $name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Resolve a media-type schema down to the resource schema name(s) it
     * ultimately carries: follow $ref, dig through allOf/oneOf/anyOf, unwrap
     * `type: array` items, and descend one level into an object's properties so
     * list envelopes ({ data: [ $ref ] }, { results: [...] }) still resolve.
     *
     * @param  array<mixed>  $schema
     * @param  int  $depth  guards against self-referential envelopes
     * @return list<string>
     */
    private function unwrapToResource(array $schema, int $depth = 0): array
    {
        if ($schema === [] || $depth > 4) {
            return [];
        }

        // Direct reference to a named component schema.
        $ref = $this->refName($schema);

        if ($ref !== null) {
            return isset($this->schemas[$ref]) ? [$ref] : [];
        }

        // Composition — any branch may carry the resource.
        foreach (['allOf', 'oneOf', 'anyOf'] as $keyword) {
            if (isset($schema[$keyword]) && is_array($schema[$keyword])) {
                $names = [];

                foreach ($schema[$keyword] as $member) {
                    if (is_array($member)) {
                        $names = [...$names, ...$this->unwrapToResource($member, $depth + 1)];
                    }
                }

                if ($names !== []) {
                    return array_values(array_unique($names));
                }
            }
        }

        // Array envelope: the items are the payload.
        if (($schema['type'] ?? null) === 'array' && is_array($schema['items'] ?? null)) {
            return $this->unwrapToResource($schema['items'], $depth + 1);
        }

        // Object envelope: a wrapper like { data: [ … ] } or { results: [ … ] }.
        // The payload is the collection property, so prefer array-valued
        // members; a metadata sibling ({ meta: $ref }) must not be mistaken for
        // the resource. Only when no array member exists do we descend into
        // scalar $ref members (a single-object envelope like { data: $ref }).
        $arrayNames = [];
        $scalarNames = [];

        foreach (ConnectionConfig::stringKeyed($schema['properties'] ?? null) as $property) {
            if (! is_array($property)) {
                continue;
            }

            if (($property['type'] ?? null) === 'array' && is_array($property['items'] ?? null)) {
                $arrayNames = [...$arrayNames, ...$this->unwrapToResource($property, $depth + 1)];
            } elseif ($this->refName($property) !== null) {
                $scalarNames = [...$scalarNames, ...$this->unwrapToResource($property, $depth + 1)];
            }
        }

        return array_values(array_unique($arrayNames !== [] ? $arrayNames : $scalarNames));
    }

    /**
     * Turn one resource schema into its manifest entry: attributes from scalar
     * (and inline-object) properties, relationships from $ref properties that
     * point at other resource schemas.
     *
     * @param  array<string, string>  $resourceSchemas  schema name => endpoint
     * @return array{type: string, table: string, attributes: array<string, string>, relationships: array<string, array{type: string, kind: string}>, filters: list<string>, sorts: list<string>, includes: list<string>}|null
     */
    private function resource(string $schemaName, string $endpoint, array $resourceSchemas): ?array
    {
        $schema = $this->resolve($this->schemas[$schemaName] ?? []);
        $properties = ConnectionConfig::stringKeyed($schema['properties'] ?? null);

        if ($properties === []) {
            return null;
        }

        $attributes = [];
        $relationships = [];

        foreach ($properties as $name => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $relationship = $this->relationship($definition, $resourceSchemas);

            if ($relationship !== null) {
                $relationships[$name] = $relationship;

                continue;
            }

            // 'id' is modelled as the key, not an attribute (mirrors JSON:API).
            if ($name === 'id') {
                continue;
            }

            $attributes[$name] = $this->attributeType($definition);
        }

        return [
            'type' => $endpoint,
            'table' => $endpoint,
            'attributes' => $attributes,
            'relationships' => $relationships,
            'filters' => [],
            'sorts' => [],
            'includes' => [],
        ];
    }

    /**
     * A property is a relationship when it is a $ref (or an array of $ref items)
     * to another resource schema. The related resource's *endpoint* is the
     * manifest type, so the generated relation targets the right model.
     *
     * @param  array<mixed>  $definition
     * @param  array<string, string>  $resourceSchemas  schema name => endpoint
     * @return array{type: string, kind: string}|null
     */
    private function relationship(array $definition, array $resourceSchemas): ?array
    {
        $items = $definition['items'] ?? null;

        if (($definition['type'] ?? null) === 'array' && is_array($items)) {
            $target = $this->refName($items);

            return $target !== null && isset($resourceSchemas[$target])
                ? ['type' => $resourceSchemas[$target], 'kind' => 'to-many']
                : null;
        }

        $target = $this->refName($definition);

        return $target !== null && isset($resourceSchemas[$target])
            ? ['type' => $resourceSchemas[$target], 'kind' => 'to-one']
            : null;
    }

    /**
     * The manifest attribute type string: OpenAPI `type`, suffixed with
     * `:format` when the schema carries one (e.g. `string:date-time`), so the
     * generator can pick the Eloquent cast. Inline objects/arrays without a
     * resource $ref collapse to `object`/`array`.
     *
     * @param  array<mixed>  $definition
     */
    private function attributeType(array $definition): string
    {
        $definition = $this->resolve($definition);
        $type = is_string($definition['type'] ?? null) ? $definition['type'] : 'string';
        $format = is_string($definition['format'] ?? null) ? $definition['format'] : null;

        return $format === null ? $type : "{$type}:{$format}";
    }

    /**
     * Collection GET parameters advertise the API's query surface. Named
     * filter/sort/page parameters lift the matching capabilities and register
     * filterable fields on the resource whose endpoint owns the path.
     *
     * @param  array<string, mixed>  $paths
     * @param  array<string, array<string, mixed>>  $resources  keyed by type
     * @param  array<string, mixed>  $capabilities
     */
    private function collectQueryCapabilities(array $paths, array &$resources, array &$capabilities): void
    {
        $pageCapability = [
            'size' => 'page.limit', 'limit' => 'page.limit', 'per_page' => 'page.limit', 'pageSize' => 'page.limit',
            'number' => 'page.number', 'page' => 'page.number',
            'offset' => 'page.offset',
            'cursor' => 'page.cursor',
        ];

        foreach ($paths as $path => $operations) {
            if ($this->isExcluded($path) || str_contains(rtrim($path, '/'), '}')) {
                continue; // excluded, or item paths (…/{id}) don't advertise collection filters
            }

            $get = ConnectionConfig::stringKeyed(ConnectionConfig::stringKeyed($operations)['get'] ?? null);
            $type = $this->endpointSegment($path);

            foreach (is_array($get['parameters'] ?? null) ? $get['parameters'] : [] as $parameter) {
                $parameter = $this->resolve(is_array($parameter) ? $parameter : []);
                $name = is_string($parameter['name'] ?? null) ? $parameter['name'] : null;
                $in = $parameter['in'] ?? null;

                if ($name === null || $in !== 'query') {
                    continue;
                }

                if (preg_match('/^filter\[([^\]]+)\]$/', $name, $matches) === 1) {
                    $capabilities['filter'] ??= ['operators' => ['eq']];

                    if ($type !== null && isset($resources[$type]['filters']) && is_array($resources[$type]['filters'])) {
                        $resources[$type]['filters'][] = $matches[1];
                    }
                } elseif ($name === 'sort') {
                    $capabilities['sort'] = true;
                    $capabilities['sort.multi'] = true;
                } elseif ($name === 'include') {
                    $capabilities['select.include'] = true;
                } elseif (str_starts_with($name, 'fields[') || $name === 'fields') {
                    $capabilities['select.columns'] = true;
                } elseif (preg_match('/^(?:page\[(size|number|offset|limit|cursor)\]|(limit|offset|page|cursor|per_page|pageSize))$/', $name, $matches) === 1) {
                    $token = $matches[1] !== '' ? $matches[1] : $matches[2];

                    $capabilities[$pageCapability[$token]] = true;
                }
            }
        }
    }

    /**
     * Merge an allOf chain and follow a top-level $ref into a single flat
     * schema, so callers see a resolved `properties`/`type`/`format` view. Does
     * not recurse into nested property schemas.
     *
     * @param  array<mixed>  $schema
     * @return array<mixed>
     */
    private function resolve(array $schema, int $depth = 0): array
    {
        if ($depth > 8) {
            return $schema;
        }

        $ref = $this->refName($schema);

        if ($ref !== null && isset($this->schemas[$ref])) {
            return $this->resolve($this->schemas[$ref], $depth + 1);
        }

        if (! isset($schema['allOf']) || ! is_array($schema['allOf'])) {
            return $schema;
        }

        $merged = $schema;
        unset($merged['allOf']);
        $properties = ConnectionConfig::stringKeyed($merged['properties'] ?? null);

        foreach ($schema['allOf'] as $member) {
            if (! is_array($member)) {
                continue;
            }

            $resolved = $this->resolve($member, $depth + 1);
            $properties = [...$properties, ...ConnectionConfig::stringKeyed($resolved['properties'] ?? null)];

            foreach ($resolved as $key => $value) {
                if ($key !== 'properties' && ! isset($merged[$key])) {
                    $merged[$key] = $value;
                }
            }
        }

        if ($properties !== []) {
            $merged['properties'] = $properties;
        }

        return $merged;
    }

    /**
     * The component name a `$ref` points at (`#/components/schemas/Foo` → Foo),
     * or null when the schema is not a local component reference.
     *
     * @param  array<mixed>  $schema
     */
    private function refName(array $schema): ?string
    {
        $ref = $schema['$ref'] ?? null;

        if (! is_string($ref) || ! str_starts_with($ref, '#/components/schemas/')) {
            return null;
        }

        return substr($ref, strlen('#/components/schemas/'));
    }
}
