<?php

declare(strict_types=1);

namespace Vitis\RestDB\Rest;

/**
 * Built-in wire-format presets for the generic adapter. A preset is nothing
 * but a connection-config fragment — the same granular keys a user could
 * write by hand, including a `capabilities` block declaring what the named
 * server framework actually honors. Connection config always wins over the
 * preset; user-defined presets in config('restdb.presets') win over built-ins
 * of the same name.
 */
final class Presets
{
    /** @return array<string, array<string, mixed>> */
    public static function builtIn(): array
    {
        return [

            // json-server / JSONPlaceholder: bare JSON bodies, `_gte`-style
            // operator suffixes, `_sort`/`_order`, `_page`/`_limit`/`_start`,
            // and a total in the X-Total-Count header. json-server has no IN
            // syntax — a single-value whereIn collapses to equality, wider
            // ones fail loudly ('in' => 'single').
            'json-server' => [
                'filters' => [
                    'style' => 'suffix',
                    'in' => 'single',
                    'like' => 'contains',
                ],
                'sort' => [
                    'param' => '_sort',
                    'direction_param' => '_order',
                ],
                'pagination' => [
                    'params' => ['page' => '_page', 'limit' => '_limit', 'offset' => '_start'],
                    'total_header' => 'X-Total-Count',
                ],
                'capabilities' => [
                    'select' => true,
                    'filter' => ['operators' => ['eq', 'in', 'ne', 'gte', 'lte', 'like']],
                    'sort' => true,
                    'sort.multi' => true,
                    'aggregate.count' => true,
                    'aggregate.exists' => true,
                    'write.insert' => true,
                    'write.update' => true,
                    'write.delete' => true,
                ],
            ],

        ];
    }

    /**
     * Declared connection keys win over preset keys. String-keyed arrays merge
     * recursively (override one pagination param, keep the rest); lists and
     * scalars are replaced wholesale (a declared operator list is the list).
     *
     * @param  array<string, mixed>  $preset
     * @param  array<string, mixed>  $declared
     * @return array<string, mixed>
     */
    public static function merge(array $preset, array $declared): array
    {
        /** @var array<string, mixed> */
        return self::mergeRecursive($preset, $declared);
    }

    /**
     * @param  array<mixed>  $base
     * @param  array<mixed>  $override
     * @return array<mixed>
     */
    private static function mergeRecursive(array $base, array $override): array
    {
        $merged = $base;

        foreach ($override as $key => $value) {
            $merged[$key] = is_array($value)
                && ! array_is_list($value)
                && isset($merged[$key])
                && is_array($merged[$key])
                && ! array_is_list($merged[$key])
                ? self::mergeRecursive($merged[$key], $value)
                : $value;
        }

        return $merged;
    }
}
