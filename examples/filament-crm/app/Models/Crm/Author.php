<?php

declare(strict_types=1);

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Vitis\RestDB\JsonApi\IsJsonApiResource;

/**
 * @property string|null $id
 * @property string|null $name
 * @property string|null $email
 */
class Author extends Model
{
    use IsJsonApiResource;

    protected $connection = 'crm';

    protected $table = 'authors';

    protected $guarded = [];

    public $timestamps = false;

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'author_id');
    }
}
