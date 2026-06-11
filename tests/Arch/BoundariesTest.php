<?php

declare(strict_types=1);

// The contracts package is the SPI: it may use illuminate primitives but never
// anything from core or jsonapi. Composer dependencies enforce this at install
// time; these tests catch violations earlier, with better messages.

$coreInternals = [
    'Vitis\RestDB\Query',
    'Vitis\RestDB\Eloquent',
    'Vitis\RestDB\Connection',
    'Vitis\RestDB\Http',
    'Vitis\RestDB\Auth',
    'Vitis\RestDB\Adapters',
    'Vitis\RestDB\Endpoints',
    'Vitis\RestDB\Exceptions',
    'Vitis\RestDB\Commands',
    'Vitis\RestDB\Testing',
];

arch('contracts depend on nothing from core or jsonapi')
    ->expect('Vitis\RestDB\Contracts')
    ->not->toUse([...$coreInternals, 'Vitis\RestDB\JsonApi']);

arch('values depend on nothing from core or jsonapi')
    ->expect('Vitis\RestDB\Values')
    ->not->toUse([...$coreInternals, 'Vitis\RestDB\JsonApi']);

arch('capabilities depend on nothing from core or jsonapi')
    ->expect('Vitis\RestDB\Capabilities')
    ->not->toUse([...$coreInternals, 'Vitis\RestDB\JsonApi']);

// jsonapi may use the contracts package and core's public API (Eloquent trait,
// adapter registry, exceptions) — never core internals.
arch('jsonapi never reaches into core internals')
    ->expect('Vitis\RestDB\JsonApi')
    ->not->toUse([
        'Vitis\RestDB\Query',
        'Vitis\RestDB\Connection',
        'Vitis\RestDB\Http',
        'Vitis\RestDB\Auth',
        'Vitis\RestDB\Endpoints',
    ]);

// core never references the jsonapi package — it must stay installable alone.
arch('core never references jsonapi')
    ->expect('Vitis\RestDB')
    ->classes()
    ->not->toUse('Vitis\RestDB\JsonApi')
    ->ignoring('Vitis\RestDB\JsonApi');

arch('no debug calls anywhere')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die', 'exit'])
    ->not->toBeUsed();
