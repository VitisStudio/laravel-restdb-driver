<?php

declare(strict_types=1);

use Symplify\MonorepoBuilder\Config\MBConfig;

return static function (MBConfig $mbConfig): void {
    $mbConfig->packageDirectories([__DIR__.'/packages']);
    $mbConfig->defaultBranch('main');

    // Sections merged from packages/*/composer.json into the root composer.json
    $mbConfig->dataToAppend([
        'require-dev' => [
            'larastan/larastan' => '^3.0',
            'laravel/pint' => '^1.14',
            'orchestra/testbench' => '^9.0||^10.0||^11.0',
            'pestphp/pest' => '^3.0',
            'pestphp/pest-plugin-arch' => '^3.0',
            'symplify/monorepo-builder' => '^11.2',
        ],
    ]);
};
