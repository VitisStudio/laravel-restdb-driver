<?php

declare(strict_types=1);

namespace Tests\Fixtures\JsonApi;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Vitis\RestDB\JsonApi\IsJsonApiResource;

class Post extends Model
{
    use IsJsonApiResource;

    protected $connection = 'jsonapi';

    protected $table = 'posts';

    protected $guarded = [];

    public $timestamps = false;

    public function author(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'author_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'post_id');
    }
}
