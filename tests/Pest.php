<?php

declare(strict_types=1);

use Tests\TestCase;

pest()->extend(TestCase::class)->in(
    'Feature',
    '../packages/core/tests/Feature',
    '../packages/jsonapi/tests/Feature',
);
