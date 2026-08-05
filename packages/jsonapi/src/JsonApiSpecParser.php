<?php

declare(strict_types=1);

namespace Vitis\RestDB\JsonApi;

use RuntimeException;
use Vitis\RestDB\Contracts\SpecParser;
use Vitis\RestDB\Values\ConnectionConfig;

/**
 * Reads an OpenAPI document that follows JSON:API conventions and produces a
 * committed, human-reviewable manifest: per-resource attributes/relationships
 * (from components.schemas) and filterable/sortable/includable fields plus
 * page parameters (from collection GET parameters). Build-time only.
 */
final class JsonApiSpecParser implements SpecParser
{
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

        $resources = [];
        $schemas = ConnectionConfig::stringKeyed(ConnectionConfig::stringKeyed($spec['components'] ?? null)['schemas'] ?? null);

        foreach ($schemas as $schema) {
            if (! is_array($schema)) {
                continue;
            }

            $resource = $this->resource($schema);

            if ($resource !== null) {
                $resources[$resource['type']] = $resource;
            }
        }

        $capabilities = ['select' => true];

        foreach (ConnectionConfig::stringKeyed($spec['paths'] ?? null) as $path => $operations) {
            $get = ConnectionConfig::stringKeyed(ConnectionConfig::stringKeyed($operations)['get'] ?? null);
            $parameters = is_array($get['parameters'] ?? null) ? $get['parameters'] : [];

            $type = ltrim($path, '/');

            if (str_contains($type, '/') || ! isset($resources[$type])) {
                continue;
            }

            foreach ($parameters as $parameter) {
                $name = is_array($parameter) ? ($parameter['name'] ?? null) : null;

                if (! is_string($name)) {
                    continue;
                }

                if (preg_match('/^filter\[([^\]]+)\]/', $name, $matches) === 1) {
                    $resources[$type]['filters'][] = $matches[1];
                    $capabilities['filter'] ??= ['operators' => ['eq']];
                } elseif ($name === 'sort') {
                    $capabilities['sort'] = true;
                    $capabilities['sort.multi'] = true;
                } elseif ($name === 'include') {
                    $capabilities['select.include'] = true;
                } elseif (str_starts_with($name, 'fields[')) {
                    $capabilities['select.columns'] = true;
                } elseif (preg_match('/^page\[(size|number|offset|limit|cursor)\]$/', $name, $matches) === 1) {
                    $capabilities[match ($matches[1]) {
                        'size', 'limit' => 'page.limit',
                        'number' => 'page.number',
                        'offset' => 'page.offset',
                        'cursor' => 'page.cursor',
                    }] = true;
                }
            }
        }

        return [
            'generated_from' => basename($specPath),
            'capabilities' => $capabilities,
            'resources' => $resources,
        ];
    }

    /**
     * @param  array<mixed>  $schema
     * @return array{type: string, attributes: array<string, string>, relationships: array<string, array{type: string, kind: string}>, filters: list<string>, sorts: list<string>, includes: list<string>}|null
     */
    private function resource(array $schema): ?array
    {
        $properties = ConnectionConfig::stringKeyed($schema['properties'] ?? null);
        $typeEnum = ConnectionConfig::stringKeyed($properties['type'] ?? null)['enum'] ?? null;
        $type = is_array($typeEnum) && is_string($typeEnum[0] ?? null) ? $typeEnum[0] : null;

        if ($type === null || ! isset($properties['attributes'])) {
            return null;
        }

        $attributes = [];

        foreach (ConnectionConfig::stringKeyed(ConnectionConfig::stringKeyed($properties['attributes'])['properties'] ?? null) as $name => $definition) {
            $definition = ConnectionConfig::stringKeyed($definition);
            $attributeType = is_string($definition['type'] ?? null) ? $definition['type'] : 'string';
            $format = is_string($definition['format'] ?? null) ? $definition['format'] : null;

            $attributes[$name] = $format === null ? $attributeType : "{$attributeType}:{$format}";
        }

        $relationships = [];

        foreach (ConnectionConfig::stringKeyed(ConnectionConfig::stringKeyed($properties['relationships'] ?? null)['properties'] ?? null) as $name => $definition) {
            $data = ConnectionConfig::stringKeyed(ConnectionConfig::stringKeyed(ConnectionConfig::stringKeyed($definition)['properties'] ?? null)['data'] ?? null);

            $relationship = $this->relationship($data);

            if ($relationship !== null) {
                $relationships[$name] = $relationship;
            }
        }

        return [
            'type' => $type,
            'attributes' => $attributes,
            'relationships' => $relationships,
            'filters' => [],
            'sorts' => [],
            'includes' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{type: string, kind: string}|null
     */
    private function relationship(array $data): ?array
    {
        $kind = ($data['type'] ?? null) === 'array' ? 'to-many' : 'to-one';

        $target = $kind === 'to-many'
            ? ConnectionConfig::stringKeyed(ConnectionConfig::stringKeyed($data['items'] ?? null)['properties'] ?? null)
            : ConnectionConfig::stringKeyed($data['properties'] ?? null);

        $enum = ConnectionConfig::stringKeyed($target['type'] ?? null)['enum'] ?? null;
        $type = is_array($enum) && is_string($enum[0] ?? null) ? $enum[0] : null;

        return $type === null ? null : ['type' => $type, 'kind' => $kind];
    }
}
