<?php

declare(strict_types=1);

use RestDBOpenApiGenerated\Trait\Post;
use Vitis\RestDB\OpenApi\OpenApiSpecParser;

function openApiSpecPath(): string
{
    return __DIR__.'/../../../../tests/Fixtures/specs/blog-openapi.json';
}

function openApiRmRf(string $path): void
{
    if (is_dir($path)) {
        foreach (glob("{$path}/*") ?: [] as $child) {
            openApiRmRf($child);
        }

        rmdir($path);

        return;
    }

    unlink($path);
}

function openApiTempDir(string $suffix): string
{
    $dir = sys_get_temp_dir().'/restdb-openapi-test-'.$suffix;

    if (is_dir($dir)) {
        foreach (glob("{$dir}/*") ?: [] as $file) {
            openApiRmRf($file);
        }
    } else {
        mkdir($dir, 0755, true);
    }

    return $dir;
}

it('parses resources reachable from paths and derives $ref relationships', function () {
    $manifest = (new OpenApiSpecParser)->parse(openApiSpecPath());

    // Only schemas exposed at a path become resources; the Geo value object and
    // the PageMeta envelope schema are not. The RPC action endpoint
    // /posts/{id}/publish does surface its ActionResult body by default.
    expect(array_keys($manifest['resources']))
        ->toEqualCanonicalizing(['authors', 'posts', 'comments', 'publish']);

    $post = $manifest['resources']['posts'];

    // Scalar + inline-object attributes, id excluded (it is the key).
    expect($post['attributes'])
        ->toHaveKey('viewCount')
        ->toHaveKey('rating')
        ->toHaveKey('featured')
        ->toHaveKey('publishedAt')
        ->toHaveKey('geo')
        ->not->toHaveKey('id')
        ->and($post['attributes']['publishedAt'])->toBe('string:date-time')
        ->and($post['attributes']['rating'])->toBe('number:float')
        ->and($post['attributes']['geo'])->toBe('object');

    // A single $ref is belongsTo; an array of $ref is hasMany. The manifest
    // type is the *endpoint* of the related resource.
    expect($post['relationships']['author'])->toBe(['type' => 'authors', 'kind' => 'to-one'])
        ->and($post['relationships']['comments'])->toBe(['type' => 'comments', 'kind' => 'to-many'])
        ->and($post['relationships'])->not->toHaveKey('geo');

    expect($manifest['resources']['authors']['relationships']['posts'])
        ->toBe(['type' => 'posts', 'kind' => 'to-many']);
    expect($manifest['resources']['comments']['relationships']['post'])
        ->toBe(['type' => 'posts', 'kind' => 'to-one']);
});

it('lifts capabilities and filters from collection query parameters', function () {
    $manifest = (new OpenApiSpecParser)->parse(openApiSpecPath());

    expect($manifest['capabilities'])->toMatchArray([
        'select' => true,
        'sort' => true,
        'select.include' => true,
        'select.columns' => true,
        'page.limit' => true,
        'page.offset' => true,
    ]);

    expect($manifest['resources']['posts']['filters'])
        ->toContain('status')
        ->toContain('authorId');
});

it('excludes RPC action endpoints from the resource set', function () {
    $manifest = (new OpenApiSpecParser)
        ->excludePaths(['/publish'])
        ->parse(openApiSpecPath());

    // The /posts/{id}/publish action — and its ActionResult body — are gone.
    expect(array_keys($manifest['resources']))
        ->toEqualCanonicalizing(['authors', 'posts', 'comments'])
        ->not->toContain('publish');
});

it('drops excluded action models when generating classes', function () {
    $dir = openApiTempDir('models-exclude');

    $this->artisan('restdb:make-openapi-models', [
        'connection' => 'blog',
        '--spec' => openApiSpecPath(),
        '--path' => $dir,
        '--namespace' => 'RestDBOpenApiGenerated\\Excluded',
        '--exclude' => ['/publish'],
    ])->assertSuccessful();

    expect(is_file("{$dir}/Post.php"))->toBeTrue()
        ->and(is_file("{$dir}/Publish.php"))->toBeFalse();
});

it('generates physical model classes from the spec', function () {
    $dir = openApiTempDir('models');

    $this->artisan('restdb:make-openapi-models', [
        'connection' => 'blog',
        '--spec' => openApiSpecPath(),
        '--path' => $dir,
        '--namespace' => 'RestDBOpenApiGenerated\\Models',
    ])->assertSuccessful();

    expect(is_file("{$dir}/Author.php"))->toBeTrue()
        ->and(is_file("{$dir}/Post.php"))->toBeTrue()
        ->and(is_file("{$dir}/Comment.php"))->toBeTrue()
        // Value object and envelope schemas never become models.
        ->and(is_file("{$dir}/Geo.php"))->toBeFalse()
        ->and(is_file("{$dir}/PageMetum.php"))->toBeFalse()
        ->and(is_file("{$dir}/PageMeta.php"))->toBeFalse();

    $post = (string) file_get_contents("{$dir}/Post.php");

    expect($post)
        ->toContain('namespace RestDBOpenApiGenerated\Models;')
        ->toContain('use IsOpenApiResource;')
        ->toContain("protected \$connection = 'blog';")
        ->toContain("protected \$table = 'posts';")
        ->toContain("'view_count' => 'integer'")
        ->toContain("'rating' => 'float'")
        ->toContain("'featured' => 'boolean'")
        ->toContain("'published_at' => 'datetime'")
        // Nested object/array members are documented but NOT cast: the driver
        // hands back decoded arrays, so an 'array' cast would double-decode.
        ->toContain('@property array|null $geo')
        ->not->toContain("'geo' => 'array'")
        ->toContain('@property int|null $view_count')
        ->toContain("return \$this->belongsTo(Author::class, 'author_id');")
        ->toContain("return \$this->hasMany(Comment::class, 'post_id');");

    // int64 stays integer, not string.
    $author = (string) file_get_contents("{$dir}/Author.php");
    expect($author)
        ->toContain("'post_count' => 'integer'")
        ->toContain("return \$this->hasMany(Post::class, 'author_id');");

    foreach (['Author', 'Post', 'Comment'] as $class) {
        $lint = shell_exec('php -l '.escapeshellarg("{$dir}/{$class}.php"));
        expect((string) $lint)->toContain('No syntax errors');
    }
});

