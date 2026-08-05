<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Vitis\RestDB\Eloquent\InteractsWithRestApi;

class Article extends Model
{
    use InteractsWithRestApi;

    protected $connection = 'testapi';

    protected $table = 'articles';

    protected $guarded = [];

    public $timestamps = false;

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'authorId');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ArticleComment::class, 'articleId');
    }
}
