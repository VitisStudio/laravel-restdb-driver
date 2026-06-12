<?php

declare(strict_types=1);

use App\Console\Commands\DemoCommand;
use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->withCommands([
        DemoCommand::class,
    ])
    ->create();
