<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Vitis\RestDB\Capabilities\UnsupportedCapabilityException;
use Vitis\RestDB\Exceptions\UnsupportedQueryException;

class DemoCommand extends Command
{
    protected $signature = 'demo {section? : queries|pagination|relations|writes|gate (default: all)}';

    protected $description = 'Eloquent over the live JSONPlaceholder API — restdb driver use cases';

    public function handle(): int
    {
        // Every request the driver sends shows up here: QueryExecuted fires
        // with the request line as "sql" and real timing.
        DB::connection('jsonplaceholder')->listen(
            fn ($event) => $this->line("    <fg=gray>⇢ {$event->sql}  ({$event->time} ms)</>"),
        );

        $sections = [
            'queries' => fn () => $this->queries(),
            'pagination' => fn () => $this->pagination(),
            'relations' => fn () => $this->relations(),
            'writes' => fn () => $this->writes(),
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

    private function queries(): void
    {
        $this->line('Filters, operators, multi-sort, and limit — plain Eloquent:');

        $posts = Post::query()
            ->where('userId', 1)
            ->where('id', '>=', 5)
            ->orderByDesc('title')
            ->limit(3)
            ->get();

        foreach ($posts as $post) {
            $this->line("    #{$post->id}  ".str($post->title)->limit(60));
        }

        $this->line('find() compiles to the resource URL:');
        $post = Post::query()->findOrFail(1);
        $this->line('    Post 1: '.str($post->title)->limit(60));

        $this->line('LIKE rides json-server\'s _like suffix:');
        $count = Post::query()->where('title', 'like', '%qui%')->get()->count();
        $this->line("    {$count} post titles match 'qui'");
    }

    private function pagination(): void
    {
        $this->line('paginate() is ONE request — the total comes from X-Total-Count:');

        $page = Post::query()->paginate(perPage: 5, page: 3);

        $this->line("    page {$page->currentPage()}/{$page->lastPage()}, total {$page->total()}, showing ".count($page->items()).' items');

        $this->line('count() probes with _limit=1 and reads the same header:');
        $this->line('    posts by user 2: '.Post::query()->where('userId', 2)->count());

        $this->line('lazy() streams page by page — one page in memory at a time:');
        $titles = Post::query()->orderBy('id')->lazy(4)->take(10)->pluck('id')->implode(', ');
        $this->line("    first 10 ids via 4-per-page chunks: {$titles}");
    }

    private function relations(): void
    {
        $this->line('belongsTo eager load — one extra request for the batch:');

        $posts = Post::query()->where('userId', 1)->limit(3)->get()->load('user');
        $this->line("    \"{$posts[0]->title}\" — by {$posts[0]->user?->name}");

        $this->line('hasMany off a single model:');
        $comments = Post::query()->findOrFail(1)->comments;
        $this->line("    post 1 has {$comments->count()} comments; first by {$comments[0]->email}");

        $this->line('Relations are gated too — whereHas decomposes into two requests:');
        $users = User::query()->whereHas('posts', fn ($q) => $q->where('id', 1))->get();
        $this->line("    author of post 1: {$users[0]?->name}");
    }

    private function writes(): void
    {
        $this->line('save() POSTs and re-fills the model from the response (JSONPlaceholder fakes writes):');

        $post = new Post(['title' => 'restdb demo', 'body' => 'hello', 'userId' => 1]);
        $post->save();
        $this->line("    created id {$post->id} (server-assigned), wasRecentlyCreated: ".var_export($post->wasRecentlyCreated, true));

        $this->line('Dirty-only PATCH — only the changed attribute goes on the wire:');
        $existing = Post::query()->findOrFail(1);
        $existing->title = 'updated by restdb';
        $existing->save();
        $this->line("    title after server echo: {$existing->title}");

        $this->line('delete() targets the resource URL:');
        $this->line('    deleted: '.var_export($existing->delete(), true));
    }

    private function gate(): void
    {
        $this->line('Nothing silently dropped — undeclared or inexpressible queries throw:');

        try {
            Post::query()->where('userId', 1)->orWhere('userId', 2)->get();
        } catch (UnsupportedCapabilityException $e) {
            $this->line('    orWhere    → '.str($e->getMessage())->before('. Hint'));
        }

        try {
            Post::query()->select(['title'])->get();
        } catch (UnsupportedCapabilityException $e) {
            $this->line('    select()   → '.str($e->getMessage())->before('. Hint'));
        }

        try {
            Post::query()->whereIn('userId', [1, 2, 3])->get();
        } catch (UnsupportedQueryException $e) {
            $this->line('    whereIn(3) → '.str($e->getMessage())->before(' —'));
        }

        try {
            Post::query()->groupBy('userId')->get();
        } catch (\BadMethodCallException $e) {
            $this->line('    groupBy    → '.$e->getMessage());
        }

        $this->line('Inspect any connection: <options=bold>php artisan restdb:capabilities jsonplaceholder</>');
    }
}
