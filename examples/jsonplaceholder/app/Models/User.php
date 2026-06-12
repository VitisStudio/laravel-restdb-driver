<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Vitis\RestDB\Eloquent\InteractsWithRestApi;

/**
 * @property int|null $id
 * @property string|null $name
 * @property string|null $username
 * @property string|null $email
 */
class User extends Model
{
    use InteractsWithRestApi;

    protected $connection = 'jsonplaceholder';

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'userId');
    }
}
