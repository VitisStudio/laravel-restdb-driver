<?php

declare(strict_types=1);

namespace Vitis\RestDB\Facades;

use Illuminate\Support\Facades\Facade;
use Vitis\RestDB\Adapters\AdapterRegistry;

/**
 * @method static void registerAdapter(string $name, string|\Vitis\RestDB\Contracts\Adapter $adapter)
 * @method static \Vitis\RestDB\Contracts\Adapter get(string $name)
 * @method static list<string> names()
 *
 * @see AdapterRegistry
 */
final class RestDB extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AdapterRegistry::class;
    }
}
