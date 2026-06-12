<?php

declare(strict_types=1);

namespace Vitis\RestDB\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Vitis\RestDB\Adapters\AdapterRegistry;
use Vitis\RestDB\Values\ConnectionConfig;

/**
 * Spec file -> committed capability manifest. Runtime reads only the manifest;
 * discovered entries are advisory — declared connection config always wins.
 * --check fails CI when the committed manifest is stale against the spec.
 */
final class DiscoverCommand extends Command
{
    protected $signature = 'restdb:discover
        {connection : The restdb connection name}
        {--spec= : Path to the API spec file (OpenAPI JSON)}
        {--check : Fail when the committed manifest is stale instead of writing}';

    protected $description = 'Parse an API spec into a committed capability manifest';

    public function handle(AdapterRegistry $adapters, Repository $config): int
    {
        $name = $this->argument('connection');
        $name = is_string($name) ? $name : '';
        $spec = $this->option('spec');

        if (! is_string($spec) || $spec === '') {
            $this->error('Provide the spec file: --spec=path/to/openapi.json');

            return self::FAILURE;
        }

        $connection = $config->get("database.connections.{$name}");

        if (! is_array($connection)) {
            $this->error("Connection [{$name}] is not configured.");

            return self::FAILURE;
        }

        $adapterName = $connection['adapter'] ?? null;
        $adapter = $adapters->get(is_string($adapterName) ? $adapterName : '');
        $parser = $adapter->specParser();

        if ($parser === null) {
            $this->error("The [{$adapter->name()}] adapter has no spec discovery.");

            return self::FAILURE;
        }

        $manifest = json_encode($parser->parse($spec), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        $path = self::manifestFile($config, $name);

        if ($this->option('check') === true) {
            if (! is_file($path) || (string) file_get_contents($path) !== $manifest) {
                $this->error("Manifest [{$path}] is stale. Run restdb:discover {$name} --spec={$spec}.");

                return self::FAILURE;
            }

            $this->info("Manifest [{$path}] is up to date.");

            return self::SUCCESS;
        }

        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, $manifest);
        $this->info("Manifest written to [{$path}]. Commit it — runtime never parses the spec.");

        return self::SUCCESS;
    }

    public static function manifestFile(Repository $config, string $connection): string
    {
        $directory = $config->get('restdb.manifest_path');
        $directory = is_string($directory) && $directory !== '' ? $directory : database_path('restdb');

        return rtrim($directory, '/')."/{$connection}.json";
    }

    /**
     * Advisory capability grants from a committed manifest; empty when none exists.
     *
     * @return array<string, mixed>
     */
    public static function manifestCapabilities(Repository $config, string $connection): array
    {
        $path = self::manifestFile($config, $connection);

        if (! is_file($path)) {
            return [];
        }

        $manifest = json_decode((string) file_get_contents($path), true);
        $capabilities = is_array($manifest) ? ($manifest['capabilities'] ?? null) : null;

        $advisory = [];

        // Advisory means additive only: a manifest may grant, never deny.
        foreach (ConnectionConfig::stringKeyed($capabilities) as $key => $value) {
            if ($value === true || is_array($value)) {
                $advisory[$key] = $value;
            }
        }

        return $advisory;
    }
}
