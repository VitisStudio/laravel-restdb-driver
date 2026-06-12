<?php

declare(strict_types=1);

namespace Vitis\RestDB\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Vitis\RestDB\Capabilities\Capability;
use Vitis\RestDB\Connection\RestConnection;

final class CapabilitiesCommand extends Command
{
    protected $signature = 'restdb:capabilities {connection : The restdb connection name}';

    protected $description = 'Print the effective capability matrix for a restdb connection';

    public function handle(DatabaseManager $db): int
    {
        $name = $this->argument('connection');
        $name = is_string($name) ? $name : '';
        $connection = $db->connection($name);

        if (! $connection instanceof RestConnection) {
            $this->error("Connection [{$name}] is not a restdb connection.");

            return self::FAILURE;
        }

        $capabilities = $connection->capabilities();

        $this->table(
            ['Capability', 'Granted'],
            array_map(
                fn (Capability $capability) => [$capability->value, $capabilities->has($capability) ? '✓' : '–'],
                Capability::cases(),
            ),
        );

        $operators = array_map(fn ($operator) => $operator->value, $capabilities->operators());

        $this->line('Filter operators: '.($operators === [] ? '(none)' : implode(', ', $operators)));

        return self::SUCCESS;
    }
}
