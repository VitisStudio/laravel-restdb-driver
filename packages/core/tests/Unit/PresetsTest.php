<?php

declare(strict_types=1);

use Vitis\RestDB\Rest\Presets;

it('merges declared keys over preset keys recursively for maps, wholesale for lists and scalars', function () {
    $preset = Presets::builtIn()['json-server'];

    $merged = Presets::merge($preset, [
        'pagination' => ['total_header' => 'X-Count'],
        'capabilities' => ['filter' => ['operators' => ['eq']]],
        'base_url' => 'https://api.test',
    ]);

    expect($merged['pagination'])
        ->toBe(['params' => ['page' => '_page', 'limit' => '_limit', 'offset' => '_start'], 'total_header' => 'X-Count'])
        ->and($merged['capabilities']['filter'])->toBe(['operators' => ['eq']])
        ->and($merged['capabilities']['select'])->toBeTrue()
        ->and($merged['filters'])->toBe($preset['filters'])
        ->and($merged['base_url'])->toBe('https://api.test');
});
