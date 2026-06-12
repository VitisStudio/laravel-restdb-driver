<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Crm\Author;
use App\Models\Crm\Post;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Vitis\RestDB\Capabilities\UnsupportedCapabilityException;

class DemoCrmCommand extends Command
{
    protected $signature = 'demo:crm';

    protected $description = 'Eloquent over a real JSON:API server (the hatchify mock in tools/mock-jsonapi)';

    public function handle(): int
    {
        DB::connection('crm')->listen(
            fn ($event) => $this->line("    <fg=gray>⇢ {$event->sql}  ({$event->time} ms)</>"),
        );

        try {
            Author::query()->exists();
        } catch (ConnectionException) {
            $this->components->error(
                'No JSON:API server at '.config('database.connections.crm.base_url')
                .' — start the mock first: cd ../../tools/mock-jsonapi && npm install && npm start',
            );

            return self::FAILURE;
        }

        $this->queries();
        $this->relations();
        $this->pagination();
        $this->writes();
        $this->gate();

        return self::SUCCESS;
    }

    private function queries(): void
    {
        $this->components->info('QUERIES');
        $this->line('Dollar-operator filters and multi-sort — plain Eloquent:');

        $posts = Post::query()
            ->where('rating', '>=', 4)
            ->where('title', 'like', '%Ada%')
            ->orderByDesc('rating')
            ->get();

        foreach ($posts as $post) {
            $this->line("    [{$post->rating}★] {$post->title}");
        }

        $this->line('find() compiles to the resource URL:');
        $first = Author::query()->orderBy('name')->get()->first();
        $found = Author::query()->findOrFail($first?->id);
        $this->line("    {$found->name} <{$found->email}>");
        $this->newLine();
    }

    private function relations(): void
    {
        $this->components->info('RELATIONS');
        $this->line('with() rides the compound document — ONE request, zero extra HTTP:');

        $posts = Post::query()->with('author')->orderBy('title')->get();
        $this->line("    \"{$posts[0]->title}\" — by {$posts[0]->author?->name}");

        $this->line('hasMany off a single model:');
        $comments = $posts[0]->comments;
        $this->line("    that post has {$comments->count()} comments");
        $this->newLine();
    }

    private function pagination(): void
    {
        $this->components->info('PAGINATION');
        $this->line('The server sends no links object — totals ride meta.unpaginatedCount,');
        $this->line('and the adapter pages by math instead of links. paginate() is ONE request:');

        $page = Post::query()->orderBy('title')->paginate(perPage: 5, page: 2);
        $this->line("    page {$page->currentPage()}/{$page->lastPage()}, total {$page->total()}, showing ".count($page->items()).' items');

        $this->line('get() without a limit drains every page:');
        $all = Post::query()->orderBy('title')->get();
        $this->line("    {$all->count()} posts across ".(int) ceil($all->count() / 5).' page-size-5 requests');

        $this->line('count() reads the same meta total:');
        $this->line('    posts rated 4+: '.Post::query()->where('rating', '>=', 4)->count());
        $this->newLine();
    }

    private function writes(): void
    {
        $this->components->info('WRITES');
        $this->line('save() POSTs a typed resource document and re-fills from the response:');

        $author = new Author(['name' => 'Annie Easley', 'email' => 'annie@example.test']);
        $author->save();
        $this->line("    created {$author->name} with server id ".str($author->id)->limit(13));

        $this->line('Dirty-only PATCH:');
        $author->email = 'annie.easley@example.test';
        $author->save();
        $this->line("    email now {$author->email}");

        $this->line('delete() targets the resource URL:');
        $this->line('    deleted: '.var_export($author->delete(), true));
        $this->newLine();
    }

    private function gate(): void
    {
        $this->components->info('GATE');
        $this->line('The dollar dialect has no null-check syntax — so whereNull gates out:');

        try {
            Post::query()->whereNull('body')->get();
        } catch (UnsupportedCapabilityException $e) {
            $this->line('    whereNull → '.str($e->getMessage())->before('. Hint'));
        }

        $this->line('Inspect the connection: <options=bold>php artisan restdb:capabilities crm</>');
    }
}
