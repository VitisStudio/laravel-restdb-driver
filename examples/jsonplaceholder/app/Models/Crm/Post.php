<?php

declare(strict_types=1);

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Vitis\RestDB\JsonApi\IsJsonApiResource;

/**
 * @property string|null $id
 * @property string|null $title
 * @property string|null $body
 * @property int|null $rating
 * @property string|null $author_id
 */
class Post extends Model
{
    use IsJsonApiResource;

    protected $connection = 'crm';

    protected $table = 'posts';

    protected $guarded = [];

    public $timestamps = false;

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'author_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'post_id');
    }
}
