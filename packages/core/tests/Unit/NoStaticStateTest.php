<?php

declare(strict_types=1);

/**
 * The prior-art package memoized tokens in static locals — it leaked across
 * connections and broke under Octane. This driver's rule: no mutable static
 * state anywhere. Shared state lives in the cache, behind locks.
 */
it('declares no static properties in any package class', function () {
    $directories = [
        __DIR__.'/../../../contracts/src',
        __DIR__.'/../../../core/src',
        __DIR__.'/../../../jsonapi/src',
    ];

    $violations = [];

    foreach ($directories as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(realpath($directory) ?: $directory));

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (preg_match('/^\s*(?:public|protected|private)\s+static\s+(?!function)/m', $source) === 1) {
                $violations[] = $file->getPathname();
            }
        }
    }

    expect($violations)->toBe([], 'Static properties found (Octane hazard): '.implode(', ', $violations));
});
