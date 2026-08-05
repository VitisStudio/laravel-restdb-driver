<?php

declare(strict_types=1);

namespace Tests\Fixtures\JsonApi;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vitis\RestDB\JsonApi\IsJsonApiResource;

class Comment extends Model
{
    use IsJsonApiResource;

    protected $connection = 'jsonapi';

    protected $table = 'comments';

    protected $guarded = [];

    public $timestamps = false;

    public function author(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'author_id');
    }
}
