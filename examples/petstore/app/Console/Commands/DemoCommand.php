<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Pet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Vitis\RestDB\Capabilities\UnsupportedCapabilityException;

/**
 * The point of this example is *code generation from a real OpenAPI 3 spec*.
 * The models in app/Models were produced by:
 *
 *   php artisan restdb:make-openapi-models petstore \
 *       --spec=spec/petstore.json --exclude=/uploadImage
 *
 * The runtime section then exercises what the live Swagger Petstore actually
 * supports through those generated models. Petstore is deliberately minimal
 * (and its demo server is flaky), so this leans on the one REST shape it
 * reliably serves: GET /pet/{id}.
 */
class DemoCommand extends Command
{
    protected $signature = 'demo {section? : codegen|read|gate (default: all)}';

    protected $description = 'Eloquent models generated from the Swagger Petstore OpenAPI 3 spec — restdb driver';

    public function handle(): int
    {
        DB::connection('petstore')->listen(
            fn ($event) => $this->line("    <fg=gray>⇢ {$event->sql}  ({$event->time} ms)</>"),
        );

        $sections = [
            'codegen' => fn () => $this->codegen(),
            'read' => fn () => $this->read(),
            'gate' => fn () => $this->gate(),
        ];

        $only = $this->argument('section');

        foreach ($sections as $name => $section) {
            if (is_string($only) && $only !== $name) {
                continue;
            }

            $this->components->info(strtoupper($name));
            $section();
            $this->newLine();
        }

        return self::SUCCESS;
    }

    private function codegen(): void
    {
        $this->line('The models under app/Models are generated from the committed spec:');
        $this->line('    <fg=cyan>php artisan restdb:make-openapi-models petstore \\</>');
        $this->line('    <fg=cyan>    --spec=spec/petstore.json --exclude=/uploadImage</>');
        $this->newLine();

        $this->line('Pet was derived from components.schemas.Pet — its attributes,');
        $this->line('@property types, and casts come straight from the schema types:');
        $pet = new Pet;
        $this->line('    $table = '.$pet->getTable().'   (the /pet endpoint segment)');
        $this->line('    scalar casts: integer/number/boolean/date-time schema types → integer/float/boolean/datetime');
        $this->newLine();

        $this->line('Category and Tag are NOT relations: the spec exposes them only as');
        $this->line('nested $ref value objects, never at their own endpoint — so they stay');
        $this->line('array attributes, not belongsTo/hasMany. Relations are $ref-to-a-resource only.');
        $this->line('(No array cast is emitted — the driver already returns decoded arrays.)');
    }

    private function read(): void
    {
        // Petstore has no GET /pet collection and its ids churn, so grab a live,
        // currently-valid id from the findByStatus RPC endpoint first...
        $id = $this->livePetId();

        if ($id === null) {
            $this->warn('    Petstore returned no available pets right now — skipping the live read.');

            return;
        }

        $this->line("find({$id}) compiles to GET /pet/{$id} and hydrates the generated model:");

        $pet = Pet::query()->find($id);

        if ($pet === null) {
            $this->warn("    Pet {$id} vanished between the probe and the read (Petstore is volatile).");

            return;
        }

        $this->line("    #{$pet->id}  {$pet->name}  [{$pet->status}]");
        $this->line('    category (nested $ref, decoded array): '.json_encode($pet->category));
        $this->line('    photoUrls (string array): '.json_encode($pet->photoUrls));
        $this->line('    tags (array of nested $ref): '.json_encode($pet->tags));
    }

    private function gate(): void
    {
        $this->line('Petstore has no collection filtering/sorting on its REST resources,');
        $this->line('so those capabilities are undeclared and fail loudly — nothing is');
        $this->line('silently dropped:');

        try {
            Pet::query()->where('status', 'available')->get();
        } catch (UnsupportedCapabilityException $e) {
            $this->line('    where(status) → '.str($e->getMessage())->before('. Hint'));
        }

        try {
            Pet::query()->orderBy('name')->get();
        } catch (UnsupportedCapabilityException $e) {
            $this->line('    orderBy(name) → '.str($e->getMessage())->before('. Hint'));
        }

        $this->newLine();
        $this->line('Inspect the effective matrix: <options=bold>php artisan restdb:capabilities petstore</>');
    }

    /** Pull one currently-valid pet id from Petstore's findByStatus RPC endpoint. */
    private function livePetId(): ?int
    {
        $base = (string) config('database.connections.petstore.base_url');

        $response = Http::acceptJson()->get("{$base}/pet/findByStatus", ['status' => 'available']);

        $id = $response->ok() ? ($response->json('0.id') ?? null) : null;

        return is_int($id) ? $id : (is_numeric($id) ? (int) $id : null);
    }
}
