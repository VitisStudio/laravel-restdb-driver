<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Vitis\RestDB\Eloquent\InteractsWithRestApi;

/**
 * @property int|null $id
 * @property int|null $userId
 * @property string|null $title
 * @property string|null $body
 */
class Post extends Model
{
    use InteractsWithRestApi;

    protected $connection = 'jsonplaceholder';

    protected $table = 'posts';

    protected $guarded = [];

    public $timestamps = false;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userId');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'postId');
    }
}
