<?php

declare(strict_types=1);

namespace Vitis\RestDB\JsonApi;

use Vitis\RestDB\Contracts\ResponseParser;
use Vitis\RestDB\JsonApi\Support\NameMapper;
use Vitis\RestDB\Values\ApiResponse;
use Vitis\RestDB\Values\ConnectionConfig;
use Vitis\RestDB\Values\DeleteIntent;
use Vitis\RestDB\Values\ErrorBag;
use Vitis\RestDB\Values\InsertIntent;
use Vitis\RestDB\Values\ResultPage;
use Vitis\RestDB\Values\SelectIntent;
use Vitis\RestDB\Values\UpdateIntent;
use Vitis\RestDB\Values\WriteResult;

/**
 * Flattens resource objects to attribute rows (id + attributes, names mapped
 * to snake_case), exposes to-one linkage as {relation}_id columns so belongsTo
 * works untouched, and builds a (type, id) identity map over data + included.
 * The map accumulates across the pages of one select so the JSON:API Eloquent
 * builder can hydrate eager-loaded relations with zero extra HTTP.
 */
final class JsonApiResponseParser implements ResponseParser
{
    /** Carries per-row relationship linkage out of the parser; stripped before models see it. */
    public const RELATIONSHIPS_KEY = '__jsonapi_relationships';

    private ?SelectIntent $documentIntent = null;

    /** @var array<string, array<mixed>> identity map: "type:id" => resource object */
    private array $identityMap = [];

    public function __construct(private readonly NameMapper $names) {}

    public function rows(ApiResponse $response, SelectIntent $intent): ResultPage
    {
        if ($this->documentIntent !== $intent) {
            $this->documentIntent = $intent;
            $this->identityMap = [];
        }

        $json = $response->json();
        $data = $json['data'] ?? [];

        $resources = [];

        if (is_array($data)) {
            foreach (array_is_list($data) ? $data : [$data] as $resource) {
                if (is_array($resource)) {
                    $resources[] = $resource;
                }
            }
        }

        foreach ([...$resources, ...$this->includedResources($json)] as $resource) {
            $this->remember($resource);
        }

        return new ResultPage(
            array_map($this->flattenResource(...), $resources),
            ConnectionConfig::stringKeyed($json['meta'] ?? null),
        );
    }

    public function writeResult(ApiResponse $response, InsertIntent|UpdateIntent|DeleteIntent $intent): WriteResult
    {
        $data = $response->json()['data'] ?? null;

        $attributes = [];

        if (is_array($data) && ! array_is_list($data)) {
            $attributes = $this->flattenResource($data);
            unset($attributes[self::RELATIONSHIPS_KEY]);
        }

        $id = $attributes['id'] ?? null;

        return new WriteResult(
            affected: $response->successful() ? 1 : 0,
            id: is_string($id) || is_int($id) ? $id : null,
            attributes: $attributes,
        );
    }

    public function errors(ApiResponse $response): ?ErrorBag
    {
        $errors = $response->json()['errors'] ?? null;

        if (! is_array($errors) || $errors === []) {
            return null;
        }

        $fields = [];
        $general = [];

        foreach ($errors as $error) {
            if (! is_array($error)) {
                continue;
            }

            $detail = $error['detail'] ?? $error['title'] ?? null;
            $detail = is_string($detail) ? $detail : 'Unknown API error';

            $pointer = is_array($error['source'] ?? null) ? ($error['source']['pointer'] ?? null) : null;

            if (is_string($pointer) && preg_match('#^/data/(attributes|relationships)/([^/]+)#', $pointer, $matches) === 1) {
                $fields[$this->names->toModel($matches[2])][] = $detail;
            } else {
                $general[] = $detail;
            }
        }

        return new ErrorBag($fields, $general);
    }

    /*
    |--------------------------------------------------------------------------
    | Compound-document surface for the JSON:API Eloquent builder
    |--------------------------------------------------------------------------
    */

    /** @return array<string, array<mixed>> */
    public function identityMap(): array
    {
        return $this->identityMap;
    }

    /** @return array<mixed>|null */
    public function lookup(string $type, string|int $id): ?array
    {
        return $this->identityMap["{$type}:{$id}"] ?? null;
    }

    /**
     * Resource object -> flat attribute row: id + mapped attributes, to-one
     * linkage as {relation}_id, raw linkage under RELATIONSHIPS_KEY.
     *
     * @param  array<mixed>  $resource
     * @return array<string, mixed>
     */
    public function flattenResource(array $resource): array
    {
        $row = ['id' => $resource['id'] ?? null];

        foreach (is_array($resource['attributes'] ?? null) ? $resource['attributes'] : [] as $name => $value) {
            if (is_string($name)) {
                $row[$this->names->toModel($name)] = $value;
            }
        }

        $linkage = [];

        foreach (is_array($resource['relationships'] ?? null) ? $resource['relationships'] : [] as $name => $relationship) {
            if (! is_string($name) || ! is_array($relationship) || ! array_key_exists('data', $relationship)) {
                continue;
            }

            $mapped = $this->names->toModel($name);
            $data = $relationship['data'];
            $linkage[$mapped] = $data;

            // To-one linkage doubles as a foreign key column.
            if ($data === null) {
                $row["{$mapped}_id"] = null;
            } elseif (is_array($data) && isset($data['type'])) {
                $row["{$mapped}_id"] = $data['id'] ?? null;
            }
        }

        if ($linkage !== []) {
            $row[self::RELATIONSHIPS_KEY] = $linkage;
        }

        return $row;
    }

    /**
     * @param  array<mixed>  $json
     * @return list<array<mixed>>
     */
    private function includedResources(array $json): array
    {
        $resources = [];

        foreach (is_array($json['included'] ?? null) ? $json['included'] : [] as $resource) {
            if (is_array($resource)) {
                $resources[] = $resource;
            }
        }

        return $resources;
    }

    /** @param array<mixed> $resource */
    private function remember(array $resource): void
    {
        $type = $resource['type'] ?? null;
        $id = $resource['id'] ?? null;

        if (is_string($type) && (is_string($id) || is_int($id))) {
            $this->identityMap["{$type}:{$id}"] = $resource;
        }
    }
}