it('lifts the connection into a shared trait with --connection-trait', function () {
    $dir = openApiTempDir('models-trait');

    $this->artisan('restdb:make-openapi-models', [
        'connection' => 'blog',
        '--spec' => openApiSpecPath(),
        '--path' => $dir,
        '--namespace' => 'RestDBOpenApiGenerated\\Trait',
        '--connection-trait' => true,
    ])->assertSuccessful();

    // The trait is written once under Concerns and sets the connection via the
    // Eloquent initializer hook (a $connection property would fatally conflict
    // with Model::$connection).
    $trait = (string) file_get_contents("{$dir}/Concerns/HasBlogConnection.php");
    expect($trait)
        ->toContain('namespace RestDBOpenApiGenerated\Trait\Concerns;')
        ->toContain('trait HasBlogConnection')
        ->toContain('public function initializeHasBlogConnection(): void')
        ->toContain("\$this->setConnection('blog');")
        ->not->toContain('protected $connection');

    // Models compose the trait and carry no inline $connection property.
    $post = (string) file_get_contents("{$dir}/Post.php");
    expect($post)
        ->toContain('use RestDBOpenApiGenerated\Trait\Concerns\HasBlogConnection;')
        // Traits emitted in Pint's ordered_traits order — alphabetical.
        ->toContain('use HasBlogConnection, IsOpenApiResource;')
        ->not->toContain("protected \$connection = 'blog';");

    foreach (['Post', 'Concerns/HasBlogConnection'] as $file) {
        $lint = shell_exec('php -l '.escapeshellarg("{$dir}/{$file}.php"));
        expect((string) $lint)->toContain('No syntax errors');
    }

    // Compose the trait for real: a trait declaring $connection as a property
    // would fatally conflict with Model::$connection. The initializer hook must
    // instead resolve the connection at runtime. Loading + instantiating proves
    // the composition is legal and the connection is applied.
    require "{$dir}/Concerns/HasBlogConnection.php";
    require "{$dir}/Post.php";

    $model = new Post;
    expect($model->getConnectionName())->toBe('blog');
});

it('skips reserved-word class names and deduplicates colliding relationship methods', function () {
    $dir = openApiTempDir('models-reserved');

    $this->artisan('restdb:make-openapi-models', [
        'connection' => 'reserved',
        '--spec' => __DIR__.'/../../../../tests/Fixtures/specs/reserved-openapi.json',
        '--path' => $dir,
        '--namespace' => 'RestDBOpenApiGenerated\\Reserved',
    ])->assertSuccessful();

    // 'List' and 'Print' are PHP reserved words — no file, no fatal.
    expect(is_file("{$dir}/List.php"))->toBeFalse()
        ->and(is_file("{$dir}/Print.php"))->toBeFalse()
        ->and(is_file("{$dir}/Order.php"))->toBeTrue();

    // subTotals + sub_totals both camel-case to subTotals; PHP methods are
    // case-insensitive, so only one is emitted — no "Cannot redeclare" fatal.
    $order = (string) file_get_contents("{$dir}/Order.php");
    expect(substr_count($order, 'public function subTotals('))->toBe(1);

    $lint = shell_exec('php -l '.escapeshellarg("{$dir}/Order.php"));
    expect((string) $lint)->toContain('No syntax errors');
});

it('never overwrites an edited class without --force', function () {
    $dir = openApiTempDir('models-force');

    $this->artisan('restdb:make-openapi-models', [
        'connection' => 'blog', '--spec' => openApiSpecPath(), '--path' => $dir, '--namespace' => 'RestDBOpenApiGenerated\\Edited',
    ])->assertSuccessful();

    $edited = "<?php // hand-edited, mine now\n";
    file_put_contents("{$dir}/Post.php", $edited);

    $this->artisan('restdb:make-openapi-models', [
        'connection' => 'blog', '--spec' => openApiSpecPath(), '--path' => $dir, '--namespace' => 'RestDBOpenApiGenerated\\Edited',
    ])->assertSuccessful();

    expect((string) file_get_contents("{$dir}/Post.php"))->toBe($edited);

    $this->artisan('restdb:make-openapi-models', [
        'connection' => 'blog', '--spec' => openApiSpecPath(), '--path' => $dir, '--namespace' => 'RestDBOpenApiGenerated\\Edited', '--force' => true,
    ])->assertSuccessful();

    expect((string) file_get_contents("{$dir}/Post.php"))->toContain('class Post extends Model');
});
