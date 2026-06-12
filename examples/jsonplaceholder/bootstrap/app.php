<?php

declare(strict_types=1);

use App\Console\Commands\DemoCommand;
use App\Console\Commands\DemoCrmCommand;
use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->withCommands([
        DemoCommand::class,
        DemoCrmCommand::class,
    ])
    ->withExceptions()
    ->create();
