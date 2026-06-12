<?php

declare(strict_types=1);

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vitis\RestDB\JsonApi\IsJsonApiResource;

/**
 * @property string|null $id
 * @property string|null $message
 * @property string|null $post_id
 */
class Comment extends Model
{
    use IsJsonApiResource;

    protected $connection = 'crm';

    protected $table = 'comments';

    protected $guarded = [];

    public $timestamps = false;

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }
}
