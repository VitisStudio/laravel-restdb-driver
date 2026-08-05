<?php

declare(strict_types=1);

namespace Vitis\RestDB\JsonApi\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;
use Vitis\RestDB\JsonApi\JsonApiSpecParser;
use Vitis\RestDB\JsonApi\Support\NameMapper;
use Vitis\RestDB\Values\ConnectionConfig;

/**
 * Spec file -> physical Eloquent model classes. Generated classes are
 * committed code the user owns: one class per resource type with the trait,
 * $connection, $table, $casts from schema types, relation methods from spec
 * relationships, and a @property docblock. Re-running never overwrites an
 * edited class without --force.
 */
final class MakeModelsCommand extends Command
{
    protected $signature = 'restdb:make-models
        {connection : The restdb connection name}
        {--spec= : Path to the API spec file (OpenAPI JSON)}
        {--path=app/Models : Directory to write classes into}
        {--namespace=App\\Models : Namespace for the generated classes}
        {--force : Overwrite classes that already exist}';

    protected $description = 'Generate physical Eloquent model classes from an API spec';

    public function handle(Repository $config): int
    {
        $connection = $this->argument('connection');
        $connection = is_string($connection) ? $connection : '';
        $spec = $this->option('spec');

        if (! is_string($spec) || $spec === '') {
            $this->error('Provide the spec file: --spec=path/to/openapi.json');

            return self::FAILURE;
        }

        $manifest = (new JsonApiSpecParser)->parse($spec);
        $resources = ConnectionConfig::stringKeyed($manifest['resources'] ?? null);

        if ($resources === []) {
            $this->error('The spec describes no JSON:API resources (components.schemas with type enums).');

            return self::FAILURE;
        }

        $style = $config->get("database.connections.{$connection}.name_mapping", 'camel');
        $names = new NameMapper(is_string($style) ? $style : 'camel');

        $path = $this->option('path');
        $path = rtrim(is_string($path) ? $path : 'app/Models', '/');
        $namespace = $this->option('namespace');
        $namespace = trim(is_string($namespace) ? $namespace : 'App\Models', '\\');

        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $classByType = [];

        foreach (array_keys($resources) as $type) {
            $classByType[$type] = Str::studly(Str::singular($names->toModel((string) $type)));
        }

        $written = 0;
        $skipped = 0;

        foreach ($resources as $type => $resource) {
            $class = $classByType[$type];
            $file = "{$path}/{$class}.php";

            if (is_file($file) && $this->option('force') !== true) {
                $this->line("Skipped {$class} — {$file} exists (use --force to overwrite).");
                $skipped++;

                continue;
            }

            file_put_contents($file, $this->render(
                $namespace,
                $class,
                $connection,
                (string) $type,
                ConnectionConfig::stringKeyed($resource),
                $classByType,
                $names,
                basename($spec),
            ));

            $written++;
        }

        $this->info("Generated {$written} model(s) in [{$path}]".($skipped > 0 ? ", skipped {$skipped} existing" : '').'.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $resource
     * @param  array<string, string>  $classByType
     */
    private function render(string $namespace, string $class, string $connection, string $type, array $resource, array $classByType, NameMapper $names, string $specName): string
    {
        $table = $names->toModel($type);
        $attributes = ConnectionConfig::stringKeyed($resource['attributes'] ?? null);
        $relationships = ConnectionConfig::stringKeyed($resource['relationships'] ?? null);

        $properties = [' * @property string|null $id'];
        $casts = [];

        foreach ($attributes as $name => $schemaType) {
            $column = $names->toModel($name);
            [$phpType, $cast] = $this->mapType(is_string($schemaType) ? $schemaType : 'string');
            $properties[] = " * @property {$phpType}|null \${$column}";

            if ($cast !== null) {
                $casts[] = "        '{$column}' => '{$cast}',";
            }
        }

        $uses = [
            'use Illuminate\Database\Eloquent\Model;',
            'use Vitis\RestDB\JsonApi\IsJsonApiResource;',
        ];
        $methods = [];

        foreach ($relationships as $name => $relationship) {
            $relationship = ConnectionConfig::stringKeyed($relationship);
            $targetType = is_string($relationship['type'] ?? null) ? $relationship['type'] : null;
            $kind = $relationship['kind'] ?? 'to-one';

            if ($targetType === null || ! isset($classByType[$targetType])) {
                continue; // target resource is not in this spec — add the relation by hand
            }

            $related = $classByType[$targetType];
            $method = Str::camel($names->toModel($name));

            if ($kind === 'to-many') {
                $uses[] = 'use Illuminate\Database\Eloquent\Relations\HasMany;';
                $foreignKey = Str::snake(Str::singular($table)).'_id';
                $methods[] = <<<PHP

    public function {$method}(): HasMany
    {
        return \$this->hasMany({$related}::class, '{$foreignKey}');
    }
PHP;
            } else {
                $uses[] = 'use Illuminate\Database\Eloquent\Relations\BelongsTo;';
                $foreignKey = $names->toModel($name).'_id';
                $methods[] = <<<PHP

    public function {$method}(): BelongsTo
    {
        return \$this->belongsTo({$related}::class, '{$foreignKey}');
    }
PHP;
            }
        }

        $uses = array_unique($uses);
        sort($uses);

        $castsBlock = $casts === [] ? '' : "\n    protected \$casts = [\n".implode("\n", $casts)."\n    ];\n";
        $usesBlock = implode("\n", $uses);
        $propertiesBlock = implode("\n", $properties);
        $methodsBlock = implode("\n", $methods);

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

{$usesBlock}

/**
 * Generated by restdb:make-models from {$specName}. This is committed code —
 * edit freely; re-running the command will not overwrite it without --force.
 *
{$propertiesBlock}
 */
class {$class} extends Model
{
    use IsJsonApiResource;

    protected \$connection = '{$connection}';

    protected \$table = '{$table}';

    protected \$guarded = [];

    public \$timestamps = false;
{$castsBlock}{$methodsBlock}
}

PHP;
    }

    /** @return array{string, string|null} php type + cast */
    private function mapType(string $schemaType): array
    {
        return match ($schemaType) {
            'integer' => ['int', 'integer'],
            'number' => ['float', 'float'],
            'boolean' => ['bool', 'boolean'],
            'string:date-time', 'string:date' => ['\Illuminate\Support\Carbon', 'datetime'],
            'array', 'object' => ['array', 'array'],
            default => ['string', null],
        };
    }
}
