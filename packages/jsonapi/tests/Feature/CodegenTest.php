<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use RestDBGenerated\Runtime\Post;
use Vitis\RestDB\Capabilities\UnsupportedCapabilityException;

function specPath(): string
{
    return __DIR__.'/../../../../tests/Fixtures/specs/crm.json';
}

function tempDir(string $suffix): string
{
    $dir = sys_get_temp_dir().'/restdb-test-'.$suffix;

    if (is_dir($dir)) {
        foreach (glob("{$dir}/*") ?: [] as $file) {
            unlink($file);
        }
    } else {
        mkdir($dir, 0755, true);
    }

    return $dir;
}

beforeEach(function () {
    $this->defineJsonApiConnection();
});

it('prints the effective capability matrix', function () {
    $this->defineJsonApiConnection(['pagination' => ['strategy' => 'page-number', 'size' => 10, 'meta_total' => 'meta.total']]);

    $this->artisan('restdb:capabilities', ['connection' => 'jsonapi'])
        ->expectsOutputToContain('page.total')
        ->assertSuccessful();
});

it('writes a manifest from the spec and validates it with --check', function () {
    config()->set('restdb.manifest_path', tempDir('manifests'));

    $this->artisan('restdb:discover', ['connection' => 'jsonapi', '--spec' => specPath()])
        ->assertSuccessful();

    $manifest = json_decode((string) file_get_contents(config('restdb.manifest_path').'/jsonapi.json'), true);

    expect($manifest['capabilities'])->toMatchArray([
        'select' => true,
        'sort' => true,
        'select.include' => true,
        'select.columns' => true,
        'page.limit' => true,
        'page.number' => true,
    ])
        ->and($manifest['resources']['posts']['attributes'])->toHaveKey('viewCount')
        ->and($manifest['resources']['posts']['relationships']['author']['type'])->toBe('people')
        ->and($manifest['resources']['posts']['filters'])->toContain('status');

    $this->artisan('restdb:discover', ['connection' => 'jsonapi', '--spec' => specPath(), '--check' => true])
        ->assertSuccessful();

    file_put_contents(config('restdb.manifest_path').'/jsonapi.json', '{"stale": true}');

    $this->artisan('restdb:discover', ['connection' => 'jsonapi', '--spec' => specPath(), '--check' => true])
        ->assertFailed();
});

it('grants manifest capabilities at runtime but declared config wins', function () {
    config()->set('restdb.manifest_path', tempDir('manifests-runtime'));

    $this->artisan('restdb:discover', ['connection' => 'jsonapi', '--spec' => specPath()])
        ->assertSuccessful();

    // The manifest grants sort — no declared capability needed.
    $this->defineJsonApiConnection();
    Http::fake(['*' => Http::response(['data' => []])]);
    Tests\Fixtures\JsonApi\Post::query()->orderBy('title')->get();

    // Declared config subtracts it — declared always wins over discovered.
    $this->defineJsonApiConnection(['capabilities' => ['sort' => false]]);

    expect(fn () => Tests\Fixtures\JsonApi\Post::query()->orderBy('title')->get())
        ->toThrow(UnsupportedCapabilityException::class, 'sort');
});

it('generates physical model classes from the spec', function () {
    $dir = tempDir('models');

    $this->artisan('restdb:make-models', [
        'connection' => 'jsonapi',
        '--spec' => specPath(),
        '--path' => $dir,
        '--namespace' => 'RestDBGenerated\\Models',
    ])->assertSuccessful();

    expect(is_file("{$dir}/Post.php"))->toBeTrue()
        ->and(is_file("{$dir}/Person.php"))->toBeTrue()
        ->and(is_file("{$dir}/Comment.php"))->toBeTrue();

    $post = (string) file_get_contents("{$dir}/Post.php");

    expect($post)
        ->toContain('namespace RestDBGenerated\Models;')
        ->toContain('use IsJsonApiResource;')
        ->toContain("protected \$connection = 'jsonapi';")
        ->toContain("protected \$table = 'posts';")
        ->toContain("'view_count' => 'integer'")
        ->toContain("'published_at' => 'datetime'")
        ->toContain("'featured' => 'boolean'")
        ->toContain('@property int|null $view_count')
        ->toContain("return \$this->belongsTo(Person::class, 'author_id');")
        ->toContain("return \$this->hasMany(Comment::class, 'post_id');");

    // Every generated file parses.
    foreach (['Post', 'Person', 'Comment'] as $class) {
        $lint = shell_exec('php -l '.escapeshellarg("{$dir}/{$class}.php"));
        expect((string) $lint)->toContain('No syntax errors');
    }
});

it('never overwrites an edited class without --force', function () {
    $dir = tempDir('models-force');

    $this->artisan('restdb:make-models', [
        'connection' => 'jsonapi', '--spec' => specPath(), '--path' => $dir, '--namespace' => 'RestDBGenerated\\Edited',
    ])->assertSuccessful();

    $edited = "<?php // hand-edited, mine now\n";
    file_put_contents("{$dir}/Post.php", $edited);

    $this->artisan('restdb:make-models', [
        'connection' => 'jsonapi', '--spec' => specPath(), '--path' => $dir, '--namespace' => 'RestDBGenerated\\Edited',
    ])->assertSuccessful();

    expect((string) file_get_contents("{$dir}/Post.php"))->toBe($edited);

    $this->artisan('restdb:make-models', [
        'connection' => 'jsonapi', '--spec' => specPath(), '--path' => $dir, '--namespace' => 'RestDBGenerated\\Edited', '--force' => true,
    ])->assertSuccessful();

    expect((string) file_get_contents("{$dir}/Post.php"))->toContain('class Post extends Model');
});

it('generated models work end to end against the fake API', function () {
    $dir = tempDir('models-runtime');

    $this->artisan('restdb:make-models', [
        'connection' => 'jsonapi', '--spec' => specPath(), '--path' => $dir, '--namespace' => 'RestDBGenerated\\Runtime',
    ])->assertSuccessful();

    foreach (['Person', 'Comment', 'Post'] as $class) {
        require_once "{$dir}/{$class}.php";
    }

    Http::fake(['*' => Http::response(['data' => [[
        'type' => 'posts', 'id' => '1',
        'attributes' => ['title' => 'Hello', 'viewCount' => 7, 'featured' => true],
    ]]])]);

    $post = Post::query()->where('status', 'open')->get()->first();

    expect($post?->title)->toBe('Hello')
        ->and($post?->view_count)->toBe(7)
        ->and($post?->featured)->toBeTrue();
});
