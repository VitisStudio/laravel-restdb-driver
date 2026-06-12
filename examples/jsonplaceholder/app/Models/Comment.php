<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vitis\RestDB\Eloquent\InteractsWithRestApi;

/**
 * @property int|null $id
 * @property int|null $postId
 * @property string|null $name
 * @property string|null $email
 * @property string|null $body
 */
class Comment extends Model
{
    use InteractsWithRestApi;

    protected $connection = 'jsonplaceholder';

    protected $table = 'comments';

    protected $guarded = [];

    public $timestamps = false;

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'postId');
    }
}
